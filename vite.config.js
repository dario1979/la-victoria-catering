import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        vue(),
        VitePWA({
            registerType: 'autoUpdate',
            manifest: {
                name: 'La Victoria Bakery',
                short_name: 'La Victoria Bakery',
                theme_color: '#7f1d1d',
                background_color: '#fffaf0',
                display: 'standalone',
                icons: [],
            },
            workbox: {
                navigateFallback: null,
                runtimeCaching: [
                    { urlPattern: /^\/api\/v1\//, handler: 'NetworkOnly', method: 'GET' },
                    { urlPattern: /^\/api\/v1\//, handler: 'NetworkOnly', method: 'POST' },
                    { urlPattern: /^\/api\/v1\//, handler: 'NetworkOnly', method: 'PUT' },
                    { urlPattern: /^\/api\/v1\//, handler: 'NetworkOnly', method: 'PATCH' },
                    { urlPattern: /^\/api\/v1\//, handler: 'NetworkOnly', method: 'DELETE' },
                ],
            },
        }),
        tailwindcss(),
    ],
});
