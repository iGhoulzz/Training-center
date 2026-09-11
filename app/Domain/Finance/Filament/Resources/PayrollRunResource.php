<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\CreatePayrollRun;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\ReviewPayrollRun;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Services\PayrollCalculator;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payroll drafts are created and reviewed here, then sealed through Actions.
 *
 * @extends resource<PayrollRun>
 */
final class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'payroll-runs';

    public static function getModelLabel(): string
    {
        return __('payroll.payroll_run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.payroll_runs');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.payroll_runs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('payroll.run_type'))
                ->options(collect(PayrollRunType::cases())
                    ->mapWithKeys(fn (PayrollRunType $type): array => [$type->value => $type->label()])
                    ->all())
                ->live()
                ->required(),

            DatePicker::make('period_start')
                ->label(__('payroll.period_start'))
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::MonthlySalary->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::MonthlySalary->value),

            DatePicker::make('period_end')
                ->label(__('payroll.period_end'))
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::MonthlySalary->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::MonthlySalary->value)
                ->afterOrEqual('period_start'),

            Select::make('assignment_ids')
                ->label(__('payroll.instructor_assignments'))
                ->multiple()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::searchInstructorAssignments($search))
                ->getOptionLabelsUsing(fn (array $values): array => self::instructorAssignmentLabels($values))
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::InstructorBatch->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::InstructorBatch->value),

            Select::make('target_line_id')
                ->label(__('payroll.corrected_line'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => self::searchCorrectionTargets($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::correctionTargetOptionLabel($value))
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value),

            TextInput::make('correction_amount')
                ->label(__('payroll.correction_amount'))
                ->inputMode('decimal')
                ->rules([
                    'numeric',
                    'decimal:0,3',
                    'regex:/^[+-]?\d{1,9}(?:\.\d{1,3})?$/D',
                    'gte:-999999999.999',
                    'lte:999999999.999',
                ])
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value)
                ->suffix(__('payroll.currency')),

            Textarea::make('correction_reason')
                ->label(__('payroll.reason'))
                ->visible(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value)
                ->required(fn (Get $get): bool => $get('type') === PayrollRunType::Adjustment->value),

            Textarea::make('notes')->label(__('payroll.notes')),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('type')
                ->label(__('payroll.run_type'))
                ->formatStateUsing(fn (PayrollRunType|string $state): string => $state instanceof PayrollRunType
                    ? $state->label()
                    : $state),
            TextEntry::make('period_start')->label(__('payroll.period_start'))->date(),
            TextEntry::make('period_end')->label(__('payroll.period_end'))->date(),
            TextEntry::make('createdBy.name')->label(__('payroll.created_by')),
            TextEntry::make('finalized_at')
                ->label(__('payroll.finalized_at'))
                ->dateTime()
                ->placeholder(__('payroll.draft')),
            TextEntry::make('finalizedBy.name')->label(__('payroll.finalized_by')),
            TextEntry::make('notes')->label(__('payroll.notes')),
            RepeatableEntry::make('lines')
                ->label(__('payroll.lines'))
                ->schema([
                    TextEntry::make('user.name')->label(__('payroll.employee')),
                    TextEntry::make('segment_start')->label(__('payroll.segment_start'))->date(),
                    TextEntry::make('segment_end')->label(__('payroll.segment_end'))->date(),
                    TextEntry::make('frozen_rate')
                        ->label(__('payroll.rate'))
                        ->formatStateUsing(fn (?string $state): string => $state === null
                            ? __('payroll.not_applicable')
                            : __('payroll.amount_lyd', ['amount' => $state])),
                    TextEntry::make('frozen_hours')->label(__('payroll.hours')),
                    TextEntry::make('computed_amount')
                        ->label(__('payroll.computed_amount'))
                        ->formatStateUsing(fn (string $state): string => __('payroll.amount_lyd', ['amount' => $state])),
                    TextEntry::make('reason')->label(__('payroll.reason')),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('payroll.run_type'))
                    ->formatStateUsing(fn (PayrollRunType|string $state): string => $state instanceof PayrollRunType
                        ? $state->label()
                        : $state),
                TextColumn::make('period_start')->label(__('payroll.period_start'))->date(),
                TextColumn::make('period_end')->label(__('payroll.period_end'))->date(),
                TextColumn::make('createdBy.name')->label(__('payroll.created_by')),
                TextColumn::make('finalized_at')
                    ->label(__('payroll.finalized_at'))
                    ->dateTime()
                    ->placeholder(__('payroll.draft')),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PayrollRun $record): string => self::getUrl('review', ['record' => $record]));
    }

    /** @return Builder<PayrollRun> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('createdBy')
            ->with('finalizedBy')
            ->with('lines.user');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'create' => CreatePayrollRun::route('/create'),
            'review' => ReviewPayrollRun::route('/{record}/review'),
        ];
    }

    /** @return array<int, string> */
    public static function searchInstructorAssignments(string $search): array
    {
        $assignments = app(PayrollCalculator::class)->searchAvailableInstructorAssignments($search);

        return $assignments->mapWithKeys(fn (array $assignment): array => [
            $assignment['id'] => self::instructorAssignmentLabel($assignment),
        ])->all();
    }

    /**
     * @param  list<int|string>  $values
     * @return array<int, string>
     */
    public static function instructorAssignmentLabels(array $values): array
    {
        return app(EnrollmentQueryService::class)
            ->instructorAssignmentsById(array_map('intval', $values))
            ->mapWithKeys(fn (array $assignment): array => [
                $assignment['id'] => self::instructorAssignmentLabel($assignment),
            ])
            ->all();
    }

    /**
     * @param  array{id: int, batch_id: int, batch_code: string, user_id: int, user_name: string, assigned_hours: int}  $assignment
     */
    private static function instructorAssignmentLabel(array $assignment): string
    {
        return __('payroll.assignment_option', [
            'employee' => $assignment['user_name'],
            'batch' => $assignment['batch_code'],
            'hours' => $assignment['assigned_hours'],
        ]);
    }

    /** @return array<int, string> */
    public static function searchCorrectionTargets(string $search): array
    {
        return PayrollLine::query()
            ->select(['id', 'user_id', 'computed_amount'])
            ->finalized()
            ->whereNull('corrects_payroll_line_id')
            ->where(function (Builder $query) use ($search): void {
                $query->whereHas(
                    'user',
                    fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"),
                );

                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
            })
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (PayrollLine $line): array => [
                $line->getKey() => self::correctionTargetLabel($line),
            ])
            ->all();
    }

    public static function correctionTargetOptionLabel(mixed $value): ?string
    {
        $line = PayrollLine::query()
            ->select(['id', 'user_id', 'computed_amount'])
            ->with('user:id,name')
            ->find($value);

        return $line instanceof PayrollLine ? self::correctionTargetLabel($line) : null;
    }

    private static function correctionTargetLabel(PayrollLine $line): string
    {
        return __('payroll.correction_target_option', [
            'line' => $line->getKey(),
            'employee' => $line->user->name,
            'amount' => $line->computed_amount,
        ]);
    }
}
