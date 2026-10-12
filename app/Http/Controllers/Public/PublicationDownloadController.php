<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Models\Article;
use App\Domain\Publications\Support\ArticleDownloadCounter;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way an article's bytes leave the private disk (P4-T13).
 *
 * THE FILE HAS NO URL, SO THIS ROUTE IS THE ONLY READER. The 'private' disk
 * registers no route and no `url` (config/filesystems.php), and nothing on a
 * PublicArticle says where a file lives. Whatever stops this route from serving
 * a file stops every reader.
 *
 * EVERY DOWNLOAD RE-READS PUBLICATION STATE. The article is looked up by slug with
 * `published_at` not null IN THAT SAME QUERY, so an article unpublished a second
 * ago is not found, and its link stops working at once. An unpublished slug and an
 * unknown one are both a 404 from the first line, with the same body: a different
 * answer would tell a visitor which articles the centre has withdrawn.
 *
 * WHAT IS CHECKED, IN THIS ORDER, AND WHY THE ORDER MATTERS
 * ---------------------------------------------------------
 *   1. The article is published (the lookup).
 *   2. Its disk is the private one, and its path is exactly the shape the upload
 *      Action generates. THE PRIVATE DISK IS SHARED: staff certificates and
 *      photos live beside the library, and receipts will. A row naming another
 *      feature's file would otherwise stream it to the open internet, so the
 *      path must be `publications/{ULID}.pdf`, and nothing else is served.
 *   3. The file exists. A row that outlived its file is a 404 and is NOT counted.
 *   4. Only then is the download counted. The counter re-reads publication state
 *      itself in one conditional UPDATE, so if the article was unpublished
 *      between steps 1 and 4 it returns false and this route answers 404 rather
 *      than serving a file nobody may now have.
 *
 * Counting after the checks means a download that cannot be served is never
 * counted. The count is approximate by nature (ArticleDownloadCounter) and is
 * taken when the response is built, not when the last byte arrives.
 *
 * This class writes nothing except through the counter.
 *
 * STREAMED, NEVER REDIRECTED, with the type fixed as PDF and `nosniff`, so a
 * browser that sniffs its way to something else is not executing a file the
 * centre uploaded on the centre's own origin.
 */
final class PublicationDownloadController extends Controller
{
    /** The generated path shape: the upload Action's directory, a ULID, and the one accepted extension. */
    private const PATH_PATTERN = '#^'.CreateArticleAction::DIRECTORY.'/[0-9A-HJKMNP-TV-Z]{26}\.'.CreateArticleAction::EXTENSION.'$#i';

    /** The longest stem a download name keeps, in characters, before ".pdf". */
    private const NAME_STEM_MAX_LENGTH = 100;

    public function __construct(private readonly ArticleDownloadCounter $counter) {}

    public function __invoke(string $slug): StreamedResponse
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        if (! $article instanceof Article) {
            abort(404);
        }

        // The row names its own disk and path, and neither comes from the
        // request. A value other than what the upload Action writes means a manual
        // database edit, a restored backup or a future importer, and is refused.
        if ($article->disk !== CreateArticleAction::DISK || preg_match(self::PATH_PATTERN, $article->path) !== 1) {
            abort(404);
        }

        $disk = Storage::disk(CreateArticleAction::DISK);

        if (! $disk->exists($article->path)) {
            abort(404);
        }

        if (! $this->counter->increment($article)) {
            abort(404);
        }

        return $disk->download($article->path, $this->downloadName($article->original_filename), [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The name the reader's browser saves the file under: a safe stem and `.pdf`.
     *
     * The uploader's own name is kept where it can be, since it is what the
     * document is called, but it arrives from a person. So it is cut to its last
     * path segment and loses its final extension; every character that is not a
     * letter, a digit, a space or one of `. _ ( ) -` becomes an underscore
     * (quotes, separators, control characters and the bidirectional overrides all
     * go); the stem is capped and trimmed of spaces, dots and underscores, so a
     * name made only of replaced characters is left with nothing; and it is given
     * `.pdf` whatever it was. A name with nothing left is `article.pdf`.
     */
    private function downloadName(string $original): string
    {
        $segments = preg_split('#[\\\\/]+#', $original) ?: [];
        $stem = pathinfo((string) end($segments), PATHINFO_FILENAME);
        $stem = (string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $stem);
        $stem = trim(mb_substr($stem, 0, self::NAME_STEM_MAX_LENGTH), ' ._');

        return ($stem === '' ? 'article' : $stem).'.'.CreateArticleAction::EXTENSION;
    }
}
