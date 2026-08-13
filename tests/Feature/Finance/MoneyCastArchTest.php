<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/** @return array<int, string> */
function financeSourceFiles(): array
{
    return collect(File::allFiles(app_path('Domain/Finance')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => (string) $file->getRealPath())
        ->values()
        ->all();
}

/** @param  array<int, array{0: int, 1: string, 2?: int}|string>  $tokens */
function nextFinanceCodeToken(array $tokens, int $offset): ?int
{
    for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $index;
    }

    return null;
}

/** @param  array{0: int, 1: string, 2?: int}|string  $token */
function financeTokenText(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}

it('forbids PHP floating-point casts throughout the Finance domain', function () {
    $violations = [];

    foreach (financeSourceFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $isFloatCast = $token[0] === T_DOUBLE_CAST;
            $isFloatFunction = $token[0] === T_STRING
                && in_array(strtolower($token[1]), ['floatval', 'doubleval'], true);

            if ($isFloatCast || $isFloatFunction) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.$token[2];
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Finance values must not cross a PHP floating-point boundary: '.implode(', ', $violations),
    );
});

it('forbids Filament numeric state casts on financial decimal fields', function () {
    $violations = [];

    foreach (financeSourceFiles() as $path) {
        $tokens = token_get_all((string) file_get_contents($path));

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'TextInput') {
                continue;
            }

            $doubleColon = nextFinanceCodeToken($tokens, $index + 1);
            $make = $doubleColon === null ? null : nextFinanceCodeToken($tokens, $doubleColon + 1);
            $openingParenthesis = $make === null ? null : nextFinanceCodeToken($tokens, $make + 1);
            $fieldToken = $openingParenthesis === null ? null : nextFinanceCodeToken($tokens, $openingParenthesis + 1);

            if (
                $doubleColon === null || financeTokenText($tokens[$doubleColon]) !== '::'
                || $make === null || ! is_array($tokens[$make]) || $tokens[$make][1] !== 'make'
                || $openingParenthesis === null || financeTokenText($tokens[$openingParenthesis]) !== '('
                || $fieldToken === null || ! is_array($tokens[$fieldToken])
                || $tokens[$fieldToken][0] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                continue;
            }

            $field = trim($tokens[$fieldToken][1], "'\"");

            if (preg_match('/(?:^|_)(?:amount|price|rate|percentage)\z/', $field) !== 1) {
                continue;
            }

            $depth = 0;
            $closingParenthesis = null;

            for ($cursor = $openingParenthesis; $cursor < $count; $cursor++) {
                $text = financeTokenText($tokens[$cursor]);

                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')' && --$depth === 0) {
                    $closingParenthesis = $cursor;
                    break;
                }
            }

            $cursor = $closingParenthesis === null ? null : nextFinanceCodeToken($tokens, $closingParenthesis + 1);

            while ($cursor !== null && financeTokenText($tokens[$cursor]) === '->') {
                $method = nextFinanceCodeToken($tokens, $cursor + 1);

                if ($method === null || ! is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
                    break;
                }

                if ($tokens[$method][1] === 'numeric') {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                        .":{$tokens[$method][2]} ({$field})";
                    break;
                }

                $methodOpeningParenthesis = nextFinanceCodeToken($tokens, $method + 1);

                if (
                    $methodOpeningParenthesis === null
                    || financeTokenText($tokens[$methodOpeningParenthesis]) !== '('
                ) {
                    break;
                }

                $methodDepth = 0;
                $methodClosingParenthesis = null;

                for ($methodCursor = $methodOpeningParenthesis; $methodCursor < $count; $methodCursor++) {
                    $text = financeTokenText($tokens[$methodCursor]);

                    if ($text === '(') {
                        $methodDepth++;
                    } elseif ($text === ')' && --$methodDepth === 0) {
                        $methodClosingParenthesis = $methodCursor;
                        break;
                    }
                }

                $cursor = $methodClosingParenthesis === null
                    ? null
                    : nextFinanceCodeToken($tokens, $methodClosingParenthesis + 1);
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Financial decimal fields must keep string state; use inputMode() and validation rules: '
        .implode(', ', $violations),
    );
});
