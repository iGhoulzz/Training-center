<?php

declare(strict_types=1);

namespace App\Domain\Staff\Enums;

enum PathKind: string
{
    case File = 'file';
    case Directory = 'directory';
}
