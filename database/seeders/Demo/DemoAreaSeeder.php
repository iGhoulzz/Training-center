<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * One area of the demo history, registered against the shared timeline.
 *
 * run() writes nothing itself. It registers events, and DemoSeeder executes the
 * whole timeline once every area has registered, so the areas interleave by date
 * the way the real centre's days did instead of running one after the other.
 */
abstract class DemoAreaSeeder extends Seeder
{
    public function __construct(protected readonly DemoContext $context) {}

    /** Register this area's events. */
    abstract public function run(): void;
}
