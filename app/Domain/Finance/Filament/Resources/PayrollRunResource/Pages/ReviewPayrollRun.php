<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages;

use App\Domain\Finance\Actions\AddPayrollLineAdjustmentAction;
use App\Domain\Finance\Actions\DeletePayrollRunAction;
use App\Domain\Finance\Actions\FinalizePayrollRunAction;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** Reviews frozen draft figures and exposes only the named payroll write Actions. */
final class ReviewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_adjustment')
                ->label(__('payroll.add_adjustment'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->visible(fn (): bool => ! $this->run()->isFinalized() && PayrollRunResource::canCreate())
                ->schema([
                    Select::make('line_id')
                        ->label(__('payroll.line'))
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
                        ->rules(['numeric', 'decimal:0,3', 'gte:-999999999.999', 'lte:999999999.999'])
                        ->suffix(__('payroll.currency')),
                    Textarea::make('reason')->label(__('payroll.reason'))->required(),
                ])
                ->action(function (array $data): void {
                    $line = PayrollLine::query()
                        ->where('payroll_run_id', $this->run()->getKey())
                        ->findOrFail((int) $data['line_id']);
                    app(AddPayrollLineAdjustmentAction::class)->execute(
                        $this->actor(),
                        $line,
                        (string) $data['amount'],
                        (string) $data['reason'],
                    );
                }),

            Action::make('finalize')
                ->label(__('payroll.finalize'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->requiresConfirmation()
                ->authorize('finalize')
                ->visible(fn (): bool => ! $this->run()->isFinalized())
                ->action(function (): void {
                    $this->record = app(FinalizePayrollRunAction::class)->execute($this->actor(), $this->run());
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
