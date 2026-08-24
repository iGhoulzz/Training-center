<?php

declare(strict_types=1);

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Exports\ReportSnapshot;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use RuntimeException;

/** Renders one permission-scoped report and notifies its requesting user. */
final class GenerateReportPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public readonly string $reference;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public readonly array $snapshot,
        public readonly int $requesterId,
    ) {
        $this->reference = (string) Str::uuid();
    }

    public function handle(): void
    {
        $requester = User::query()->findOrFail($this->requesterId);

        if (! $requester->is_active
            || ! $requester->can('view_financial_report')
            || ! $requester->can('export_financial_report')) {
            return;
        }

        $snapshot = ReportSnapshot::fromArray($this->snapshot);
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale($snapshot->locale);
            $direction = str_starts_with($snapshot->locale, 'ar') ? 'rtl' : 'ltr';

            File::ensureDirectoryExists(storage_path('app/mpdf'));

            $mpdf = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
            $mpdf->WriteHTML(view('finance.reports.pdf', [
                'snapshot' => $snapshot,
                'locale' => $snapshot->locale,
                'direction' => $direction,
            ])->render());

            $path = "financial-reports/{$this->requesterId}/{$this->reference}.pdf";

            if (! Storage::disk('private')->put($path, $mpdf->OutputBinaryData())) {
                throw new RuntimeException('The report PDF could not be stored.');
            }

            $routeName = Filament::getPanel('admin')->generateRouteName('pages.reports.pdf.download');
            $downloadUrl = URL::temporarySignedRoute($routeName, now()->addDay(), [
                'requester' => $requester->getKey(),
                'reference' => $this->reference,
            ]);

            Notification::make()
                ->title(__('reports.notifications.pdf_ready'))
                ->success()
                ->actions([
                    Action::make('download_pdf')
                        ->label(__('reports.actions.download_pdf'))
                        ->url($downloadUrl, shouldOpenInNewTab: true)
                        ->markAsRead(),
                ])
                ->sendToDatabase($requester, isEventDispatched: true);
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
