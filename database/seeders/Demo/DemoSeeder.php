<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Support\IdentifierCode;
use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Publications\Models\Article;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Builds the demo history by registering every area on one timeline and replaying it.
 *
 * It refuses on its own (DemoGuard) before its first write, and seeds the base roles and
 * owner account itself, as the first event of the history, when they are missing.
 *
 * Three process-wide settings are changed for the length of the run and put back, in
 * the same finally block, also when a step throws:
 *
 * - the default queue connection becomes `sync`, so each receipt job runs as the payment
 *   commits and the demo ends with every receipt generated whether or not a worker is
 *   running (the jobs and Actions are untouched);
 * - the identifier-code generator is rebound to a seeded one, so student numbers come out
 *   the same on every run, and PHP's random generator is reseeded afterwards;
 * - the clock is stepped by the timeline, which restores it itself.
 */
final class DemoSeeder extends Seeder
{
    /** @var list<class-string<DemoAreaSeeder>> in registration order */
    private const AREAS = [
        DemoStaffSeeder::class,
        DemoCatalogueSeeder::class,
        DemoStudentSeeder::class,
        DemoFinanceSeeder::class,
        DemoPayrollSeeder::class,
        DemoPublicationSeeder::class,
    ];

    private ?DemoContext $context = null;

    /**
     * @throws RuntimeException when the environment or the database is not one the demo may be written to.
     */
    public function run(): void
    {
        // Before anything is touched, whoever called: the command checks too, a bare
        // `db:seed --class` does not.
        DemoGuard::assertSafe();

        $queue = config('queue.default');
        $binding = app()->getBindings()[IdentifierCode::class] ?? null;

        config(['queue.default' => 'sync']);

        mt_srand(DemoContext::SEED);
        app()->bind(IdentifierCode::class, static fn (): IdentifierCode => new IdentifierCode(
            static fn (int $max): int => mt_rand(0, $max),
        ));

        $context = new DemoContext(CarbonImmutable::now(), app(CauserResolver::class));
        $this->context = $context;

        try {
            $this->registerBaseSeeders($context);

            foreach (self::AREAS as $area) {
                (new $area($context))->run();
            }

            $context->timeline->run();

            $this->generateMissingReceipts();
        } finally {
            config(['queue.default' => $queue]);

            if ($binding === null) {
                unset(app()[IdentifierCode::class]);
            } else {
                app()->bind(IdentifierCode::class, $binding['concrete'], $binding['shared']);
            }

            mt_srand(random_int(1, 2_147_483_647));
        }
    }

    /**
     * The roles, permissions and owner account, on the demo's own first day.
     *
     * Seeding them inside the timeline, not before it, is what dates the owner's role
     * grant before the owner's first act. A database that already ran
     * `migrate:fresh --seed` has them stamped with that day instead, which is the
     * truth about when they were made and the reason the README recommends plain
     * `migrate:fresh`.
     */
    private function registerBaseSeeders(DemoContext $context): void
    {
        $context->timeline->add($context->day(-185), 7, 'base roles and owner account', function (): void {
            $seeded = Role::query()->whereIn('name', ['super_admin', 'admin', 'staff', 'student'])->count() === 4
                && User::query()->where('email', 'owner@example.test')->exists();

            if (! $seeded) {
                (new DatabaseSeeder)->setContainer(app())->__invoke();
            }
        });
    }

    /** The demo logins: staff accounts, then the portal students issued during the run. */
    public function logins(): array
    {
        $rows = [];

        foreach (User::query()->with('roles')->whereDoesntHave('student')->orderBy('id')->get() as $user) {
            $rows[] = [
                'name' => $user->name,
                'email' => $user->email,
                'password' => DemoContext::PASSWORD,
                'role' => (string) $user->roles->pluck('name')->first(),
                'status' => $user->is_active ? 'active' : 'deactivated',
            ];
        }

        foreach ($this->context?->portalLogins ?? [] as $login) {
            $rows[] = $login + ['role' => 'student (portal)', 'status' => 'temporary password'];
        }

        return $rows;
    }

    /**
     * What was made, as label => count.
     *
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        $enrolments = Enrollment::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $batches = Batch::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $runs = PayrollRun::query()->get();

        return [
            'Staff accounts' => User::query()->whereDoesntHave('student')->count(),
            'Courses' => Course::query()->count(),
            'Batches (planned / active / completed)' => ($batches[BatchStatus::Planned->value] ?? 0).' / '.($batches[BatchStatus::Active->value] ?? 0).' / '.($batches[BatchStatus::Completed->value] ?? 0),
            'Students (live / removed)' => Student::query()->count().' / '.Student::onlyTrashed()->count(),
            'Enrolments (active / completed / withdrawn)' => ($enrolments[EnrollmentStatus::Active->value] ?? 0).' / '.($enrolments[EnrollmentStatus::Completed->value] ?? 0).' / '.($enrolments[EnrollmentStatus::Withdrawn->value] ?? 0),
            'Certificates' => StudentCertificate::query()->count(),
            'Charges' => Charge::query()->count(),
            'Payments (reversed)' => Payment::query()->count().' ('.Payment::query()->whereNotNull('reversed_at')->count().')',
            'Receipts generated' => Payment::query()->whereNotNull('receipt_path')->count(),
            'Expenses (reversed)' => Expense::query()->count().' ('.Expense::query()->whereNotNull('reversed_at')->count().')',
            'Payroll runs (finalized / draft)' => $runs->whereNotNull('finalized_at')->count().' / '.$runs->whereNull('finalized_at')->count(),
            'Articles (published)' => Article::query()->count().' ('.Article::query()->whereNotNull('published_at')->count().')',
            'Activity log entries' => DB::table('activity_log')->count(),
        ];
    }

    /**
     * Belt and braces: any receipt the queue did not generate is generated now, by the
     * same job, so the demo never ends with a receipt still waiting for a worker.
     */
    private function generateMissingReceipts(): void
    {
        Payment::query()->whereNull('receipt_path')->orderBy('id')->pluck('id')->each(
            static fn (int $id) => GenerateReceiptJob::dispatchSync($id),
        );
    }
}
