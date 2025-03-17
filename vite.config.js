import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    // Load environment variables
    const env = loadEnv(mode, process.cwd());
    
    // Default CORS origins if none specified (safer than true)
    const defaultOrigins = [
        'http://localhost',
        'http://127.0.0.1',
    ];
    
    // Parse allowed origins from env or use defaults
    const allowedOrigins = env.VITE_ALLOWED_ORIGINS 
        ? env.VITE_ALLOWED_ORIGINS.split(',') 
        : defaultOrigins;
    
    return {
        plugins: [
            laravel({
                input: 'resources/js/app.js',
                ssr: 'resources/js/ssr.js',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        server: {
            cors: {
                origin: allowedOrigins,
                methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                credentials: true
            },
            hmr: {
                host: env.VITE_DEV_SERVER_HMR_HOST || 'localhost',
            }
        },
    };
});