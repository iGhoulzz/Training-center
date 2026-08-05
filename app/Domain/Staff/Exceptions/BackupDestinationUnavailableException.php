<?php

declare(strict_types=1);

namespace App\Domain\Staff\Exceptions;

use RuntimeException;

/**
 * The backup destination is not usable right now — most often, the drive is out.
 *
 * Distinct from the RuntimeException BackupConfiguration throws for a broken
 * .env, because the two want opposite reactions: a misconfigured install needs a
 * deploy, while this one usually needs somebody to walk over and plug the drive
 * back in. Naming it also lets the console boundary catch exactly this and leave
 * every other failure alone.
 */
class BackupDestinationUnavailableException extends RuntimeException {}
