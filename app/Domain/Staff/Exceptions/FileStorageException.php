<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * A storage operation that reported failure.
 *
 * This exception exists because the 'private' disk is configured with
 * `throw => false`, so Laravel returns `false` from put()/delete() instead of
 * raising. A boolean nobody checks is a silent data-loss bug: an upload that
 * "succeeded" with no bytes behind it, or a deletion that "succeeded" while the
 * document is still on disk.
 *
 * Every call site converts that false into this exception. Storage failures are
 * never swallowed to keep a request looking successful — the one place a throw
 * is the DESIRED outcome is inside PurgeDeletedFileJob, where throwing is what
 * makes Laravel retry.
 */
final class FileStorageException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $disk,
        public readonly string $path,
    ) {
        parent::__construct($message);
    }

    public static function writeFailed(string $disk, string $path): self
    {
        return new self(
            "Failed to write [{$path}] to the [{$disk}] disk.",
            $disk,
            $path,
        );
    }

    public static function deleteFailed(string $disk, string $path): self
    {
        return new self(
            "Failed to delete [{$path}] from the [{$disk}] disk.",
            $disk,
            $path,
        );
    }
}
