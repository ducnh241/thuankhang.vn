import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Giao diện admin (template ProCMS: Ant Design 4 + Tailwind CSS v4 + jQuery) và website (Tailwind CSS v4, resources/site).
// `npm run build` ghi ra public/assets/admin/build và thư mục này được commit, nên server live không cần chạy npm.
// Tailwind sinh class từ view Blade (xem @source trong resources/admin/css/app.css): thêm class mới trong view thì
// phải build lại và commit build mới, nếu không class đó không có trên live.
export default defineConfig({
    plugins: [
        laravel({
            // Admin + website (resources/site, cửa hàng) dùng chung một lần build / một manifest
            input: ['resources/admin/css/app.css', 'resources/admin/js/app.js', 'resources/site/css/app.css', 'resources/site/js/app.js'],
            buildDirectory: 'assets/admin/build',
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
