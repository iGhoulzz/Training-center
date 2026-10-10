<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per document in the public library (phase 4, spec section 6).
 *
 * The row holds metadata and a pointer. The PDF itself lives on the 'private'
 * disk under publications/ — never the public disk — and is served only by a
 * route that re-reads publication state on every request. Binary content never
 * goes in the database.
 *
 * `published_at` IS the publication state. Null means unpublished; there is no
 * separate flag to disagree with it. Every public read filters on it, so it is
 * indexed.
 *
 * There is no soft delete and no delete path at all: a withdrawn article is
 * unpublished, and its row and file stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();

            // Bilingual from commit one. The Arabic columns are nullable because
            // an article may be published before anyone has translated it;
            // readers fall back to the English text, as courses do.
            $table->string('title_en', 200);
            $table->string('title_ar', 200)->nullable();

            // The public address of the article. Generated from title_en on
            // creation and fixed once the article is published, so a link that
            // has been shared keeps working.
            $table->string('slug', 150)->unique();

            $table->text('description_en');
            $table->text('description_ar')->nullable();

            // Free text, grouped into chips on the public index; indexed because
            // that index filters on it.
            $table->string('topic', 100)->index();
            $table->string('authors', 255);
            $table->date('issued_on')->index();

            // What the uploader called the file, preserved for the download
            // filename. Never used to build the storage path.
            $table->string('original_filename', 255);

            // Which disk holds the bytes, recorded per row so that moving to a
            // different disk later does not orphan the rows already written. No
            // default: a row that does not say where its file lives is a bug
            // worth failing on, not a value worth guessing.
            $table->string('disk', 30);

            // Unique because a stored file belongs to exactly one article:
            // replacing or withdrawing one must never reach another's PDF. It is
            // also what lets the file lifecycle's ownership check find the owning
            // row through the index rather than by scanning, and locking, the
            // whole table.
            $table->string('path', 512)->unique();

            // An approximate anonymous counter, written by exactly one class
            // (ArticleDownloadCounter) through a single conditional increment.
            // Indexed for the "most downloaded" sort. See docs/ENGINEERING.md,
            // "The one exception: the article download counter".
            $table->unsignedInteger('download_count')->default(0)->index();

            $table->timestamp('published_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
