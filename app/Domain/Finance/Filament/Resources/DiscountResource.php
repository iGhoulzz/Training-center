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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
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
        return $schema->columns(2)->extraAttributes(['class' => 'rounded-[14px] bg-gray-900 p-5 ring-1 ring-hairline [&_.fi-input]:text-base! [&_.fi-select-input]:text-base!'])->components([
            Text::make(__('pricing.discount_definition'))->columnSpanFull(),
            Text::make(__('pricing.immutable_hint'))->columnSpanFull(),
            TextInput::make('name')
                ->label(__('pricing.discount_name'))
                ->required()
                ->maxLength(150)
                ->unique(ignoreRecord: true),

            TextInput::make('percentage')
                ->label(__('pricing.discount_percentage'))
                ->required()
                ->inputMode('decimal')
                ->rules(['numeric', 'decimal:0,2', 'min:0.01', 'max:100'])
                ->suffix(__('pricing.percentage_suffix')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')
                            ->label(__('pricing.discount_name'))
                            ->searchable()
                            ->sortable()->weight('semibold'),

                        TextColumn::make('percentage')
                            ->label(__('pricing.discount_percentage'))
                            ->formatStateUsing(fn (string $state): string => __('pricing.percentage_value', [
                                'percentage' => $state,
                            ]))->color('warning'),
                    ])->space(1),

                    IconColumn::make('is_active')
                        ->label(__('pricing.active'))
                        ->boolean()
                        ->sortable(),
                ])->from('sm')->extraAttributes(['class' => 'px-4.5 py-3.5']),
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
                    // The handler has no refusal branch: a Gate refusal throws past
                    // the notification, so Filament's own success toast is safe here.
                    ->successNotificationTitle(__('pricing.discount_deactivated'))
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
