<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Deletes a locked draft and lets its genuine children cascade with it. */
final class DeletePayrollRunAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(User $actor, PayrollRun $run): void
    {
        Gate::forUser($actor)->authorize('delete', $run);

        $this->causers->withCauser(
            $actor,
            fn (): bool => DB::transaction(function () use ($actor, $run): bool {
                $lockedRun = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('delete', $lockedRun);
                $lockedRun->delete();

                return true;
            }),
        );
    }
}
