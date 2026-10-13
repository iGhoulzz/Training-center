import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/public.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600, 700],
                }),
                // The public site's two families (P4-T20a), loaded by the public
                // layout. Preload is narrowed to what every page paints at the top:
                // the display face for the brand name, and the 600 weight, in both
                // subsets, for the pill and the Arabic name. The other faces load when
                // the page's text first needs them, so a visitor does not download
                // ten font files before reading anything.
                bunny('Instrument Serif', {
                    weights: [400],
                    styles: ['normal', 'italic'],
                    preload: [{ weight: 400, style: 'normal' }],
                }),
                bunny('IBM Plex Sans Arabic', {
                    weights: [400, 500, 600, 700],
                    subsets: ['latin', 'arabic'],
                    preload: [{ weight: 600 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
