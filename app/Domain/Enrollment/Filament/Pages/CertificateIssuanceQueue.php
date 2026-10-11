<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Queries\CertificateIssuanceQueue as IssuanceQueueQuery;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CertificateIssuanceQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.enrollment.certificate-issuance-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active
            && Gate::allows('issue', StudentCertificate::class);
    }

    public static function getNavigationLabel(): string
    {
        return __('certificates.queue_title');
    }

    public function getTitle(): string
    {
        return __('certificates.queue_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(app(IssuanceQueueQuery::class)->query())
            ->columns([
                TextColumn::make('student.student_code')->label(__('certificates.queue_student_code')),
                TextColumn::make('student.full_name')->label(__('certificates.student')),
                TextColumn::make('batch.course.name_en')
                    ->label(__('certificates.course'))
                    ->formatStateUsing(fn (Enrollment $record): string => $record->batch->course->name()),
                TextColumn::make('batch.code')->label(__('certificates.queue_batch')),
                TextColumn::make('completed_at')->label(__('certificates.completed_on'))->date(),
            ])
            ->recordActions([
                Action::make('issueCertificate')
                    ->label(__('certificates.issue'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->url(fn (Enrollment $record): string => static::getUrl([
                        'action' => 'issue',
                        'actionArguments' => ['enrollment_id' => $record->getKey()],
                    ])),
            ])
            ->emptyStateHeading(__('certificates.queue_empty'));
    }

    /**
     * Reuse the register's authorized form and Action without requiring register
     * view permission. Filament's action/actionArguments URL state mounts this
     * modal on page load; the enrollment argument only preselects the picker.
     */
    public function issueAction(): Action
    {
        return StudentCertificateResource::issueAction()
            ->fillForm(fn (array $arguments): array => [
                'enrollment_id' => $arguments['enrollment_id'] ?? null,
            ]);
    }
}
