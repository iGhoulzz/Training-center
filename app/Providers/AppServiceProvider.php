<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Policies\BatchPolicy;
use App\Domain\Enrollment\Policies\CoursePolicy;
use App\Domain\Enrollment\Policies\StudentPolicy;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Policies\StaffCertificatePolicy;
use App\Domain\Staff\Policies\StaffProfilePolicy;
use App\Domain\Staff\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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

        /*
         * saveQuietly() is deliberate: P1-T12 adds activity logging, and a login
         * timestamp must not produce a spurious "user updated" audit entry.
         */
        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
