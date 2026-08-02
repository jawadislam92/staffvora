<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // The per-org business-timezone cache is process-static; clear it between
        // tests so a recycled organization id can't return a stale timezone.
        \App\Support\BusinessTime::flushTzCache();
    }
}
