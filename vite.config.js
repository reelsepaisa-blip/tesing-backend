import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament.css',
                'resources/js/app.js',
                'resources/js/components/location-picker.js',
            ],
            refresh: [
                'resources/views/**',
                'routes/**',
                'app/Filament/**',
                'app/Http/Livewire/**',
                'app/Providers/Filament/**',
            ],

        }),
    ],
    server: {
        host: '127.0.0.1',
        cors: true,
        watch: {

            usePolling: true,
            ignored: ['**/storage/**', '**/vendor/**'],
        },
        hmr: {
            host: '127.0.0.1',
        },
    },
});