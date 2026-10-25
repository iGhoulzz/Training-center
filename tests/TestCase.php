<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Start every test with Livewire's per-request static state cleared.
     *
     * Livewire keeps "a component was rendered this request" in a static property,
     * which outlives the application a test booted, and an ordinary page request
     * that renders a component (a Filament login page fetched with `$this->get()`)
     * leaves it set. The next test in the same process then gets Livewire's script
     * injected into whatever HTML it fetches, including pages that promise to carry
     * none, such as the certificate verifier (VerificationDisclosureTest).
     *
     * Serial order happened never to put that pair side by side; a parallel worker
     * runs files in whatever order it is handed them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Livewire::flushState();
    }
}
