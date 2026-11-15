<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FileUpload;
use App\Support\FileLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Quản lý file (quyền: file, file/upload, file/delete): tải ảnh / tài liệu lên để lấy link dùng trong trang, mô tả sản
 * phẩm hoặc gửi cho khách; danh sách có tab Ảnh / Tài liệu, lọc theo nguồn, tìm theo tên, sao chép link.
 * Danh sách gồm cả ảnh sản phẩm, ảnh trang, logo / favicon đã tải ở các màn hình khác (App\Support\FileLibrary) — các file
 * đó chỉ xem / lấy link; chỉ file của thư viện (tải lên tại đây) mới xóa được ở đây.
 */
class FileController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'image' => 'Ảnh', 'document' => 'Tài liệu'];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $source = $this->queryString($request, 'source');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : '',
            'source' => array_key_exists($source, FileLibrary::SOURCES) ? $source : '',
        ];

        // Danh sách đọc từ đĩa (thư viện + ảnh sản phẩm / trang / cài đặt) nên lọc, đếm và phân trang trong bộ nhớ
        $all = FileLibrary::all();
        $needle = mb_strtolower($filter['q']);
        $matched = $all
            ->when($filter['source'] !== '', fn ($items) => $items->where('source', $filter['source']))
            ->when($needle !== '', fn ($items) => $items->filter(fn ($item) => str_contains(mb_strtolower($item->name.' '.($item->usage['label'] ?? '')), $needle)));

        $images = $matched->where('is_image', true)->count();
        $counts = ['' => $matched->count(), 'image' => $images, 'document' => $matched->count() - $images];
        $rows = $matched->when($filter['tab'] !== '', fn ($items) => $items->where('is_image', $filter['tab'] === 'image'))->values();

        $perPage = 30;
        $page = max(1, (int) $this->queryString($request, 'page'));
        $files = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(),
            'query' => array_filter($filter, fn ($v) => $v !== ''),
        ]);

        if ($files->isEmpty() && $page > 1) {
            return redirect($files->url(max(1, $files->lastPage())));
        }

        return view('backend.files.index', [
            'files' => $files,
            'filter' => $filter,
            'counts' => $counts,
            'totalFiles' => $all->count(),
            'usedBytes' => (int) $all->sum('size'),
            // Gửi quá post_max_size: PHP bỏ cả request, bootstrap/app.php đưa về đây kèm ?too_large=1
            'tooLarge' => $request->boolean('too_large'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $max = FileUpload::maxKilobytes();
        $request->validate(['files' => ['required', 'array', 'max:'.FileUpload::MAX_FILES]], [
            'files.required' => 'Chưa chọn file nào.',
            'files.array' => 'Chưa chọn file nào.',
            'files.max' => 'Mỗi lần tải tối đa '.FileUpload::MAX_FILES.' file.',
        ]);

        // Kiểm tra từng file để báo lỗi kèm tên file (một file không hợp lệ → không lưu file nào)
        $errors = [];
        foreach ($request->file('files') ?? [] as $i => $file) {
            $name = $file instanceof UploadedFile ? $this->cleanName($file->getClientOriginalName()) : 'File '.($i + 1);
            $check = Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.implode(',', FileUpload::extensions()), 'max:'.$max]], [
                'file.mimes' => 'không thuộc loại được nhận — chỉ nhận ảnh (JPG, PNG, WEBP, GIF) và tài liệu (PDF, Word, Excel, PowerPoint, TXT, CSV, ZIP).',
                'file.max' => 'vượt quá '.FileUpload::formatSize($max * 1024).' mỗi file.',
                'file.*' => 'tải lên bị lỗi (có thể vượt quá '.FileUpload::formatSize($max * 1024).').',
            ]);
            if ($check->fails()) {
                $errors["files.{$i}"] = "“{$name}” ".$check->errors()->first('file');
            }
        }
        if ($errors !== [] || ! $request->hasFile('files')) {
            throw ValidationException::withMessages($errors ?: ['files' => 'Chưa chọn file nào.']);
        }

        $directory = FileUpload::DIRECTORY.'/'.now()->format('Y/m');
        $stored = [];
        try {
            DB::transaction(function () use ($request, $directory, &$stored) {
                foreach ($request->file('files') as $file) {
                    // Tên trên đĩa ngẫu nhiên, đuôi lấy theo nội dung file (đã qua rule mimes) — không dùng tên người gửi
                    $path = $file->store($directory, 'public');
                    $stored[] = $path;
                    FileUpload::create([
                        'user_id' => $request->user()->id,
                        'name' => $this->cleanName($file->getClientOriginalName()),
                        'path' => $path,
                        'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                        'mime' => (string) $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($stored);
            throw $e;
        }

        return redirect()->route('backend.files.index')->with('success', 'Đã tải lên '.count($stored).' file.');
    }

    public function destroy(FileUpload $file): RedirectResponse
    {
        $file->remove();

        return back()->with('success', "Đã xóa file “{$file->name}”.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']]);
        $files = FileUpload::whereIn('id', $data['ids'])->get();
        if ($files->isEmpty()) {
            return back()->with('error', 'Các file đã chọn không còn tồn tại.');
        }

        // Xóa bằng query + một dòng nhật ký chung (không ghi từng file)
        Storage::disk('public')->delete($files->pluck('path')->filter(fn ($p) => str_starts_with($p, FileUpload::DIRECTORY.'/'))->all());
        FileUpload::whereKey($files->modelKeys())->delete();
        AuditLog::recordBulkDelete(FileUpload::class, $files->modelKeys(), 'Xóa '.$files->count().' file: '.$files->pluck('name')->implode(', '));

        return back()->with('success', 'Đã xóa '.$files->count().' file.');
    }

    /** Tên gốc để hiển thị / tải về: bỏ đường dẫn và ký tự điều khiển, giới hạn độ dài. */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename(str_replace('\\', '/', $name))));

        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }
}
