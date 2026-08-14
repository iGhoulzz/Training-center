<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages;

use App\Domain\Finance\Actions\AdjustPayrollLineAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Models\PayrollLine;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Creates the run and any correction line through their self-authorizing Actions. */
final class CreatePayrollRun extends CreateRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = $this->actor();
        $type = PayrollRunType::from((string) $data['type']);
        $run = app(CreatePayrollRunAction::class)->execute(
            $actor,
            $type,
            isset($data['period_start']) ? (string) $data['period_start'] : null,
            isset($data['period_end']) ? (string) $data['period_end'] : null,
            array_map('intval', (array) ($data['assignment_ids'] ?? [])),
            isset($data['notes']) ? (string) $data['notes'] : null,
        );

        if ($type === PayrollRunType::Adjustment) {
            $target = PayrollLine::query()->findOrFail((int) $data['target_line_id']);
            app(AdjustPayrollLineAction::class)->execute(
                $actor,
                $run,
                $target,
                (string) $data['correction_amount'],
                (string) $data['correction_reason'],
            );
        }

        return $run;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('review', ['record' => $this->getRecord()]);
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
