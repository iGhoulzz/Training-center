<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ListStaffCompensations;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ViewStaffCompensation;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
        return $schema->components([
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
        return User::withTrashed()
            ->whereKey($value)
            ->value('name');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('payroll.employee'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('payroll.compensation_type'))
                    ->formatStateUsing(fn (CompensationType|string $state): string => $state instanceof CompensationType
                        ? $state->label()
                        : $state),
                TextColumn::make('amount')
                    ->label(__('payroll.amount'))
                    ->formatStateUsing(fn (string $state): string => __('payroll.amount_lyd', ['amount' => $state])),
                TextColumn::make('effective_from')
                    ->label(__('payroll.effective_from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->label(__('payroll.effective_to'))
                    ->date()
                    ->placeholder(__('payroll.current_rate')),
            ])
            ->defaultSort('effective_from', 'desc');
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
