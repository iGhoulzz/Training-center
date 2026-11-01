<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages;

use App\Domain\Enrollment\Filament\Pages\CertificateIssuanceQueue;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Queries\CertificateIssuanceQueue as IssuanceQueueQuery;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * The certificate register, listed. Read-plus-three-actions — see
 * `StudentCertificateResource`'s own docblock.
 *
 * issueAction() IS THE ONLY HEADER ACTION, AND THERE IS NO CREATE PAGE TO LINK
 * TO.
 * -------------------------------------------------------------------------------
 * `StudentCertificatePolicy::create()` refuses unconditionally (T4) and
 * `StudentCertificateResource::canCreate()` says so explicitly. Issuing a
 * certificate is not "creating a certificate resource record" in Filament's
 * sense — it is a domain transition with its own enrolment picker — which is
 * why it is a plain `Action`, never a `CreateAction`, matching
 * docs/ENGINEERING.md's note that only NOT registering a create route closes
 * that handler off.
 */
class ListStudentCertificates extends ListRecords
{
    protected static string $resource = StudentCertificateResource::class;

    public function content(Schema $schema): Schema
    {
        $components = [];
        if (Gate::allows('issue', StudentCertificate::class)) {
            $count = app(IssuanceQueueQuery::class)->query()->count();
            if ($count > 0) {
                $components[] = Callout::make(trans_choice('certificates.queue_pending', $count, ['count' => $count]))
                    ->warning()->description(__('certificates.outstanding_balance_hint'))
                    ->extraAttributes(['class' => 'rounded-[14px] border border-amber-400/28 bg-amber-400/7 px-[18px] py-4'])
                    ->actions([Action::make('reviewQueue')->label(__('certificates.review_queue'))
                        ->url(CertificateIssuanceQueue::getUrl())]);
            }
        }
        $components[] = EmbeddedTable::make();

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            StudentCertificateResource::issueAction(),
        ];
    }
}
