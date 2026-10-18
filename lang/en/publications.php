<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Publications: the public library, staff side (phase 4, P4-T03)
|--------------------------------------------------------------------------
|
| The public pages append their own keys here (T13).
*/

return [
    'article' => 'Article',
    'articles' => 'Articles',

    // Fields. Also the names a refusal uses, so a message says "Title (English)"
    // rather than "title_en".
    'title_en' => 'Title (English)',
    'title_ar' => 'Title (Arabic)',
    'title_ar_help' => 'Optional. Readers of the Arabic site see the English title when this is empty.',
    'slug' => 'Web address',
    'slug_help' => 'The last part of the article\'s public address. It is fixed once the article is published, so links that have been shared keep working.',
    'description_en' => 'Description (English)',
    'description_ar' => 'Description (Arabic)',
    'description_ar_help' => 'Optional. Readers of the Arabic site see the English description when this is empty.',
    'topic' => 'Topic',
    'topic_help' => 'Articles with the same topic are grouped together for readers.',
    'authors' => 'Authors',
    'issued_on' => 'Issue date',
    'pdf_file' => 'PDF file',
    'pdf_file_help' => 'A PDF document of up to :megabytes MB.',
    'replacement_pdf_file' => 'Replace the PDF file',
    'replacement_pdf_file_help' => 'Leave this empty to keep the current file. A new file replaces it for readers as soon as you save. A PDF document of up to :megabytes MB.',
    'original_filename' => 'File name',

    // The list.
    'status' => 'Status',
    'published' => 'Published',
    'unpublished' => 'Unpublished',
    'published_at' => 'Published on',
    'download_count' => 'Downloads',
    'download_count_help' => 'An approximate count: it is rate-limited and not deduplicated per reader.',
    'all_articles' => 'All articles',
    'create_article' => 'Add article',
    'view_article' => 'View',
    'edit_article' => 'Edit',

    // Publishing and withdrawing.
    'publish' => 'Publish',
    'publish_modal_heading' => 'Publish this article?',
    'publish_modal_description' => 'It becomes visible on the public site, and anyone can download its PDF.',
    'published_successfully' => 'Article published',
    'unpublish' => 'Unpublish',
    'unpublish_modal_heading' => 'Unpublish this article?',
    'unpublish_modal_description' => 'It disappears from the public site at once and its download link stops working. The article and its PDF are kept, and you can publish it again.',
    'unpublished_successfully' => 'Article unpublished',

    // Refusals.
    'already_published' => 'This article is already published.',
    'already_unpublished' => 'This article is not published.',
    'slug_locked' => 'The web address cannot be changed while the article is published. Unpublish it first.',
    'refused_unauthorized' => 'You do not have permission to do that.',
    'refused_invalid' => 'The article could not be saved. Check the details and try again.',
    'storage_unavailable' => 'The PDF could not be stored, so nothing was saved. Try again, and tell an administrator if it keeps happening.',

    /*
    | The public library (P4-T13). Draft copy pending the owner's text (2026-10-12):
    | replace freely; keep the keys.
    |
    | Whole sentences with named placeholders, so a translator can reorder them: the
    | count line has four variants (the library, one topic, a search, both) instead
    | of one sentence assembled from parts. The download count is always called
    | approximate, because it is rate-limited and not deduplicated per reader. There
    | is no file size and no online reader: no column stores a size, and a viewer
    | would need a script.
    */
    'public' => [
        'heading' => 'Publications',
        'intro' => 'Papers and guides the centre has published, newest first. Each one downloads as the original PDF.',
        'library_eyebrow' => 'Library',
        'back_home' => 'Back to the centre',

        'search_label' => 'Search the publications',
        'search_placeholder' => 'Search titles, topics, authors and descriptions',
        'search_submit' => 'Search',

        'topics_label' => 'Topics',
        'topic_all' => 'All',

        'sort_newest' => 'Newest first',
        'sort_downloads' => 'Most downloaded',
        // The sort is one button that names the current order; this is what a screen
        // reader says it does.
        'sort_switch' => 'Sorted by :current. Sort by :next instead.',
        'downloads_note' => 'Download counts are approximate.',

        'count' => ':count publication|:count publications',
        'count_in_topic' => ':count publication in :topic|:count publications in :topic',
        'count_matching' => ':count publication matching “:search”|:count publications matching “:search”',
        'count_in_topic_matching' => ':count publication in :topic matching “:search”|:count publications in :topic matching “:search”',

        'empty_library' => 'No publications are listed at the moment.',
        'empty_heading' => 'Nothing matches that search',
        'empty_body' => 'Try a different topic, or clear the search field.',
        'empty_clear' => 'Show all publications',

        'downloads' => ':count download|:count downloads',
        'download_pdf' => 'Download PDF',
        'download_pdf_named' => 'Download PDF: :title',
        // The short label on a library card's PDF link and on a document thumbnail.
        'pdf' => 'PDF',
        // There is no online reader, so the second button opens the article's page.
        'read_more' => 'Read more',
        'read_more_named' => 'Read more about :title',

        'pagination_label' => 'Pages of publications',
        'previous' => 'Previous page',
        'next' => 'Next page',
        'page_of' => 'Page :current of :last',

        // A PHP date() pattern, rendered in the reader's locale.
        'date_format' => 'd M Y',
        'issued' => 'Issued :date',

        'back' => 'All publications',
        'original_filing' => 'Original filing',
        'uploaded_by' => 'uploaded by the administration',
        // No article body is stored, only the PDF, so this is always the page's second paragraph.
        'full_text_note' => 'The full text of this paper is distributed as the original PDF exactly as it was filed with the centre. Download it above to read the complete argument, tables and authorities.',
        'more_in' => 'More in :topic',
    ],

    // The upload field's validation wording (P4-T31). The attribute stands in
    // for the label, which Filament would lower-case into "pDF file".
    'pdf_file_attribute' => 'PDF file',
    'pdf_file_not_pdf' => 'The file must be a PDF.',
];
