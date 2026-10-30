<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages;

use App\Domain\Finance\Actions\AdjustPayrollLineAction;
use App\Domain\Finance\Actions\CreatePayrollRunAction;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryMonthAlreadyHasRunException;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Models\PayrollLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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

        // The form asks for a month; the Action takes its first and last day.
        $month = $type === PayrollRunType::MonthlySalary
            ? CarbonImmutable::createFromFormat('!Y-m', (string) ($data['salary_month'] ?? ''))
            : null;

        try {
            $run = app(CreatePayrollRunAction::class)->execute(
                $actor,
                $type,
                $month instanceof CarbonImmutable ? $month->startOfMonth()->toDateString() : null,
                $month instanceof CarbonImmutable ? $month->endOfMonth()->toDateString() : null,
                array_map('intval', (array) ($data['assignment_ids'] ?? [])),
                isset($data['notes']) ? (string) $data['notes'] : null,
            );
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception->validator->errors()->first() ?: __('payroll.refused'));

            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (SalaryMonthAlreadyHasRunException $exception) {
            $this->notifyRefusal($exception->getMessage());

            throw (new Halt)->rollBackDatabaseTransaction();
        }

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

    /** The Action's own refusal, shown until dismissed; same shape as ReviewPayrollRun. */
    private function notifyRefusal(string $message): void
    {
        Notification::make()->title($message)->danger()->persistent()->send();
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
