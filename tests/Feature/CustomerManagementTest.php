<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_pages_require_permission(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('customer')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $customer = Customer::factory()->create();

        $this->actingAs($noAccess)->get('/backend/customer')->assertForbidden();
        $this->actingAs($viewer)->get('/backend/customer')->assertOk()->assertSee($customer->name)->assertDontSee('Thêm khách hàng');
        foreach ([['get', '/backend/customer/create'], ['post', '/backend/customer'], ['post', '/backend/customer/quick-create'], ['get', "/backend/customer/{$customer->id}/edit"], ['put', "/backend/customer/{$customer->id}"], ['delete', "/backend/customer/{$customer->id}"]] as [$method, $url]) {
            $this->actingAs($viewer)->{$method}($url, ['name' => 'X', 'ids' => [$customer->id]])->assertForbidden();
        }
        $this->assertNotSoftDeleted($customer);
    }

    public function test_create_and_update(): void
    {
        $root = $this->root();
        $referrer = Customer::factory()->create();

        $this->actingAs($root)->post('/backend/customer', ['name' => 'Khách Mới', 'phone' => '0901', 'email' => 'moi@example.com', 'referrer_customer_id' => $referrer->id])
            ->assertSessionHasNoErrors();
        $customer = Customer::where('name', 'Khách Mới')->firstOrFail();
        $this->assertSame($referrer->id, $customer->referrer_customer_id);

        $this->actingAs($root)->put("/backend/customer/{$customer->id}", ['name' => 'Khách Đã Sửa', 'referrer_customer_id' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Khách Đã Sửa', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->referrer_customer_id);

        $this->actingAs($root)->post('/backend/customer', ['name' => '', 'email' => 'sai'])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_referrer_cannot_create_cycle(): void
    {
        $root = $this->root();
        $a = Customer::factory()->create();
        $b = Customer::factory()->create(['referrer_customer_id' => $a->id]);
        $c = Customer::factory()->create(['referrer_customer_id' => $b->id]);

        // A không thể chọn chính mình hoặc C (cháu của A) làm người giới thiệu
        foreach ([$a->id, $b->id, $c->id] as $id) {
            $this->actingAs($root)->put("/backend/customer/{$a->id}", ['name' => $a->name, 'referrer_customer_id' => $id])->assertSessionHasErrors('referrer_customer_id');
        }

        $html = $this->actingAs($root)->get("/backend/customer/{$a->id}/edit")->getContent();
        $this->assertStringNotContainsString('<option value="'.$c->id.'"', $html);
    }

    public function test_deleted_referrer_is_kept_when_saving(): void
    {
        $root = $this->root();
        $referrer = Customer::factory()->create();
        $customer = Customer::factory()->create(['referrer_customer_id' => $referrer->id]);
        $referrer->delete();

        $this->actingAs($root)->get("/backend/customer/{$customer->id}/edit")->assertSee('đã xóa');
        $this->actingAs($root)->put("/backend/customer/{$customer->id}", ['name' => 'Tên mới', 'referrer_customer_id' => $referrer->id])->assertSessionHasNoErrors();
        $this->assertSame($referrer->id, $customer->fresh()->referrer_customer_id);

        // Nhưng không gán được người đã xóa cho khách khác
        $other = Customer::factory()->create();
        $this->actingAs($root)->put("/backend/customer/{$other->id}", ['name' => 'X', 'referrer_customer_id' => $referrer->id])->assertSessionHasErrors('referrer_customer_id');
    }

    public function test_index_tabs_search_and_revenue(): void
    {
        $root = $this->root();
        $withProject = Customer::factory()->create(['name' => 'Có Dự Án']);
        $project = Project::factory()->create(['customer_id' => $withProject->id]);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 3_500_000]);
        Customer::factory()->create(['name' => 'Không Dự Án', 'phone' => '0987_654']);
        Customer::factory()->create(['name' => 'Được Giới Thiệu', 'referrer_customer_id' => $withProject->id]);

        $this->actingAs($root)->get('/backend/customer?tab=with_projects')->assertSee('Có Dự Án')->assertDontSee('Không Dự Án')->assertSee('3.500.000');
        $this->actingAs($root)->get('/backend/customer?tab=no_projects')->assertSee('Không Dự Án')->assertDontSee('data-row-key="'.$withProject->id.'"', false);
        $this->actingAs($root)->get('/backend/customer?tab=referred')->assertSee('Được Giới Thiệu')->assertDontSee('Không Dự Án');
        $this->actingAs($root)->get('/backend/customer?q=0987_')->assertSee('Không Dự Án')->assertDontSee('Được Giới Thiệu');
        $this->actingAs($root)->get('/backend/customer?q=_')->assertDontSee('Được Giới Thiệu');
        $this->actingAs($root)->get('/backend/customer?sort=projects:desc&tab=xyz')->assertOk();
    }

    public function test_tabs_and_sort_links_keep_other_choices(): void
    {
        $root = $this->root();
        $b = Customer::factory()->create(['name' => 'Bình Quang']);
        $a = Customer::factory()->create(['name' => 'An Quang']);
        Project::factory()->create(['customer_id' => $b->id]);
        Customer::factory()->create(['name' => 'Chưa dự án', 'referrer_customer_id' => $b->id]);

        $page = $this->actingAs($root)->get('/backend/customer?q=Quang&tab=with_projects&sort=name:asc')->assertOk();
        $html = html_entity_decode($page->getContent());
        // Tab: giữ ô tìm + sắp xếp, có số lượng (theo ô tìm), tab đang chọn được đánh dấu
        $this->assertStringContainsString('/backend/customer?q=Quang&tab=no_projects&sort=name%3Aasc"', $html);
        $this->assertStringContainsString('/backend/customer?q=Quang&sort=name%3Aasc" role="tab" aria-selected="false"', $html); // Tất cả
        $this->assertMatchesRegularExpression('#tab=with_projects&sort=name%3Aasc" role="tab" aria-selected="true"#', $html);
        $this->assertSame([2, 1, 1, 0], array_values($page->viewData('counts') ?? [])); // Tất cả 2 (Quang), có dự án 1, chưa 1, được giới thiệu 0
        // Sắp xếp (nút pill + danh sách chọn): giữ ô tìm + tab
        $page->assertSee('Sắp xếp: Tên A–Z');
        $this->assertStringContainsString('/backend/customer?q=Quang&tab=with_projects&sort=projects%3Adesc"', $html);
        $this->assertStringContainsString('/backend/customer?q=Quang&tab=with_projects">Mới nhất', $html); // mặc định: không ghi sort
        $this->assertMatchesRegularExpression('#ant-dropdown-menu-item-selected" role="menuitem"><a href="[^"]*sort=name%3Aasc">Tên A–Z#', $html);
        // Ô tìm gửi kèm tab + sắp xếp đang chọn
        $page->assertSee('<input type="hidden" name="tab" value="with_projects" />', false)->assertSee('<input type="hidden" name="sort" value="name:asc" />', false);
        // Sắp tên A–Z
        $this->actingAs($root)->get('/backend/customer?q=Quang&sort=name:asc')->assertSeeInOrder([$a->name, $b->name]);
    }

    public function test_list_shows_created_date(): void
    {
        $customer = Customer::factory()->create(['name' => 'Khách mới tạo', 'created_at' => '2026-09-05 14:30:00']);

        $html = $this->actingAs($this->root())->get('/backend/customer')->assertOk()
            ->assertSee('data-col="created">Ngày tạo</th>', false)->getContent();
        $this->assertMatchesRegularExpression('#title="05/09/2026 14:30"\s*>05/09/2026</td>#', $html);
    }

    public function test_newest_and_oldest_sort_by_created_date_not_id(): void
    {
        $root = $this->root();
        // id tăng dần nhưng ngày tạo không theo thứ tự id (khách cũ đã đổi ngày tạo theo dự án đầu tiên)
        $c2018 = Customer::factory()->create(['name' => 'KH 2018', 'created_at' => '2018-02-28 00:00:00']);
        $c2017b = Customer::factory()->create(['name' => 'KH 2017 B', 'created_at' => '2017-02-28 00:00:00']);
        $c2017a = Customer::factory()->create(['name' => 'KH 2017 A', 'created_at' => '2017-02-28 00:00:00']);
        $c2026 = Customer::factory()->create(['name' => 'KH 2026', 'created_at' => '2026-09-10 08:46:00']);
        $c2017c = Customer::factory()->create(['name' => 'KH 2017 C', 'created_at' => '2017-03-06 00:00:00']);

        $ids = fn (string $query) => $this->actingAs($root)->get('/backend/customer'.$query)->viewData('customers')->pluck('id')->all();
        // Cũ nhất: ngày tạo tăng dần, cùng ngày thì id tăng dần
        $this->assertSame([$c2017b->id, $c2017a->id, $c2017c->id, $c2018->id, $c2026->id], $ids('?sort=created:asc'));
        // Mới nhất (mặc định): ngược lại
        $this->assertSame([$c2026->id, $c2018->id, $c2017c->id, $c2017a->id, $c2017b->id], $ids(''));
        $this->assertSame($ids(''), $ids('?sort=created:desc'));
        // Khoá cũ trên URL → mặc định
        $this->assertSame($ids(''), $ids('?sort=id:asc'));
        $this->actingAs($root)->get('/backend/customer?sort=created:asc')->assertSeeInOrder(['28/02/2017', '28/02/2017', '06/03/2017', '28/02/2018', '10/09/2026']);
    }

    public function test_cannot_delete_customer_with_projects(): void
    {
        $root = $this->root();
        $busy = Customer::factory()->create();
        Project::factory()->create(['customer_id' => $busy->id]);
        $free = Customer::factory()->create();
        $free2 = Customer::factory()->create();

        $this->actingAs($root)->delete("/backend/customer/{$busy->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($busy);

        $this->actingAs($root)->delete("/backend/customer/{$free->id}")->assertSessionHas('success');
        $this->assertSoftDeleted($free);

        // Danh sách: chỉ khách không còn dự án mới có nút xóa (hỏi lại trước khi xóa)
        $this->actingAs($root)->get('/backend/customer')->assertSee('data-delete-url="'.route('backend.customers.destroy', $free2).'"', false)
            ->assertDontSee('data-delete-url="'.route('backend.customers.destroy', $busy).'"', false)->assertSee('Còn 1 dự án, không xóa được');
    }

    public function test_quick_create_returns_json(): void
    {
        $root = $this->root();

        $this->actingAs($root)->postJson('/backend/customer/quick-create', ['name' => 'Tạo Nhanh', 'company' => 'ACME'])
            ->assertCreated()->assertJson(['label' => 'Tạo Nhanh — ACME']);
        $this->assertDatabaseHas('customers', ['name' => 'Tạo Nhanh']);

        $this->actingAs($root)->postJson('/backend/customer/quick-create', ['name' => '', 'email' => 'sai'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_edit_page_lists_projects_and_referrals(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create();
        Project::factory()->create(['customer_id' => $customer->id, 'name' => 'Dự Án Của Khách']);
        Customer::factory()->create(['referrer_customer_id' => $customer->id, 'name' => 'Người Được Giới Thiệu']);

        $this->actingAs($root)->get("/backend/customer/{$customer->id}/edit")
            ->assertOk()->assertSee('Dự Án Của Khách')->assertSee('Người Được Giới Thiệu');
    }

    public function test_new_project_link_preselects_customer(): void
    {
        $customer = Customer::factory()->create();

        $html = $this->actingAs($this->root())->get("/backend/project/create?customer_id={$customer->id}")->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$customer->id.'"\s+selected/', $html);
    }

    // ---- Trường hợp biên ----

    public function test_deleted_referrer_is_not_linked_in_list(): void
    {
        $referrer = Customer::factory()->create(['name' => 'Người GT Đã Xóa']);
        Customer::factory()->create(['referrer_customer_id' => $referrer->id]);
        $referrer->delete();

        $this->actingAs($this->root())->get('/backend/customer')
            ->assertSee('Người GT Đã Xóa')->assertSee('(đã xóa)')
            ->assertDontSee(route('backend.customers.edit', $referrer));
        $this->actingAs($this->root())->get(route('backend.customers.edit', $referrer))->assertNotFound();
    }

    public function test_quick_create_requires_permission_and_csrf(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('customer', 'project', 'project/add')->create()->id]);

        $this->actingAs($viewer)->postJson('/backend/customer/quick-create', ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->get('/backend/project/create')->assertOk()->assertDontSee('id="customer-modal"', false)->assertDontSee('+ Khách hàng mới');
        $this->assertDatabaseMissing('customers', ['name' => 'X']);
    }

    public function test_list_hides_project_links_without_project_permission(): void
    {
        $user = User::factory()->create(['group_id' => Group::factory()->withPermissions('customer', 'customer/edit')->create()->id]);
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)->get('/backend/customer')->assertOk()->assertDontSee(route('backend.projects.index', ['customer_id' => $customer->id]));
        $this->actingAs($user)->get("/backend/customer/{$customer->id}/edit")->assertOk()
            ->assertSee($project->name)->assertDontSee(route('backend.projects.show', $project))->assertDontSee('Dự án mới');
    }

    public function test_saving_unchanged_form_keeps_everything(): void
    {
        $referrer = Customer::factory()->create();
        $customer = Customer::factory()->create(['referrer_customer_id' => $referrer->id, 'company' => 'ACME', 'address' => 'Hà Nội', 'note' => 'Ghi chú']);
        $before = $customer->fresh()->only(['name', 'company', 'email', 'phone', 'address', 'note', 'referrer_customer_id']);

        $html = $this->actingAs($this->root())->get("/backend/customer/{$customer->id}/edit")->getContent();
        $value = fn ($name) => preg_match('/name="'.$name.'"[^>]*value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
        preg_match('/<textarea[^>]*name="note"[^>]*>([^<]*)</', $html, $note);
        preg_match('/<option value="(\d+)"\s+selected/', $html, $ref);

        $this->actingAs($this->root())->put("/backend/customer/{$customer->id}", [
            'name' => $value('name'), 'company' => $value('company'), 'email' => $value('email'), 'phone' => $value('phone'),
            'address' => $value('address'), 'note' => html_entity_decode($note[1]), 'referrer_customer_id' => $ref[1] ?? '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $customer->fresh()->only(array_keys($before)));
    }

    public function test_cycle_detection_through_deep_chain(): void
    {
        $chain = [Customer::factory()->create()];
        for ($i = 1; $i < 6; $i++) {
            $chain[] = Customer::factory()->create(['referrer_customer_id' => $chain[$i - 1]->id]);
        }

        // Gốc không chọn được cháu đời thứ 5 làm người giới thiệu
        $this->actingAs($this->root())->put("/backend/customer/{$chain[0]->id}", ['name' => 'Gốc', 'referrer_customer_id' => $chain[5]->id])
            ->assertSessionHasErrors('referrer_customer_id');
        // Còn đời thứ 5 chọn một người ngoài chuỗi thì được
        $outsider = Customer::factory()->create();
        $this->actingAs($this->root())->put("/backend/customer/{$chain[5]->id}", ['name' => 'Đời 5', 'referrer_customer_id' => $outsider->id])
            ->assertSessionHasNoErrors();
    }

    public function test_add_only_user_returns_to_list_after_create(): void
    {
        $user = User::factory()->create(['group_id' => Group::factory()->withPermissions('customer', 'customer/add')->create()->id]);

        $this->actingAs($user)->post('/backend/customer', ['name' => 'Chỉ Thêm'])->assertRedirect('/backend/customer');
        $this->actingAs($user)->get('/backend/customer')->assertOk()->assertSee('Đã tạo khách hàng Chỉ Thêm.');
    }

    // ---- Giao diện Ant Design ----

    public function test_metrics_strip(): void
    {
        $root = $this->root();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $big = Customer::factory()->create(['name' => 'Khách Lớn', 'created_at' => '2025-01-01']);
        $small = Customer::factory()->create(['name' => 'Khách Nhỏ', 'referrer_customer_id' => $big->id, 'created_at' => '2026-10-02']);
        Customer::factory()->create(['referrer_customer_id' => $big->id, 'created_at' => '2026-09-30']);
        $p1 = Project::factory()->create(['customer_id' => $big->id]);
        $p2 = Project::factory()->create(['customer_id' => $small->id]);
        Payment::factory()->create(['project_id' => $p1->id, 'amount' => 5_000_000]);
        Payment::factory()->create(['project_id' => $p2->id, 'amount' => 1_000_000]);

        $page = $this->actingAs($root)->get('/backend/customer?tab=referred')->assertOk();
        // 3 khách tạo trong test + khách của 2 dự án đã gắn sẵn → chỉ đếm khách thật: tổng theo DB
        $total = Customer::count();
        $page->assertSeeInOrder(['Khách hàng', (string) $total, '+'.Customer::where('created_at', '>=', '2026-10-01')->count().' trong tháng 10'])
            ->assertSeeInOrder(['Có dự án', '2', ($total - 2).' chưa có dự án'])
            ->assertSeeInOrder(['Được giới thiệu', '2', 'từ 1 người giới thiệu'])
            ->assertSeeInOrder(['Đã thu từ khách', '6.000.000', 'Nhiều nhất: Khách Lớn · 5.000.000']);
        // Đang ở tab "Được giới thiệu": chỉ số đó sáng lên, bấm lại về Tất cả
        $page->assertSee('href="'.route('backend.customers.index').'" class="product-metric is-active"', false);
        Carbon::setTestNow();
    }

    public function test_row_contact_actions_and_permissions(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create(['name' => 'Anh Hà FNB', 'company' => 'FNB', 'phone' => '0909 111 222']);

        $html = $this->actingAs($root)->get('/backend/customer')->getContent();
        $this->assertStringNotContainsString('ant-avatar', substr($html, strpos($html, 'id="customers"')));   // không có avatar
        $this->assertStringContainsString('#'.$customer->id.' · FNB · 0909 111 222', $html);
        $this->assertStringContainsString('data-href="'.route('backend.customers.edit', $customer).'"', $html);

        // Chỉ xem: không bấm dòng để sửa, không nút sửa / xóa / thêm
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('customer')->create()->id]);
        $this->actingAs($viewer)->get('/backend/customer')->assertOk()
            ->assertDontSee('data-href=', false)->assertDontSee('aria-label="Sửa ', false)->assertDontSee('data-delete-url', false)
            ->assertDontSee(route('backend.customers.create'));
    }

    public function test_page_beyond_last_and_delete_from_edit_page(): void
    {
        $root = $this->root();
        Customer::factory()->count(25)->create();
        $this->actingAs($root)->get('/backend/customer?sort=name:asc&page=9')->assertRedirect(route('backend.customers.index', ['sort' => 'name:asc', 'page' => 2]));

        // Xóa từ trang sửa của chính khách đó: về danh sách (trang sửa không còn)
        $customer = Customer::factory()->create();
        $this->actingAs($root)->from(route('backend.customers.edit', $customer))->delete(route('backend.customers.destroy', $customer))
            ->assertRedirect(route('backend.customers.index'));
        // Xóa từ danh sách đang lọc: quay lại đúng trang đó
        $other = Customer::factory()->create();
        $this->actingAs($root)->from('/backend/customer?tab=no_projects&page=2')->delete(route('backend.customers.destroy', $other))
            ->assertRedirect('/backend/customer?tab=no_projects&page=2');
    }

    public function test_form_errors_show_under_fields_and_referrer_is_searchable(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create(['name' => 'Tên Cũ']);

        $this->actingAs($root)->from(route('backend.customers.edit', $customer))
            ->put(route('backend.customers.update', $customer), ['name' => '', 'email' => 'sai']);
        $html = $this->actingAs($root)->get(route('backend.customers.edit', $customer))->getContent();
        $this->assertMatchesRegularExpression('#ant-form-item-has-error[^"]*" data-required="Nhập tên khách hàng."#', $html);
        $this->assertMatchesRegularExpression('#ant-form-item-has-error[^"]*" data-type="email"#', $html);
        $this->assertStringContainsString('Chưa lưu được: kiểm tra các ô báo đỏ bên dưới.', $html);
        $this->assertMatchesRegularExpression('#data-edit-form\s+data-dirty#', $html);
        $this->assertStringContainsString('<h1 class="page-topbar-title">Tên Cũ</h1>', $html);
        $this->assertStringContainsString('<select data-antd data-show-search data-allow-clear id="referrer_customer_id"', $html);

        // "Hủy thay đổi" trên trang báo lỗi tải lại trang (edit-form.js): lần mở sau hiện dữ liệu đã lưu, hết lỗi
        $html = $this->actingAs($root)->get(route('backend.customers.edit', $customer))->getContent();
        $this->assertStringContainsString('value="Tên Cũ"', $html);
        $this->assertStringNotContainsString('ant-form-item-has-error', $html);
        $this->assertDoesNotMatchRegularExpression('#data-edit-form\s+data-dirty#', $html);
    }
}
