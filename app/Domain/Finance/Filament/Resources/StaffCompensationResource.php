<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ListStaffCompensations;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ViewStaffCompensation;
use App\Domain\Finance\Models\StaffCompensation;
use App\Filament\HelpPanel;
use App\Filament\Tables\AdminColumns;
use App\Filament\Tables\AdminTable;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Effective-dated rates: view history and create the next rate, never edit it.
 *
 * @extends resource<StaffCompensation>
 */
final class StaffCompensationResource extends Resource
{
    protected static ?string $model = StaffCompensation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $slug = 'staff-compensations';

    public static function getModelLabel(): string
    {
        return __('payroll.staff_compensation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.staff_compensations');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.staff_compensations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => '[&_.fi-input]:text-base! [&_.fi-select-input]:text-base! [&_.fi-select-input-btn]:text-base! [&_textarea]:text-base!'])->components([
            Section::make(__('payroll.rate_details'))->description(__('payroll.effective_rate_hint'))->columns(2)->schema([
                Select::make('user_id')
                    ->label(__('payroll.employee'))
                    ->options(fn (): array => self::searchEmployees(''))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchEmployees($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::employeeOptionLabel($value))
                    ->required(),

                Select::make('type')
                    ->label(__('payroll.compensation_type'))
                    ->options(collect(CompensationType::cases())
                        ->mapWithKeys(fn (CompensationType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->required(),

                TextInput::make('amount')
                    ->label(__('payroll.amount'))
                    ->required()
                    ->inputMode('decimal')
                    ->rules(['numeric', 'decimal:0,3', 'gt:0', 'lte:999999999.999'])
                    ->suffix(__('payroll.currency')),

                DatePicker::make('effective_from')
                    ->label(__('payroll.effective_from'))
                    ->required(),
            ]),
        ]);
    }

    /** @return array<int, string> */
    public static function searchEmployees(string $search): array
    {
        return User::query()
            ->select(['id', 'name'])
            ->where('is_active', true)
            ->whereHas('staffProfile')
            ->where('name', 'like', "%{$search}%")
            ->orderBy('name')
            ->limit(25)
            ->pluck('name', 'id')
            ->all();
    }

    public static function employeeOptionLabel(mixed $value): ?string
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('staffProfile')
            ->whereKey($value)
            ->value('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('payroll.rate_details'))->description(__('payroll.effective_rate_hint'))->columns(2)->schema([
                TextEntry::make('user.name')->label(__('payroll.employee')),
                TextEntry::make('type')
                    ->label(__('payroll.compensation_type'))
                    ->formatStateUsing(fn (CompensationType|string $state): string => $state instanceof CompensationType
                        ? $state->label()
                        : $state),
                TextEntry::make('amount')
                    ->label(__('payroll.amount'))
                    ->formatStateUsing(fn (string $state): string => __('payroll.amount_lyd', ['amount' => $state])),
                TextEntry::make('effective_from')->label(__('payroll.effective_from'))->date(),
                TextEntry::make('effective_to')
                    ->label(__('payroll.effective_to'))
                    ->date()
                    ->placeholder(__('payroll.current_rate')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::configure($table)
            ->columns([
                AdminColumns::title('user.name', __('payroll.employee'))
                    ->searchable()
                    ->sortable(),

                AdminColumns::secondary('type', __('payroll.compensation_type'))
                    ->formatStateUsing(fn (CompensationType|string $state): string => $state instanceof CompensationType
                        ? $state->label()
                        : $state),

                AdminColumns::money('amount', __('payroll.amount'), 'payroll.amount_lyd')
                    ->weight('semibold')
                    ->color('warning'),

                AdminColumns::date('effective_from', __('payroll.effective_from'))
                    ->sortable(),

                AdminColumns::date('effective_to', __('payroll.effective_to'))
                    ->placeholder(__('payroll.current_rate')),
            ])
            ->defaultSort('effective_from', 'desc');
    }

    /**
     * "How to read this page" for the rates list (P4-T37): what the history is,
     * the effective-dated rule (a raise adds a rate and never edits one), and what
     * each row shows. Display only.
     */
    public static function listHelpPanel(): Section
    {
        return self::helpPanel('staff-compensation-help', [
            ['title' => __('payroll.help_what_title'), 'lines' => [
                __('payroll.help_comp_what_1'),
                __('payroll.help_comp_what_2'),
                __('payroll.help_comp_what_3'),
            ]],
            ['title' => __('payroll.help_comp_rows_title'), 'lines' => [
                __('payroll.help_comp_row_amount'),
                __('payroll.help_comp_row_dates'),
                __('payroll.help_comp_row_overlap'),
            ]],
            ['title' => __('payroll.help_comp_pay_title'), 'lines' => [
                __('payroll.help_comp_pay_monthly', [
                    'monthly' => PayrollRunType::MonthlySalary->label(),
                    'example_salary' => __('payroll.amount_lyd', ['amount' => '3000.000']),
                    'example_paid' => __('payroll.amount_lyd', ['amount' => '1500.000']),
                ]),
                __('payroll.help_comp_pay_raise'),
                __('payroll.help_comp_pay_hourly', ['instructor' => PayrollRunType::InstructorBatch->label()]),
                __('payroll.help_comp_pay_none'),
                __('payroll.help_comp_pay_draft', ['adjustment' => PayrollRunType::Adjustment->label()]),
            ]],
            ['title' => __('payroll.help_buttons_title'), 'terms' => [
                __('payroll.change_compensation') => __('payroll.help_comp_change'),
            ]],
        ]);
    }

    /** "How to read this page" above the new-rate form (P4-T37). Display only. */
    public static function createHelpPanel(): Section
    {
        return self::helpPanel('staff-compensation-create-help', [
            ['title' => __('payroll.help_comp_create_title'), 'lines' => [
                __('payroll.help_comp_create_1'),
                __('payroll.help_comp_create_2'),
                __('payroll.help_comp_create_3'),
                __('payroll.help_comp_create_4'),
                __('payroll.help_comp_create_type', [
                    'salary' => CompensationType::Salary->label(),
                    'hourly' => CompensationType::Hourly->label(),
                ]),
            ]],
        ]);
    }

    /**
     * @param  list<array{title: string, lines?: list<string>, terms?: array<string, string>}>  $groups
     */
    private static function helpPanel(string $id, array $groups): Section
    {
        return HelpPanel::make($id, __('payroll.help_heading'), 'filament.finance.page-help', ['groups' => $groups]);
    }

    /** @return Builder<StaffCompensation> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffCompensations::route('/'),
            'create' => CreateStaffCompensation::route('/create'),
            'view' => ViewStaffCompensation::route('/{record}'),
        ];
    }
}
