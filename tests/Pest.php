<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Real uploaded files for the staff file-lifecycle tests (P1-T06b)
|--------------------------------------------------------------------------
|
| The upload Actions validate the REAL mime type (mimetypes: reads the bytes),
| so the tests must hand them files whose content is genuine. Two constraints
| shape these helpers:
|
|   - UploadedFile::fake()->image() needs the GD extension, which is not
|     installed in this environment. So images are synthesised as raw PNG bytes
|     with gzcompress (zlib) — no GD, and getimagesize() reads the dimensions.
|   - Illuminate\Http\Testing\File::getMimeType() reports the mime from the
|     FILE NAME extension, not the content, so a fake could never prove that the
|     extension is ignored. A real Illuminate\Http\UploadedFile (test mode)
|     sniffs the actual bytes, which is exactly what must be validated.
*/

/**
 * A minimal but genuinely valid PNG of the given size, built without GD.
 *
 * finfo sniffs the \x89PNG signature as image/png and getimagesize() reads the
 * IHDR, so both the `image`/`mimetypes` and `dimensions` validation rules see a
 * real image.
 */
function makePngBytes(int $width = 100, int $height = 100): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
        .$type.$data.pack('N', crc32($type.$data));

    // 8-bit grayscale, no interlacing.
    $ihdr = pack('N', $width).pack('N', $height).chr(8).chr(0).chr(0).chr(0).chr(0);

    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        // Filter byte 0 (None) then one grayscale byte per pixel.
        $raw .= chr(0).str_repeat(chr(200), $width);
    }

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', $ihdr)
        .$chunk('IDAT', (string) gzcompress($raw, 6))
        .$chunk('IEND', '');
}

/**
 * Wrap real bytes in a real UploadedFile (test mode), so getMimeType() sniffs
 * the content rather than trusting the name.
 */
function uploadWithBytes(string $clientName, string $bytes): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'p1t06b_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $clientName, null, null, true);
}

/** A real PNG upload of the given dimensions. */
function pngUpload(string $clientName = 'scan.png', int $width = 100, int $height = 100): UploadedFile
{
    return uploadWithBytes($clientName, makePngBytes($width, $height));
}

/** A real (tiny) PDF upload. */
function pdfUpload(string $clientName = 'diploma.pdf'): UploadedFile
{
    return uploadWithBytes(
        $clientName,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}
