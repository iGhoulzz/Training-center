<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Actions\PublishArticleAction;
use App\Domain\Publications\Models\Article;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mpdf\Mpdf;

/**
 * The public library: three published articles and one draft.
 *
 * CreateArticleAction needs a real PDF upload, so each article gets a one-page PDF
 * rendered with mPDF (already a dependency, used for receipts) and wrapped as an
 * UploadedFile in test mode. Publishing is PublishArticleAction. Titles and
 * descriptions are given in both English and Arabic, the two columns the model keeps.
 */
final class DemoPublicationSeeder extends DemoAreaSeeder
{
    /**
     * [key, days from today, publish after N days or null for a draft, title en, title ar,
     * description en, description ar, topic, authors].
     *
     * @var list<array{string, int, ?int, string, string, string, string, string, string}>
     */
    private const ARTICLES = [
        ['study-habits', -90, 1, 'Study Habits That Work for Adult Learners', 'عادات دراسية فعّالة للمتعلمين البالغين', 'A short guide to planning study time around work and family.', 'دليل موجز لتنظيم وقت الدراسة بالتوازي مع العمل والأسرة.', 'Learning', 'Omar Khalil'],
        ['spreadsheet-tips', -60, 2, 'Ten Spreadsheet Formulas Every Office Needs', 'عشر صيغ في الجداول الإلكترونية يحتاجها كل مكتب', 'The formulas that save the most time in everyday office work.', 'الصيغ التي توفر أكبر قدر من الوقت في الأعمال المكتبية اليومية.', 'Office skills', 'Hana Belkacem'],
        ['bookkeeping-basics', -30, 1, 'Bookkeeping Basics for Small Shops', 'أساسيات مسك الدفاتر للمحلات الصغيرة', 'How to keep a clean cash book and why it matters at year end.', 'كيف تحتفظ بدفتر نقدية منظم ولماذا يهمّ ذلك في نهاية السنة.', 'Accounting', 'Yousef Mansour'],
        ['upcoming-guide', -4, null, 'Choosing Your First Programming Language', 'اختيار لغة البرمجة الأولى', 'A draft comparing three beginner-friendly languages.', 'مسودة تقارن بين ثلاث لغات مناسبة للمبتدئين.', 'Technology', 'Salma Idris'],
    ];

    /** @var array<string, Article> */
    private array $articles = [];

    public function run(): void
    {
        $context = $this->context;

        foreach (self::ARTICLES as [$key, $day, $publishAfter, $titleEn, $titleAr, $descriptionEn, $descriptionAr, $topic, $authors]) {
            $context->timeline->add($context->day($day), 12, "article {$key}", function () use ($context, $key, $day, $titleEn, $titleAr, $descriptionEn, $descriptionAr, $topic, $authors): void {
                $file = $this->pdf($key, $titleEn);

                try {
                    $this->articles[$key] = $context->by('admin', fn (User $actor) => app(CreateArticleAction::class)->execute($actor, [
                        'title_en' => $titleEn,
                        'title_ar' => $titleAr,
                        'description_en' => $descriptionEn,
                        'description_ar' => $descriptionAr,
                        'topic' => $topic,
                        'authors' => $authors,
                        'issued_on' => $context->day($day)->toDateString(),
                    ], $file));
                } finally {
                    @unlink($file->getPathname());
                }
            });

            if ($publishAfter !== null) {
                $context->timeline->add($context->day($day + $publishAfter), 12, "publish {$key}", function () use ($context, $key): void {
                    $context->by('admin', fn (User $actor) => app(PublishArticleAction::class)->execute($actor, $this->articles[$key]));
                });
            }
        }
    }

    /**
     * A one-page PDF, wrapped the way a browser upload arrives.
     *
     * The temporary file is removed by the caller once the Action has copied it.
     */
    private function pdf(string $key, string $title): UploadedFile
    {
        $directory = storage_path('app/mpdf');
        is_dir($directory) || mkdir($directory, 0775, true);

        $mpdf = new Mpdf(['tempDir' => $directory]);
        $mpdf->WriteHTML('<h1>'.e($title).'</h1><p>Demo article. Fictional content for the demo dataset.</p>');

        $path = (string) tempnam(sys_get_temp_dir(), 'demo-article');
        file_put_contents($path, $mpdf->OutputBinaryData());

        return new UploadedFile($path, "{$key}.pdf", 'application/pdf', null, true);
    }
}
