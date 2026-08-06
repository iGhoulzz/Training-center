<?php

declare(strict_types=1);

namespace Tooling;

use RuntimeException;

/**
 * The project-owned bytes around Boost's generated guideline block.
 *
 * Boost owns the marked block. The project owns everything before and after it,
 * and a manual update must not normalize or otherwise rewrite those bytes.
 */
final class BoostGuidelines
{
    private const OPEN = '<laravel-boost-guidelines>';

    private const CLOSE = '</laravel-boost-guidelines>';

    /**
     * @return array{before: string, block: string, after: string}
     */
    public static function split(string $contents, string $file): array
    {
        if (substr_count($contents, self::OPEN) !== 1 || substr_count($contents, self::CLOSE) !== 1) {
            throw new RuntimeException("{$file} must contain exactly one Boost marker pair.");
        }

        $start = strpos($contents, self::OPEN);
        $close = strpos($contents, self::CLOSE);

        if ($start === false || $close === false || $close < $start) {
            throw new RuntimeException("{$file} has an invalid Boost marker order.");
        }

        $end = $close + strlen(self::CLOSE);

        return [
            'before' => substr($contents, 0, $start),
            'block' => substr($contents, $start, $end - $start),
            'after' => substr($contents, $end),
        ];
    }

    public static function withUpdatedBlock(string $original, string $updated, string $file): string
    {
        $owned = self::split($original, $file);
        $generated = self::split($updated, $file);

        return $owned['before'].$generated['block'].$owned['after'];
    }
}
