import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/admin/theme.css',
                'resources/js/app.js',
                'vendor/apriansyahrs/mekaya-theme/resources/js/mekaya.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
