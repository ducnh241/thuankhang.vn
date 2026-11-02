<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Group;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'name' => 'Website ABC',
            'primary_domain' => 'abc.vn',
            'status' => 'in_progress',
            'price' => '12.500.000',
            'currency' => 'VND',
            'start_date' => '2026-10-01',
        ], $override);
    }

    // ---- Phân quyền ----

    public function test_project_pages_require_permission(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->create()->id]);
        $project = Project::factory()->create();

        $this->actingAs($noAccess)->get('/backend/project')->assertForbidden();
        $this->actingAs($noAccess)->get("/backend/project/{$project->id}")->assertForbidden();

        $this->actingAs($viewer)->get('/backend/project')->assertOk();
        $this->actingAs($viewer)->get("/backend/project/{$project->id}")->assertOk();
        $this->actingAs($viewer)->get('/backend/project/create')->assertForbidden();
        $this->actingAs($viewer)->post('/backend/project', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->get("/backend/project/{$project->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/backend/project/{$project->id}", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/project/{$project->id}")->assertForbidden();
        $this->assertNotSoftDeleted($project);
    }

    public function test_list_hides_actions_without_permission(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        Project::factory()->create();

        $this->actingAs($viewer)->get('/backend/project')
            ->assertOk()
            ->assertDontSee('Thêm dự án')
            ->assertDontSee('name="ids[]"', false)
            ->assertDontSee('aria-label="Sửa ', false)
            ->assertDontSee('data-delete-url', false);
    }

    // ---- Tạo / sửa ----

    public function test_create_project_with_tags_hostings_and_parsed_money(): void
    {
        $tag = Tag::factory()->create();
        $hosting = Hosting::factory()->create();

        $response = $this->actingAs($this->root())->post('/backend/project', $this->payload([
            'tag_ids' => [$tag->id],
            'hosting_ids' => [$hosting->id],
            'renewal_hosting_amount' => '1,000,000',
        ]));

        $project = Project::firstOrFail();
        $response->assertRedirect(route('backend.projects.show', $project));
        $this->assertSame('12500000.00', $project->price);
        $this->assertSame('1000000.00', $project->renewal_hosting_amount);
        $this->assertSame('abc.vn', $project->site_key);
        $this->assertSame('2026-10-01', $project->next_renewal_date->toDateString(), 'Gia hạn mặc định = ngày bắt đầu');
        $this->assertSame([$tag->id], $project->tags->pluck('id')->all());
        $this->assertSame([$hosting->id], $project->hostings->pluck('id')->all());
        $this->assertSame('unpaid', $project->payment_status);
        $this->assertDatabaseHas('project_audit_logs', ['project_id' => $project->id, 'action' => 'created']);
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->root())->from('/backend/project/create')
            ->post('/backend/project', ['name' => '', 'customer_id' => 999, 'status' => 'xyz', 'price' => 'abc'])
            ->assertRedirect('/backend/project/create')
            ->assertSessionHasErrors(['name', 'customer_id', 'status']);
    }

    public function test_domain_must_be_unique_after_normalizing_including_trashed(): void
    {
        $root = $this->root();
        $existing = Project::factory()->create(['primary_domain' => 'shop.vn']);

        $this->actingAs($root)->post('/backend/project', $this->payload(['primary_domain' => 'https://WWW.Shop.vn/abc']))
            ->assertSessionHasErrors('primary_domain');

        $existing->delete();
        $this->actingAs($root)->post('/backend/project', $this->payload(['primary_domain' => 'shop.vn']))
            ->assertSessionHasErrors('primary_domain');

        // Sửa chính nó thì không báo trùng
        $other = Project::factory()->create(['primary_domain' => 'other.vn']);
        $this->actingAs($root)->put("/backend/project/{$other->id}", $this->payload(['customer_id' => $other->customer_id, 'primary_domain' => 'www.other.vn']))
            ->assertSessionHasNoErrors();
    }

    public function test_update_recalculates_payment_status_and_logs_changes(): void
    {
        $project = Project::factory()->create(['price' => 10_000_000, 'status' => 'in_progress']);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 4_000_000]);

        $this->actingAs($this->root())->put("/backend/project/{$project->id}", $this->payload([
            'customer_id' => $project->customer_id,
            'primary_domain' => $project->primary_domain,
            'status' => 'delivered',
            'price' => '4.000.000',
        ]))->assertRedirect(route('backend.projects.show', $project));

        $project->refresh();
        $this->assertSame('delivered', $project->status);
        $this->assertSame('paid', $project->payment_status);

        $log = ProjectAuditLog::where('project_id', $project->id)->where('action', 'updated')->latest('id')->first();
        $this->assertSame('in_progress', $log->changes['old']['status']);
        $this->assertSame('delivered', $log->changes['new']['status']);
    }

    public function test_payment_status_partial(): void
    {
        $project = Project::factory()->create(['price' => 10_000_000]);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 3_000_000]);
        $project->recalculatePaymentStatus();

        $this->assertSame('partial', $project->fresh()->payment_status);
    }

    // ---- Danh sách: tìm kiếm, lọc, sắp xếp ----

    public function test_search_by_name_domain_customer_and_wildcards(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create(['name' => 'Khách Đặc Biệt']);
        Project::factory()->create(['name' => 'Dự án Một', 'primary_domain' => 'mot.vn']);
        Project::factory()->create(['name' => 'Dự án Hai', 'primary_domain' => 'hai_gach.vn', 'customer_id' => $customer->id]);

        $this->actingAs($root)->get('/backend/project?q=mot.vn')->assertSee('Dự án Một')->assertDontSee('Dự án Hai');
        $this->actingAs($root)->get('/backend/project?q=Đặc Biệt')->assertSee('Dự án Hai')->assertDontSee('Dự án Một');
        $this->actingAs($root)->get('/backend/project?q=_')->assertSee('Dự án Hai')->assertDontSee('Dự án Một');
    }

    public function test_filters_by_status_payment_customer_and_tag(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create();
        $a = Project::factory()->create(['name' => 'Alpha', 'status' => 'cancelled', 'payment_status' => 'paid']);
        $b = Project::factory()->create(['name' => 'Beta', 'status' => 'delivered', 'payment_status' => 'unpaid']);
        $b->tags()->attach($tag);

        $this->actingAs($root)->get('/backend/project?status=cancelled')->assertSee('Alpha')->assertDontSee('Beta');
        $this->actingAs($root)->get('/backend/project?payment_status=unpaid')->assertSee('Beta')->assertDontSee('Alpha');
        $this->actingAs($root)->get("/backend/project?customer_id={$a->customer_id}")->assertSee('Alpha')->assertDontSee('Beta');
        $this->actingAs($root)->get("/backend/project?tag_id={$tag->id}")->assertSee('Beta')->assertDontSee('Alpha');
        // Giá trị lạ bị bỏ qua, không lỗi
        $this->actingAs($root)->get('/backend/project?status=xyz&sort=password:asc&payment_status=1')->assertOk()->assertSee('Alpha')->assertSee('Beta');
    }

    public function test_default_sort_is_newest_first_and_trashed_hidden(): void
    {
        $root = $this->root();
        $old = Project::factory()->create(['name' => 'Cũ Hơn']);
        $new = Project::factory()->create(['name' => 'Mới Hơn']);
        Project::factory()->create(['name' => 'Đã Xóa'])->delete();

        $html = $this->actingAs($root)->get('/backend/project')->assertDontSee('Đã Xóa')->getContent();
        $this->assertLessThan(strpos($html, 'Cũ Hơn'), strpos($html, 'Mới Hơn'));

        $html = $this->actingAs($root)->get('/backend/project?sort=id:asc')->getContent();
        $this->assertLessThan(strpos($html, 'Mới Hơn'), strpos($html, 'Cũ Hơn'));
    }

    // ---- Xóa ----

    public function test_delete_is_soft(): void
    {
        $root = $this->root();
        [$a, $b] = Project::factory()->count(2)->create();

        $this->actingAs($root)->delete("/backend/project/{$a->id}")->assertRedirect('/backend/project')->assertSessionHas('success');
        $this->assertSoftDeleted($a);
        $this->assertNotSoftDeleted($b);
        $this->assertDatabaseHas('project_audit_logs', ['project_id' => $a->id, 'action' => 'deleted']);

        $this->actingAs($root)->get("/backend/project/{$a->id}")->assertNotFound();
    }

    public function test_show_page_renders_payments_hostings_and_history(): void
    {
        $project = Project::factory()->create(['name' => 'Dự Án Chi Tiết']);
        $project->hostings()->attach(Hosting::factory()->create(['name' => 'VPS-Test']));
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 2_500_000, 'note' => 'Đặt cọc']);

        $this->actingAs($this->root())->get("/backend/project/{$project->id}")
            ->assertOk()
            ->assertSee('Dự Án Chi Tiết')
            ->assertSee('VPS-Test')
            ->assertSee('Đặt cọc')
            ->assertSee('2.500.000')
            ->assertSee('Tạo mới');
    }

    public function test_menu_shows_projects_only_with_permission(): void
    {
        $this->actingAs($this->root())->get('/backend')->assertSee(route('backend.projects.index'));

        $plain = User::factory()->create(['group_id' => Group::factory()->create()->id]);
        $this->actingAs($plain)->get('/backend')->assertDontSee(route('backend.projects.index'));
    }

    public function test_customer_project_count_includes_referrals(): void
    {
        $parent = Customer::factory()->create();
        $child = Customer::factory()->create(['referrer_customer_id' => $parent->id]);
        $grandchild = Customer::factory()->create(['referrer_customer_id' => $child->id]);
        Project::factory()->create(['customer_id' => $parent->id]);
        Project::factory()->count(2)->create(['customer_id' => $grandchild->id]);

        $map = Customer::projectCountMap();
        $this->assertSame(['direct' => 1, 'total' => 3], $map[$parent->id]);
        $this->assertSame(['direct' => 0, 'total' => 2], $map[$child->id]);
    }

    public function test_normalize_site_key(): void
    {
        $this->assertSame('shop-abc.com', Project::normalizeSiteKey('https://WWW.Shop-ABC.com:8080/abc?x=1#h'));
        $this->assertNull(Project::normalizeSiteKey('  '));
    }

    // ---- Tiền có phần lẻ (dữ liệu thật: #196 giá 2.499.999,99) ----

    /** Lưu lại form sửa mà không đổi gì thì mọi số tiền phải giữ nguyên. */
    public function test_saving_unchanged_edit_form_keeps_decimal_money(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => '2499999.99', 'renewal_hosting_amount' => '999999.95', 'renewal_domain_amount' => 0]);

        $html = $this->actingAs($root)->get("/backend/project/{$project->id}/edit")->assertOk()->getContent();
        $value = fn ($name) => preg_match('/name="'.$name.'"[^>]*value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;

        $this->assertSame('2.499.999,99', $value('price'));
        $this->assertSame('999.999,95', $value('renewal_hosting_amount'));

        $this->actingAs($root)->put("/backend/project/{$project->id}", $this->payload([
            'customer_id' => $project->customer_id,
            'primary_domain' => $project->primary_domain,
            'status' => $project->status,
            'start_date' => $project->start_date->toDateString(),
            'price' => $value('price'),
            'renewal_hosting_amount' => $value('renewal_hosting_amount'),
            'renewal_domain_amount' => $value('renewal_domain_amount'),
        ]))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('2499999.99', $project->price);
        $this->assertSame('999999.95', $project->renewal_hosting_amount);
        $this->assertSame('0.00', $project->renewal_domain_amount);
    }

    public function test_money_input_formats(): void
    {
        $root = $this->root();
        // Cùng quy tắc với ô tiền ở trình duyệt (formatMoneyInput trong resources/admin/js/forms.js): phần lẻ là dấu
        // phẩy cuối theo sau tối đa 2 chữ số, còn lại là phân cách nghìn
        $cases = ['12.500.000' => '12500000.00', '12,500,000' => '12500000.00', '12500000' => '12500000.00', '1.234,5' => '1234.50',
            '1.234.567,89' => '1234567.89', '1,500' => '1500.00', '1,5' => '1.50', '0' => '0.00', '' => '0.00'];

        foreach ($cases as $input => $expected) {
            $project = Project::factory()->create();
            $this->actingAs($root)->put("/backend/project/{$project->id}", $this->payload([
                'customer_id' => $project->customer_id, 'primary_domain' => $project->primary_domain, 'price' => $input,
            ]))->assertSessionHasNoErrors();
            $this->assertSame($expected, $project->fresh()->price, "input '{$input}'");
        }

        // Chữ không phải số thì báo lỗi, không âm thầm thành 0
        $project = Project::factory()->create(['price' => 5_000_000]);
        $this->actingAs($root)->put("/backend/project/{$project->id}", $this->payload([
            'customer_id' => $project->customer_id, 'primary_domain' => $project->primary_domain, 'price' => 'năm triệu',
        ]))->assertSessionHasErrors('price');
        $this->assertSame('5000000.00', $project->fresh()->price);
    }

    public function test_domain_link_only_for_real_domains(): void
    {
        $this->assertSame('https://shop.vn', (new Project(['primary_domain' => 'https://WWW.shop.vn/x']))->domainUrl());
        $this->assertNull((new Project(['primary_domain' => 'CMS VR Tour']))->domainUrl());
        $this->assertNull((new Project(['primary_domain' => 'localhost']))->domainUrl());

        $project = Project::factory()->create(['primary_domain' => 'CMS VR Tour']);
        $this->actingAs($this->root())->get('/backend/project')->assertSee('CMS VR Tour')->assertDontSee('https://cms vr tour', false);
    }

    public function test_user_input_is_escaped_everywhere(): void
    {
        $root = $this->root();
        $evil = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
        $tag = Tag::factory()->create(['name' => $evil, 'color' => 'red;background:url(javascript:alert(3))']);
        $customer = Customer::factory()->create(['name' => $evil]);
        $project = Project::factory()->create(['name' => $evil, 'customer_id' => $customer->id, 'primary_domain' => 'javascript:alert(4)', 'note' => $evil]);
        $project->tags()->attach($tag);
        Payment::factory()->create(['project_id' => $project->id, 'note' => $evil, 'method' => $evil]);

        foreach (['/backend/project', "/backend/project/{$project->id}", "/backend/project/{$project->id}/edit"] as $url) {
            $html = $this->actingAs($root)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('url(javascript', $html, $url);
            $this->assertStringNotContainsString('href="javascript', $html, $url);
            $this->assertStringNotContainsString('href="https://javascript', $html, $url);
        }
    }

    // ---- Hồi quy từ đợt soát code ----

    public function test_pagination_is_stable_when_sort_values_tie(): void
    {
        $root = $this->root();
        Project::factory()->count(25)->create(['price' => 0]);

        $names = fn ($page) => collect(preg_match_all('/class="product-name[^"]*" href="[^"]*">([^<]+)</', $this->actingAs($root)->get("/backend/project?sort=price:desc&page={$page}")->getContent(), $m) ? $m[1] : []);
        $all = $names(1)->merge($names(2));

        $this->assertCount(25, $all);
        $this->assertCount(25, $all->unique());
    }

    public function test_unchecking_all_tags_survives_validation_error(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create();
        $project = Project::factory()->create();
        $project->tags()->attach($tag);

        // Bỏ tick hết tag + để trống tên → lỗi validate, quay lại form
        $this->actingAs($root)->from("/backend/project/{$project->id}/edit")
            ->put("/backend/project/{$project->id}", $this->payload(['customer_id' => $project->customer_id, 'name' => '']))
            ->assertSessionHasErrors('name');

        $html = $this->actingAs($root)->get("/backend/project/{$project->id}/edit")->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="tag_ids\[\]" value="'.$tag->id.'"[^>]*checked/', $html);
    }

    public function test_missing_currency_does_not_crash(): void
    {
        $project = Project::factory()->create(['currency' => 'USD']);
        $payload = $this->payload(['customer_id' => $project->customer_id, 'primary_domain' => $project->primary_domain]);
        unset($payload['currency']);

        $this->actingAs($this->root())->put("/backend/project/{$project->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('USD', $project->fresh()->currency);

        $this->actingAs($this->root())->put("/backend/project/{$project->id}", [...$payload, 'price' => ['1']])->assertSessionHasErrors('price');
    }

    public function test_project_of_deleted_customer_can_still_be_saved(): void
    {
        $project = Project::factory()->create();
        $project->customer->delete();

        $this->actingAs($this->root())->get("/backend/project/{$project->id}/edit")->assertOk()->assertSee('đã xóa');
        $this->actingAs($this->root())->put("/backend/project/{$project->id}", $this->payload(['customer_id' => $project->customer_id, 'primary_domain' => $project->primary_domain]))
            ->assertSessionHasNoErrors();

        // Nhưng không gắn được dự án khác vào khách hàng đã xóa
        $other = Project::factory()->create();
        $this->actingAs($this->root())->put("/backend/project/{$other->id}", $this->payload(['customer_id' => $project->customer_id, 'primary_domain' => $other->primary_domain]))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_audit_log_old_and_new_values_have_same_format(): void
    {
        $project = Project::factory()->create(['start_date' => '2026-01-01']);

        $this->actingAs($this->root())->put("/backend/project/{$project->id}", $this->payload([
            'customer_id' => $project->customer_id, 'primary_domain' => $project->primary_domain, 'start_date' => '2026-02-01',
        ]));

        $log = ProjectAuditLog::where('project_id', $project->id)->where('action', 'updated')->latest('id')->first();
        $this->assertStringStartsWith('2026-01-01', $log->changes['old']['start_date']);
        $this->assertStringNotContainsString('T00:00:00', $log->changes['old']['start_date']);
    }

    /** Số quá lớn / ngày vô lý phải bị validate thay vì làm lỗi SQL (500). */
    public function test_huge_amounts_and_absurd_dates_are_rejected(): void
    {
        $root = User::factory()->superAdmin()->create();
        $customer = \App\Models\Customer::factory()->create();

        $this->actingAs($root)->post('/backend/project', [
            'name' => 'Quá lớn', 'customer_id' => $customer->id, 'status' => 'lead',
            'price' => '9999999999999999999', 'renewal_hosting_amount' => '1e30', 'renewal_domain_amount' => '99999999999999',
            'start_date' => '99999-01-01', 'next_renewal_date' => '1800-01-01',
        ])->assertSessionHasErrors(['price', 'renewal_hosting_amount', 'renewal_domain_amount', 'start_date', 'next_renewal_date']);
        $this->assertDatabaseMissing('projects', ['name' => 'Quá lớn']);
    }

    /** Ghi chú đăng nhập domain không được chép vào lịch sử thay đổi. */
    public function test_domain_note_is_masked_in_audit_log(): void
    {
        $root = User::factory()->superAdmin()->create();
        $project = Project::factory()->create(['domain_note' => 'user: admin / pass: SecretP@ss']);
        $this->actingAs($root)->put("/backend/project/{$project->id}", [
            'name' => $project->name, 'customer_id' => $project->customer_id, 'status' => $project->status,
            'domain_note' => 'pass: NewSecret!',
        ])->assertSessionHasNoErrors();

        $logs = \App\Models\ProjectAuditLog::where('project_id', $project->id)->get();
        $this->assertCount(2, $logs);   // created + updated
        foreach ($logs as $log) {
            $json = json_encode($log->changes);
            $this->assertStringNotContainsString('SecretP@ss', $json);
            $this->assertStringNotContainsString('NewSecret', $json);
        }
        $this->assertSame('••••••', $logs->last()->changes['new']['domain_note']);
        $this->assertSame('pass: NewSecret!', $project->fresh()->domain_note);
        $this->actingAs($root)->get("/backend/project/{$project->id}")->assertOk()->assertDontSee('SecretP@ss');
    }
}
