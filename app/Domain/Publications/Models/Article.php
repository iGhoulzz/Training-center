<?php

declare(strict_types=1);

namespace App\Domain\Publications\Models;

use App\Domain\Staff\Support\RecordsActivity;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A document in the public library: metadata plus a pointer to a PDF.
 *
 * The bytes live on a private, non-web-served disk and have no URL of their own.
 * The only reader is the public download route, which finds the article through
 * a query that requires publication on every request (T13).
 *
 * `published_at` is the publication state: null means unpublished, and there is
 * no separate flag to disagree with it. Only PublishArticleAction and
 * UnpublishArticleAction move it.
 *
 * TWO COLUMNS ARE DELIBERATELY NOT FILLABLE
 * -----------------------------------------
 * `download_count` is the one column written outside an actor-first Action: an
 * anonymous counter, incremented by ArticleDownloadCounter through a single
 * conditional UPDATE and by nothing else (docs/ENGINEERING.md, "The one
 * exception"). Leaving it out of $fillable means a create() or update() that
 * carries it is silently ignored rather than honoured.
 *
 * `published_at` is left out for the same reason in a smaller key: a form that
 * accidentally carried it must not be able to publish. The two lifecycle Actions
 * set it explicitly, which keeps the act of publishing deliberate and
 * attributable.
 *
 * Configuration only — casts, a scope, two predicates and two display helpers.
 * No business logic and no write guards.
 */
#[Fillable([
    'title_en',
    'title_ar',
    'slug',
    'description_en',
    'description_ar',
    'topic',
    'authors',
    'issued_on',
    'original_filename',
    'disk',
    'path',
])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Articles a reader may see: published ones.
     *
     * The single definition of "published" for every public query. T13's download
     * route does not rely on this alone — it states the condition in the lookup
     * itself — but listings and searches share it so the rule has one spelling.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /** The row-level counterpart of scopePublished(). */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * The title in the reader's language.
     *
     * Arabic wins only when the locale is Arabic AND a translation exists, so an
     * article published before anyone translated it still has a usable title. A
     * method rather than an accessor for the reason Course::name() is one: the
     * value depends on the request locale, not on the row.
     */
    public function title(): string
    {
        return app()->getLocale() === 'ar' && filled($this->title_ar)
            ? (string) $this->title_ar
            : (string) $this->title_en;
    }

    /** The description in the reader's language, with the same fallback as title(). */
    public function description(): string
    {
        return app()->getLocale() === 'ar' && filled($this->description_ar)
            ? (string) $this->description_ar
            : (string) $this->description_en;
    }

    /**
     * Every column a person edits, plus the lifecycle columns, and nothing else.
     *
     * `path` IS AUDITED, UNLIKE StaffCertificate's. A staff credential's storage
     * path is not an audit fact and its filename can carry a national ID. Here the
     * filename is the public document's own and the path is a generated ULID, and
     * auditing it is what makes replacing the PDF visible: a replacement that
     * keeps the same title and filename would otherwise move no audited column,
     * the diff would be empty, dontLogEmptyChanges() would suppress it, and the
     * document readers download would change with no trace of who changed it.
     *
     * `download_count` is absent on purpose. It is an anonymous counter with no
     * actor to attribute, and logging each download would flood an append-only
     * log with rows nobody can explain. `disk` never changes and is absent.
     */
    public function auditedAttributes(): array
    {
        return [
            'title_en',
            'title_ar',
            'slug',
            'description_en',
            'description_ar',
            'topic',
            'authors',
            'issued_on',
            'original_filename',
            'path',
            'published_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'download_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** See StaffProfile::newFactory() for why this is stated rather than guessed. */
    protected static function newFactory(): ArticleFactory
    {
        return ArticleFactory::new();
    }
}
