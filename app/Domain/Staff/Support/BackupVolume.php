<?php

declare(strict_types=1);

namespace App\Domain\Staff\Support;

/**
 * Facts about the filesystem a backup path actually sits on.
 *
 * WHY THIS IS A CLASS AND NOT THREE INLINE CALLS
 * ----------------------------------------------
 * The question "is the removable drive really mounted" can only be answered by
 * the filesystem, and a test cannot mount a USB stick. Resolving it through an
 * injectable object lets the suite substitute the answer and still exercise the
 * decision — which matters more than usual here, because P1-T17's first attempt
 * shipped a positive control that certified the DANGEROUS case as safe: it used
 * sys_get_temp_dir(), which on the machine it ran on is the same device as the
 * project.
 *
 * DEVICE IDENTITY IS THE LOAD-BEARING FACT. `/mnt/backups` is an ordinary,
 * existing, writable directory whether or not anything is mounted on it, so
 * checking existence and writability proves nothing about the drive. When the
 * drive is absent that path resolves to the ROOT filesystem — the same device
 * the application lives on — and comparing device ids is what tells the two
 * apart.
 */
class BackupVolume
{
    /**
     * The filesystem device the path lives on, or null if it cannot be read.
     *
     * stat()'s `dev` is the device number on Unix and the drive on Windows, so
     * the comparison means the same thing on both: a different value is a
     * different filesystem.
     */
    public function deviceIdFor(string $path): ?int
    {
        if (! file_exists($path)) {
            return null;
        }

        // stat() always includes 'dev' when it succeeds, so only the false
        // return needs handling — PHPStan is right that checking the key is
        // dead code.
        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        return (int) $stat['dev'];
    }

    /** Does this path exist as a directory? */
    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    /** Can the application write into it? */
    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    /** Is the operator's volume marker present on the drive? */
    public function hasMarker(string $path, string $marker): bool
    {
        return is_file(rtrim($path, '/\\').DIRECTORY_SEPARATOR.$marker);
    }
}
