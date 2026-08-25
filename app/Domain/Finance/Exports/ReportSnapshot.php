<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use InvalidArgumentException;

/** Immutable, translated report content captured before a queued export starts. */
final readonly class ReportSnapshot
{
    /**
     * @param  array<string, string>  $filters
     */
    public function __construct(
        public ReportKind $kind,
        public string $title,
        public string $locale,
        public array $filters,
        public ReportDataset $dataset,
    ) {}

    /**
     * @return array{
     *     kind: string,
     *     title: string,
     *     locale: string,
     *     filters: array<string, string>,
     *     dataset: array{
     *         columns: array<string, string>,
     *         rows: list<array{carrier_id: int, cells: array<string, string|int|null>}>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'title' => $this->title,
            'locale' => $this->locale,
            'filters' => $this->filters,
            'dataset' => $this->dataset->toArray(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $kind = ReportKind::tryFrom(self::string($payload, 'kind'));

        if (! $kind instanceof ReportKind) {
            throw new InvalidArgumentException('An export snapshot has an invalid report kind.');
        }

        $filters = $payload['filters'] ?? null;
        $dataset = $payload['dataset'] ?? null;

        if (! is_array($filters) || ! is_array($dataset)) {
            throw new InvalidArgumentException('An export snapshot has invalid metadata.');
        }

        /** @var array<string, string> $filters */
        /** @var array<string, string>|null $columns */
        $columns = $dataset['columns'] ?? null;
        /** @var list<array{carrier_id: int, cells: array<string, string|int|null>}>|null $rows */
        $rows = $dataset['rows'] ?? null;

        if (! is_array($columns) || ! is_array($rows)) {
            throw new InvalidArgumentException('An export snapshot has an invalid dataset.');
        }

        return new self(
            kind: $kind,
            title: self::string($payload, 'title'),
            locale: self::string($payload, 'locale'),
            filters: $filters,
            dataset: new ReportDataset($columns, $rows),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("An export snapshot has an invalid [{$key}] value.");
        }

        return $value;
    }
}
