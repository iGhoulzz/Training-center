<?php

declare(strict_types=1);

namespace App\Domain\Publications\Data;

/**
 * One published article, in the shape the public library renders directly (P4-T13).
 *
 * A CLOSED, NAMED PROJECTION, NEVER A MODEL. docs/ENGINEERING.md: "A public
 * surface renders a closed, named projection — never a model." This is the
 * library's version of CertificateVerificationView and of PublicProgramme, and
 * it exists for the same reason. PublicArticleCatalogue builds one by naming each
 * property off an `Article` row, never by handing the row itself, or its
 * `toArray()`, to a view. A column added to `articles` in a later phase therefore
 * cannot reach the public internet by default. Reaching it takes a deliberate new
 * property here AND a deliberate line in the catalogue that fills it.
 * PublicationsIndexTest pins this class's property list and checks that no view
 * on a publications route is handed a model.
 *
 * WHAT IS HERE IS WHAT THE PAGES SHOW: the slug (the article's URL), the title and
 * description in the reader's language, the topic, the authors, the issue date and
 * the download count. The storage disk, the path, the original filename, the
 * publication timestamp, the row id and the Arabic and English columns taken
 * apart are ABSENT ON PURPOSE. The file is reached through the download route by
 * slug alone, so nothing on this object tells a reader where the bytes live.
 *
 * THE DOWNLOAD COUNT IS APPROXIMATE, and every page that prints it says so (see
 * ArticleDownloadCounter): it is rate-limited, not deduplicated per reader.
 */
final readonly class PublicArticle
{
    /**
     * @param  string  $slug  The article's address segment; the show and download routes both take it.
     * @param  string  $title  The title in the reader's language.
     * @param  string  $description  The description in the reader's language.
     * @param  string  $topic  The article's topic, as the chips and the filter spell it.
     * @param  string  $authors  The authors, as one line of text.
     * @param  string  $issuedOn  The issue date as Y-m-d. The column is NOT NULL, so every article has one.
     * @param  int  $downloadCount  The approximate number of downloads.
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $topic,
        public string $authors,
        public string $issuedOn,
        public int $downloadCount,
    ) {}
}
