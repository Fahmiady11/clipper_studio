<?php

namespace App\Support;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Lets a queued job be cancelled from the UI while it runs.
 *
 * The job wraps its work in watch() with a check that reads the cancel flag
 * from the database. Long external commands (yt-dlp, ffmpeg) go through
 * exec(), which polls that check and kills the command as soon as the job is
 * cancelled — so a 20-minute download stops within seconds, not when it ends.
 */
final class Cancellation
{
    private const CHECK_EVERY = 1.5; // seconds between cancel-flag reads while a command runs

    private static ?Closure $check = null;

    /**
     * @template T
     *
     * @param  callable(): bool  $isCancelled
     * @param  callable(): T  $work
     * @return T
     */
    public static function watch(callable $isCancelled, callable $work): mixed
    {
        $previous = self::$check;
        self::$check = Closure::fromCallable($isCancelled);

        try {
            return $work();
        } finally {
            self::$check = $previous;
        }
    }

    public static function throwIfCancelled(): void
    {
        if (self::$check !== null && (self::$check)()) {
            throw new JobCancelled;
        }
    }

    /**
     * Run a shell command like exec(), but stop it when the watched job is cancelled.
     *
     * @return array{0: int, 1: list<string>} exit code and output lines
     */
    public static function exec(string $command): array
    {
        set_time_limit(0); // encodes/downloads outlast PHP max_execution_time

        $process = Process::fromShellCommandline($command, null, null, null, null);
        $process->start();

        $lastCheck = microtime(true);
        while ($process->isRunning()) {
            usleep(200_000);

            if (self::$check === null || microtime(true) - $lastCheck < self::CHECK_EVERY) {
                continue;
            }
            $lastCheck = microtime(true);

            if ((self::$check)()) {
                self::kill($process);
                throw new JobCancelled;
            }
        }

        $output = rtrim($process->getOutput().$process->getErrorOutput(), "\n");

        return [$process->getExitCode() ?? 1, $output === '' ? [] : explode("\n", $output)];
    }

    private static function kill(Process $process): void
    {
        // The command runs under `sh -c`; terminate its children (ffmpeg spawned
        // by yt-dlp, the encoder itself) before the shell, or they keep running.
        if ($pid = $process->getPid()) {
            exec('pkill -TERM -P '.(int) $pid.' 2>/dev/null');
        }
        $process->stop(3);
    }
}
