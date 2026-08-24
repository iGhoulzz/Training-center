<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/** Refuses queued report work after its requester loses either required ability. */
final readonly class EnsureReportExportAuthorized
{
    public function __construct(private int $requesterId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $requester = User::query()->find($this->requesterId);

        if (! $requester instanceof User
            || ! $requester->is_active
            || ! $requester->can('view_financial_report')
            || ! $requester->can('export_financial_report')
        ) {
            throw new AuthorizationException('The report requester is no longer authorized to export financial data.');
        }

        return $next($job);
    }
}
