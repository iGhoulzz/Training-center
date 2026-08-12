<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Actions\DeactivateDiscountAction;
use App\Domain\Finance\Actions\DeleteDiscountAction;
use App\Domain\Finance\Exceptions\DiscountInUseException;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\CreateDiscount;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ListDiscounts;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ViewDiscount;
use App\Domain\Finance\Models\Discount;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Reusable, immutable discount definitions managed under `manage_pricing`. */
final class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPercentBadge;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('pricing.discount');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pricing.discounts');
    }

    public static function getNavigationLabel(): string
    {
        return __('pricing.discounts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('pricing.discount_name'))
                ->required()
                ->maxLength(150)
                ->unique(ignoreRecord: true),

            TextInput::make('percentage')
                ->label(__('pricing.discount_percentage'))
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->maxValue(100)
                ->rules(['decimal:0,2'])
                ->suffix(__('pricing.percentage_suffix')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('pricing.discount_name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('percentage')
                    ->label(__('pricing.discount_percentage'))
                    ->formatStateUsing(fn (string $state): string => __('pricing.percentage_value', [
                        'percentage' => $state,
                    ])),

                IconColumn::make('is_active')
                    ->label(__('pricing.active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('view')
                    ->label(__('pricing.view_discount'))
                    ->icon(Heroicon::OutlinedEye)
                    ->authorize('view')
                    ->url(fn (Discount $record): string => self::getUrl('view', ['record' => $record])),

                Action::make('deactivate')
                    ->label(__('pricing.deactivate_discount'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize('deactivate')
                    ->visible(fn (Discount $record): bool => $record->is_active)
                    ->action(function (Discount $record): void {
                        app(DeactivateDiscountAction::class)->execute(self::actor(), $record);
                    }),

                DeleteAction::make()
                    ->authorize('delete')
                    ->action(function (Discount $record, DeleteAction $action): void {
                        try {
                            app(DeleteDiscountAction::class)->execute(self::actor(), $record);
                        } catch (DiscountInUseException) {
                            Notification::make()
                                ->title(__('pricing.discount_in_use'))
                                ->body(__('pricing.discount_in_use_hint'))
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscounts::route('/'),
            'create' => CreateDiscount::route('/create'),
            'view' => ViewDiscount::route('/{record}'),
        ];
    }

    private static function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
