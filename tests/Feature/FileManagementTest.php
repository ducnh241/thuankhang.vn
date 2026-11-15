<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\FileUpload;
use App\Models\Group;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Màn Quản lý file (/backend/file): danh sách (tab, tìm), tải lên (loại / dung lượng / số file), xóa, phân quyền. */
class FileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create(['name' => 'Quản trị']);
    }

    private function userWith(string ...$permissions): User
    {
        return User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', ...$permissions)->create()->id]);
    }

    /** File của thư viện có thật trên đĩa (danh sách đọc từ đĩa). */
    private function libraryFile(string $name, string $extension = 'jpg'): FileUpload
    {
        $file = $extension === 'jpg' ? FileUpload::factory()->create(['name' => $name]) : FileUpload::factory()->document($extension)->create(['name' => $name]);
        Storage::disk('public')->put($file->path, 'x');

        return $file;
    }

    public function test_upload_stores_files_with_random_names_and_lists_them(): void
    {
        $root = $this->root();

        $this->actingAs($root)->post('/backend/file', ['files' => [
            UploadedFile::fake()->image('Bảng giá tháng 11.jpg', 40, 40),
            UploadedFile::fake()->create('Chính sách đại lý.pdf', 120, 'application/pdf'),
        ]])->assertRedirect(route('backend.files.index'))->assertSessionHas('success', 'Đã tải lên 2 file.');

        $this->assertSame(2, FileUpload::count());
        $image = FileUpload::where('extension', 'jpg')->firstOrFail();
        $pdf = FileUpload::where('extension', 'pdf')->firstOrFail();
        $this->assertSame('Bảng giá tháng 11.jpg', $image->name);
        $this->assertSame($root->id, $image->user_id);
        $this->assertSame(120 * 1024, $pdf->size);
        // Tên trên đĩa: uploads/năm/tháng/<ngẫu nhiên>.<đuôi theo nội dung>, không dùng tên người gửi
        $this->assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/[A-Za-z0-9]{40}\.jpg$#', $image->path);
        Storage::disk('public')->assertExists([$image->path, $pdf->path]);
        $this->assertSame(2, AuditLog::where('action', 'created')->where('auditable_type', FileUpload::class)->count());

        $this->actingAs($root)->get('/backend/file')->assertOk()
            ->assertSee('Bảng giá tháng 11.jpg')->assertSee('Chính sách đại lý.pdf')
            ->assertSee('<img src="'.$image->url().'"', false)
            ->assertSee('<span class="file-ext">pdf</span>', false)
            ->assertSee('data-copy-text="'.$pdf->url().'"', false)
            ->assertSee('Quản trị tải lên')
            ->assertSee('data-upload-trigger', false)
            ->assertSee('data-bulk-form', false);
    }

    public function test_tabs_and_search_filter_the_list(): void
    {
        $this->libraryFile('banner-tet.jpg');
        $this->libraryFile('bang-gia.pdf', 'pdf');
        $this->libraryFile('ton-kho_100%.xlsx', 'xlsx');
        $root = $this->root();

        $this->actingAs($root)->get('/backend/file?tab=image')->assertSee('banner-tet.jpg')->assertDontSee('bang-gia.pdf');
        $this->actingAs($root)->get('/backend/file?tab=document')->assertDontSee('banner-tet.jpg')->assertSee('bang-gia.pdf')->assertSee('ton-kho_100%.xlsx');
        $this->actingAs($root)->get('/backend/file?q=bang')->assertSee('bang-gia.pdf')->assertDontSee('banner-tet.jpg');
        // % và _ trong từ khóa là ký tự thường, không phải ký tự đại diện
        $this->actingAs($root)->get('/backend/file?q=100%25')->assertSee('ton-kho_100%.xlsx')->assertDontSee('bang-gia.pdf');
        $this->actingAs($root)->get('/backend/file?q=_')->assertSee('ton-kho_100%.xlsx')->assertDontSee('bang-gia.pdf');
        $this->actingAs($root)->get('/backend/file?q[]=x&tab[]=y')->assertOk()->assertSee('bang-gia.pdf');
        $this->actingAs($root)->get('/backend/file?q=khong-co')->assertSee('Không tìm thấy file nào');
        $this->actingAs($root)->get('/backend/file?too_large=1')->assertSee('vượt quá mức máy chủ nhận');
    }

    public function test_rejects_dangerous_types_oversize_and_too_many_files(): void
    {
        $root = $this->root();
        $post = fn (array $files) => $this->actingAs($root)->from('/backend/file')->post('/backend/file', ['files' => $files]);

        // File thật trên đĩa (không phải file giả của test) để loại file được đoán theo nội dung
        $disguised = tempnam(sys_get_temp_dir(), 'tk');
        file_put_contents($disguised, "<?php echo 'x'; ?>\n");

        foreach ([
            UploadedFile::fake()->create('shell.php', 5, 'application/x-php'),
            UploadedFile::fake()->create('page.html', 5, 'text/html'),
            UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('run.exe', 5, 'application/x-msdownload'),
            // Tên giả đuôi ảnh nhưng nội dung là PHP: xét theo nội dung
            new UploadedFile($disguised, 'anh.jpg', 'image/jpeg', null, true),
        ] as $file) {
            $post([$file])->assertRedirect('/backend/file')->assertSessionHasErrors('files.0');
        }

        $post([UploadedFile::fake()->create('to.pdf', FileUpload::maxKilobytes() + 1, 'application/pdf')])->assertSessionHasErrors('files.0');
        $post(array_map(fn ($i) => UploadedFile::fake()->image("a{$i}.png", 10, 10), range(1, FileUpload::MAX_FILES + 1)))->assertSessionHasErrors('files');
        $post([])->assertSessionHasErrors('files');
        $this->actingAs($root)->from('/backend/file')->post('/backend/file', ['files' => 'abc'])->assertSessionHasErrors('files');

        // Một file sai trong lô → không lưu file nào; lỗi ghi rõ tên file, mỗi file một dòng
        $post([UploadedFile::fake()->image('tot.png', 10, 10), UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])
            ->assertSessionHasErrors(['files.1' => '“logo.svg” không thuộc loại được nhận — chỉ nhận ảnh (JPG, PNG, WEBP, GIF) và tài liệu (PDF, Word, Excel, PowerPoint, TXT, CSV, ZIP).'])
            ->assertSessionDoesntHaveErrors('files.0');
        $post([UploadedFile::fake()->create('to.pdf', FileUpload::maxKilobytes() + 1, 'application/pdf')])
            ->assertSessionHasErrors(['files.0' => '“to.pdf” vượt quá '.FileUpload::formatSize(FileUpload::maxKilobytes() * 1024).' mỗi file.']);
        $this->actingAs($root)->from('/backend/file')->post('/backend/file', ['files' => ['abc', 'def']])->assertSessionHasErrors('files');

        // Gửi quá post_max_size của PHP: về lại màn hình kèm ?too_large=1 thay cho trang 413
        $this->actingAs($root)->call('POST', '/backend/file', [], [], [], ['CONTENT_LENGTH' => (string) (FileUpload::maxPostBytes() + 1)])
            ->assertRedirect(route('backend.files.index', ['too_large' => 1]));

        $this->assertSame(0, FileUpload::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        @unlink($disguised);
    }

    public function test_delete_and_bulk_delete_remove_files_from_disk(): void
    {
        $root = $this->root();
        $this->actingAs($root)->post('/backend/file', ['files' => [
            UploadedFile::fake()->image('a.png', 10, 10), UploadedFile::fake()->image('b.png', 10, 10), UploadedFile::fake()->image('c.png', 10, 10),
        ]]);
        [$a, $b, $c] = FileUpload::orderBy('id')->get()->all();

        $this->actingAs($root)->from('/backend/file')->delete("/backend/file/{$a->id}")->assertRedirect('/backend/file')->assertSessionHas('success');
        Storage::disk('public')->assertMissing($a->path);
        $this->assertNull(FileUpload::find($a->id));
        $this->assertSame(1, AuditLog::where('action', 'deleted')->where('auditable_type', FileUpload::class)->count());

        $this->actingAs($root)->from('/backend/file')->post('/backend/file/bulk-destroy', ['ids' => [$b->id, $c->id, 999999]])->assertSessionHas('success', 'Đã xóa 2 file.');
        Storage::disk('public')->assertMissing([$b->path, $c->path]);
        $this->assertSame(0, FileUpload::count());
        $log = AuditLog::where('action', 'bulk_deleted')->firstOrFail();
        $this->assertSame([$b->id, $c->id], $log->changes['old']['ids']);

        $this->actingAs($root)->from('/backend/file')->post('/backend/file/bulk-destroy', ['ids' => [999999]])->assertSessionHas('error');
        $this->actingAs($root)->post('/backend/file/bulk-destroy', [])->assertSessionHasErrors('ids');
        $this->actingAs($root)->delete('/backend/file/999999')->assertNotFound();
    }

    public function test_delete_never_touches_files_outside_uploads_directory(): void
    {
        Storage::disk('public')->put('products/keep.jpg', 'x');
        $file = FileUpload::factory()->create(['path' => 'products/keep.jpg']);

        $this->actingAs($this->root())->delete("/backend/file/{$file->id}");

        Storage::disk('public')->assertExists('products/keep.jpg');
        $this->assertNull(FileUpload::find($file->id));
    }

    public function test_lists_product_page_and_setting_images_read_only(): void
    {
        $disk = Storage::disk('public');
        $product = Product::factory()->create(['category_id' => Category::factory()->create(['type' => Category::TYPE_PRODUCT])->id, 'name' => 'Nước giặt ROW']);
        $disk->put('products/row.webp', 'x');
        $product->images()->create(['path' => 'products/row.webp', 'sort_order' => 0]);
        $disk->put('products/mo-coi.png', 'x'); // không còn sản phẩm nào dùng
        $page = Page::factory()->create(['title' => 'Giới thiệu', 'image' => 'pages/about.jpg']);
        $disk->put('pages/about.jpg', 'x');
        $disk->put('settings/logo.png', 'x');
        Setting::updateOrCreate(['key' => 'site_logo'], ['value' => 'settings/logo.png']);
        $disk->put('avatars/me.jpg', 'x'); // avatar không liệt kê
        $mine = $this->libraryFile('tu-tai.jpg');
        $root = $this->root();

        $html = $this->actingAs($root)->get('/backend/file')->assertOk()
            ->assertSee('row.webp')->assertSee('Sản phẩm: Nước giặt ROW')->assertSee(route('backend.products.edit', $product), false)
            ->assertSee('mo-coi.png')->assertSee('Không còn dùng')
            ->assertSee('about.jpg')->assertSee('Trang: Giới thiệu')->assertSee(route('backend.pages.edit', $page), false)
            ->assertSee('logo.png')->assertSee('Logo website')
            ->assertSee('tu-tai.jpg')->assertDontSee('me.jpg')
            ->assertSee('5 file')
            ->getContent();
        // Chỉ file của thư viện có nút xóa / ô chọn dùng được
        $this->assertSame(1, substr_count($html, 'data-delete-url='));
        $this->assertSame(4, substr_count($html, 'data-row-check aria-label="Chọn') - substr_count($html, 'value="'.$mine->id.'" data-row-check'));
        $this->assertSame(4, preg_match_all('/data-row-check aria-label="[^"]*"\s+disabled/', $html));

        // Lọc theo nguồn; tìm theo tên sản phẩm đang dùng ảnh
        $this->actingAs($root)->get('/backend/file?source=product')->assertSee('row.webp')->assertSee('mo-coi.png')->assertDontSee('about.jpg')->assertDontSee('tu-tai.jpg');
        $this->actingAs($root)->get('/backend/file?source=library')->assertSee('tu-tai.jpg')->assertDontSee('row.webp');
        $this->actingAs($root)->get('/backend/file?source=khac')->assertSee('row.webp')->assertSee('tu-tai.jpg');
        $this->actingAs($root)->get('/backend/file?q=nước giặt')->assertSee('row.webp')->assertDontSee('mo-coi.png')->assertDontSee('about.jpg');

        // Người chỉ có quyền file: thấy ảnh nhưng không có link sang màn sản phẩm; sản phẩm đã xóa mềm ghi rõ
        $product->delete();
        $this->actingAs($this->userWith('file'))->get('/backend/file')
            ->assertSee('Sản phẩm: Nước giặt ROW (đã xóa)')->assertDontSee(route('backend.pages.edit', $page), false);

        // Ảnh ngoài thư viện không xóa được qua màn này
        $this->actingAs($root)->post('/backend/file/bulk-destroy', ['ids' => ['products/row.webp']])->assertSessionHasErrors('ids.0');
        $disk->assertExists(['products/row.webp', 'pages/about.jpg', 'settings/logo.png']);
    }

    public function test_pagination_over_disk_listing(): void
    {
        foreach (range(1, 31) as $i) {
            Storage::disk('public')->put("products/p{$i}.jpg", 'x');
        }
        $root = $this->root();
        $this->actingAs($root)->get('/backend/file')->assertSee('Hiển thị 1–30 / 31 file');
        $this->actingAs($root)->get('/backend/file?page=2')->assertSee('Hiển thị 31–31 / 31 file');
        $this->actingAs($root)->get('/backend/file?page=9')->assertRedirect(route('backend.files.index', ['page' => 2]));
        $this->actingAs($root)->get('/backend/file?page=abc&tab=document')->assertOk()->assertSee('Không tìm thấy file nào');
    }

    public function test_permissions(): void
    {
        $file = $this->libraryFile('anh.jpg');
        $upload = ['files' => [UploadedFile::fake()->image('x.png', 10, 10)]];

        $none = $this->userWith('page');
        $this->actingAs($none)->get('/backend/file')->assertForbidden();
        $this->actingAs($none)->get('/backend')->assertDontSee(route('backend.files.index'), false);

        $viewer = $this->userWith('file');
        $this->actingAs($viewer)->get('/backend')->assertSee(route('backend.files.index'), false);
        $this->actingAs($viewer)->get('/backend/file')->assertOk()->assertSee('anh.jpg')
            ->assertDontSee('data-upload-trigger', false)->assertDontSee('data-delete-url', false)->assertDontSee('data-bulk-form', false);
        $this->actingAs($viewer)->post('/backend/file', $upload)->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/file/{$file->id}")->assertForbidden();
        $this->actingAs($viewer)->post('/backend/file/bulk-destroy', ['ids' => [$file->id]])->assertForbidden();

        $uploader = $this->userWith('file', 'file/upload');
        $this->actingAs($uploader)->post('/backend/file', $upload)->assertRedirect(route('backend.files.index'));
        $this->actingAs($uploader)->delete("/backend/file/{$file->id}")->assertForbidden();
        $this->assertSame(2, FileUpload::count());

        $this->post('/backend/logout');
        $this->get('/backend/file')->assertRedirect(route('login'));

        // Vai trò mặc định: Vận hành có đủ quyền file, Kế toán không
        $this->seed(RoleSeeder::class);
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->assertTrue($ops->can('file/upload') && $ops->can('file/delete'));
        $this->assertFalse(User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')])->can('file'));
    }

    public function test_size_label_and_php_limit(): void
    {
        $this->assertSame('512 B', FileUpload::formatSize(512));
        $this->assertSame('532 KB', FileUpload::formatSize(532 * 1024));
        $this->assertSame('1,4 MB', FileUpload::formatSize((int) (1.4 * 1024 * 1024)));
        $this->assertSame('10 MB', FileUpload::formatSize(10 * 1024 * 1024));
        $this->assertLessThanOrEqual(FileUpload::MAX_KILOBYTES, FileUpload::maxKilobytes());
        $this->assertGreaterThan(0, FileUpload::maxKilobytes());
    }
}
