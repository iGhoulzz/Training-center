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
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Builds the demo history by registering every area on one timeline and replaying it.
 *
 * The roles, permissions and the owner account must already exist (DatabaseSeeder);
 * DemoSeedCommand makes sure of that and of the safety rules before calling this.
 *
 * Two process-wide settings are changed for the length of the run and put back:
 *
 * - the default queue connection becomes `sync`, so each receipt job runs as the payment
 *   commits and the demo ends with every receipt generated whether or not a worker is
 *   running (the jobs and Actions are untouched);
 * - the identifier-code generator is rebound to a seeded one, so student numbers come out
 *   the same on every run. The timeline restores the clock itself.
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

    public function run(): void
    {
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        mt_srand(DemoContext::SEED);
        app()->bind(IdentifierCode::class, static fn (): IdentifierCode => new IdentifierCode(
            static fn (int $max): int => mt_rand(0, $max),
        ));

        $context = new DemoContext(CarbonImmutable::now(), app(CauserResolver::class));
        $this->context = $context;

        try {
            foreach (self::AREAS as $area) {
                (new $area($context))->run();
            }

            $context->timeline->run();

            $this->generateMissingReceipts();
        } finally {
            config(['queue.default' => $queue]);
        }
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
