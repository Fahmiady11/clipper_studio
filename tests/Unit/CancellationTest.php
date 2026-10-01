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
        $marker = 'cancel-test-' . uniqid();
        $start  = microtime(true);
        $checks = 0;

        try {
            Cancellation::watch(
                function () use (&$checks) { return ++$checks >= 2; },
                fn() => Cancellation::exec("sh -c 'sleep 30; echo {$marker}'")
            );
            $this->fail('expected JobCancelled');
        } catch (JobCancelled) {
        }

        $this->assertLessThan(10, microtime(true) - $start);
        exec('pgrep -f ' . escapeshellarg("sleep 30; echo {$marker}"), $left);
        $this->assertSame([], $left, 'child process killed');
    }

    public function test_throw_if_cancelled_only_inside_watch(): void
    {
        Cancellation::throwIfCancelled(); // no watcher → no-op

        $this->expectException(JobCancelled::class);
        Cancellation::watch(fn() => true, fn() => Cancellation::throwIfCancelled());
    }
}
