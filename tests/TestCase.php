<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test that reaches the network is slow, flaky offline and may spend real API quota: fake the call
        Http::preventStrayRequests();
    }
}
