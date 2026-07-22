<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources;

use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ViewCourse;
use App\Domain\Enrollment\Models\Course;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The course catalogue (P1-T08).
 *
 * NO PRICE FIELD, ANYWHERE
 * ------------------------
 * `courses.default_price` exists in the schema so that phase 2 never has to
 * ALTER a table holding production data. It appears in neither the form nor the
 * table, and that is not an oversight to be tidied up later — phase 1 has no
 * financial features of any kind. CourseTest asserts the field's absence from
 * both, so restoring it fails the build rather than quietly shipping a price
 * someone can edit before any of the money rules exist.
 *
 * FULL PAGES, NOT MODAL ACTIONS
 * -----------------------------
 * List, create, view and edit are all real pages. Nothing here has a save hook
 * today, but Filament's modal CreateAction/EditAction persist with a bare
 * create()/update() that never runs a page hook, so a resource built on modals
 * quietly breaks the moment one is added. The list page's create button is a
 * plain link Action for the same reason as StudentResource's: CreateAction keeps
 * a mountable server-side handler even when ->url() is set.
 *
 * NO BULK ACTIONS
 * ---------------
 * Filament authorizes a bulk action once against the *Any policy method and
 * never consults the per-record one. CoursePolicy defines no deleteAny(), so a
 * bulk delete added later fails closed rather than inheriting a rule nobody
 * decided. It would also be the worst possible place for one: a course with
 * batches is refused by the foreign key, and a bulk delete would surface that
 * as a raw database error part-way through a selection.
 */
class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('enrollment.course');
    }

    public static function getPluralModelLabel(): string
    {
        return __('enrollment.courses');
    }

    public static function getNavigationLabel(): string
    {
        return __('enrollment.courses');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('enrollment.course_code'))
                ->required()
                ->maxLength(30)
                // The code is what humans mean by a course, on the phone and in
                // brochures, so a duplicate is a real-world ambiguity rather
                // than merely a broken index.
                ->unique(ignoreRecord: true),

            TextInput::make('name_en')
                ->label(__('enrollment.name_en'))
                ->required()
                ->maxLength(200),

            // Optional: the Arabic catalogue arrives in phase 4, and until then
            // an untranslated course is normal. Course::name() falls back.
            TextInput::make('name_ar')
                ->label(__('enrollment.name_ar'))
                ->maxLength(200),

            TextInput::make('total_hours')
                ->label(__('enrollment.total_hours'))
                ->helperText(__('enrollment.total_hours_course_hint'))
                ->required()
                ->numeric()
                ->integer()
                ->minValue(0)
                // unsignedSmallInteger: 65535 is the column's ceiling, and a
                // larger value would be a silent truncation in strict mode or a
                // raw database error out of it.
                ->maxValue(65535)
                ->default(0),

            Toggle::make('is_active')
                ->label(__('enrollment.is_active'))
                ->helperText(__('enrollment.is_active_course_hint'))
                ->default(true),

            Textarea::make('description_en')
                ->label(__('enrollment.description_en'))
                ->rows(3)
                ->columnSpanFull(),

            Textarea::make('description_ar')
                ->label(__('enrollment.description_ar'))
                ->rows(3)
                ->columnSpanFull(),

            // DELIBERATELY ABSENT: default_price. Phase 2 owns it.
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('enrollment.course_code'))
                    ->searchable()
                    ->sortable(),

                // Shows the localized name but sorts and searches the real
                // columns behind it — Course::name() is a locale-dependent
                // method, not an attribute, so it cannot be ordered on.
                TextColumn::make('name_en')
                    ->label(__('enrollment.course_name'))
                    ->formatStateUsing(fn (Course $record): string => $record->name())
                    ->searchable(['name_en', 'name_ar'])
                    ->sortable(),

                TextColumn::make('total_hours')
                    ->label(__('enrollment.total_hours'))
                    ->numeric()
                    ->sortable(),

                // How many times this course has actually been run. Counted
                // rather than stored: a cached count is a derived value that
                // drifts, and the catalogue list is the only place it is read.
                TextColumn::make('batches_count')
                    ->label(__('enrollment.batches'))
                    ->counts('batches')
                    ->numeric(),

                IconColumn::make('is_active')
                    ->label(__('enrollment.is_active'))
                    ->boolean()
                    ->sortable(),

                // DELIBERATELY ABSENT: default_price. Phase 2 owns it.
            ])
            ->defaultSort('code')
            // Delete belongs on the row, not only on the edit page.
            //
            // EditRecord::authorizeAccess() requires update_course to open the
            // page at all, so an actor holding delete_course WITHOUT
            // update_course could never reach a delete action placed there —
            // the grant would be unreachable, and the two permissions are
            // separate on purpose. From the table it is reachable with view +
            // delete alone.
            //
            // authorize() rather than visible(): visible() is a UX affordance
            // that a crafted Livewire mount ignores, whereas authorize() runs
            // CoursePolicy::delete() against this record on the server.
            ->recordActions([
                DeleteAction::make()
                    ->authorize('delete'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'view' => ViewCourse::route('/{record}'),
            'edit' => EditCourse::route('/{record}/edit'),
        ];
    }
}
