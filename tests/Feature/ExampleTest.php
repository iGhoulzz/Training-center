<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * RefreshDatabase since P4-T12: the home page now reads the course catalogue, so
 * this test touches the database and left DatabaseIsolationTest's list of tests
 * that touch none. Written as Pest, like the rest of the suite, because that
 * guard recognises an isolation declaration only as a `uses()` call. Without it,
 * a run of this file alone on a fresh test database fails on a missing `courses`
 * table.
 */
uses(RefreshDatabase::class);

it('returns a successful response from the application', function () {
    $this->get('/')->assertStatus(200);
});
