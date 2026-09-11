<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages\Reports;

use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Exports\PrepareReportCsvExport;
use App\Domain\Finance\Exports\ReportDataBuilder;
use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportExportAuthorization;
use App\Domain\Finance\Exports\ReportExporter;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Models\User;
use App\Support\CentreCalendar;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Shared filter, authorization, and queued-export shell for report pages.
 *
 * @property-read Schema $form
 */
abstract class ReportPage extends Page
{
    protected string $view = 'finance.reports.page';

    /** @var array<string, mixed> */
    public array $filters = [];

    /** @var array<string, mixed> */
    public array $appliedFilters = [];

    #[Locked]
    public int $requesterId;

    abstract public static function kind(): ReportKind;

    /** @return class-string<ReportExporter> */
    abstract protected static function exporter(): string;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->is_active
            && $user->can('view_financial_report');
    }

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        parent::routes($panel, $configuration);

        if (static::kind() !== ReportKind::Revenue) {
            return;
        }

        Route::get('/reports/pdf/{requester}/{reference}', ReportPdfDownload::class)
            ->whereNumber('requester')
            ->whereUuid('reference')
            ->middleware(['signed', 'throttle:30,1'])
            ->name('reports.pdf.download');

        Route::get('/reports/xlsx/{export}', ReportXlsxDownload::class)
            ->whereNumber('export')
            ->middleware(['signed', 'throttle:30,1'])
            ->name('reports.xlsx.download');
    }

    public static function getNavigationGroup(): string
    {
        return __('reports.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return self::pageLabel('navigation');
    }

    public function getTitle(): string
    {
        return self::pageLabel('title');
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->requesterId = $user->getKey();
        $this->filters = $this->defaultFilters();
        $this->appliedFilters = $this->filters;
        $this->form->fill($this->filters);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->filterComponents())
            ->columns(3)
            ->statePath('filters');
    }

    public function applyFilters(): void
    {
        $validated = $this->validate($this->filterRules());
        $this->appliedFilters = $validated['filters'];
    }

    #[Computed]
    public function dataset(): ReportDataset
    {
        return app(ReportDataBuilder::class)->build(
            static::kind(),
            $this->appliedFilters,
            $this->requester(),
        );
    }

    /** @return list<Component> */
    private function filterComponents(): array
    {
        return match (static::kind()) {
            ReportKind::Revenue, ReportKind::PaymentMethod => [
                DatePicker::make('from')
                    ->label(__('reports.filters.from'))
                    ->required(),
                DatePicker::make('to')
                    ->label(__('reports.filters.to'))
                    ->required()
                    ->afterOrEqual('from'),
            ],
            ReportKind::OutstandingAged, ReportKind::DailyTender => [
                DatePicker::make('date')
                    ->label(__('reports.filters.date'))
                    ->required(),
            ],
            ReportKind::WageCost, ReportKind::Profit => [
                TextInput::make('month')
                    ->label(__('reports.filters.month'))
                    ->type('month')
                    ->required(),
            ],
            ReportKind::StudentPaymentHistory => [
                Select::make('student_id')
                    ->label(__('reports.filters.student_id'))
                    ->placeholder(__('reports.filters.choose_student'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchStudents($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::studentOptionLabel($value)),
            ],
        };
    }

    /** @return array<int, string> */
    public static function searchStudents(string $search): array
    {
        return Student::withTrashed()
            ->select(['id', 'student_code', 'first_name', 'last_name'])
            ->where(function (Builder $query) use ($search): void {
                $query
                    ->where('student_code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Student $student): array => [
                (int) $student->getKey() => self::studentLabel($student),
            ])
            ->all();
    }

    public static function studentOptionLabel(mixed $value): ?string
    {
        $student = Student::withTrashed()
            ->select(['id', 'student_code', 'first_name', 'last_name'])
            ->find($value);

        return $student instanceof Student ? self::studentLabel($student) : null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'dataset' => $this->dataset(),
        ];
    }

    /** @return array<Action | ExportAction> */
    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make('export_xlsx')
                ->label(__('reports.actions.export_xlsx'))
                ->exporter(static::exporter())
                ->job(PrepareReportCsvExport::class)
                ->formats([ExportFormat::Xlsx])
                ->columnMapping(false)
                ->options(fn (): array => ['snapshot' => $this->captureSnapshot()->toArray()])
                ->modifyQueryUsing(function (Builder $query, array $options): Builder {
                    $exporter = static::exporter();

                    return $exporter::scopeToReport($query, $options, $this->requester());
                })
                ->authorize(fn (): bool => $this->canExport()),
            Action::make('export_pdf')
                ->label(__('reports.actions.export_pdf'))
                ->authorize(fn (): bool => $this->canExport())
                ->action(function (): void {
                    $user = $this->requester();

                    GenerateReportPdfJob::dispatch(
                        $this->captureSnapshot()->toArray(),
                        $user->getKey(),
                    )->afterCommit();

                    Notification::make()
                        ->title(__('reports.notifications.pdf_queued'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    private function defaultFilters(): array
    {
        $today = CentreCalendar::localise(now());

        return match (static::kind()) {
            ReportKind::Revenue, ReportKind::PaymentMethod => [
                'from' => $today->startOfMonth()->format('Y-m-d'),
                'to' => $today->endOfMonth()->format('Y-m-d'),
            ],
            ReportKind::OutstandingAged, ReportKind::DailyTender => [
                'date' => $today->format('Y-m-d'),
            ],
            ReportKind::WageCost, ReportKind::Profit => [
                'month' => $today->format('Y-m'),
            ],
            ReportKind::StudentPaymentHistory => ['student_id' => null],
        };
    }

    /** @return array<string, list<string>> */
    private function filterRules(): array
    {
        return match (static::kind()) {
            ReportKind::Revenue, ReportKind::PaymentMethod => [
                'filters.from' => ['required', 'date_format:Y-m-d'],
                'filters.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filters.from'],
            ],
            ReportKind::OutstandingAged, ReportKind::DailyTender => [
                'filters.date' => ['required', 'date_format:Y-m-d'],
            ],
            ReportKind::WageCost, ReportKind::Profit => [
                'filters.month' => ['required', 'date_format:Y-m'],
            ],
            ReportKind::StudentPaymentHistory => [
                'filters.student_id' => ['nullable', 'integer', 'exists:students,id'],
            ],
        };
    }

    private function requester(): User
    {
        return User::query()->findOrFail($this->requesterId);
    }

    private function canExport(): bool
    {
        $requester = $this->requester();

        return ReportExportAuthorization::allows($requester);
    }

    private function captureSnapshot(): ReportSnapshot
    {
        $requester = $this->requester();
        $locale = $requester->locale;
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale($locale);

            return new ReportSnapshot(
                kind: static::kind(),
                title: $this->getTitle(),
                locale: $locale,
                filters: $this->displayedFilters(),
                dataset: app(ReportDataBuilder::class)->build(
                    static::kind(),
                    $this->appliedFilters,
                    $requester,
                ),
            );
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /** @return array<string, string> */
    private function displayedFilters(): array
    {
        return match (static::kind()) {
            ReportKind::Revenue, ReportKind::PaymentMethod => [
                (string) __('reports.filters.from') => (string) ($this->appliedFilters['from'] ?? ''),
                (string) __('reports.filters.to') => (string) ($this->appliedFilters['to'] ?? ''),
            ],
            ReportKind::OutstandingAged, ReportKind::DailyTender => [
                (string) __('reports.filters.date') => (string) ($this->appliedFilters['date'] ?? ''),
            ],
            ReportKind::WageCost, ReportKind::Profit => [
                (string) __('reports.filters.month') => (string) ($this->appliedFilters['month'] ?? ''),
            ],
            ReportKind::StudentPaymentHistory => [
                (string) __('reports.filters.student_id') => self::studentOptionLabel(
                    $this->appliedFilters['student_id'] ?? null,
                ) ?? (string) __('reports.filters.not_selected'),
            ],
        };
    }

    private static function studentLabel(Student $student): string
    {
        return "{$student->student_code} — {$student->full_name}";
    }

    private static function pageLabel(string $label): string
    {
        return (string) __(implode('.', [
            'reports',
            'pages',
            static::kind()->value,
            $label,
        ]));
    }
}
