<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
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
 * Set or replace a staff member's profile photo.
 *
 * WHERE PHOTOS LIVE — the single source of truth
 * ----------------------------------------------
 * staff_profiles has no `disk` column, only a path, so the disk has to be stated
 * somewhere. It is stated HERE, in the Action that writes the file, and the
 * Actions that delete one read it from this constant rather than repeating the
 * literal. Photos share the private disk with certificates: spec section 6 says
 * uploads never go to a web-served disk, and a staff photograph is personal data
 * like any other.
 *
 * REPLACE MEANS COMMIT FIRST, THEN DESTROY
 * ----------------------------------------
 * The new file is written, the new path is committed, and only then is the old
 * file scheduled for deletion. Destroying the old file first would leave the
 * profile pointing at nothing if the save then failed.
 *
 * Amending a photo is amending the profile, so this authorizes `update` on
 * StaffProfile — not `delete`, which is the right to remove the whole record.
 */
final class UpdateStaffPhotoAction
{
    /** Photos live on the private, non-web-served disk. Spec section 6. */
    public const DISK = 'private';

    public const DIRECTORY = 'staff-photos';

    /** 2 MB. A portrait, not a print master. */
    public const MAX_KILOBYTES = 2048;

    /**
     * @var array<int, string>
     */
    public const ACCEPTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** Below this and the avatar is unusable; above it and someone uploaded a poster. */
    public const MIN_DIMENSION = 64;

    public const MAX_DIMENSION = 4000;

    /**
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly FileLifecycleService $files,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws FileStorageException
     */
    public function execute(User $actor, StaffProfile $profile, UploadedFile $file): void
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $this->validateFile($file);

        $previousPath = $profile->profile_photo_path;
        $path = $this->store($file);

        try {
            $pendingIds = DB::transaction(function () use ($profile, $path, $previousPath): array {
                // The receipt for the file being replaced is written in the same
                // transaction as the new path, so "the profile points here now"
                // and "destroy what it pointed at before" commit together.
                $ids = is_string($previousPath) && $previousPath !== ''
                    ? $this->files->record([['disk' => self::DISK, 'path' => $previousPath]])
                    : [];

                $profile->update(['profile_photo_path' => $path]);

                return $ids;
            });
        } catch (Throwable $exception) {
            // The new path never committed, so nothing references these bytes.
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }

        $this->files->dispatchPurges($pendingIds);
    }

    private function validateFile(UploadedFile $file): void
    {
        Validator::make(['photo' => $file], [
            'photo' => [
                'required',
                'file',
                // Content-derived: `image` and `mimetypes:` both read the bytes,
                // where `mimes:` would have trusted the uploaded extension.
                'image',
                'mimetypes:'.implode(',', self::ACCEPTED_MIME_TYPES),
                'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width='.self::MIN_DIMENSION
                    .',min_height='.self::MIN_DIMENSION
                    .',max_width='.self::MAX_DIMENSION
                    .',max_height='.self::MAX_DIMENSION,
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

        if ($path === false) {
            throw FileStorageException::writeFailed(self::DISK, self::DIRECTORY.'/'.$name);
        }

        return $path;
    }

    private function extensionFor(UploadedFile $file): string
    {
        return self::EXTENSIONS[$file->getMimeType()]
            ?? throw FileStorageException::writeFailed(self::DISK, self::DIRECTORY);
    }
}
