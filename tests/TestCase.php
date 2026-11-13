<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test must never reach a real bank or a real shop: any HTTP call
        // that was not faked fails the test instead of leaving the machine.
        Http::preventStrayRequests();
        Setting::forget(); // settings are remembered per request; a test is many requests
    }
}
