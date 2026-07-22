<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Intakes of a course (P1-T09).
 *
 * NO PRICE FIELD, ANYWHERE
 * ------------------------
 * `batches.price` exists in the schema so that phase 2 never has to ALTER a
 * table holding production data. It appears in neither the form nor the table,
 * and that is not an oversight to be tidied up later — phase 1 has no financial
 * features of any kind. BatchResourceTest asserts the field's absence from both,
 * so restoring it fails the build rather than quietly shipping a price someone
 * can edit before any of the money rules exist.
 *
 * THE HOURS FIELD IS NULLABLE ON PURPOSE
 * --------------------------------------
 * Leaving total_hours empty means "inherit from the course", and the table shows
 * the inherited figure so nobody has to open the course to read it. The field is
 * therefore NOT ->required() and NOT ->default(0): a zero would look like a
 * decision and would stop inheriting, silently, forever.
 *
 * FULL PAGES, NOT MODAL ACTIONS
 * -----------------------------
 * List, create, view and edit are all real pages. Nothing here has a save hook
 * today, but Filament's modal CreateAction/EditAction persist with a bare
 * create()/update() that never runs a page hook, so a resource built on modals
 * quietly breaks the moment one is added — and P1-T10 adds instructor assignment
 * through an Action. The list page's create button is a plain link Action for
 * the same reason as StudentResource's: CreateAction keeps a mountable
 * server-side handler even when ->url() is set.
 *
 * NO BULK ACTIONS
 * ---------------
 * Filament authorizes a bulk action once against the *Any policy method and
 * never consults the per-record one. BatchPolicy defines no deleteAny(), so a
 * bulk delete added later fails closed rather than inheriting a rule nobody
 * decided — which matters here more than anywhere, because P1-T11 makes a batch
 * with enrolments undeletable and a bulk action could not express that.
 *
 * The generic tag below is load-bearing, not decoration. Filament's Resource is
 * generic over its model and defaults the parameter to Model, so without it
 * getEloquentQuery() is inferred as Builder<Model> and static analysis fails —
 * the first resource here to override that method is the first to need it.
 *
 * The class name is written out in full deliberately: Pint's phpdoc_types fixer
 * lowercases a bare `Resource` into PHP's `resource` pseudo-type, which silently
 * turns the tag into a reference to nothing. A namespaced name is left alone.
 *
 * @extends \Filament\Resources\Resource<Batch>
 */
class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('enrollment.batch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrollment.batches');
    }

    public static function getNavigationLabel(): string
    {
        return __('enrollment.batches');
    }

    /**
     * Eager-load the course on every read.
     *
     * effective_total_hours reaches through the relation, and the table renders
     * it per row — without this the list is a textbook N+1.
     *
     * @return Builder<Batch>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('course');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_id')
                ->label(__('enrollment.course'))
                ->required()
                // Only courses the centre still offers. A retired course keeps
                // its existing batches but should not receive new ones.
                ->options(fn (): array => Course::query()
                    ->active()
                    ->orderBy('code')
                    ->pluck('code', 'id')
                    ->all())
                ->searchable()
                ->preload(),

            TextInput::make('code')
                ->label(__('enrollment.batch_code'))
                ->required()
                ->maxLength(40)
                // The code is quoted at the desk and printed on schedules, so a
                // duplicate is a real-world ambiguity, not just a broken index.
                ->unique(ignoreRecord: true),

            Select::make('status')
                ->label(__('enrollment.status'))
                ->options(fn (): array => collect(BatchStatus::cases())
                    ->mapWithKeys(fn (BatchStatus $case): array => [$case->value => $case->label()])
                    ->all())
                ->default(BatchStatus::Planned->value)
                ->required(),

            DatePicker::make('start_date')
                ->label(__('enrollment.start_date')),

            DatePicker::make('end_date')
                ->label(__('enrollment.end_date'))
                // A schedule that ends before it starts is a data-entry slip,
                // not a state the centre can be in.
                ->afterOrEqual('start_date'),

            TextInput::make('capacity')
                ->label(__('enrollment.capacity'))
                ->helperText(__('enrollment.capacity_hint'))
                ->numeric()
                ->integer()
                ->minValue(0)
                // unsignedSmallInteger: 65535 is the column's ceiling.
                ->maxValue(65535)
                ->default(0),

            // Deliberately optional, with NO default. Empty means inherit from
            // the course; a zero here would look like a decision and would stop
            // inheriting for good.
            TextInput::make('total_hours')
                ->label(__('enrollment.total_hours'))
                ->helperText(__('enrollment.total_hours_batch_hint'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(65535)
                ->placeholder(__('enrollment.inherits_from_course')),

            // DELIBERATELY ABSENT: price. Phase 2 owns it.
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('enrollment.batch_code'))
                    ->searchable()
                    ->sortable(),

                // The course code, sortable and searchable through the relation.
                TextColumn::make('course.code')
                    ->label(__('enrollment.course'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('enrollment.status'))
                    ->badge()
                    ->formatStateUsing(fn (BatchStatus $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label(__('enrollment.start_date'))
                    ->date()
                    ->placeholder(__('enrollment.no_date'))
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label(__('enrollment.end_date'))
                    ->date()
                    ->placeholder(__('enrollment.no_date'))
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label(__('enrollment.capacity'))
                    ->numeric()
                    ->sortable(),

                // The INHERITED figure, not the raw column: a batch with a null
                // total_hours shows its course's hours, which is what anyone
                // reading this list actually wants to know. Not sortable — it is
                // an accessor resolved in PHP, not a column the database can
                // order by.
                TextColumn::make('effective_total_hours')
                    ->label(__('enrollment.total_hours')),

                // DELIBERATELY ABSENT: price. Phase 2 owns it.
            ])
            // Soonest first, and batches with no date yet last: the question
            // this list answers is "what is starting next".
            ->defaultSort('start_date')
            // Delete belongs on the row, not only on the edit page.
            //
            // EditRecord::authorizeAccess() requires update_batch to open the
            // page at all, so an actor holding delete_batch WITHOUT update_batch
            // could never reach a delete action placed there — the grant would
            // be unreachable, and the two permissions are separate on purpose.
            //
            // authorize() rather than visible(): visible() is a UX affordance
            // that a crafted Livewire mount ignores, whereas authorize() runs
            // BatchPolicy::delete() against this record on the server.
            ->recordActions([
                DeleteAction::make()
                    ->authorize('delete'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBatches::route('/'),
            'create' => CreateBatch::route('/create'),
            'view' => ViewBatch::route('/{record}'),
            'edit' => EditBatch::route('/{record}/edit'),
        ];
    }
}
