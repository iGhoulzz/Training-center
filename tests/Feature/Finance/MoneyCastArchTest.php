<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** @return array<int, string> */
function financeSourceFiles(): array
{
    return collect(File::allFiles(app_path('Domain/Finance')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => (string) $file->getRealPath())
        ->values()
        ->all();
}

/** @return array<int, Node> */
function financeAst(string $source): array
{
    $nodes = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $nodes = $traverser->traverse($nodes);
    $parentConnector = new NodeTraverser;
    $parentConnector->addVisitor(new ParentConnectingVisitor);

    return $parentConnector->traverse($nodes);
}

/** @return array<int, int> */
function financeFloatViolationLines(string $source): array
{
    $nodes = financeAst($source);
    $finder = new NodeFinder;
    $lines = [];

    foreach ($finder->findInstanceOf($nodes, Expr\Cast\Double::class) as $cast) {
        $lines[] = $cast->getStartLine();
    }

    foreach ($finder->findInstanceOf($nodes, Expr\FuncCall::class) as $call) {
        if (
            $call->name instanceof Name
            && in_array(strtolower($call->name->getLast()), ['floatval', 'doubleval'], true)
        ) {
            $lines[] = $call->getStartLine();
        }
    }

    sort($lines);

    return array_values(array_unique($lines));
}

function financeTextInputField(Expr $expression): ?string
{
    while ($expression instanceof Expr\MethodCall) {
        $expression = $expression->var;
    }

    if (
        ! $expression instanceof Expr\StaticCall
        || ! $expression->class instanceof Name
        || ! $expression->name instanceof Identifier
        || strtolower($expression->class->getLast()) !== 'textinput'
        || strtolower($expression->name->toString()) !== 'make'
    ) {
        return null;
    }

    $fieldArgument = null;

    foreach ($expression->getArgs() as $position => $argument) {
        if (! $argument instanceof Arg) {
            continue;
        }

        $isNameArgument = $argument->name?->toString() === 'name';
        $isFirstPositionalArgument = $position === 0 && $argument->name === null;

        if ($isNameArgument || $isFirstPositionalArgument) {
            $fieldArgument = $argument;
            break;
        }
    }

    if (! $fieldArgument?->value instanceof String_) {
        return null;
    }

    $field = $fieldArgument->value->value;

    return preg_match('/(?:^|_)(?:amount|price|rate|percentage)\z/', $field) === 1
        ? $field
        : null;
}

function financeRootVariable(Expr $expression): ?Expr\Variable
{
    while ($expression instanceof Expr\MethodCall) {
        $expression = $expression->var;
    }

    return $expression instanceof Expr\Variable ? $expression : null;
}

function financeContainingScope(Node $node): ?Node\FunctionLike
{
    $parent = $node->getAttribute('parent');

    while ($parent instanceof Node) {
        if ($parent instanceof Node\FunctionLike) {
            return $parent;
        }

        $parent = $parent->getAttribute('parent');
    }

    return null;
}

/** @param  array<int, Expr\Assign>  $assignments */
function financeAssignedTextInputField(
    Expr\Variable $variable,
    Expr\MethodCall $call,
    array $assignments,
): ?string {
    if (! is_string($variable->name)) {
        return null;
    }

    $callPosition = $call->getStartFilePos();
    $scope = financeContainingScope($call);
    $nearest = null;
    $nearestPosition = -1;

    foreach ($assignments as $assignment) {
        if (
            ! $assignment->var instanceof Expr\Variable
            || $assignment->var->name !== $variable->name
            || financeContainingScope($assignment) !== $scope
        ) {
            continue;
        }

        $position = $assignment->getStartFilePos();

        if ($position < $callPosition && $position > $nearestPosition) {
            $nearest = $assignment;
            $nearestPosition = $position;
        }
    }

    return $nearest instanceof Expr\Assign ? financeTextInputField($nearest->expr) : null;
}

/** @return array<int, array{line: int, field: string}> */
function financeNumericStateCastViolations(string $source): array
{
    $nodes = financeAst($source);
    $finder = new NodeFinder;
    $assignments = $finder->findInstanceOf($nodes, Expr\Assign::class);
    $violations = [];

    foreach ($finder->findInstanceOf($nodes, Expr\MethodCall::class) as $call) {
        if (! $call->name instanceof Identifier || strtolower($call->name->toString()) !== 'numeric') {
            continue;
        }

        $field = financeTextInputField($call->var);

        if ($field === null && ($variable = financeRootVariable($call->var)) !== null) {
            $field = financeAssignedTextInputField($variable, $call, $assignments);
        }

        if ($field !== null) {
            $violations[] = ['line' => $call->getStartLine(), 'field' => $field];
        }
    }

    return $violations;
}

it('forbids PHP floating-point casts throughout the Finance domain', function () {
    $violations = [];

    foreach (financeSourceFiles() as $path) {
        foreach (financeFloatViolationLines((string) file_get_contents($path)) as $line) {
            $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).":{$line}";
        }
    }

    expect($violations)->toBeEmpty(
        'Finance values must not cross a PHP floating-point boundary: '.implode(', ', $violations),
    );
});

it('forbids numeric calls on financial decimal TextInput fields', function () {
    $violations = [];

    foreach (financeSourceFiles() as $path) {
        foreach (financeNumericStateCastViolations((string) file_get_contents($path)) as $violation) {
            $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .":{$violation['line']} ({$violation['field']})";
        }
    }

    expect($violations)->toBeEmpty(
        'Financial decimal fields must keep string state; use inputMode() and validation rules: '
        .implode(', ', $violations),
    );
});

it('recognizes every prohibited floating-point spelling', function (string $source) {
    expect(financeFloatViolationLines("<?php\n{$source}"))->not->toBeEmpty();
})->with([
    'float cast' => '(float) $amount;',
    'double cast' => '(double) $amount;',
    'bare function' => 'floatval($amount);',
    'uppercase function' => 'FLOATVAL($amount);',
    'fully qualified function' => '\\floatval($amount);',
    'fully qualified alias' => '\\doubleval($amount);',
    'imported function alias' => 'use function floatval as toFloat; toFloat($amount);',
]);

it('ignores floating-point words that do not execute a cast', function (string $source) {
    expect(financeFloatViolationLines("<?php\n{$source}"))->toBeEmpty();
})->with([
    'comment' => '// floatval($amount);',
    'literal string' => '$text = \'floatval($amount)\';',
    'interpolated property' => '$text = "{$object->floatval}";',
    'method' => '$object->floatval();',
    'static method' => 'Number::floatval();',
    'property' => '$object->floatval;',
]);

it('recognizes numeric state casts across valid TextInput syntax', function (string $source) {
    expect(financeNumericStateCastViolations("<?php\n{$source}"))->not->toBeEmpty();
})->with([
    'positional argument' => "TextInput::make('amount')->numeric();",
    'named argument' => "TextInput::make(name: 'default_price')->numeric();",
    'fully qualified class' => "\\Filament\\Forms\\Components\\TextInput::make('frozen_rate')->numeric();",
    'aliased class' => "use Filament\\Forms\\Components\\TextInput as MoneyInput; MoneyInput::make('amount')->numeric();",
    'case-insensitive method' => "TextInput::make('discount_percentage')->NUMERIC();",
    'parenthesized chain' => "(TextInput::make('amount'))->numeric();",
    'extracted chain' => '$field = TextInput::make(\'amount\'); $field->numeric();',
    'extracted chain before reassignment' => '$field = TextInput::make(\'amount\'); $field->numeric(); $field = TextInput::make(\'quantity\');',
]);

it('ignores numeric calls that do not cast a financial TextInput', function (string $source) {
    expect(financeNumericStateCastViolations("<?php\n{$source}"))->toBeEmpty();
})->with([
    'non-financial field' => "TextInput::make('quantity')->numeric();",
    'different component' => "OtherInput::make('amount')->numeric();",
    'safe input mode' => "TextInput::make('amount')->inputMode('decimal');",
    'comment' => "// TextInput::make('amount')->numeric();",
    'literal string' => '$text = "TextInput::make(\'amount\')->numeric()";',
    'property access' => "TextInput::make('amount')->numeric;",
    'unrelated scope' => 'function moneyField(): void { $field = TextInput::make(\'amount\'); } function quantityField(): void { $field = TextInput::make(\'quantity\'); $field->numeric(); }',
]);
