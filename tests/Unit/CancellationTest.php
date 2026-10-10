<?php

namespace Tests\Unit;

use App\Support\Cancellation;
use App\Support\JobCancelled;
use PHPUnit\Framework\TestCase;

class CancellationTest extends TestCase
{
    public function test_exec_returns_code_and_output_like_exec(): void
    {
        [$code, $out] = Cancellation::exec('echo hi; echo err 1>&2; exit 3');

        $this->assertSame(3, $code);
        $this->assertSame(['hi', 'err'], $out);
    }

    public function test_cancel_kills_the_running_command_and_its_children(): void
    {
        // A unique duration tags the grandchild itself: `sleep` is what gets orphaned
        // when only the shell layers die (Linux runs PHP > sh > sh > sleep)
        $sleep = '30.'.random_int(10_000_000, 99_999_999);
        $start = microtime(true);
        $checks = 0;

        try {
            Cancellation::watch(
                function () use (&$checks) {
                    return ++$checks >= 2;
                },
                fn () => Cancellation::exec("sh -c 'sleep {$sleep}; echo done'")
            );
            $this->fail('expected JobCancelled');
        } catch (JobCancelled) {
        }

        $this->assertLessThan(10, microtime(true) - $start);
        // "[s]leep" so the pattern can't match the `sh -c pgrep ...` running it
        exec('pgrep -f '.escapeshellarg("[s]leep {$sleep}"), $left);
        $this->assertSame([], $left, 'every process of the command was killed');
    }

    public function test_throw_if_cancelled_only_inside_watch(): void
    {
        Cancellation::throwIfCancelled(); // no watcher → no-op

        $this->expectException(JobCancelled::class);
        Cancellation::watch(fn () => true, fn () => Cancellation::throwIfCancelled());
    }
}
