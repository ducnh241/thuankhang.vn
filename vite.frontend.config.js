import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Frontend — website cửa hàng (Tailwind CSS v4, JS thuần), resources/frontend → public/assets/frontend/build.
// Tách hẳn khỏi admin (vite.config.js): manifest, thư mục build, file hot và cổng dev server riêng.
// Tailwind chỉ quét view resources/views/frontend (xem @source trong resources/frontend/css/app.css).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/frontend/css/app.css', 'resources/frontend/js/app.js'],
            buildDirectory: 'assets/frontend/build',
            hotFile: 'public/hot-frontend',
            refresh: ['resources/views/frontend/**'],
        }),
        tailwindcss(),
    ],
    server: {
        port: 5174,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
