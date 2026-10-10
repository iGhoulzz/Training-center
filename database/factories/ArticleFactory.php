<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Publications\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(4, true));

        return [
            'title_en' => $title,

            // Null by default, and deliberately so: the Arabic catalogue arrives
            // in phase 4, so an untranslated article is the normal state. It is
            // also the case title() falls back for, and a fixture that always
            // translated would never exercise it.
            'title_ar' => null,

            // Unique without leaving it to chance: the random suffix is what
            // keeps two factory rows with the same random words apart.
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),

            'description_en' => fake()->sentence(),
            'description_ar' => null,
            'topic' => fake()->randomElement(['Welding', 'Safety', 'Electronics', 'Languages']),
            'authors' => fake()->name(),
            'issued_on' => fake()->dateTimeBetween('-3 years', '-1 month'),

            'original_filename' => fake()->slug(2).'.pdf',

            // The private disk from config/filesystems.php, never the public one.
            'disk' => 'private',
            'path' => 'publications/'.Str::ulid()->toString().'.pdf',

            'download_count' => 0,

            // Unpublished unless a test says otherwise: published_at null is the
            // state a new article is created in, and the one every public query
            // must exclude.
            'published_at' => null,
        ];
    }

    /** A published article, live to readers since a moment ago. */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now()->subHour(),
        ]);
    }

    /** An article with an Arabic title and description, for the localized path. */
    public function translated(): static
    {
        return $this->state(fn (): array => [
            'title_ar' => 'دليل السلامة المهنية',
            'description_ar' => 'ملخص الدليل باللغة العربية.',
        ]);
    }
}
