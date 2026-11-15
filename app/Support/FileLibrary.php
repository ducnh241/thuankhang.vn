<?php

namespace App\Support;

use App\Models\FileUpload;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use FilesystemIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Mọi file đã tải lên website để hiện ở màn Quản lý file: file của thư viện (uploads/, bảng file_uploads) cùng ảnh sản
 * phẩm, ảnh trang, logo / favicon (đọc thẳng từ disk public). Avatar người dùng không liệt kê. File ngoài thư viện chỉ
 * xem / lấy link ở đây — sửa / gỡ ở màn hình đang dùng nó (sản phẩm, trang, cài đặt).
 */
class FileLibrary
{
    /** Nguồn (bộ lọc "Nguồn" của danh sách) => [nhãn, thư mục trên disk public]. */
    public const SOURCES = [
        'library' => ['Thư viện', FileUpload::DIRECTORY],
        'product' => ['Sản phẩm', Product::IMAGE_DIRECTORY],
        'page' => ['Trang', Page::IMAGE_DIRECTORY],
        'setting' => ['Cài đặt', SiteSettings::IMAGE_DIRECTORY],
    ];

    /**
     * Mỗi phần tử: source, path, url, name (tên hiển thị), extension, size, time (timestamp sửa lần cuối), is_image,
     * upload (FileUpload | null — có thì xóa được ở đây), uploader, usage (['label', 'route', 'can'] | null: đang dùng ở đâu).
     * Mới nhất trước.
     *
     * @return Collection<int, object>
     */
    public static function all(): Collection
    {
        $disk = Storage::disk('public');
        $uploads = FileUpload::with('user:id,name')->get()->keyBy('path');
        $productImages = ProductImage::with(['product' => fn ($q) => $q->withTrashed()->select('id', 'name', 'deleted_at')])->get(['path', 'product_id'])->keyBy('path');
        $pages = Page::whereNotNull('image')->get(['id', 'title', 'image'])->keyBy('image');
        $settings = Setting::whereIn('key', SiteSettings::IMAGE_KEYS)->pluck('key', 'value');

        $items = collect();
        foreach (self::SOURCES as $source => [, $directory]) {
            $root = $disk->path($directory);
            if (! is_dir($root)) {
                continue;
            }
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }
                $path = $directory.'/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                $extension = strtolower($file->getExtension());
                $upload = $source === 'library' ? ($uploads[$path] ?? null) : null;
                $usage = match ($source) {
                    'product' => ($product = $productImages[$path]->product ?? null)
                        ? ['label' => 'Sản phẩm: '.$product->name.($product->trashed() ? ' (đã xóa)' : ''), 'route' => $product->trashed() ? null : route('backend.products.edit', $product), 'can' => 'product/edit']
                        : null,
                    'page' => isset($pages[$path]) ? ['label' => 'Trang: '.$pages[$path]->title, 'route' => route('backend.pages.edit', $pages[$path]), 'can' => 'page/edit'] : null,
                    'setting' => isset($settings[$path]) ? ['label' => $settings[$path] === 'site_logo' ? 'Logo website' : 'Favicon', 'route' => route('backend.settings.edit'), 'can' => 'setting'] : null,
                    default => null,
                };
                $items->push((object) [
                    'source' => $source,
                    'path' => $path,
                    'url' => $disk->url($path),
                    'name' => $upload->name ?? $file->getFilename(),
                    'extension' => $extension,
                    'size' => (int) $file->getSize(),
                    'time' => (int) $file->getMTime(),
                    'is_image' => in_array($extension, FileUpload::TYPES['image'], true),
                    'upload' => $upload,
                    'uploader' => $upload?->user?->name,
                    'usage' => $usage,
                ]);
            }
        }

        return $items->sort(fn ($a, $b) => [$b->time, $b->path] <=> [$a->time, $a->path])->values();
    }
}
