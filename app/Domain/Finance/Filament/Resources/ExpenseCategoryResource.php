<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Actions\CreateExpenseCategoryAction;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\CreateExpenseCategory;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\EditExpenseCategory;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Domain\Finance\Models\ExpenseCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The list of headings an expense is recorded against, maintained by admins.
 *
 * Adding a category like "Insurance" needs no developer. A category is
 * DEACTIVATED, never deleted: the edit page's `is_active` switch is the whole
 * lifecycle.
 *
 * NO DELETE, ANYWHERE
 * -------------------
 * This resource registers no DeleteAction, no bulk action and no force-delete or
 * restore, and `ExpenseCategoryPolicy` refuses all of them for every actor, super
 * admin included. `expenses.expense_category_id` restricts as a backstop.
 * `ExpenseResourceTest` asserts through Livewire that none of them can be reached,
 * so the claim does not rest on this paragraph.
 *
 * EVERY WRITE ROUTES THROUGH AN ACTION
 * ------------------------------------
 * The create and edit pages replace Filament's generic persistence with
 * `CreateExpenseCategoryAction` and `UpdateExpenseCategoryAction`; there is no
 * bare `$record->update()` here. Full pages rather than modal actions, because
 * Filament's modal CreateAction and EditAction persist with a bare create() or
 * update() that never reaches a page hook, and `->url()` does not disable that
 * handler (docs/ENGINEERING.md). The row and header links are plain Actions with
 * a URL, so there is no server handler to mount.
 *
 * The class name is written out in full in the `@extends` tag deliberately —
 * Pint's phpdoc_types fixer lowercases a bare `Resource` into PHP's `resource`
 * pseudo-type, which silently turns the tag into a reference to nothing.
 *
 * @extends \Filament\Resources\Resource<ExpenseCategory>
 */
final class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getModelLabel(): string
    {
        return __('expenses.category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses.categories');
    }

    public static function getNavigationLabel(): string
    {
        return __('expenses.categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => '[&_.fi-input]:text-base! [&_.fi-select-input]:text-base! [&_.fi-select-input-btn]:text-base! [&_textarea]:text-base!'])->components([
            Section::make(__('expenses.category_details'))->description(__('expenses.category_lifecycle_hint'))->columns(2)->schema([
                TextInput::make('name_en')
                    ->label(__('expenses.category_name_en'))
                    ->required()
                    ->maxLength(CreateExpenseCategoryAction::NAME_MAX_LENGTH)
                    ->unique(ignoreRecord: true),

                TextInput::make('name_ar')
                    ->label(__('expenses.category_name_ar'))
                    ->maxLength(CreateExpenseCategoryAction::NAME_MAX_LENGTH),

                // A new category is always active, so the switch is an edit-page
                // control: deactivating is an update (spec section 5).
                Toggle::make('is_active')
                    ->label(__('expenses.category_is_active'))
                    ->helperText(__('expenses.category_is_active_hint'))
                    ->default(true)
                    ->hiddenOn('create'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name_en')
                            ->label(__('expenses.category_name_en'))
                            ->searchable()
                            ->sortable()->weight('semibold'),

                        TextColumn::make('name_ar')
                            ->label(__('expenses.category_name_ar'))
                            ->placeholder(__('expenses.not_translated'))
                            ->searchable()->size('xs')->color('gray'),
                    ])->space(1),

                    IconColumn::make('is_active')
                        ->label(__('expenses.category_is_active'))
                        ->boolean()
                        ->sortable(),
                ])->from('sm')->extraAttributes(['class' => 'px-4.5 py-3.5']),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('expenses.category_is_active'))
                    ->placeholder(__('expenses.all_categories'))
                    ->trueLabel(__('expenses.category_active_only'))
                    ->falseLabel(__('expenses.category_inactive_only')),
            ])
            ->defaultSort('name_en')
            ->recordActions([
                // A plain link Action, not an EditAction: the edit page owns the
                // write, through UpdateExpenseCategoryAction.
                Action::make('edit')
                    ->label(__('expenses.edit_category'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->url(fn (ExpenseCategory $record): string => self::getUrl('edit', ['record' => $record])),
            ])
            // No bulk actions of any kind, and no DeleteAction above. See the class
            // docblock: a category is deactivated, never deleted.
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
