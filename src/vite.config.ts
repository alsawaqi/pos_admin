import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, loadEnv } from 'vite';
import { bunny } from 'laravel-vite-plugin/fonts';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const vitePort = Number(env.VITE_PORT ?? 5174);
    const viteDevServerUrl = env.VITE_DEV_SERVER_URL ?? `http://localhost:${vitePort}`;
    const corsOrigins = (env.VITE_DEV_SERVER_CORS_ORIGINS ?? env.APP_URL ?? 'http://localhost:8086')
        .split(',')
        .map((origin) => origin.trim())
        .filter(Boolean);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600, 700],
                    }),
                ],
            }),
            vue(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
        server: {
            host: '0.0.0.0',
            port: vitePort,
            strictPort: true,
            origin: viteDevServerUrl,
            cors: {
                origin: corsOrigins,
                credentials: true,
            },
            hmr: {
                host: env.VITE_HMR_HOST ?? 'localhost',
                port: Number(env.VITE_HMR_PORT ?? vitePort),
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
