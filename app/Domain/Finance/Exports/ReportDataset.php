<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use LogicException;

/** A presentation-ready report whose rows remain traceable to source models. */
final readonly class ReportDataset
{
    /** @var array<int, array<string, string | int | null>> */
    private array $rowsByCarrierId;

    /**
     * @param  array<string, string>  $columns
     * @param  list<array{carrier_id: int, cells: array<string, string | int | null>}>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
        $rowsByCarrierId = [];

        foreach ($rows as $row) {
            $carrierId = $row['carrier_id'];

            if (array_key_exists($carrierId, $rowsByCarrierId)) {
                throw new LogicException("A report dataset contains duplicate carrier id [{$carrierId}].");
            }

            $rowsByCarrierId[$carrierId] = $row['cells'];
        }

        $this->rowsByCarrierId = $rowsByCarrierId;
    }

    /** @return list<int> */
    public function carrierIds(): array
    {
        return array_keys($this->rowsByCarrierId);
    }

    public function cell(int $carrierId, string $column): string|int|null
    {
        if (! isset($this->rowsByCarrierId[$carrierId])) {
            throw new LogicException("No report row exists for carrier id [{$carrierId}].");
        }

        if (! array_key_exists($column, $this->rowsByCarrierId[$carrierId])) {
            throw new LogicException("No report column [{$column}] exists for carrier id [{$carrierId}].");
        }

        return $this->rowsByCarrierId[$carrierId][$column];
    }

    /**
     * @return array{
     *     columns: array<string, string>,
     *     rows: list<array{carrier_id: int, cells: array<string, string|int|null>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
        ];
    }
}
