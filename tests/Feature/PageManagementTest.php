<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Màn Trang (/backend/page): danh sách, thêm / sửa (ảnh đại diện, SEO), xóa, gắn vào menu (/page/{slug}). */
class PageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function userWith(string ...$permissions): User
    {
        return User::factory()->create(['group_id' => Group::factory()->withPermissions(...$permissions)->create()->id]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function text(?\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node?->textContent ?? ''));
    }

    /** Dữ liệu form đầy đủ (công tắc gửi status=0 rồi 1 khi bật). */
    private function form(array $data = []): array
    {
        return ['title' => 'Giới thiệu', 'slug' => '', 'excerpt' => '', 'content' => '', 'status' => '1', 'meta_title' => '', 'meta_description' => '', ...$data];
    }

    private function menuItem(Page $page, string $label = 'Giới thiệu', array $extra = []): MenuItem
    {
        return Menu::forPosition('main')->items()->create(['label' => $label, 'page_id' => $page->id, 'position' => 0, ...$extra]);
    }

    public function test_permissions_and_sidebar(): void
    {
        $page = Page::factory()->create();
        $none = $this->userWith('backend_access', 'project');
        $viewer = $this->userWith('backend_access', 'page');

        $this->actingAs($none)->get('/backend/page')->assertForbidden();
        $this->actingAs($none)->get('/backend')->assertDontSee(route('backend.pages.index').'"', false);
        $this->actingAs($viewer)->get('/backend')->assertSee('href="'.route('backend.pages.index').'"', false);
        $html = $this->actingAs($viewer)->get('/backend/page')->assertOk()->assertDontSee('Thêm trang')->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(0, $xp->query('//tr[@data-href]')->length);
        $this->assertSame(0, $xp->query('//*[@data-delete-url]')->length);
        $this->actingAs($viewer)->get('/backend/page/create')->assertForbidden();
        $this->actingAs($viewer)->post('/backend/page', $this->form())->assertForbidden();
        $this->actingAs($viewer)->get("/backend/page/{$page->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/backend/page/{$page->id}", $this->form())->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/page/{$page->id}")->assertForbidden();
        $this->assertModelExists($page);
    }

    public function test_create_page_with_image_and_auto_slug(): void
    {
        $root = $this->root();
        $this->actingAs($root)->get('/backend/page/create')->assertOk()
            ->assertSee('<span class="page-slug-base" title="'.url('/page').'/"><bdi>'.url('/page').'/</bdi></span>', false)
            ->assertSee('name="status" value="1" class="switch-input" hidden checked', false);

        $response = $this->actingAs($root)->post('/backend/page', $this->form([
            'title' => '  Giới thiệu Đức NH ', 'excerpt' => ' Công ty làm web ', 'content' => '<p>Xin chào</p>',
            'meta_title' => '  ', 'meta_description' => 'Mô tả SEO', 'image' => UploadedFile::fake()->image('anh.jpg', 1200, 630),
        ]));
        $page = Page::firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('backend.pages.edit', $page))->assertSessionHas('success', 'Đã tạo trang Giới thiệu Đức NH.');
        $this->assertSame(
            ['Giới thiệu Đức NH', 'gioi-thieu-duc-nh', 'Công ty làm web', '<p>Xin chào</p>', true, null, 'Mô tả SEO', '/page/gioi-thieu-duc-nh'],
            [$page->title, $page->slug, $page->excerpt, $page->content, $page->status, $page->meta_title, $page->meta_description, $page->url()],
        );
        $this->assertStringStartsWith('pages/', $page->image);
        Storage::disk('public')->assertExists($page->image);

        // Trình soạn thảo trống vẫn gửi "<p></p>" → không có nội dung; đường dẫn gõ có dấu → chuẩn hóa
        $this->actingAs($root)->post('/backend/page', $this->form(['title' => 'Chính sách', 'slug' => 'Chính Sách Bảo Mật!', 'content' => '<p></p>', 'status' => '0']))->assertSessionHasNoErrors();
        $policy = Page::where('title', 'Chính sách')->firstOrFail();
        $this->assertSame(['chinh-sach-bao-mat', null, false], [$policy->slug, $policy->content, $policy->status]);
    }

    public function test_validation_and_old_input(): void
    {
        $root = $this->root();
        Page::factory()->create(['slug' => 'lien-he']);
        $from = route('backend.pages.create');

        $this->actingAs($root)->from($from)->post('/backend/page', $this->form(['title' => 'Liên hệ']))->assertRedirect($from)
            ->assertSessionHasErrors(['slug' => 'Đường dẫn này đã có trang khác dùng.']);
        $this->actingAs($root)->post('/backend/page', $this->form(['title' => '']))->assertSessionHasErrors(['title', 'slug' => 'Nhập đường dẫn (hoặc tiêu đề có chữ / số để tự tạo).']);
        $this->actingAs($root)->post('/backend/page', $this->form(['title' => '!!!']))->assertSessionHasErrors('slug');
        $this->actingAs($root)->post('/backend/page', $this->form(['meta_title' => str_repeat('a', 71), 'meta_description' => str_repeat('b', 161)]))
            ->assertSessionHasErrors(['meta_title', 'meta_description']);
        $this->actingAs($root)->post('/backend/page', $this->form(['image' => UploadedFile::fake()->create('a.svg', 10, 'image/svg+xml')]))
            ->assertSessionHasErrors(['image' => 'Chọn ảnh PNG, JPG, WEBP hoặc GIF.']);
        $this->actingAs($root)->post('/backend/page', $this->form(['image' => UploadedFile::fake()->image('to.jpg')->size(3000)]))
            ->assertSessionHasErrors(['image' => 'Ảnh tối đa 2 MB.']);
        $this->assertSame(1, Page::count());

        // Lỗi ở ô SEO: phần SEO mở sẵn, các ô giữ dữ liệu vừa nhập (kể cả ô xóa trắng)
        $html = $this->actingAs($root)->from($from)->followingRedirects()
            ->post('/backend/page', $this->form(['title' => 'Mới', 'slug' => 'moi', 'content' => '<p>A</p>', 'meta_title' => str_repeat('a', 71), 'status' => '0']))
            ->assertSee('Tiêu đề SEO không được dài quá 70 ký tự', false)->getContent();
        $xp = $this->xpath($html);
        $this->assertSame('true', $xp->query('//*[@id="seo-edit"]')->item(0)->getAttribute('aria-expanded'));
        $this->assertStringNotContainsString('hidden', $xp->query('//*[@id="seo-fields"]')->item(0)->getAttribute('class'));
        $this->assertSame('moi', $xp->query('//input[@name="slug"]')->item(0)->getAttribute('value'));
        $this->assertSame('<p>A</p>', $xp->query('//textarea[@name="content"]')->item(0)->textContent);
        $this->assertSame(0, $xp->query('//input[@name="status"][@type="checkbox"][@checked]')->length);
    }

    public function test_update_slug_status_and_image(): void
    {
        $root = $this->root();
        $page = Page::factory()->create(['title' => 'Cũ', 'slug' => 'cu', 'image' => UploadedFile::fake()->image('a.png')->store('pages', 'public')]);
        $old = $page->image;

        // Đổi ảnh: ảnh cũ bị xóa
        $this->actingAs($root)->put("/backend/page/{$page->id}", $this->form(['title' => 'Mới', 'slug' => 'moi', 'status' => '0', 'image' => UploadedFile::fake()->image('b.jpg')]))
            ->assertSessionHasNoErrors()->assertRedirect(route('backend.pages.edit', $page))->assertSessionHas('success', 'Đã lưu trang.');
        $page->refresh();
        $this->assertSame(['Mới', 'moi', false], [$page->title, $page->slug, $page->status]);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($page->image);
        // Giữ đường dẫn của chính nó không báo trùng; bỏ ảnh
        $kept = $page->image;
        $this->actingAs($root)->put("/backend/page/{$page->id}", $this->form(['title' => 'Mới', 'slug' => 'moi', 'remove_image' => '1']))->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->image);
        Storage::disk('public')->assertMissing($kept);

        // Trang sửa: đường dẫn đã có → coi như đã sửa tay (đổi tiêu đề không đổi đường dẫn); tag "Đang ẩn" khi tắt
        $page->refresh()->update(['status' => false]);
        $xp = $this->xpath($this->actingAs($root)->get("/backend/page/{$page->id}/edit")->assertOk()->getContent());
        $this->assertTrue($xp->query('//input[@name="slug"]')->item(0)->hasAttribute('data-touched'));
        $this->assertSame('Đang ẩn', $this->text($xp->query('//*[contains(@class, "page-topbar-left")]//span[contains(@class, "ant-tag")]')->item(0)));
    }

    public function test_list_tabs_search_and_menu_count(): void
    {
        $root = $this->root();
        $about = Page::factory()->create(['title' => 'Giới thiệu', 'slug' => 'gioi-thieu']);
        $contact = Page::factory()->create(['title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => false]);
        Page::factory()->create(['title' => 'Chính sách', 'slug' => 'chinh-sach']);
        $this->menuItem($about);
        $this->menuItem($about, 'Về chúng tôi', ['position' => 1]);

        $page = $this->actingAs($root)->get('/backend/page')->assertOk()->assertViewHas('counts', ['' => 3, 'visible' => 2, 'hidden' => 1]);
        $xp = $this->xpath($page->getContent());
        $row = fn (Page $p) => $xp->query("//tr[@data-row-key='{$p->id}']")->item(0);
        $this->assertSame(route('backend.pages.edit', $about), $row($about)->getAttribute('data-href'));
        $this->assertStringContainsString('Giới thiệu /page/gioi-thieu', $this->text($xp->query('./td[1]', $row($about))->item(0)));
        $this->assertSame('2 mục', $this->text($xp->query('./td[@data-col="menu"]', $row($about))->item(0)));
        $this->assertSame('Đang ẩn', $this->text($xp->query('./td[@data-col="status"]', $row($contact))->item(0)));
        // Trang đang dùng trong menu: nút xóa bị khóa
        $this->assertSame(0, $xp->query('.//*[@data-delete-url]', $row($about))->length);
        $this->assertSame(route('backend.pages.destroy', $contact), $xp->query('.//*[@data-delete-url]', $row($contact))->item(0)->getAttribute('data-delete-url'));

        $this->actingAs($root)->get('/backend/page?tab=hidden')->assertViewHas('pages', fn ($p) => $p->pluck('id')->all() === [$contact->id]);
        $this->actingAs($root)->get('/backend/page?q=lien')->assertViewHas('pages', fn ($p) => $p->pluck('id')->all() === [$contact->id])
            ->assertViewHas('counts', ['' => 1, 'visible' => 0, 'hidden' => 1]);
        $this->actingAs($root)->get('/backend/page?q=Gi%E1%BB%9Bi')->assertViewHas('pages', fn ($p) => $p->pluck('id')->all() === [$about->id]);
        $this->actingAs($root)->get('/backend/page?q=khong-co')->assertSee('Không tìm thấy trang nào');
        $this->actingAs($root)->get('/backend/page?q[]=x&tab=xyz')->assertOk()->assertViewHas('filter', ['q' => '', 'tab' => '']);
        $this->actingAs($root)->get('/backend/page?page=5')->assertRedirect(route('backend.pages.index', ['page' => 1]));
        Page::query()->delete();
        $this->actingAs($root)->get('/backend/page')->assertSee('Chưa có trang nào');
    }

    public function test_delete_blocked_while_used_in_menu(): void
    {
        $root = $this->root();
        $page = Page::factory()->create(['title' => 'Giới thiệu', 'image' => UploadedFile::fake()->image('a.png')->store('pages', 'public')]);
        $item = $this->menuItem($page);

        $this->actingAs($root)->from(route('backend.pages.edit', $page))->delete("/backend/page/{$page->id}")
            ->assertRedirect(route('backend.pages.edit', $page))
            ->assertSessionHas('error', 'Không xóa được "Giới thiệu": đang dùng trong menu (Menu chính › Giới thiệu). Hãy đổi liên kết các mục menu đó trước.');
        $this->assertModelExists($page);
        $xp = $this->xpath($this->actingAs($root)->get("/backend/page/{$page->id}/edit")->getContent());
        $this->assertSame('Menu chính › Giới thiệu', $this->text($xp->query('//*[@data-card="menus"]//a')->item(0)));
        $this->assertSame(route('backend.menus.index', ['position' => 'main', 'id' => $item->id]), $xp->query('//*[@data-card="menus"]//a')->item(0)->getAttribute('href'));
        $this->assertSame(0, $xp->query('//*[@data-delete-url]')->length);

        // Bỏ link khỏi menu → xóa được, ảnh bị xóa, về danh sách
        $item->update(['page_id' => null, 'url' => '/gioi-thieu']);
        $this->actingAs($root)->from(route('backend.pages.edit', $page))->delete("/backend/page/{$page->id}")
            ->assertRedirect(route('backend.pages.index'))->assertSessionHas('success', 'Đã xóa trang Giới thiệu.');
        $this->assertModelMissing($page);
        Storage::disk('public')->assertMissing($page->image);
    }

    public function test_menu_links_to_page(): void
    {
        $root = $this->root();
        $about = Page::factory()->create(['title' => 'Giới thiệu', 'slug' => 'gioi-thieu']);
        $draft = Page::factory()->create(['title' => 'Bản nháp', 'slug' => 'ban-nhap', 'status' => false]);

        // Ô Liên kết: "Trang" › danh sách trang (trang ẩn ghi "đang ẩn")
        $tree = fn (string $html) => json_decode($this->xpath($html)->query('//*[@data-link-picker]')->item(0)->getAttribute('data-tree'), true);
        $this->assertSame(
            [['value' => 'page:'.$draft->id, 'label' => 'Bản nháp', 'note' => 'đang ẩn'], ['value' => 'page:'.$about->id, 'label' => 'Giới thiệu', 'note' => null]],
            $tree($this->actingAs($root)->get('/backend/menu?position=main&new=1')->getContent())['menus']['page']['items'],
        );
        $this->actingAs($root)->post('/backend/menu/main/item', ['label' => 'X', 'parent_id' => '', 'link' => 'page', 'is_visible' => '1'])->assertSessionHasErrors(['page_id' => 'Chọn trang.']);

        $this->actingAs($root)->post('/backend/menu/main/item', ['label' => 'Về chúng tôi', 'parent_id' => '', 'link' => 'page:'.$about->id, 'url' => '/x', 'is_visible' => '1'])->assertSessionHasNoErrors();
        $item = MenuItem::where('label', 'Về chúng tôi')->firstOrFail();
        $this->assertSame([$about->id, null, null, 'page', '/page/gioi-thieu'], [$item->page_id, $item->url, $item->category_id, $item->linkType(), $item->href()]);
        $this->assertSame([['label' => 'Về chúng tôi', 'url' => '/page/gioi-thieu', 'children' => []]], Menu::tree('main'));
        $this->actingAs($root)->get("/backend/menu?position=main&id={$item->id}")
            ->assertSee('<span class="menu-link">Trang: Giới thiệu</span>', false)
            ->assertSee('<input type="hidden" name="link" value="page:'.$about->id.'" data-link-value />', false)
            ->assertSee('<span class="ant-select-item-text">Trang › Giới thiệu</span>', false);

        // Đổi đường dẫn trang → link menu đổi theo (cache làm mới); ẩn trang → mục menu không hiện trên website
        $this->actingAs($root)->put("/backend/page/{$about->id}", $this->form(['title' => 'Giới thiệu', 'slug' => 've-chung-toi']))->assertSessionHasNoErrors();
        $this->assertSame('/page/ve-chung-toi', Menu::tree('main')[0]['url']);
        $this->actingAs($root)->put("/backend/page/{$about->id}", $this->form(['title' => 'Giới thiệu', 'slug' => 've-chung-toi', 'status' => '0']))->assertSessionHasNoErrors();
        $this->assertSame([], Menu::tree('main'));
    }
}
