<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Actions\RemoveInstructorAction;
use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Actions\UpdateBatchPriceAction;
use App\Domain\Finance\Actions\UpdateCoursePriceAction;

/**
 * What the centre teaches: five courses, nine batches and who teaches them.
 *
 * MIRRORS, where the admin panel writes without an Action:
 *
 * - CreateCourse (app/Domain/Enrollment/Filament/Resources/CourseResource/Pages/CreateCourse.php):
 *   a plain create of the catalogue fields WITHOUT the price, then the price through
 *   UpdateCoursePriceAction in afterCreate() (the price field is never dehydrated).
 * - CreateBatch (.../BatchResource/Pages/CreateBatch.php:90, handleRecordCreation): the row
 *   through CreateWithIdentifierCodeAction::createBatch(), then a batch price, when one is
 *   set, through UpdateBatchPriceAction in afterCreate().
 * - EditBatch (.../BatchResource/Pages/EditBatch.php): the status moves planned, active,
 *   completed with Filament's ordinary edit save, a plain update by the signed-in admin.
 *
 * Instructors go through AssignInstructorAction and RemoveInstructorAction, which are
 * refused once a batch is closed, so an instructor is always assigned while the batch is
 * still open and the batch is only closed after its last day.
 */
final class DemoCatalogueSeeder extends DemoAreaSeeder
{
    /** The day the English course's price rose (UpdateCoursePriceAction). */
    private const PRICE_CHANGE_DAY = -70;

    private const PRICE_CHANGE_TO = '480.000';

    public function run(): void
    {
        $context = $this->context;

        $this->registerCourses();

        $context->timeline->add($context->day(self::PRICE_CHANGE_DAY), 9, 'English course price change', function () use ($context): void {
            app(UpdateCoursePriceAction::class)->execute(
                $context->user('owner'),
                $context->courses['ENG-B1'],
                self::PRICE_CHANGE_TO,
            );
        });

        foreach (DemoScenarios::batches() as $key => $spec) {
            $this->registerBatch($key, $spec);
        }
    }

    private function registerCourses(): void
    {
        $context = $this->context;

        $context->timeline->add($context->day(-183), 9, 'courses', function () use ($context): void {
            foreach (DemoScenarios::courses() as $code => $spec) {
                $course = $context->as($context->user('admin'), fn (): Course => Course::query()->create([
                    'code' => $code,
                    'name_en' => $spec['name_en'],
                    'name_ar' => $spec['name_ar'],
                    'description_en' => $spec['description_en'],
                    'description_ar' => $spec['description_ar'],
                    'total_hours' => $spec['hours'],
                    'is_active' => true,
                ]));

                app(UpdateCoursePriceAction::class)->execute($context->user('owner'), $course, $spec['price']);

                $context->courses[$code] = $course->refresh();
            }
        });
    }

    /**
     * @param  array{course: string, code: string, start: int, end: int, capacity: int, state: string, instructors: array<string, int>, removed?: array<string, array{int, int}>, price?: string, hours?: int}  $spec
     */
    private function registerBatch(string $key, array $spec): void
    {
        $context = $this->context;

        // Opened about two and a half weeks before it starts, but never before the catalogue
        // exists and never in the future (an upcoming batch is already open today).
        $opened = min(max($spec['start'] - 18, -180), -12);

        $context->timeline->add($context->day($opened), 9, "open batch {$key}", function () use ($context, $key, $spec): void {
            $batch = app(CreateWithIdentifierCodeAction::class)->createBatch($context->user('admin'), [
                'course_id' => $context->courses[$spec['course']]->getKey(),
                'code' => $spec['code'],
                'start_date' => $context->day($spec['start'])->toDateString(),
                'end_date' => $context->day($spec['end'])->toDateString(),
                'capacity' => $spec['capacity'],
                'total_hours' => $spec['hours'] ?? null,
                'status' => BatchStatus::Planned,
            ]);

            if (isset($spec['price'])) {
                app(UpdateBatchPriceAction::class)->execute($context->user('owner'), $batch, $spec['price']);
            }

            $context->batches[$key] = $batch->refresh();
        });

        foreach ($spec['instructors'] + ($spec['removed'] ?? []) as $instructor => $hours) {
            $assignedHours = is_array($hours) ? $hours[0] : $hours;

            $context->timeline->add($context->day($opened + 2), 9, "assign {$instructor} to {$key}", function () use ($context, $key, $instructor, $assignedHours): void {
                app(AssignInstructorAction::class)->execute($context->user('admin'), new AssignInstructorData(
                    (int) $context->batches[$key]->getKey(),
                    (int) $context->user($instructor)->getKey(),
                    $assignedHours,
                ));
            });
        }

        foreach ($spec['removed'] ?? [] as $instructor => [$hours, $afterStart]) {
            $context->timeline->add($context->day($spec['start'] + $afterStart), 9, "remove {$instructor} from {$key}", function () use ($context, $key, $instructor): void {
                app(RemoveInstructorAction::class)->execute(
                    $context->user('admin'),
                    $context->batches[$key],
                    $context->user($instructor),
                );
            });
        }

        if ($spec['state'] === 'upcoming') {
            return;
        }

        $this->registerStatus($key, $spec['start'], 7, BatchStatus::Active);

        if ($spec['state'] === 'finished') {
            $this->registerStatus($key, $spec['end'], 12, BatchStatus::Completed);
        }
    }

    private function registerStatus(string $key, int $day, int $hour, BatchStatus $status): void
    {
        $context = $this->context;

        $context->timeline->add($context->day($day), $hour, "batch {$key} becomes {$status->value}", function () use ($context, $key, $status): void {
            $batch = $context->batches[$key];

            $context->as($context->user('admin'), fn (): bool => $batch->update(['status' => $status]));
        });
    }
}
