<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Policies\BatchPolicy;
use App\Domain\Enrollment\Policies\CoursePolicy;
use App\Domain\Enrollment\Policies\EnrollmentPolicy;
use App\Domain\Enrollment\Policies\StudentPolicy;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Policies\ChargePolicy;
use App\Domain\Finance\Policies\DiscountPolicy;
use App\Domain\Finance\Policies\StaffCompensationPolicy;
use App\Domain\Staff\Console\GuardedBackupCommand;
use App\Domain\Staff\Console\GuardedCleanupCommand;
use App\Domain\Staff\Console\GuardedListCommand;
use App\Domain\Staff\Console\GuardedMonitorCommand;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Policies\ActivityPolicy;
use App\Domain\Staff\Policies\StaffCertificatePolicy;
use App\Domain\Staff\Policies\StaffProfilePolicy;
use App\Domain\Staff\Policies\UserPolicy;
use App\Domain\Staff\Support\ActivityEvent;
use App\Domain\Staff\Support\BackupConfiguration;
use App\Domain\Staff\Support\SuperAdminRoleId;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The log name authentication events are recorded under.
     *
     * Separated from 'default' so the activity panel can filter sign-in noise
     * away from record changes without matching on description strings.
     */
    public const AUTH_LOG = 'auth';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * SCOPED, NOT SINGLETON — AND THE DIFFERENCE IS THE WHOLE BINDING.
         *
         * SuperAdminRoleId memoizes one row's primary key so the user list stops
         * re-reading it per row; P3-T15's audit measured 28 of these lookups on
         * a 10-row page. The LIFETIME of that memo is the requirement, not an
         * implementation detail: it must not outlive the request or the job that
         * filled it.
         *
         * `scoped()` is `singleton()` plus registration in $scopedInstances
         * (Container.php:528-535), and the queue worker's reset callback calls
         * $app->forgetScopedInstances() between jobs
         * (QueueServiceProvider.php:263). A plain singleton — or a static
         * property on Role, which is the shape this started as — is rebuilt only
         * when the process restarts, so a long-lived worker would carry one
         * job's answer into every job after it.
         */
        $this->app->scoped(SuperAdminRoleId::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Refuse to run production without a usable backup configuration.
         *
         * Placed first so it fires before anything else has a chance to succeed:
         * an install missing its destination or archive password looks completely
         * healthy until a restore is needed, and that is exactly when discovering
         * it is worst.
         *
         * CONFIGURATION ONLY (P1-T17). Whether the removable drive is actually
         * plugged in is deliberately NOT checked here — this runs for every
         * request and every artisan command, so an absent drive would take the
         * centre offline to protect data nobody could then reach.
         *
         * That check lives on the four backup commands registered just below, so
         * it applies however they are started — by the scheduler, by hand, or
         * through Artisan::call(). See BackupConfiguration.
         */
        BackupConfiguration::assertReadyForProduction($this->app->environment());

        /*
         * AN UNEXPECTED LAZY LOAD FAILS IN DEVELOPMENT AND CI, NEVER IN PRODUCTION.
         *
         * On local and testing this throws `LazyLoadingViolationException` the
         * moment a relationship is read without being loaded, which is where an
         * N+1 is cheap to see and cheap to fix. In production it is OFF: turning
         * a public request into a 500 over a performance defect trades a slow
         * page for no page, and a certificate verification or a receipt download
         * failing outright is worse for the centre than one extra query.
         *
         * WHAT THIS DOES NOT CATCH, STATED HERE BECAUSE A COMMENT CLAIMING MORE
         * THAN THE MECHANISM DELIVERS IS THIS REPOSITORY'S MOST FREQUENT DEFECT.
         * ---------------------------------------------------------------------
         * It catches ONE shape: reading an Eloquent relationship that was not
         * eager-loaded. It does not detect full table scans, bad join order,
         * correlated subqueries, repeated scalar queries, or a query builder
         * returning an unbounded result set.
         *
         * The sharpest illustration is P3-T15's own headline finding, which this
         * guard would NOT have caught: the user list's per-row
         * `roles()->whereKey($id)->exists()` is an explicit relationship QUERY,
         * not a lazy-loaded property, so it never triggers a violation. It took
         * 59 statements for a populated page and was found by counting
         * statements, which is what P35-T02's UserResourceQueryCountTest now
         * does. This guard complements query-count tests and EXPLAIN; it does
         * not replace either.
         *
         * AND IT IS NARROWER STILL THAN "RELATIONSHIP LAZY LOADING", IN A WAY
         * THE AUDIT DID NOT STATE AND NOBODY WOULD GUESS.
         * ---------------------------------------------------------------------
         * `Builder::hydrate():498-501` in the installed framework arms the
         * per-instance flag ONLY when the result carried more than one row:
         *
         *     if (count($items) > 1) {
         *         $model->preventsLazyLoading = Model::preventsLazyLoading();
         *     }
         *
         * A model from `find()`, `first()` or `findOrFail()` therefore never
         * violates, however many relations are read off it. Laravel's reasoning
         * holds — one model read once is not an N+1 — but the practical effect
         * is that verifying this guard with `Model::find(1)->relation` shows it
         * doing nothing, and reads exactly like a guard that was never wired up.
         * That is measured behaviour, not a reading of the docs, and
         * LazyLoadingGuardTest pins it in both directions.
         *
         * A violation is a bug in the caller. Fix the eager load — never widen
         * the environment check to make a failing test pass.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        /*
         * The transient half of that guard, on the commands themselves.
         *
         * These carry Spatie's signatures — `backup:run`, `backup:monitor`,
         * `backup:clean` — so registering them after the package's provider
         * replaces its commands by name, and every caller gets the check:
         * scheduled, hand-typed, queued, or Artisan::call().
         *
         * A scheduler ->before() callback, which is where this started, guarded
         * only the scheduler and — by aborting before the command began —
         * suppressed the very notification that says the backup did not happen.
         * See RefusesAnUnavailableDestination.
         */
        $this->commands([
            GuardedBackupCommand::class,
            GuardedMonitorCommand::class,
            GuardedCleanupCommand::class,
            // Warns rather than refuses — see its docblock. Listing what
            // survived is what somebody needs during an incident.
            GuardedListCommand::class,
        ]);

        /*
         * NINE OF THESE ELEVEN ARE REDUNDANT. TWO ARE NOT. ALL ELEVEN STAY.
         *
         * The comment that stood here claimed discovery could not find any of
         * them because they live outside app/Policies. That is wrong for nine.
         * `Gate::guessPolicyName()` walks every namespace prefix and tries
         * `<prefix>\Policies\<Class>Policy`, longest first (Gate.php:721-724),
         * so a domain model finds its sibling policy unaided:
         *
         *   App\Domain\Finance\Models\Charge
         *     -> App\Domain\Finance\Policies\ChargePolicy
         *
         * NOT via the `\Models\` -> `\Policies\` substitution at
         * Gate.php:725-727. That branch is guarded by a str_contains for
         * `\Models\` WITH a trailing separator, and these namespaces end in
         * `Models`, so it never fires. Worth stating because the phase-2 plan
         * cited that line as the mechanism; it is the prefix walk above.
         *
         * The two that genuinely need registering are the two whose model and
         * policy share no prefix:
         *
         *   App\Models\User                    -> App\Domain\Staff\Policies\UserPolicy
         *   Spatie\Activitylog\Models\Activity -> App\Domain\Staff\Policies\ActivityPolicy
         *
         * Measured with `Gate::getPolicyFor()` rather than reasoned: strip this
         * block and those two return null while the other nine still resolve.
         * A null policy is a silent false, and these two guard panel account
         * management and the append-only audit log.
         *
         * They stay as deliberate explicitness. The two that matter look
         * identical to the nine at a glance, so trimming to "only the necessary
         * ones" invites the next reader to finish the job. Nor can the block rot
         * unnoticed: removing it fails 45 tests under tests/Feature/Staff alone.
         */
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(StaffProfile::class, StaffProfilePolicy::class);
        Gate::policy(StaffCertificate::class, StaffCertificatePolicy::class);
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Batch::class, BatchPolicy::class);
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(Charge::class, ChargePolicy::class);
        Gate::policy(Discount::class, DiscountPolicy::class);
        Gate::policy(StaffCompensation::class, StaffCompensationPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        /*
         * THE FIRST NAMED LIMITER IN THIS FILE, AND IT IS REFERENCED (P3-T08).
         *
         * `routes/web.php`'s public verifier group applies this by name —
         * `throttle:certificate-verification` — to its three routes. Being
         * referenced is the property whose absence got P1-T03's `login` limiter
         * deleted: a registered-and-unreferenced limiter reads as a control
         * while protecting nothing, and this repository does not carry one
         * twice.
         *
         * TWO WINDOWS, ONE LIMITER. Returning an array of Limit objects layers
         * both under the single name the route asks for, so a route cannot
         * accidentally end up throttled by only one of the two: ten requests a
         * minute stops a fast script, a hundred an hour stops a slow one
         * spread out to dodge the first window.
         *
         * KEYED BY IP, NOT BY ANY ACCOUNT. There is no login here to key
         * against — this is the public internet, unauthenticated by design.
         *
         * THE WINDOW NAME IS PART OF THE KEY, AND IT HAS TO BE.
         * -----------------------------------------------------
         * `ThrottleRequests::handleRequestUsingNamedLimiter()` derives each
         * limit's cache key as `md5($limiterName.$limit->key)` — the limiter
         * NAME plus whatever `by()` was given, and nothing that distinguishes
         * one window from another. Two limits under one name keyed on the bare
         * IP therefore produce the SAME key, and `handleRequest()` then hits
         * that one bucket once per limit, twice per request, with the decay of
         * whichever limit created the entry first. Measured on this exact code
         * with `by($request->ip())` on both:
         *
         *     limit 0  maxAttempts=10   decay=60    key=23a3c129…
         *     limit 1  maxAttempts=100  decay=3600  key=23a3c129…
         *     Distinct keys: 1 of 2
         *     Request 5: allowed   counter now = 10
         *     Request 6: REFUSED (429)
         *
         * The sixth request refused rather than the eleventh, and no hour
         * window existing at all. Prefixing each `by()` with its window name
         * gives the two limits distinct keys, which is what makes them two
         * windows rather than one bucket counted twice.
         */
        RateLimiter::for('certificate-verification', function (Request $request): array {
            return [
                Limit::perMinute(10)->by('minute:'.$request->ip()),
                Limit::perHour(100)->by('hour:'.$request->ip()),
            ];
        });

        /*
         * saveQuietly() is deliberate: P1-T12 adds activity logging, and a login
         * timestamp must not produce a spurious "user updated" audit entry.
         *
         * last_login_at is also absent from User::auditedAttributes(), so this is
         * belt and braces — either alone would suppress the noise, and both are
         * cheap next to a log where every real change is buried under sign-ins.
         */
        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            /*
             * The event carries an Authenticatable, which any guard may supply.
             * Narrowing to our User keeps the causer a real, resolvable record
             * rather than whatever a future guard hands over — phase 3 adds a
             * separate student guard, and a student session must not silently
             * start writing staff-shaped audit rows.
             */
            if (! $event->user instanceof User) {
                return;
            }

            activity(self::AUTH_LOG)
                ->causedBy($event->user)
                ->event(ActivityEvent::LOGGED_IN)
                ->log(ActivityEvent::LOGGED_IN);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            // Null on a session that expired rather than a deliberate sign-out.
            if (! $event->user instanceof User) {
                return;
            }

            activity(self::AUTH_LOG)
                ->causedBy($event->user)
                ->event(ActivityEvent::LOGGED_OUT)
                ->log(ActivityEvent::LOGGED_OUT);
        });

        /*
         * A failed attempt has NO causer — the whole point is that nobody proved
         * who they were. The attempted identifier goes in the properties so a
         * pattern of attempts against one account is visible; the submitted
         * password never does, not even hashed.
         *
         * The IP is attached centrally by RecordActivityWithContext, which is
         * what makes this entry useful at all.
         */
        Event::listen(Failed::class, function (Failed $event): void {
            activity(self::AUTH_LOG)
                ->event(ActivityEvent::LOGIN_FAILED)
                ->withProperties(['email' => $event->credentials['email'] ?? null])
                ->log(ActivityEvent::LOGIN_FAILED);
        });
    }
}
