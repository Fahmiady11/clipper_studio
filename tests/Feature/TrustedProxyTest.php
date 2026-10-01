<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_forwarded_https_scheme_is_used_for_generated_urls(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create(
    'http://example.ngrok-free.app/login',
    'GET',
    [],
    [],
    [],
    [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'example.ngrok-free.app',
        'HTTP_X_FORWARDED_HOST' => 'example.ngrok-free.app',
        'HTTP_X_FORWARDED_PORT' => '443',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ],
);
$response = $kernel->handle($request);
preg_match('/action="([^"]+)/', $response->getContent(), $matches);
echo $matches[1] ?? 'missing';
$kernel->terminate($request, $response);
PHP;

        $process = new Process(
            [PHP_BINARY, '-r', $script],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_URL' => 'http://localhost',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'TRUSTED_PROXIES' => false,
            ],
        );
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('https://example.ngrok-free.app/login', $process->getOutput());
    }
}
