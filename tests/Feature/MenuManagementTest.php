<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** Cây 3 cấp: Giới thiệu; Dịch vụ > Thiết kế web > Landing page, Dịch vụ > Hosting; Liên hệ. */
    private function tree(): array
    {
        return [
            ['label' => 'Giới thiệu', 'url' => '#about', 'children' => []],
            ['label' => 'Dịch vụ', 'url' => '/dich-vu', 'children' => [
                ['label' => 'Thiết kế web', 'url' => '/dich-vu/web', 'children' => [
                    ['label' => 'Landing page', 'url' => '/dich-vu/web/landing', 'children' => []],
                ]],
                ['label' => 'Hosting', 'url' => 'https://example.com/hosting', 'children' => []],
            ]],
            ['label' => 'Liên hệ', 'url' => 'mailto:hi@example.com', 'children' => []],
        ];
    }

    private function main(): Menu
    {
        $menu = Menu::create(['name' => 'Menu chính', 'handle' => 'main']);
        $menu->saveTree($this->tree());

        return $menu;
    }

    private function item(string $label): MenuItem
    {
        return MenuItem::where('label', $label)->firstOrFail();
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

    /** Ô Liên kết nhiều cấp: [giá trị gửi lên, chữ đang hiện trong ô, dữ liệu các cấp]. */
    private function linkPicker(DOMXPath $xp): array
    {
        $picker = $xp->query('//*[@data-link-picker]')->item(0);
        $shown = $xp->query('.//*[contains(@class, "ant-select-selection-item") or contains(@class, "ant-select-selection-placeholder")]', $picker)->item(0);

        return [$xp->query('//input[@name="link"][@data-link-value]')->item(0)->getAttribute('value'), $this->text($shown), json_decode($picker->getAttribute('data-tree'), true)];
    }

    /** Ô đi kèm Liên kết đang hiện (ô đường dẫn khi chọn "Tự nhập…"). */
    private function visibleLinkFields(DOMXPath $xp): array
    {
        return array_map(fn ($n) => $n->getAttribute('data-link-field'), iterator_to_array($xp->query('//*[@data-link-field][not(contains(concat(" ", @class, " "), " hidden "))]')));
    }

    /** Dữ liệu form đầy đủ (form luôn gửi ô Liên kết + hiển thị). */
    private function form(array $data): array
    {
        return ['parent_id' => '', 'link' => 'url', 'url' => '', 'is_visible' => '1', ...$data];
    }

    // ---- Quyền, trang ----

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('menu')->create()->id]);
        $adder = User::factory()->create(['group_id' => Group::factory()->withPermissions('menu', 'menu/add')->create()->id]);
        $other = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $this->main();
        $about = $this->item('Giới thiệu');
        $contact = $this->item('Liên hệ');

        $this->actingAs($other)->get('/backend/menu')->assertForbidden();
        $this->actingAs($other)->get('/backend')->assertDontSee(route('backend.menus.index'));

        // Chỉ xem: thấy cây, form khóa, không thêm / xóa / kéo thả được
        $this->actingAs($viewer)->get('/backend/menu')->assertOk()->assertSee('Giới thiệu')->assertSee('Bạn chỉ có quyền xem menu.')
            ->assertDontSee('id="create-item"', false)->assertDontSee('data-delete-url', false)->assertDontSee('draggable="true"', false)
            ->assertDontSee('data-move-url', false)->assertDontSee('data-edit-form', false);
        $this->actingAs($viewer)->post('/backend/menu/main/item', $this->form(['label' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->postJson("/backend/menu/item/{$about->id}/move", ['target_id' => $contact->id, 'zone' => 'after'])->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/menu/item/{$about->id}")->assertForbidden();
        $this->assertSame(['Giới thiệu', 0], [$about->fresh()->label, $about->fresh()->position]);

        // Chỉ thêm: thêm được, sửa thì không
        $this->actingAs($adder)->post('/backend/menu/main/item', $this->form(['label' => 'Mới']))->assertSessionHasNoErrors();
        $this->actingAs($adder)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X']))->assertForbidden();
    }

    public function test_page_shows_fixed_positions_and_tree(): void
    {
        $this->main();
        $root = $this->root();

        $html = $this->actingAs($root)->get('/backend/menu')->assertOk()->getContent();
        // 3 vị trí cố định, kèm số mục; đang ở Menu chính, mở mục đầu tiên
        $this->assertStringContainsString('<option value="top" data-url="'.route('backend.menus.index', ['position' => 'top']).'" >Menu đầu trang (0)</option>', $html);
        $this->assertStringContainsString('<option value="main" data-url="'.route('backend.menus.index', ['position' => 'main']).'" selected>Menu chính (6)</option>', $html);
        $this->assertStringContainsString('>Menu chân trang (0)</option>', $html);
        $this->assertStringContainsString('<h1 class="page-topbar-title">Giới thiệu</h1>', $html);
        $tree = substr($html, strpos($html, 'id="menu-tree"'));
        $this->assertSame(['Giới thiệu', 'Dịch vụ', 'Thiết kế web', 'Landing page', 'Hosting', 'Liên hệ'],
            array_map(fn ($m) => $m, preg_match_all('#<span class="cat-name">([^<]+)</span>#', $tree, $m) ? $m[1] : []));
        $this->assertStringContainsString('data-depth="2" data-parent="'.$this->item('Thiết kế web')->id.'"', $tree);
        $this->assertStringContainsString('<span class="menu-link">mailto:hi@example.com</span>', $tree);

        // Vị trí chưa có mục: trang mở form thêm mới, không tạo menu khi chỉ xem
        $this->actingAs($root)->get('/backend/menu?position=footer')->assertOk()->assertSee('Menu chân trang chưa có mục nào')
            ->assertSee('action="'.route('backend.menus.store', 'footer').'"', false);
        $this->assertFalse(Menu::where('handle', 'footer')->exists());
        // Vị trí lạ → Menu chính
        $this->actingAs($root)->get('/backend/menu?position=evil')->assertOk()->assertSee('selected>Menu chính (6)</option>', false);
        $this->actingAs($root)->post('/backend/menu/evil/item', $this->form(['label' => 'X']))->assertNotFound();
    }

    // ---- Thêm / sửa ----

    public function test_create_item_in_new_position_and_as_child(): void
    {
        $root = $this->root();
        $this->main();

        // Vị trí chưa có menu: thêm mục đầu tiên tạo menu cho vị trí
        $res = $this->actingAs($root)->post('/backend/menu/footer/item', $this->form(['label' => 'Chính sách', 'url' => '/chinh-sach']));
        $item = $this->item('Chính sách');
        $res->assertRedirect(route('backend.menus.index', ['position' => 'footer', 'id' => $item->id]))->assertSessionHas('success', 'Đã thêm mục menu.');
        $this->assertSame(['Menu chân trang', 'footer', 0, '/chinh-sach', true], [$item->menu->name, $item->menu->handle, $item->position, $item->url, $item->is_visible]);

        // Mục con: đứng cuối các mục cùng cha; trang "Thêm mục con" chọn sẵn cha
        $services = $this->item('Dịch vụ');
        $this->actingAs($root)->get("/backend/menu?position=main&new&parent={$services->id}")->assertOk()
            ->assertSee('<option value="'.$services->id.'" selected>Dịch vụ</option>', false)->assertSee('<h1 class="page-topbar-title">Mục menu mới</h1>', false);
        $this->actingAs($root)->post('/backend/menu/main/item', $this->form(['label' => 'Email', 'parent_id' => $services->id, 'url' => '/email', 'is_visible' => '0']))->assertSessionHasNoErrors();
        $email = $this->item('Email');
        $this->assertSame([$services->id, 2, false], [$email->parent_id, $email->position, $email->is_visible]);

        // Cha phải thuộc cùng menu
        $this->actingAs($root)->post('/backend/menu/footer/item', $this->form(['label' => 'Lạc', 'parent_id' => $services->id]))->assertSessionHasErrors('parent_id');
    }

    public function test_link_sources(): void
    {
        $root = $this->root();
        $this->main();
        $about = $this->item('Giới thiệu');
        $category = Category::factory()->create(['name' => 'Bán hàng', 'slug' => 'ban-hang']);
        $news = Category::factory()->create(['name' => 'Công nghệ', 'slug' => 'cong-nghe', 'type' => Category::TYPE_POST]);
        $event = Category::factory()->create(['type' => Category::TYPE_EVENT]);

        // Danh mục (ô Liên kết nhiều cấp: "Danh mục dự án" › danh mục): chỉ giữ category_id (bỏ url đang có), link theo
        // đường dẫn của danh mục
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Dự án', 'link' => 'category:'.$category->id, 'url' => 'javascript:x']))
            ->assertSessionHasNoErrors()->assertRedirect(route('backend.menus.index', ['position' => 'main', 'id' => $about->id]));
        $about->refresh();
        $this->assertSame([null, $category->id, null, 'category', '/du-an/ban-hang'], [$about->url, $about->category_id, $about->page_id, $about->linkType(), $about->href()]);
        $this->assertSame('/du-an/ban-hang', Menu::tree('main')[0]['url']);
        $xp = $this->xpath($this->actingAs($root)->get("/backend/menu?position=main&id={$about->id}")
            ->assertSee('<span class="menu-link">Danh mục: Bán hàng</span>', false)->getContent());
        // Cấp ngoài: các loại link (loại có danh sách mở tiếp trong ô); cấp trong: danh mục từng loại, trang
        [$value, $shown, $tree] = $this->linkPicker($xp);
        $this->assertSame(['category:'.$category->id, 'Danh mục dự án › Bán hàng'], [$value, $shown]);
        $this->assertSame(['home Trang chủ', 'category:project Danh mục dự án', 'category:news Danh mục tin tức', 'page Trang', 'url Tự nhập đường dẫn…'],
            array_map(fn ($i) => ($i['value'] ?? $i['menu']).' '.$i['label'], $tree['items']));
        $this->assertSame([['value' => 'category:'.$category->id, 'label' => 'Bán hàng', 'depth' => 0, 'note' => null]], $tree['menus']['category:project']['items']);
        $this->assertSame(['category:'.$news->id], array_column($tree['menus']['category:news']['items'], 'value'));
        $this->assertSame(['Trang', 'Chưa có trang nào', []], [$tree['menus']['page']['title'], $tree['menus']['page']['empty'], $tree['menus']['page']['items']]);
        $this->assertSame([], $this->visibleLinkFields($xp)); // ô đường dẫn chỉ hiện khi chọn "Tự nhập…"

        // Lỗi liên kết hiện dưới ô Liên kết
        $html = $this->actingAs($root)->from(route('backend.menus.index', ['position' => 'main', 'id' => $about->id]))->followingRedirects()
            ->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'category']))->getContent();
        $xp = $this->xpath($html);
        $this->assertSame('Chọn danh mục.', $this->text($xp->query('//*[@data-link-picker]/ancestor::*[contains(concat(" ", @class, " "), " ant-form-item-control ")][1]//*[@role="alert"]')->item(0)));
        $this->assertSame(['category', 'Chọn liên kết'], array_slice($this->linkPicker($xp), 0, 2));

        // Đổi slug danh mục → link menu đổi theo (cache được làm mới); danh mục ẩn → mục không hiện trên website
        $category->update(['slug' => 'ban-hang-moi']);
        $this->assertSame('/du-an/ban-hang-moi', Menu::tree('main')[0]['url']);
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Tin', 'link' => 'category:'.$news->id]));
        $this->assertSame(['Tin', '/tin-tuc/cong-nghe'], [Menu::tree('main')[0]['label'], Menu::tree('main')[0]['url']]);
        $news->update(['status' => false]);
        $this->assertNotContains('Tin', array_column(Menu::tree('main'), 'label'));
        // Xóa danh mục: mục menu thành mục không có link
        $news->delete();
        $this->assertSame([null, 'url', null], [$about->fresh()->category_id, $about->fresh()->linkType(), $about->fresh()->href()]);
        $this->assertSame(['label' => 'Tin', 'url' => null, 'children' => []], Menu::tree('main')[0]);

        // Danh mục bắt buộc khi chọn nguồn danh mục; loại không có trang quản lý không nhận; trang không có thì báo lỗi
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'category']))->assertSessionHasErrors(['category_id' => 'Chọn danh mục.']);
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'category:abc']))->assertSessionHasErrors('category_id');
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'category:'.$event->id]))->assertSessionHasErrors('category_id');
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'page:1']))->assertSessionHasErrors(['page_id' => 'Trang đã bị xóa, chọn trang khác.']);
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'khac']))->assertSessionHasErrors('link_type');
        // Neo trên trang chủ (#…) là đường dẫn tự nhập: bước 1 chỉ có các loại link; ô đường dẫn giữ #journey
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Hành trình', 'url' => '#journey']))->assertSessionHasNoErrors();
        $this->assertSame(['#journey', null], [$about->fresh()->url, $about->fresh()->category_id]);
        $xp = $this->xpath($this->actingAs($root)->get("/backend/menu?position=main&id={$about->id}")
            ->assertSee('id="menu_url" name="url" value="#journey"', false)->getContent());
        [$value, $shown, $tree] = $this->linkPicker($xp);
        $this->assertSame(['url', 'Tự nhập đường dẫn…'], [$value, $shown]);
        $this->assertSame(['url'], $this->visibleLinkFields($xp));
        // Mỗi loại link có icon: trang chủ, danh mục, trang, tự nhập
        $this->assertSame(['home', 'folder', 'folder', 'file-text', 'link'], array_column($tree['items'], 'icon'));
        $this->assertSame('link', $xp->query('//*[@data-link-picker]//*[contains(@class, "ant-select-selection-item")]//*[@role="img"]')->item(0)->getAttribute('aria-label'));

        // Trang chủ: lưu "/" (bỏ ô tự nhập), hiện lại là "Trang chủ"; trong cây có icon ngôi nhà
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Trang chủ', 'link' => 'home', 'url' => '/khac']))->assertSessionHasNoErrors();
        $this->assertSame('/', $about->fresh()->url);
        $html = $this->actingAs($root)->get("/backend/menu?position=main&id={$about->id}")->getContent();
        $this->assertSame(['home', 'Trang chủ'], array_slice($this->linkPicker($this->xpath($html)), 0, 2));
        $this->assertStringContainsString('aria-label="home" class="anticon anticon-home cat-icon"', $html);
        $this->assertStringContainsString('<span class="menu-link">Trang chủ</span>', $html);
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'link' => 'page:none']))->assertSessionHasErrors('page_id');

        // Đường dẫn: đổi lại về url, bỏ category
        $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Giới thiệu', 'url' => ' #about ', 'category_id' => $category->id]))->assertSessionHasNoErrors();
        $this->assertSame(['#about', null], [$about->fresh()->url, $about->fresh()->category_id]);
    }

    public function test_rejects_unsafe_links_bad_labels_and_too_deep(): void
    {
        $root = $this->root();
        $this->main();
        $about = $this->item('Giới thiệu');
        $landing = $this->item('Landing page'); // cấp 3
        $web = $this->item('Thiết kế web');      // cấp 2, có con

        foreach (['javascript:alert(1)', 'JaVaScRiPt:x', 'data:text/html,x', 'vbscript:x', '/a b', '/"x"', '<x>', str_repeat('a', 501)] as $url) {
            $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'X', 'url' => $url]))->assertSessionHasErrors('url');
        }
        foreach (['', '   ', str_repeat('a', 101)] as $label) {
            $this->actingAs($root)->put("/backend/menu/item/{$about->id}", $this->form(['label' => $label]))->assertSessionHasErrors('label');
        }
        // Quá 3 cấp: đặt vào mục cấp 3, hoặc đưa mục có con xuống dưới một mục cấp 2
        $this->actingAs($root)->post('/backend/menu/main/item', $this->form(['label' => 'Sâu', 'parent_id' => $landing->id]))->assertSessionHasErrors('parent_id');
        $this->actingAs($root)->put("/backend/menu/item/{$web->id}", $this->form(['label' => 'Thiết kế web', 'parent_id' => $this->item('Hosting')->id]))->assertSessionHasErrors('parent_id');
        // Chính nó / con cháu làm cha
        $this->actingAs($root)->put("/backend/menu/item/{$web->id}", $this->form(['label' => 'Thiết kế web', 'parent_id' => $landing->id]))
            ->assertSessionHasErrors(['parent_id' => 'Mục cha không thể là chính nó hoặc mục con của nó.']);
        $this->assertSame('#about', $about->fresh()->url);
        $this->assertSame(6, MenuItem::count());

        // Ô chọn cha không có chính nó / con cháu / mục ở cấp 3
        $html = $this->actingAs($root)->get("/backend/menu?position=main&id={$web->id}")->getContent();
        $select = substr($html, strpos($html, 'id="menu_parent"'));
        $select = substr($select, 0, strpos($select, '</select>'));
        foreach ([$web, $landing] as $blocked) {
            $this->assertStringNotContainsString('value="'.$blocked->id.'"', $select);
        }
        $this->assertStringContainsString('>Giới thiệu</option>', $select);
        $this->assertStringNotContainsString('— Hosting', $select); // đặt vào Hosting thì Landing page thành cấp 4
    }

    public function test_update_keeps_order_unless_parent_changes_and_redisplays_after_error(): void
    {
        $root = $this->root();
        $this->main();
        $hosting = $this->item('Hosting');

        $this->actingAs($root)->put("/backend/menu/item/{$hosting->id}", $this->form(['label' => 'Hosting VPS', 'parent_id' => $hosting->parent_id, 'url' => '/hosting']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['Hosting VPS', 1], [$hosting->fresh()->label, $hosting->fresh()->position]);
        $this->actingAs($root)->put("/backend/menu/item/{$hosting->id}", $this->form(['label' => 'Hosting VPS', 'url' => '/hosting']));
        $this->assertSame([null, 3], [$hosting->fresh()->parent_id, $hosting->fresh()->position]);

        // Lỗi: form hiện lại đúng dữ liệu vừa gửi (ô xóa trắng vẫn trống), đúng mục
        $html = $this->actingAs($root)->from("/backend/menu?position=main&id={$hosting->id}")->followingRedirects()
            ->put("/backend/menu/item/{$hosting->id}", $this->form(['label' => '', 'url' => 'javascript:x', 'is_visible' => '0']))->getContent();
        $this->assertStringContainsString('id="menu_label" name="label" value=""', $html);
        $this->assertStringContainsString('value="javascript:x"', $html);
        $this->assertStringContainsString('Đường dẫn không hợp lệ', $html);
        $this->assertStringContainsString('aria-checked="false"', $html);
        $this->assertStringContainsString('action="'.route('backend.menus.update', $hosting).'"', $html);
        $this->assertSame('Hosting VPS', $hosting->fresh()->label);
    }

    // ---- Kéo thả, xóa ----

    public function test_move_reorders_and_nests_within_depth(): void
    {
        $root = $this->root();
        $this->main();
        $move = fn (string $label, string $target, string $zone) => $this->actingAs($root)
            ->postJson('/backend/menu/item/'.$this->item($label)->id.'/move', ['target_id' => $this->item($target)->id, 'zone' => $zone]);
        $level = fn (?string $parent) => MenuItem::whereHas('menu', fn ($q) => $q->where('handle', 'main'))
            ->where('parent_id', $parent ? $this->item($parent)->id : null)->orderBy('position')->pluck('label')->all();

        $move('Liên hệ', 'Giới thiệu', 'before')->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['Liên hệ', 'Giới thiệu', 'Dịch vụ'], $level(null));
        $this->assertSame('Đã chuyển “Liên hệ”.', session('success'));
        $this->assertSame('Liên hệ', Menu::tree('main')[0]['label']); // cache làm mới

        $move('Liên hệ', 'Dịch vụ', 'inside')->assertOk();
        $this->assertSame(['Thiết kế web', 'Hosting', 'Liên hệ'], $level('Dịch vụ'));
        $move('Hosting', 'Giới thiệu', 'after')->assertOk();
        $this->assertSame(['Giới thiệu', 'Hosting', 'Dịch vụ'], $level(null));

        // Quá 3 cấp / vào nhánh của chính nó / sai dữ liệu
        $move('Dịch vụ', 'Hosting', 'inside')->assertStatus(422)->assertJson(['message' => 'Menu chỉ lồng tối đa 3 cấp.']);
        $move('Giới thiệu', 'Landing page', 'inside')->assertStatus(422);
        $move('Dịch vụ', 'Landing page', 'before')->assertStatus(422)->assertJson(['message' => 'Không thể chuyển mục vào chính nó hoặc mục con của nó.']);
        $this->actingAs($root)->postJson('/backend/menu/item/'.$this->item('Hosting')->id.'/move', ['target_id' => 99999, 'zone' => 'before'])->assertJsonValidationErrors('target_id');
        $this->actingAs($root)->postJson('/backend/menu/item/'.$this->item('Hosting')->id.'/move', ['target_id' => $this->item('Dịch vụ')->id, 'zone' => 'x'])->assertJsonValidationErrors('zone');
        $footer = Menu::forPosition('footer')->items()->create(['label' => 'Ở footer', 'url' => '/f']);
        $this->actingAs($root)->postJson('/backend/menu/item/'.$this->item('Hosting')->id.'/move', ['target_id' => $footer->id, 'zone' => 'inside'])->assertJsonValidationErrors('target_id');
        $this->assertSame(['Giới thiệu', 'Hosting', 'Dịch vụ'], $level(null));
    }

    public function test_delete_removes_item_with_children(): void
    {
        $root = $this->root();
        $this->main();
        $services = $this->item('Dịch vụ');

        $this->actingAs($root)->get("/backend/menu?position=main&id={$services->id}")
            ->assertSee('data-delete-content="Cả 3 mục con bên trong cũng bị xóa. Không thể hoàn tác."', false);
        $this->actingAs($root)->delete("/backend/menu/item/{$services->id}")
            ->assertRedirect(route('backend.menus.index', ['position' => 'main']))->assertSessionHas('success', 'Đã xóa mục Dịch vụ và 3 mục con.');
        $this->assertSame(['Giới thiệu', 'Liên hệ'], MenuItem::orderBy('position')->pluck('label')->all());
        $this->assertSame(['Giới thiệu', 'Liên hệ'], array_column(Menu::tree('main'), 'label'));
        $this->actingAs($root)->delete('/backend/menu/item/99999')->assertNotFound();
    }

    // ---- Website ----

    public function test_hidden_items_and_their_children_are_not_on_the_website(): void
    {
        $this->main();
        $this->item('Dịch vụ')->update(['is_visible' => false]);
        $this->item('Liên hệ')->update(['is_visible' => false]);
        Menu::forgetCache('main');

        $this->assertSame([['label' => 'Giới thiệu', 'url' => '#about', 'children' => []]], Menu::tree('main'));
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<li><a href="#about">Giới thiệu</a></li>', $html);
        $this->assertStringNotContainsString('Dịch vụ', $html);
        $this->assertStringNotContainsString('mailto:hi@example.com', $html);
    }

    public function test_frontend_renders_top_level_items_and_escapes_them(): void
    {
        $menu = Menu::create(['name' => 'Menu chính', 'handle' => 'main']);
        $menu->saveTree([
            ['label' => 'Giới thiệu <b>x</b>', 'url' => '/gioi-thieu?a=1&b=2', 'children' => [
                ['label' => 'Mục con', 'url' => '/con', 'children' => []],
            ]],
            ['label' => 'Blog', 'url' => 'https://blog.example.com', 'children' => []],
            ['label' => 'Không link', 'url' => '', 'children' => []],
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<li><a href="/gioi-thieu?a=1&amp;b=2">Giới thiệu &lt;b&gt;x&lt;/b&gt;</a></li>', $html);
        $this->assertStringContainsString('<li><a href="https://blog.example.com" target="_blank" rel="noopener">Blog</a></li>', $html);
        $this->assertStringContainsString('<li><a href="#">Không link</a></li>', $html);
        $this->assertStringNotContainsString('Mục con', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<li><a href="#about">', $html); // liên kết mặc định không còn (nút hero vẫn trỏ #about)
    }

    public function test_frontend_guards_links_even_if_validation_were_bypassed(): void
    {
        $menu = Menu::create(['name' => 'Menu chính', 'handle' => 'main']);
        $menu->saveTree([
            ['label' => 'Xấu', 'url' => 'JavaScript:alert(1)', 'children' => []],
            ['label' => 'Ngoài', 'url' => '//evil.example', 'children' => []],
            ['label' => 'Trong', 'url' => '/trang', 'children' => []],
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<li><a href="#">Xấu</a></li>', $html);
        $this->assertStringContainsString('<li><a href="//evil.example" target="_blank" rel="noopener">Ngoài</a></li>', $html);
        $this->assertStringContainsString('<li><a href="/trang">Trong</a></li>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);

        // Menu main còn nhưng hết mục → thanh nav trống (không quay về liên kết mặc định); chưa có menu main → mặc định
        $menu->saveTree([]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<ul class="nav-links">', $html);
        $this->assertStringNotContainsString('<li><a href="#about">', $html);
        $menu->delete();
        Menu::forgetCache('main');
        $this->get('/')->assertOk()->assertSee('<li><a href="#about">Giới thiệu</a></li>', false);
    }

    public function test_seeder_creates_main_menu_once(): void
    {
        $this->seed(MenuSeeder::class);
        $this->seed(MenuSeeder::class);

        $menu = Menu::where('handle', 'main')->firstOrFail();
        $this->assertSame(1, Menu::count());
        $this->assertSame(['Giới thiệu', 'Chuyên môn', 'Hành trình', 'Chạy bộ', 'Liên hệ'], $menu->items->pluck('label')->all());
        $this->get('/')->assertOk()->assertSeeInOrder(['href="#about"', 'href="#expertise"', 'href="#journey"', 'href="#running"', 'href="#contact"'], false);
    }

    public function test_odd_input_never_crashes_and_old_urls(): void
    {
        $root = $this->root();
        $this->main();
        $about = $this->item('Giới thiệu');

        foreach (['label', 'parent_id', 'link', 'url', 'category_id', 'is_visible'] as $field) {
            $this->actingAs($root)->from('/backend/menu')->put("/backend/menu/item/{$about->id}", $this->form(['label' => 'Giới thiệu', 'url' => '#about', $field => ['x']]))->assertStatus(302);
            $this->actingAs($root)->get('/backend/menu')->assertOk();
        }
        $this->assertSame(['Giới thiệu', '#about', null], [$about->fresh()->label, $about->fresh()->url, $about->fresh()->category_id]);
        foreach (['position[]=main', 'id[]=1', 'id=abc', 'id=99999', 'parent=abc&new'] as $query) {
            $this->actingAs($root)->get('/backend/menu?'.$query)->assertOk();
        }
        // URL cũ (tạo / sửa cả menu) → trang mới
        $this->actingAs($root)->get('/backend/menu/create')->assertRedirect(route('backend.menus.index', ['position' => 'main']));
        $this->actingAs($root)->get('/backend/menu/'.Menu::first()->id.'/edit')->assertRedirect(route('backend.menus.index', ['position' => 'main']));
        $this->actingAs($root)->get('/backend/menu/999/edit')->assertNotFound();
    }
}
