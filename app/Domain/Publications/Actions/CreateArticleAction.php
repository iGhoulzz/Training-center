<?php

declare(strict_types=1);

namespace App\Domain\Publications\Actions;

use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Add an article to the library: its details and its PDF, unpublished.
 *
 * Actor first and self-authorizing, like every other request-path Action. The
 * Filament create page is one caller; a console import would be another, and each
 * must be held to the same rules.
 *
 * A NEW ARTICLE IS NEVER PUBLISHED
 * --------------------------------
 * `published_at` is not an input. Publishing is its own act behind its own
 * permission (PublishArticleAction), so holding create_article alone can never
 * put a document in front of the public.
 *
 * STORAGE IDENTITY IS SERVER-OWNED
 * --------------------------------
 * `disk`, `path` and `original_filename` are derived here from the UploadedFile
 * and never read from caller-supplied metadata, for the reason
 * UploadStaffCertificateAction records: a client-chosen path is a path-traversal
 * and overwrite primitive. The stored name is a fresh ULID plus the extension of
 * the one accepted type. The PDF goes on the PRIVATE disk under publications/ and
 * has no URL of its own; the public download route is the only reader (T13).
 *
 * MIME TYPE IS READ FROM THE BYTES
 * --------------------------------
 * `mimetypes:` derives the type from the file's content, so a script renamed to
 * `paper.pdf` fails validation. (`mimes:` would have trusted the extension.)
 *
 * RECEIPT FIRST, FILE SECOND, ROW THIRD
 * -------------------------------------
 * The same write-ahead lifecycle the staff certificates use, through
 * FileLifecycleService::persistNewFile(): a provisional cleanup receipt is
 * committed before bytes are written and cancelled only when the owning
 * transaction commits, so neither a row without a file nor a file without a row
 * survives a partial failure.
 *
 * THE AUDIT TRAIL IS THE MODEL EVENT. The `created` entry is written by
 * RecordsActivity; this Action only names the actor it is attributed to.
 */
final class CreateArticleAction
{
    /** Articles live on the private, non-web-served disk. Spec section 6. */
    public const DISK = 'private';

    public const DIRECTORY = 'publications';

    /** 10 MB, the same ceiling staff certificate uploads have. */
    public const MAX_KILOBYTES = 10240;

    /** The one accepted type. A library of PDFs, not of anything a browser can open. */
    public const MIME_TYPE = 'application/pdf';

    public const EXTENSION = 'pdf';

    /** The longest slug the column holds. */
    public const SLUG_MAX_LENGTH = 150;

    /** Room kept for the "-xxxxxx" suffix a colliding slug gets. */
    private const SLUG_SUFFIX_LENGTH = 7;

    public function __construct(
        private readonly FileLifecycleService $files,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  title_en, title_ar, description_en,
     *                                          description_ar, topic, authors, issued_on — nothing else is read.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws FileStorageException if the disk reports the write failed.
     */
    public function execute(User $actor, array $metadata, UploadedFile $file): Article
    {
        Gate::forUser($actor)->authorize('create', Article::class);

        $attributes = self::validateMetadata($metadata);
        self::validateFile($file);

        $path = self::DIRECTORY.'/'.Str::ulid()->toString().'.'.self::EXTENSION;

        return $this->files->persistNewFile(
            self::DISK,
            $path,
            function () use ($file, $path): void {
                $this->store($file, $path);
            },
            function () use ($actor, $attributes, $file, $path): Article {
                $article = $this->causers->withCauser(
                    $actor,
                    fn (): Article => Article::query()->create([
                        ...$attributes,
                        'slug' => $this->slugFor($attributes['title_en']),
                        'original_filename' => self::displayName($file),
                        'disk' => self::DISK,
                        'path' => $path,
                    ]),
                );

                // The row was inserted without download_count, so the column holds
                // its database default (0) and this instance does not know it yet.
                return $article->refresh();
            },
        );
    }

    /**
     * The rules shared with UpdateArticleAction, so the two cannot drift apart.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{title_en: string, title_ar: ?string, description_en: string, description_ar: ?string, topic: string, authors: string, issued_on: string}
     *
     * @throws ValidationException
     */
    public static function validateMetadata(array $metadata): array
    {
        $trimmed = [];

        foreach (['title_en', 'title_ar', 'description_en', 'description_ar', 'topic', 'authors', 'issued_on'] as $key) {
            $value = $metadata[$key] ?? null;
            $trimmed[$key] = is_string($value) ? trim($value) : $value;
        }

        /** @var array{title_en: string, title_ar: ?string, description_en: string, description_ar: ?string, topic: string, authors: string, issued_on: string} $validated */
        $validated = Validator::make($trimmed, [
            'title_en' => ['required', 'string', 'max:200'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'description_en' => ['required', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'topic' => ['required', 'string', 'max:100'],
            'authors' => ['required', 'string', 'max:255'],
            // A document cannot have been issued tomorrow. The centre's own
            // calendar, not UTC's, decides what "today" is.
            'issued_on' => ['required', 'date', 'before_or_equal:'.CentreCalendar::localise(now())->toDateString()],
        ], [], self::attributeNames())->validate();

        // A blank Arabic field is "not translated", which readers answer with the
        // English text. Storing '' would read as a translation that is empty.
        foreach (['title_ar', 'description_ar'] as $key) {
            if (! isset($validated[$key]) || $validated[$key] === '') {
                $validated[$key] = null;
            }
        }

        return $validated;
    }

    /**
     * @throws ValidationException
     */
    public static function validateFile(UploadedFile $file): void
    {
        Validator::make(['file' => $file], [
            'file' => [
                'required',
                'file',
                // Content-derived, not extension-derived. See the class docblock.
                'mimetypes:'.self::MIME_TYPE,
                'max:'.self::MAX_KILOBYTES,
            ],
        ], [], self::attributeNames())->validate();
    }

    /**
     * The uploader's own name for the file, kept for the download header only.
     *
     * Reduced to its last path segment and length-capped before storage. It is
     * never joined onto a directory, but a value that cannot contain a separator
     * cannot become one if a later caller forgets that.
     */
    public static function displayName(UploadedFile $file): string
    {
        $segments = preg_split('#[\\\\/]+#', $file->getClientOriginalName()) ?: [];
        $name = (string) (array_pop($segments) ?? '');

        return Str::limit(trim($name) === '' ? 'article.'.self::EXTENSION : $name, 255, '');
    }

    /**
     * Field names as a person reads them, so a refusal names "Title (English)"
     * and not "title_en".
     *
     * @return array<string, string>
     */
    public static function attributeNames(): array
    {
        return [
            'title_en' => __('publications.title_en'),
            'title_ar' => __('publications.title_ar'),
            'slug' => __('publications.slug'),
            'description_en' => __('publications.description_en'),
            'description_ar' => __('publications.description_ar'),
            'topic' => __('publications.topic'),
            'authors' => __('publications.authors'),
            'issued_on' => __('publications.issued_on'),
            'file' => __('publications.pdf_file'),
        ];
    }

    /**
     * @throws FileStorageException
     */
    private function store(UploadedFile $file, string $path): void
    {
        $storedPath = Storage::disk(self::DISK)->putFileAs(
            self::DIRECTORY,
            $file,
            basename($path),
        );

        // The private disk is configured with throw => false, so a failed write
        // returns false. Returning "success" here would create a row pointing at
        // bytes that were never stored.
        if ($storedPath === false || $storedPath !== $path) {
            throw FileStorageException::writeFailed(self::DISK, $path);
        }
    }

    /**
     * A unique slug from the English title.
     *
     * The plain slug when it is free, otherwise the slug with a short random
     * suffix. Computed inside the insert's transaction, as close to the write as
     * possible; the unique index is the backstop for two creations that still race
     * to the same value.
     */
    private function slugFor(string $title): string
    {
        $base = trim(Str::limit(Str::slug($title), self::SLUG_MAX_LENGTH - self::SLUG_SUFFIX_LENGTH, ''), '-');

        if ($base === '') {
            $base = 'article';
        }

        if (! Article::query()->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $candidate = $base.'-'.Str::lower(Str::random(6));
        } while (Article::query()->where('slug', $candidate)->exists());

        return $candidate;
    }
}
