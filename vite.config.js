import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Backend — giao diện admin (template ProCMS: Ant Design 4 + Tailwind CSS v4 + jQuery), resources/backend
// → public/assets/backend/build. Website (frontend) build riêng bằng vite.frontend.config.js.
// `npm run build` build cả hai; thư mục build được commit, nên server live không cần chạy npm.
// Tailwind sinh class từ view Blade (xem @source trong resources/backend/css/app.css): thêm class mới trong view thì
// phải build lại và commit build mới, nếu không class đó không có trên live.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/backend/css/app.css', 'resources/backend/js/app.js'],
            buildDirectory: 'assets/backend/build',
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
