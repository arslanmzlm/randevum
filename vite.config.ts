import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        // Listen on all interfaces inside the DDEV web container.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // ddev-router proxies https://<project>.test:5173 to the container,
        // so assets/HMR must be advertised on that external origin.
        origin: `${process.env.DDEV_PRIMARY_URL}:5173`,
        // Accept the Host header forwarded by ddev-router (*.test / *.ddev.site).
        allowedHosts: true,
        cors: {
            origin: /https?:\/\/([a-z0-9-]+\.)*(ddev\.site|test)(:\d+)?$/,
        },
    },
});
