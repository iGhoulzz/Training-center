<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages;

use App\Domain\Finance\Actions\AddPayrollLineAdjustmentAction;
use App\Domain\Finance\Actions\DeletePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Actions\RecalculateSalaryDraftAction;
use App\Domain\Finance\Enums\PayrollRunType;
use App\Domain\Finance\Exceptions\SalaryAdjustmentsHaveNoLineException;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Reviews frozen draft figures and exposes only the named payroll write Actions.
 *
 * Every write here says what happened: a success toast, or the Action's own
 * refusal as a persistent danger toast with the transaction rolled back. A write
 * also reloads the run, so the page shows the new state without a reload.
 */
final class ReviewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    /**
     * A salary draft follows the pay rates on file, so opening one recalculates it.
     *
     * Only someone who may create payroll runs triggers the write; a viewer with
     * less still gets the page, unchanged and with no toast, because finalize
     * recalculates under its lock regardless. A refusal (a bonus with no line to
     * move to) is shown and the page still renders the draft as it was.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $run = $this->run();

        if ($run->type !== PayrollRunType::MonthlySalary
            || $run->isFinalized()
            || ! $this->actor()->can('create', PayrollRun::class)
        ) {
            return;
        }

        try {
            $changed = app(RecalculateSalaryDraftAction::class)->execute($this->actor(), $run);
        } catch (SalaryAdjustmentsHaveNoLineException $exception) {
            $this->notifyRefusal($exception->getMessage());

            return;
        }

        if ($changed) {
            $this->reloadRun();

            Notification::make()->title(__('payroll.lines_recalculated'))->info()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_adjustment')
                ->label(__('payroll.add_adjustment'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->visible(fn (): bool => ! $this->run()->isFinalized() && PayrollRunResource::canCreate())
                ->successNotificationTitle(__('payroll.adjustment_added'))
                ->schema([
                    Select::make('line_id')
                        ->label(__('payroll.line'))
                        // Bounded by the lines already frozen onto this one
                        // draft run; this is not a centre-wide catalogue.
                        ->options(fn (): array => $this->run()->lines
                            ->mapWithKeys(fn (PayrollLine $line): array => [
                                $line->getKey() => __('payroll.line_option', [
                                    'employee' => $line->user->name,
                                    'amount' => $line->computed_amount,
                                ]),
                            ])->all())
                        ->required(),
                    TextInput::make('amount')
                        ->label(__('payroll.amount'))
                        ->inputMode('decimal')
                        ->required()
                        ->rules([
                            'numeric',
                            'decimal:0,3',
                            'regex:/^[+-]?\d{1,9}(?:\.\d{1,3})?$/D',
                            'gte:-999999999.999',
                            'lte:999999999.999',
                        ])
                        ->suffix(__('payroll.currency')),
                    Textarea::make('reason')->label(__('payroll.reason'))->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $line = PayrollLine::query()
                        ->where('payroll_run_id', $this->run()->getKey())
                        ->findOrFail((int) $data['line_id']);
                    $this->attempt(fn (): mixed => app(AddPayrollLineAdjustmentAction::class)->execute(
                        $this->actor(),
                        $line,
                        (string) $data['amount'],
                        (string) $data['reason'],
                    ));

                    $this->reloadRun();
                    $action->success();
                }),

            Action::make('finalize')
                ->label(__('payroll.finalize'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->requiresConfirmation()
                ->authorize('finalize')
                ->visible(fn (): bool => ! $this->run()->isFinalized())
                ->successNotificationTitle(__('payroll.finalized_successfully'))
                ->action(function (Action $action): void {
                    $this->attempt(fn (): mixed => app(FinalizePayrollRunAction::class)->execute($this->actor(), $this->run()));

                    $this->reloadRun();
                    $action->success();
                }),

            Action::make('delete')
                ->label(__('payroll.delete_draft'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->authorize('delete')
                ->visible(fn (): bool => ! $this->run()->isFinalized())
                ->action(function (): void {
                    app(DeletePayrollRunAction::class)->execute($this->actor(), $this->run());
                    $this->redirect(PayrollRunResource::getUrl('index'));
                }),
        ];
    }

    /**
     * Eager-load the adjustments and who added them with the run, so the review
     * page reads a fixed number of queries however many lines it has and never
     * trips the lazy-loading guard. Runs on every request, because Livewire
     * resolves the record again each time.
     */
    protected function resolveRecord(int|string $key): Model
    {
        $run = parent::resolveRecord($key);
        $run->loadMissing('lines.adjustments.createdBy');

        return $run;
    }

    /**
     * Run a write and turn a refusal the Action worded itself into a persistent
     * notification, a Halt and a rollback. Anything else propagates: a real fault
     * must not be dressed up as a notification. Same shape as ExpenseResource.
     *
     * @param  Closure(): mixed  $write
     *
     * @throws Halt
     */
    private function attempt(Closure $write): void
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            $this->notifyRefusal($exception->validator->errors()->first() ?: __('payroll.refused'));

            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (SalaryAdjustmentsHaveNoLineException $exception) {
            $this->notifyRefusal($exception->getMessage());

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    private function notifyRefusal(string $message): void
    {
        Notification::make()->title($message)->danger()->persistent()->send();
    }

    /**
     * Read the run again after a write. The infolist is built once per request
     * and holds the record it was given, so it is pointed at the new one too, or
     * the page would keep rendering the state from before the write until a reload.
     */
    private function reloadRun(): void
    {
        $this->record = $this->resolveRecord($this->run()->getKey());
        $this->getSchema('infolist')?->record($this->record);
    }

    private function run(): PayrollRun
    {
        /** @var PayrollRun $run */
        $run = $this->getRecord();

        return $run;
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }
}
