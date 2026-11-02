<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\ProjectDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Domain alias của dự án: cùng website (chung source code, chung database) chạy trên nhiều domain. */
class ProjectDomainTest extends TestCase
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
            'price' => '0',
            'currency' => 'VND',
        ], $override);
    }

    public function test_create_with_aliases_normalizes_and_keeps_order(): void
    {
        $root = $this->root();
        $this->actingAs($root)->post('/backend/project', $this->payload(['domains' => [
            ['domain' => 'https://EN.abc.vn/', 'note' => ' Bản tiếng Anh '],
            ['domain' => '', 'note' => ''],                // dòng trống: bỏ qua
            ['domain' => 'www.abc.com.vn', 'note' => ''],
        ]]))->assertSessionHasNoErrors();

        $project = Project::where('name', 'Website ABC')->sole();
        $this->assertSame([['https://EN.abc.vn/', 'en.abc.vn', 'Bản tiếng Anh', 0], ['www.abc.com.vn', 'abc.com.vn', null, 1]],
            $project->domains->map(fn ($d) => [$d->domain, $d->site_key, $d->note, $d->position])->all());
        // Lịch sử dự án ghi danh sách domain alias
        $log = ProjectAuditLog::where('project_id', $project->id)->where('changes->new->domains', '!=', null)->sole();
        $this->assertSame(['old' => ['domains' => null], 'new' => ['domains' => 'https://EN.abc.vn/ (Bản tiếng Anh), www.abc.com.vn']], $log->changes);

        // Trang chi tiết: từng alias một dòng, có link mở website + ghi chú; lịch sử ghi "Domain alias"
        $this->actingAs($root)->get("/backend/project/{$project->id}")->assertOk()
            ->assertSee('<a href="https://en.abc.vn" target="_blank" rel="noopener noreferrer" class="wrap-anywhere">', false)
            ->assertSee('— Bản tiếng Anh', false)->assertSee('https://abc.com.vn', false)->assertSee('Domain alias:', false);
        // Form sửa hiện lại đúng các dòng
        $this->actingAs($root)->get("/backend/project/{$project->id}/edit")->assertOk()
            ->assertSeeInOrder(['name="domains[0][domain]" value="https://EN.abc.vn/"', 'name="domains[0][note]" value="Bản tiếng Anh"', 'name="domains[1][domain]" value="www.abc.com.vn"'], false)
            ->assertSee('data-next="2"', false);
        // Danh sách: các domain alias hiện ngay dưới tên (link mở website)
        $this->actingAs($root)->get('/backend/project')->assertOk()
            ->assertSeeInOrder(['<div class="project-aliases" title="Domain alias">', '<span class="project-alias"><a href="https://en.abc.vn" target="_blank" rel="noopener noreferrer" >en.abc.vn</a>,</span> ',
                '<span class="project-alias"><a href="https://abc.com.vn" target="_blank" rel="noopener noreferrer" >abc.com.vn</a></span>'], false);
    }

    public function test_update_replaces_aliases_and_logs_only_real_changes(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['primary_domain' => 'abc.vn']);
        $project->domains()->createMany([['domain' => 'a.vn', 'position' => 0], ['domain' => 'b.vn', 'note' => 'cũ', 'position' => 1]]);
        $base = $this->payload(['customer_id' => $project->customer_id, 'name' => $project->name, 'status' => $project->status]);
        $logs = fn () => ProjectAuditLog::where('project_id', $project->id)->whereNotNull('changes->new->domains')->count();

        // Lưu lại y nguyên: không đổi, không ghi lịch sử
        $this->actingAs($root)->put("/backend/project/{$project->id}", $base + ['domains' => [['domain' => 'a.vn', 'note' => ''], ['domain' => 'b.vn', 'note' => 'cũ']]])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $logs());

        // Đổi thứ tự + bỏ một + thêm một
        $this->actingAs($root)->put("/backend/project/{$project->id}", $base + ['domains' => [['domain' => 'c.vn', 'note' => ''], ['domain' => 'a.vn', 'note' => 'giữ']]])
            ->assertSessionHasNoErrors();
        $this->assertSame([['c.vn', null], ['a.vn', 'giữ']], $project->domains()->get()->map(fn ($d) => [$d->domain, $d->note])->all());
        $this->assertSame(1, $logs());

        // Bỏ hết (không gửi domains)
        $this->actingAs($root)->put("/backend/project/{$project->id}", $base)->assertSessionHasNoErrors();
        $this->assertSame(0, $project->domains()->count());
        $this->assertSame(0, ProjectDomain::count());
    }

    public function test_domains_are_unique_across_primary_and_aliases(): void
    {
        $root = $this->root();
        $other = Project::factory()->create(['name' => 'Dự án Khác', 'primary_domain' => 'khac.vn']);
        $other->domains()->create(['domain' => 'alias-khac.vn']);
        $trashed = Project::factory()->create(['name' => 'Đã xóa', 'primary_domain' => 'daxoa.vn']);
        $trashed->domains()->create(['domain' => 'alias-daxoa.vn']);
        $trashed->delete();

        $cases = [
            'domains.0.domain' => [['domain' => 'CMS VR Tour'], 'không phải tên miền'],
            'domains.1.domain' => [['domain' => 'x.vn'], 'bị trùng với dòng 1', [['domain' => 'x.vn'], ['domain' => 'https://www.x.vn']]],
            'domains.0.domain#primary' => [['domain' => 'www.abc.vn'], 'bị trùng với domain chính'],
            'domains.0.domain#other-primary' => [['domain' => 'khac.vn'], 'đã gắn với dự án #'.$other->id.' — Dự án Khác'],
            'domains.0.domain#other-alias' => [['domain' => 'alias-khac.vn'], 'đã gắn với dự án #'.$other->id.' — Dự án Khác'],
            'domains.0.domain#trashed-alias' => [['domain' => 'alias-daxoa.vn'], '— Đã xóa (đã xóa)'],
            'domains.0.domain#note-only' => [['domain' => '', 'note' => 'chỉ có ghi chú'], 'Nhập domain'],
        ];
        foreach ($cases as $key => $case) {
            [$row, $message, $rows] = $case + [2 => null];
            // assertInvalid: thông báo lỗi chứa đoạn chữ cho trước
            $this->actingAs($root)->post('/backend/project', $this->payload(['domains' => $rows ?? [$row]]))
                ->assertInvalid([explode('#', $key)[0] => $message]);
        }
        // Domain chính trùng alias của dự án khác (kể cả dự án đã xóa)
        $this->actingAs($root)->post('/backend/project', $this->payload(['primary_domain' => 'https://alias-khac.vn']))
            ->assertSessionHasErrors(['primary_domain' => 'Domain "alias-khac.vn" đã gắn với dự án #'.$other->id.' — Dự án Khác']);
        $this->actingAs($root)->post('/backend/project', $this->payload(['primary_domain' => 'alias-daxoa.vn']))->assertSessionHasErrors('primary_domain');
        $this->assertSame(2, Project::withTrashed()->count());

        // Sửa chính dự án: giữ alias của nó không bị báo trùng
        $this->actingAs($root)->put("/backend/project/{$other->id}", $this->payload(['customer_id' => $other->customer_id, 'name' => 'Dự án Khác', 'primary_domain' => 'khac.vn', 'status' => $other->status,
            'domains' => [['domain' => 'alias-khac.vn']]]))->assertSessionHasNoErrors();
    }

    public function test_errors_redisplay_rows_at_the_right_position(): void
    {
        $root = $this->root();
        Project::factory()->create(['primary_domain' => 'trung.vn']);

        // Dòng trống ở giữa vẫn giữ chỗ: lỗi nằm đúng dòng thứ 3
        $html = $this->actingAs($root)->from('/backend/project/create')->followingRedirects()->post('/backend/project', $this->payload(['domains' => [
            ['domain' => 'ok.vn', 'note' => 'a'], ['domain' => '', 'note' => ''], ['domain' => 'trung.vn', 'note' => 'b'],
        ]]))->assertOk()->getContent();
        $this->assertStringContainsString('data-next="3"', $html);
        $this->assertMatchesRegularExpression('#name="domains\[2\]\[domain\]" value="trung\.vn"[^>]*/>\s*</div></div>\s*<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">Domain &quot;trung\.vn&quot; đã gắn với dự án#u', $html);
        $this->assertStringContainsString('name="domains[0][domain]" value="ok.vn"', $html);
        $this->assertSame(0, ProjectDomain::count());
    }

    public function test_search_finds_projects_by_alias(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['name' => 'Shop Mẹ Bé', 'primary_domain' => 'mebe.vn']);
        $project->domains()->create(['domain' => 'mebe-shop.com']);
        Project::factory()->create(['name' => 'Khác Hẳn', 'primary_domain' => 'khac.vn']);
        Payment::factory()->create(['project_id' => $project->id, 'note' => 'Thu lần 1']);

        $this->actingAs($root)->get('/backend/project?q=mebe-shop')->assertOk()->assertSee('Shop Mẹ Bé')->assertDontSee('Khác Hẳn');
        $this->actingAs($root)->get('/backend/payment?q=mebe-shop')->assertOk()->assertSee('Thu lần 1');
        // Ô tìm dự án ở form hosting tìm được theo alias
        $this->actingAs($root)->get('/backend/hosting/create')->assertOk()->assertSee('data-search="Shop Mẹ Bé mebe.vn mebe-shop.com', false);
    }

    public function test_list_shows_hosting_and_highlights_searched_domain(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['name' => 'Shop Mẹ Bé', 'primary_domain' => 'mebe.vn']);
        $project->domains()->createMany([['domain' => 'mebe-shop.com', 'position' => 0], ['domain' => 'en.mebe.vn', 'position' => 1]]);
        $mine = \App\Models\Hosting::factory()->create(['name' => 'Contabo VPS 9', 'ip' => '203.0.113.9', 'ownership' => 'mine']);
        $theirs = \App\Models\Hosting::factory()->create(['name' => 'Hosting khách', 'ip' => null, 'ownership' => 'customer']);
        $project->hostings()->attach([$mine->id, $theirs->id]);
        Project::factory()->create(['name' => 'Không hosting', 'primary_domain' => 'trong.vn']);

        $html = $this->actingAs($root)->get('/backend/project?q=mebe-shop')->assertOk()->getContent();
        // Domain khớp từ khóa in đậm, domain khác thì không
        $this->assertStringContainsString('class="project-domain-match" >mebe-shop.com</a>,', $html);
        $this->assertStringContainsString('rel="noopener noreferrer" >en.mebe.vn</a>', $html);
        // Cột Hosting: tên (lọc theo hosting), IP, "Của khách"; màn hẹp: server ghi dưới tên
        $this->assertStringContainsString('<th class="ant-table-cell max-lg:hidden" data-col="hosting">Hosting</th>', $html);
        $this->assertStringContainsString('href="'.e(route('backend.projects.index', ['q' => 'mebe-shop', 'hosting_id' => $mine->id])).'" class="project-link" title="Lọc dự án trên Contabo VPS 9">Contabo VPS 9</a>', $html);
        $this->assertStringContainsString('203.0.113.9', $html);
        $this->assertStringContainsString('<span class="ant-tag ant-tag-blue project-hosting-tag">Của khách</span>', $html);
        $this->assertStringContainsString('<span>Contabo VPS 9 (203.0.113.9), Hosting khách</span>', $html);
        $this->assertStringNotContainsString('Không hosting', $html);

        // Tìm theo domain chính cũng in đậm (khi tên dự án khác domain)
        $this->actingAs($root)->get('/backend/project?q=mebe.vn')->assertOk()
            ->assertSee('<span class="project-domain-match">mebe.vn</span>', false);
        // Dự án không có hosting: "—"
        $this->actingAs($root)->get('/backend/project?q=trong.vn')->assertOk()
            ->assertSeeInOrder(['data-col="hosting">', '<span class="text-[rgba(0,0,0,0.45)]">—</span>'], false);
    }

    public function test_odd_input_never_crashes(): void
    {
        $root = $this->root();
        foreach (['x', ['x'], [['domain' => ['x']]], [['note' => ['x']]], [['domain' => 'a.vn', 'note' => str_repeat('a', 256)]], array_fill(0, 51, ['domain' => 'a.vn'])] as $domains) {
            $this->actingAs($root)->from('/backend/project/create')->post('/backend/project', $this->payload(['domains' => $domains]))->assertStatus(302)->assertSessionHasErrors();
            $this->actingAs($root)->get('/backend/project/create')->assertOk();
        }
        $this->assertSame(0, Project::count());
    }
}
