<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Store an uploaded credential and record it against a staff profile.
 *
 * Actor first and self-authorizing, like every other request-path Action here.
 * The Filament relation manager is one caller; a console import or an API would
 * be another, and each must be held to the same rules.
 *
 * STORAGE IDENTITY IS SERVER-OWNED
 * --------------------------------
 * `disk`, `path`, and `original_filename` are derived here from the UploadedFile
 * and never read from caller-supplied metadata. A client-supplied `path` is a
 * path-traversal and overwrite primitive: "../../../.env" would let an uploader
 * choose which file on the server to replace, and a later download would happily
 * serve whatever the row pointed at. `original_filename` is kept as DATA — it
 * decides the name in the Content-Disposition header and nothing else. It never
 * builds a filesystem path.
 *
 * The stored name is a fresh ULID plus an extension chosen from the VALIDATED
 * mime type, so neither the name nor the extension on disk is under the
 * uploader's control.
 *
 * MIME TYPE IS READ FROM THE BYTES
 * --------------------------------
 * The `mimetypes:` rule calls UploadedFile::getMimeType(), which Symfony derives
 * from the file's content, not from the client's declared type or the extension
 * in its name. Renaming a PHP script to `certificate.pdf` therefore fails
 * validation. (`mimes:` would have trusted the extension; it is not used.)
 *
 * FILE FIRST, ROW SECOND
 * ----------------------
 * A row without a file is a broken download; a file without a row is personal
 * data nobody can find or delete. The file is written first and removed again if
 * the row write fails, so neither survives a partial failure.
 */
final class UploadStaffCertificateAction
{
    /** Certificates live on the private, non-web-served disk. Spec section 6. */
    public const DISK = 'private';

    public const DIRECTORY = 'staff-certificates';

    /** 10 MB. A scan of a diploma; anything larger is a mistake or an attack. */
    public const MAX_KILOBYTES = 10240;

    /**
     * The real mime types a credential may have.
     *
     * @var array<int, string>
     */
    public const ACCEPTED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * The extension written to disk for each accepted type.
     *
     * Derived from the validated mime type rather than the uploaded name: the
     * uploader never chooses what this file is called or what it ends in.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param  array<string, mixed>  $metadata  title, issued_on, expires_on — nothing else is read.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws FileStorageException if the disk reports the write failed.
     */
    public function execute(User $actor, StaffProfile $profile, UploadedFile $file, array $metadata): StaffCertificate
    {
        Gate::forUser($actor)->authorize('create', StaffCertificate::class);

        $attributes = $this->validateMetadata($metadata);
        $this->validateFile($file);

        $path = $this->store($file);

        try {
            return DB::transaction(fn (): StaffCertificate => StaffCertificate::query()->create([
                'staff_profile_id' => $profile->getKey(),
                'title' => $attributes['title'],
                'issued_on' => $attributes['issued_on'],
                'expires_on' => $attributes['expires_on'],
                'original_filename' => $this->displayName($file),
                'disk' => self::DISK,
                'path' => $path,
            ]));
        } catch (Throwable $exception) {
            // No row was committed, so nothing points at these bytes and no
            // pending_file_deletions receipt is needed — deleting immediately is
            // safe here precisely because there is no row to roll back to.
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{title: string, issued_on: ?string, expires_on: ?string}
     */
    private function validateMetadata(array $metadata): array
    {
        /** @var array{title: string, issued_on?: ?string, expires_on?: ?string} $validated */
        $validated = Validator::make($metadata, [
            'title' => ['required', 'string', 'max:200'],
            'issued_on' => ['nullable', 'date'],
            // A credential cannot lapse before it was issued. Null means it
            // never expires — see StaffCertificate::scopeExpired().
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
        ])->validate();

        return [
            'title' => $validated['title'],
            'issued_on' => $validated['issued_on'] ?? null,
            'expires_on' => $validated['expires_on'] ?? null,
        ];
    }

    private function validateFile(UploadedFile $file): void
    {
        Validator::make(['file' => $file], [
            'file' => [
                'required',
                'file',
                // Content-derived, not extension-derived. See the class docblock.
                'mimetypes:'.implode(',', self::ACCEPTED_MIME_TYPES),
                'max:'.self::MAX_KILOBYTES,
            ],
        ])->validate();
    }

    /**
     * @throws FileStorageException
     */
    private function store(UploadedFile $file): string
    {
        $name = Str::ulid()->toString().'.'.$this->extensionFor($file);

        $path = Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, $name);

        // The private disk is configured with throw => false, so a failed write
        // returns false. Returning "success" here would create a row pointing at
        // bytes that were never stored.
        if ($path === false) {
            throw FileStorageException::writeFailed(self::DISK, self::DIRECTORY.'/'.$name);
        }

        return $path;
    }

    private function extensionFor(UploadedFile $file): string
    {
        // validateFile() has already restricted the type to the accepted list,
        // so the lookup cannot miss. The fallback exists only so a future
        // accepted type added without an extension entry fails loudly at the
        // validation boundary rather than writing an extensionless file.
        return self::EXTENSIONS[$file->getMimeType()]
            ?? throw FileStorageException::writeFailed(self::DISK, self::DIRECTORY);
    }

    /**
     * The uploader's own name for the file, kept for the download header only.
     *
     * Reduced to its last path segment and length-capped before storage. It is
     * never joined onto a directory, but a value that cannot contain a
     * separator cannot become one if a later caller forgets that.
     */
    private function displayName(UploadedFile $file): string
    {
        $segments = preg_split('#[\\\\/]+#', $file->getClientOriginalName()) ?: [];
        $name = (string) (array_pop($segments) ?? '');

        return Str::limit(trim($name) === '' ? 'certificate' : $name, 255, '');
    }
}
