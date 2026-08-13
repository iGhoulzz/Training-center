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
use App\Domain\Finance\Policies\ChargePolicy;
use App\Domain\Finance\Policies\DiscountPolicy;
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
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        //
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
         * These policies live outside app/Policies, so Laravel's
         * convention-based discovery will not find them. Without these lines
         * every check against them silently falls through to false.
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
        Gate::policy(Activity::class, ActivityPolicy::class);

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
