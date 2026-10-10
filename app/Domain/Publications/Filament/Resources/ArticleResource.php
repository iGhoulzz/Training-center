<?php

declare(strict_types=1);

namespace App\Domain\Publications\Filament\Resources;

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Actions\PublishArticleAction;
use App\Domain\Publications\Actions\UnpublishArticleAction;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Models\User;
use App\Support\CentreCalendar;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The public library, as staff manage it: list, add, edit, publish, unpublish.
 *
 * NO DELETE, ANYWHERE
 * -------------------
 * A withdrawn article is unpublished, and its row and file stay. This resource
 * registers no DeleteAction, no bulk action and no force-delete or restore, and
 * ArticlePolicy refuses all of them for every actor, super admin included. The
 * table's `toolbarActions([])` and the record actions below are the whole
 * surface; ArticleResourceTest asserts through Livewire that none of them can be
 * reached, so the claim does not rest on this paragraph.
 *
 * EVERY WRITE ROUTES THROUGH AN ACTION
 * ------------------------------------
 * The create and edit pages replace Filament's generic persistence with
 * CreateArticleAction and UpdateArticleAction, and publish and unpublish call
 * their Actions. There is no bare `$record->update()` or `Article::create()`
 * here: a plain create would let the client choose disk, path and original
 * filename, and a plain update would leave a replaced PDF on disk for ever.
 *
 * disk, path and original_filename ARE NOT FORM FIELDS, and neither is
 * published_at or download_count. The Actions derive the first three from the
 * UploadedFile; publication is its own act behind its own permission; the counter
 * belongs to the anonymous download route alone.
 *
 * ACTIONS ARE AUTHORIZED, NOT MERELY HIDDEN
 * -----------------------------------------
 * `->authorize()` calls ArticlePolicy, so an actor without the permission cannot
 * mount publish or unpublish at all. `->visible()` only hides the one that cannot
 * apply to the row's current state, and the Actions refuse that case themselves.
 *
 * The class name is written out in full in the `@extends` tag deliberately —
 * Pint's phpdoc_types fixer lowercases a bare `Resource` into PHP's `resource`
 * pseudo-type, which silently turns the tag into a reference to nothing.
 *
 * @extends \Filament\Resources\Resource<Article>
 */
class ArticleResource extends Resource
{
    /**
     * How many existing topics the form suggests, most-used first.
     *
     * Topic is free text, so its distinct values grow with the library. Every
     * create and edit form sends its suggestions to Livewire, so the list is
     * bounded in SQL, before any row is hydrated, not trimmed afterwards. That
     * is the unbounded option list P3.5-T03 removed elsewhere, not reintroduced
     * here.
     */
    public const TOPIC_SUGGESTION_LIMIT = 25;

    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'title_en';

    public static function getModelLabel(): string
    {
        return __('publications.article');
    }

    public static function getPluralModelLabel(): string
    {
        return __('publications.articles');
    }

    public static function getNavigationLabel(): string
    {
        return __('publications.articles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title_en')
                ->label(__('publications.title_en'))
                ->required()
                ->maxLength(200),

            TextInput::make('title_ar')
                ->label(__('publications.title_ar'))
                ->helperText(__('publications.title_ar_help'))
                ->maxLength(200)
                ->extraInputAttributes(['dir' => 'rtl']),

            // Generated from the English title on creation, so there is nothing
            // to type there. Once the article is published the field is disabled
            // and therefore not sent, which the Action reads as "leave it".
            TextInput::make('slug')
                ->label(__('publications.slug'))
                ->helperText(__('publications.slug_help'))
                ->hiddenOn('create')
                ->required()
                ->maxLength(CreateArticleAction::SLUG_MAX_LENGTH)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Article $record): bool => $record?->isPublished() ?? false),

            Textarea::make('description_en')
                ->label(__('publications.description_en'))
                ->required()
                ->maxLength(5000)
                ->rows(4),

            Textarea::make('description_ar')
                ->label(__('publications.description_ar'))
                ->helperText(__('publications.description_ar_help'))
                ->maxLength(5000)
                ->rows(4)
                ->extraInputAttributes(['dir' => 'rtl']),

            TextInput::make('topic')
                ->label(__('publications.topic'))
                ->helperText(__('publications.topic_help'))
                ->required()
                ->maxLength(100)
                // Offered so that "Safety" is typed the same way twice; a free
                // text field alone would grow "Safety" and "safety " as two chips.
                // The most-used topics only, bounded in SQL: see TOPIC_SUGGESTION_LIMIT.
                ->datalist(fn (): array => Article::query()
                    ->select('topic')
                    ->groupBy('topic')
                    ->orderByRaw('COUNT(*) DESC')
                    ->orderBy('topic')
                    ->limit(self::TOPIC_SUGGESTION_LIMIT)
                    ->pluck('topic')
                    ->all()),

            TextInput::make('authors')
                ->label(__('publications.authors'))
                ->required()
                ->maxLength(255),

            DatePicker::make('issued_on')
                ->label(__('publications.issued_on'))
                ->required()
                ->maxDate(fn () => CentreCalendar::localise(now())),

            // storeFiles(false): the upload stays a Livewire temporary file and
            // the Action owns writing it to the private disk under a generated
            // name. Without this Filament would store it itself, choosing the
            // path — the exact thing the Action must control.
            FileUpload::make('pdf_file')
                ->label(fn (string $operation): string => $operation === 'create'
                    ? __('publications.pdf_file')
                    : __('publications.replacement_pdf_file'))
                ->helperText(fn (string $operation): string => $operation === 'create'
                    ? __('publications.pdf_file_help', ['megabytes' => intdiv(CreateArticleAction::MAX_KILOBYTES, 1024)])
                    : __('publications.replacement_pdf_file_help', ['megabytes' => intdiv(CreateArticleAction::MAX_KILOBYTES, 1024)]))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->visibleOn(['create', 'edit'])
                ->storeFiles(false)
                ->acceptedFileTypes([CreateArticleAction::MIME_TYPE])
                ->maxSize(CreateArticleAction::MAX_KILOBYTES),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title_en')
                    ->label(__('publications.title_en'))
                    ->searchable()
                    ->sortable()
                    ->limit(60),

                TextColumn::make('topic')
                    ->label(__('publications.topic'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('authors')
                    ->label(__('publications.authors'))
                    ->searchable()
                    ->limit(40),

                TextColumn::make('issued_on')
                    ->label(__('publications.issued_on'))
                    ->date()
                    ->sortable(),

                IconColumn::make('is_published')
                    ->label(__('publications.published'))
                    ->state(fn (Article $record): bool => $record->isPublished())
                    ->boolean(),

                TextColumn::make('published_at')
                    ->label(__('publications.published_at'))
                    ->dateTime()
                    ->placeholder(__('publications.unpublished'))
                    ->sortable(),

                TextColumn::make('download_count')
                    ->label(__('publications.download_count'))
                    ->tooltip(__('publications.download_count_help'))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('published')
                    ->label(__('publications.status'))
                    ->placeholder(__('publications.all_articles'))
                    ->trueLabel(__('publications.published'))
                    ->falseLabel(__('publications.unpublished'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('published_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('published_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('publications.view_article'))
                    ->icon(Heroicon::OutlinedEye)
                    ->authorize('view')
                    ->url(fn (Article $record): string => self::getUrl('view', ['record' => $record])),

                Action::make('edit')
                    ->label(__('publications.edit_article'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->url(fn (Article $record): string => self::getUrl('edit', ['record' => $record])),

                self::publishAction(),
                self::unpublishAction(),
            ])
            // No bulk actions of any kind, and no DeleteAction above. See the
            // class docblock: the library has no delete path.
            ->toolbarActions([]);
    }

    /**
     * Make an article visible to readers. Hidden once it is published — a UX
     * courtesy, not the guard: PublishArticleAction refuses an already-published
     * article whatever the panel shows.
     */
    public static function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('publications.publish'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('publications.publish_modal_heading'))
            ->modalDescription(__('publications.publish_modal_description'))
            ->authorize('publish')
            ->visible(fn (Article $record): bool => ! $record->isPublished())
            ->successNotificationTitle(__('publications.published_successfully'))
            ->action(function (Article $record): void {
                self::attempt(fn (): Article => app(PublishArticleAction::class)->execute(self::actor(), $record));
            });
    }

    /**
     * Withdraw an article from readers. Hidden while it is not published, on the
     * same terms as publishAction().
     */
    public static function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label(__('publications.unpublish'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('publications.unpublish_modal_heading'))
            ->modalDescription(__('publications.unpublish_modal_description'))
            ->authorize('unpublish')
            ->visible(fn (Article $record): bool => $record->isPublished())
            ->successNotificationTitle(__('publications.unpublished_successfully'))
            ->action(function (Article $record): void {
                self::attempt(fn (): Article => app(UnpublishArticleAction::class)->execute(self::actor(), $record));
            });
    }

    /**
     * Run a write and turn the three outcomes the panel can explain into a
     * notification, a Halt and a rollback.
     *
     * Only those three are caught — an authorization denial, a refusal with a
     * message the Action wrote, and a storage failure the disk reported. Anything
     * else propagates; a real fault must not be dressed up as a notification.
     *
     * The storage failure is expected in the same sense as the other two: the
     * private disk is configured with throw => false, so the Actions turn a false
     * return into FileStorageException rather than pretending the write succeeded.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     *
     * @throws Halt
     */
    public static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (AuthorizationException) {
            self::refuse(__('publications.refused_unauthorized'));
        } catch (ValidationException $exception) {
            self::refuse($exception->validator->errors()->first() ?: __('publications.refused_invalid'));
        } catch (FileStorageException) {
            self::refuse(__('publications.storage_unavailable'));
        }
    }

    /**
     * The one uploaded PDF in a form submission, or null when none was sent.
     *
     * A FileUpload holds an array of uploads keyed by a hash; a single upload is
     * its one element. storeFiles(false) keeps it a TemporaryUploadedFile (an
     * UploadedFile subclass), which is exactly what the Actions validate and store.
     *
     * @param  array<string, mixed>  $data
     */
    public static function uploadedFileFrom(array $data): ?UploadedFile
    {
        $file = $data['pdf_file'] ?? null;

        if (is_array($file)) {
            $file = reset($file);
        }

        return $file instanceof UploadedFile ? $file : null;
    }

    public static function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    private static function refuse(string $title): never
    {
        Notification::make()
            ->title($title)
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'view' => ViewArticle::route('/{record}'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
