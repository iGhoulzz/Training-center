<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded credential.
 *
 * A separate table rather than columns on the profile, because a certificate
 * carries business metadata of its own — what it is, when it was issued, when
 * it expires — and one person holds several.
 *
 * The row holds metadata and a path. The bytes live on the 'private' disk
 * configured in config/filesystems.php; binary content never goes in the
 * database (spec section 6, "File storage").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_certificates', function (Blueprint $table): void {
            $table->id();

            // index() before constrained() so the foreign key reuses this index
            // rather than MySQL creating an implicit one — same result, but the
            // "index every foreign key" rule is visible in the source.
            $table->foreignId('staff_profile_id')->index()->constrained()->cascadeOnDelete();

            $table->string('title', 200);
            $table->date('issued_on')->nullable();

            // Filtered on to list lapsed accreditations, so indexed. Nullable
            // means "does not expire" — see StaffCertificate::scopeExpired().
            $table->date('expires_on')->nullable()->index();

            // What the uploader called the file, preserved for the download
            // filename. Never used to build the storage path.
            $table->string('original_filename', 255);

            // Which disk holds the bytes, recorded per row so that moving to a
            // different disk later does not orphan the rows already written.
            // No default: a row that does not say where its file lives is a bug
            // worth failing on, not a value worth guessing.
            $table->string('disk', 30);

            $table->string('path', 512);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_certificates');
    }
};
