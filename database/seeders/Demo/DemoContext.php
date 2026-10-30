<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * What every area seeder shares: the clock, the people, and what has been made.
 *
 * Registration time and run time are different moments. An area seeder
 * registers events against the timeline while this object is still empty, and
 * the events read and fill the registries below when they finally run in date
 * order. A closure therefore never holds a model it was handed at registration;
 * it looks the model up by key at the moment it runs, which is the only way a
 * student created in March is the same row the April payment refers to.
 *
 * The password every demo account uses is one constant here so the seeders, the
 * command's login table and the tests cannot disagree about it.
 */
final class DemoContext
{
    public const PASSWORD = 'demo-password';

    /** The fixed seed behind every pseudo-random choice, so two runs agree. */
    public const SEED = 39_001;

    /** The local day the demo history ends on: today, on the centre's calendar. */
    public readonly CarbonImmutable $today;

    public readonly DemoTimeline $timeline;

    /** @var array<string, User> */
    public array $users = [];

    /** @var array<string, Course> */
    public array $courses = [];

    /** @var array<string, Batch> */
    public array $batches = [];

    /** @var array<string, Student> */
    public array $students = [];

    /** @var array<string, Enrollment> */
    public array $enrollments = [];

    /** @var array<string, Discount> */
    public array $discounts = [];

    /** @var array<string, ExpenseCategory> */
    public array $expenseCategories = [];

    /** @var array<string, PayrollRun> */
    public array $payrollRuns = [];

    /** @var array<string, PayrollLine> */
    public array $payrollLines = [];

    /**
     * Portal logins issued during the history, for the command to print.
     *
     * @var list<array{name: string, email: string, password: string}>
     */
    public array $portalLogins = [];

    public function __construct(
        public readonly CarbonImmutable $realNow,
        public readonly CauserResolver $causers,
    ) {
        $this->today = CentreCalendar::localise($realNow)->startOfDay();
        $this->timeline = new DemoTimeline($realNow);
    }

    /** The local day this many days from today; negative is the past. */
    public function day(int $offset): CarbonImmutable
    {
        return $this->today->addDays($offset);
    }

    /** The first day of the month this many months from the current one. */
    public function monthStart(int $monthsFromNow): CarbonImmutable
    {
        return $this->today->startOfMonth()->addMonthsNoOverflow($monthsFromNow);
    }

    /** A staff account by its demo key. */
    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new \LogicException("The demo has no staff account [{$key}].");
    }

    /**
     * Run an Action as one of the demo's accounts, with that account as the causer.
     *
     * Several Actions write models whose activity-log causer comes from the signed-in
     * user (Spatie resolves it from the session), and a seeder has no session: left
     * alone the log would name nobody. Every Action call in the demo therefore goes
     * through here, so the causer is the same account the Action authorizes.
     *
     * @param  Closure(User): mixed  $action
     */
    public function by(string $account, Closure $action): mixed
    {
        $actor = $this->user($account);

        return $this->causers->withCauser($actor, fn (): mixed => $action($actor));
    }

    /**
     * Run a plain Eloquent write as an actor.
     *
     * Used only where the admin panel itself writes with a bare model save (a
     * Filament create or edit page with no Action behind it). The activity log
     * then names the actor, exactly as it would for the signed-in user.
     *
     * @param  Closure(): mixed  $write
     */
    public function as(User $actor, Closure $write): mixed
    {
        return $this->causers->withCauser($actor, $write);
    }
}
