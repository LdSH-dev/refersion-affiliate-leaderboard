<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network: any request without a matching fake throws.
        Http::preventStrayRequests();

        // Retry backoff must never slow the suite down; tests assert on sleeps instead.
        Sleep::fake();
    }
}
