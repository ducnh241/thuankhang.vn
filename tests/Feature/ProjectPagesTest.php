<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Giao diện Ant Design của các trang dự án: danh sách, form thêm / sửa, chi tiết. */
class ProjectPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 15:00:00');
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($doc);
    }

    /** Chữ của phần tử: các đoạn chữ nối bằng dấu cách (như mắt đọc), khoảng trắng gộp lại. */
    private function text(?\DOMNode $node): string
    {
        if (! $node) {
            return '';
        }
        $parts = [];
        foreach ((new DOMXPath($node->ownerDocument))->query('.//text()', $node) as $t) {
            $parts[] = $t->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts ?: [$node->textContent])));
    }

    /** So link không phụ thuộc thứ tự tham số. */
    private function assertSameUrl(string $expected, string $actual): void
    {
        $parse = function (string $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            ksort($query);

            return [strtok($url, '?'), $query];
        };
        $this->assertSame($parse($expected), $parse($actual));
    }

    /** Dải chỉ số: nhãn => [số, dòng phụ, href]. */
    private function metrics(string $html): array
    {
        $xp = $this->xpath($html);
        $out = [];
        foreach ($xp->query('//*[@id="project-metrics"]/*') as $metric) {
            $part = fn (string $class) => $this->text($xp->query(".//*[contains(@class, '{$class}')]", $metric)->item(0));
            $out[$part('product-metric-label')] = [$part('product-metric-value'), $part('product-metric-sub'), $metric->getAttribute('href') ?: null];
        }

        return $out;
    }

    // ---- Danh sách ----

    public function test_metrics_match_dashboard(): void
    {
        $root = $this->root();
        Project::factory()->create(['status' => 'in_progress', 'price' => 10_000_000, 'next_renewal_date' => null]);
        Project::factory()->count(2)->create(['status' => 'lead', 'next_renewal_date' => null]);
        Project::factory()->create(['status' => 'maintenance', 'next_renewal_date' => '2025-10-20', 'name' => 'Sắp đến kỳ']);   // kỳ 20/10/2026
        Project::factory()->count(3)->create(['status' => 'delivered', 'next_renewal_date' => '2027-05-01']);                  // đã gia hạn
        $paid = Project::factory()->create(['status' => 'delivered', 'price' => 4_000_000, 'next_renewal_date' => '2027-05-01']);
        Payment::factory()->create(['project_id' => $paid->id, 'amount' => 4_000_000]);

        $metrics = $this->metrics($this->actingAs($root)->get('/backend/project')->assertOk()->getContent());
        $this->assertSame(['1', '2 đang đàm phán', route('backend.projects.index', ['status' => 'in_progress'])], $metrics['Đang triển khai']);
        $this->assertSame(['4', '1 đang bảo trì', route('backend.projects.index', ['status' => 'delivered'])], $metrics['Đã bàn giao']);
        $this->assertSame(['1', 'Gần nhất: Sắp đến kỳ · 20/10'], array_slice($metrics['Sắp gia hạn'], 0, 2));
        // Giá mặc định của factory 5tr: 2 lead + 1 bảo trì + 3 bàn giao + 10tr đang làm; dự án đã thu đủ không tính
        // Bấm "Còn phải thu" → lọc dự án còn công nợ
        $this->assertSame(['40.000.000', '7 dự án còn công nợ', route('backend.projects.index', ['debt' => 1])], $metrics['Còn phải thu']);

        // Cùng số với dashboard
        $this->actingAs($root)->get('/backend')
            ->assertViewHas('renewals', fn ($r) => $r['upcoming_count'] === 1)
            ->assertViewHas('debt', fn ($d) => $d['total'] === 40_000_000.0 && $d['count'] === 7);

        // Đang ở tab của chỉ số: chỉ số sáng lên, bấm lại về Tất cả
        $metrics = $this->metrics($this->actingAs($root)->get('/backend/project?status=in_progress')->getContent());
        $this->assertSame(route('backend.projects.index'), $metrics['Đang triển khai'][2]);
        $this->actingAs($root)->get('/backend/project?status=in_progress')->assertSee('class="product-metric is-active"', false);
    }

    public function test_status_tabs_count_within_other_filters(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create();
        Project::factory()->count(2)->create(['status' => 'delivered'])->each(fn ($p) => $p->tags()->attach($tag));
        Project::factory()->create(['status' => 'cancelled'])->tags()->attach($tag);
        Project::factory()->create(['status' => 'cancelled']);

        $xp = $this->xpath($this->actingAs($root)->get("/backend/project?tag_id={$tag->id}&status=cancelled")->getContent());
        $tabs = [];
        foreach ($xp->query('//*[contains(@class, "product-views")]/a') as $a) {
            $tabs[$this->text($a)] = [$a->getAttribute('href'), $a->getAttribute('aria-selected')];
        }
        $this->assertSame([route('backend.projects.index', ['tag_id' => $tag->id]), 'false'], $tabs['Tất cả 3']);
        $this->assertSame([route('backend.projects.index', ['status' => 'delivered', 'tag_id' => $tag->id]), 'false'], $tabs['Đã bàn giao 2']);
        $this->assertSame('true', $tabs['Đã hủy gia hạn 1'][1]);
    }

    public function test_filter_pills_link_and_keep_other_filters(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create(['name' => 'Ưu tiên']);
        $withProjects = Customer::factory()->create(['name' => 'Có dự án', 'company' => null]);
        Customer::factory()->create(['name' => 'Chưa có dự án nào']);
        Project::factory()->create(['customer_id' => $withProjects->id, 'name' => 'Web A'])->tags()->attach($tag);

        $html = $this->actingAs($root)->get("/backend/project?q=web&tag_id={$tag->id}&sort=name:asc")->assertOk()->getContent();
        $xp = $this->xpath($html);

        // Pill đang lọc: tên lựa chọn + nút × bỏ riêng bộ lọc đó
        $this->assertSame('Tag: Ưu tiên', $this->text($xp->query('//*[contains(@class, "is-applied")]//button')->item(0)));
        $this->assertSameUrl(route('backend.projects.index', ['q' => 'web', 'sort' => 'name:asc']), $xp->query('//*[contains(@class, "is-applied")]//a')->item(0)->getAttribute('href'));

        // Danh sách chọn khách hàng: chỉ khách có dự án, link giữ tìm kiếm / tag / sắp xếp
        $links = [];
        foreach ($xp->query('//template[@id="filter-customer_id"]//a') as $a) {
            $links[$this->text($a)] = $a->getAttribute('href');
        }
        $this->assertSame(['Có dự án · 1 dự án'], array_keys($links));
        $this->assertSameUrl(route('backend.projects.index', ['q' => 'web', 'customer_id' => $withProjects->id, 'tag_id' => $tag->id, 'sort' => 'name:asc']), $links['Có dự án · 1 dự án']);

        // Chưa có danh mục dự án nào: không có nút lọc / cột danh mục
        $this->assertSame(0, $xp->query('//*[@data-filter="cat_id"]')->length);
        $this->assertSame(0, $xp->query('//th[@data-col="category"]')->length);
        Category::factory()->create(['name' => 'Coupon']);
        $this->actingAs($root)->get('/backend/project')->assertSee('data-filter="cat_id"', false)->assertSee('data-col="category"', false);

        // Sắp xếp và "Xóa bộ lọc" (giữ trạng thái + sắp xếp, bỏ tìm kiếm / bộ lọc)
        $this->assertStringContainsString('Sắp xếp: Tên A–Z', $this->text($xp->query('//*[@data-filter="sort"]')->item(0)));
        $this->assertSame(route('backend.projects.index', ['sort' => 'name:asc']), $xp->query('//*[@id="clear-filters"]')->item(0)->getAttribute('href'));
    }

    public function test_next_renewal_matches_renewal_page(): void
    {
        $root = $this->root();
        $make = fn (array $attrs) => Project::factory()->create(['status' => 'delivered', ...$attrs]);
        $rows = [
            'overdue' => $make(['next_renewal_date' => '2024-03-01']),
            'upcoming' => $make(['next_renewal_date' => '2026-10-25']),
            'today' => $make(['next_renewal_date' => '2026-10-10']),
            'later' => $make(['next_renewal_date' => '2027-02-01']),
            'old_year' => $make(['next_renewal_date' => '2019-12-15']),
            'self' => $make(['next_renewal_date' => '2026-10-20']),
            'closed' => $make(['next_renewal_date' => '2019-03-16', 'status' => 'closed']),
            'delivery' => $make(['next_renewal_date' => null, 'deliver_date' => '2025-11-01']),
            'none' => $make(['next_renewal_date' => null, 'deliver_date' => null]),
        ];
        $rows['self']->hostings()->attach(Hosting::factory()->create(['ownership' => 'customer']));

        // Ngày gia hạn kế tiếp (trang dự án): [ngày, trạng thái, số ngày còn lại (âm = quá hạn)]
        $next = function (Project $p) {
            $renewal = $p->fresh()->nextRenewal(CarbonImmutable::today());

            return $renewal ? [$renewal['date']->format('d/m/Y'), $renewal['state'], $renewal['days']] : null;
        };

        // Năm trong dữ liệu cũ không chuẩn: so ngày–tháng như trang Nhắc gia hạn
        $this->assertSame(['01/03/2026', 'overdue', -223], $next($rows['overdue']));
        $this->assertSame(['25/10/2026', 'upcoming', 15], $next($rows['upcoming']));
        $this->assertSame(['10/10/2026', 'upcoming', 0], $next($rows['today']));
        $this->assertSame(['01/02/2027', 'later', null], $next($rows['later']));
        $this->assertSame(['15/12/2026', 'later', null], $next($rows['old_year']));
        $this->assertSame(['20/10/2026', 'self', null], $next($rows['self']));
        $this->assertSame(['16/03/2019', 'stopped', null], $next($rows['closed']));
        $this->assertSame(['01/11/2026', 'upcoming', 22], $next($rows['delivery']));
        $this->assertNull($next($rows['none']));

        // Cùng danh sách với trang Nhắc gia hạn (30 ngày tới, chưa gia hạn)
        $due = $this->actingAs($root)->get('/backend/renewal?period=custom&from=2026-10-10&to=2026-11-09&renewed=not_renewed')->viewData('projects')->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$rows['upcoming'], $rows['today'], $rows['delivery']])->pluck('id')->sort()->values()->all(), $due);
    }

    public function test_row_values_and_links(): void
    {
        $root = $this->root();
        $owing = Project::factory()->create(['name' => 'owing.vn', 'primary_domain' => 'owing.vn', 'price' => 10_000_000, 'tech_stack' => 'Laravel']);
        Payment::factory()->create(['project_id' => $owing->id, 'amount' => 4_000_000]);
        Payment::factory()->create(['project_id' => $owing->id, 'amount' => 1_500_000, 'type' => 'renewal']);
        $free = Project::factory()->create(['name' => 'Dự án tự do', 'primary_domain' => 'tudo.vn', 'price' => 2_000_000, 'currency' => 'USD', 'start_date' => '2017-02-28']);

        $xp = $this->xpath($this->actingAs($root)->get('/backend/project')->getContent());
        $row = fn (Project $p) => $xp->query("//tr[@data-row-key='{$p->id}']")->item(0);

        // Còn nợ tính trên tiền thu mới (không trừ tiền gia hạn); tiền khác VND ghi đơn vị
        $this->assertSame('10.000.000 Còn nợ 6.000.000 +1.500.000 gia hạn', $this->text($xp->query('.//td[@data-col="value"]', $row($owing))->item(0)));
        $this->assertSame('2.000.000 USD Còn nợ 2.000.000', $this->text($xp->query('.//td[@data-col="value"]', $row($free))->item(0)));
        // Tên trùng domain: dòng phụ không nhắc lại domain, icon quả cầu mở website
        $this->assertSame('#'.$owing->id.' · Laravel', $this->text($xp->query('.//div[contains(@class, "truncate")]', $row($owing))->item(0)));
        $this->assertSame('#'.$free->id.' · tudo.vn', $this->text($xp->query('.//div[contains(@class, "truncate")]', $row($free))->item(0)));
        $this->assertSame('https://owing.vn', $xp->query('.//a[contains(@class, "project-thumb")]', $row($owing))->item(0)->getAttribute('href'));

        // Ngày tạo = ngày bắt đầu dự án (created_at của dữ liệu cũ là ngày chuyển hệ thống)
        $this->assertSame('28/02/2017', $this->text($xp->query('.//td[@data-col="created"]', $row($free))->item(0)));
        $this->assertSame(0, $xp->query('//th[@data-col="renewal"]')->length);

        // Không còn cột Thao tác: sửa / xóa ở trang dự án (bấm tên / cả dòng để mở)
        $this->assertSame(0, $xp->query('//*[@data-delete-url] | //a[contains(@href, "/edit")]')->length);
        $this->assertSame(route('backend.projects.show', $free), $xp->query('.//a[contains(@class, "product-name")]', $row($free))->item(0)->getAttribute('href'));
        $this->assertSame(route('backend.projects.show', $free), $row($free)->getAttribute('data-href'));
    }

    public function test_pagination_and_empty_states(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create();
        Project::factory()->count(205)->create(['customer_id' => $customer->id, 'status' => 'delivered']);

        $xp = $this->xpath($this->actingAs($root)->get('/backend/project?status=delivered&page=6')->getContent());
        $items = [];
        foreach ($xp->query('//ul[contains(@class, "ant-pagination")]/li') as $li) {
            $items[] = ($li->getAttribute('title')).(str_contains($li->getAttribute('class'), 'active') ? '*' : '');
        }
        $this->assertSame(['Trang trước', '1', 'Lùi 5 trang', '4', '5', '6*', '7', '8', 'Tới 5 trang', '11', 'Trang sau'], $items);
        $this->assertSame(route('backend.projects.index', ['status' => 'delivered', 'page' => 11]), $xp->query('//li[@title="Tới 5 trang"]/a')->item(0)->getAttribute('href'));
        $this->assertSame('Hiển thị 101–120 / 205 dự án', $this->text($xp->query('//*[contains(@class, "product-index-footer")]/span')->item(0)));

        // Trang đầu: nút trước bị khóa, không có ••• bên trái
        $xp = $this->xpath($this->actingAs($root)->get('/backend/project')->getContent());
        $this->assertSame(1, $xp->query('//li[contains(@class, "ant-pagination-prev") and contains(@class, "ant-pagination-disabled")]//button[@disabled]')->length);
        $this->assertSame(0, $xp->query('//li[@title="Lùi 5 trang"]')->length);

        $this->actingAs($root)->get('/backend/project?q=khong-co')->assertSee('Không tìm thấy dự án nào')->assertSee('Xóa bộ lọc')
            ->assertDontSee('product-index-footer', false);
    }

    public function test_page_beyond_last_goes_to_last_page(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create();
        Project::factory()->count(25)->create(['customer_id' => $customer->id, 'status' => 'delivered']);

        // Vd vừa xóa dòng cuối của trang cuối rồi quay lại: về trang cuối còn dữ liệu, giữ bộ lọc
        $this->actingAs($root)->get('/backend/project?status=delivered&page=9')
            ->assertRedirect(route('backend.projects.index', ['status' => 'delivered', 'page' => 2]));
        // Không có kết quả nào: về trang 1, báo không tìm thấy (không phải "Chưa có dự án nào")
        $this->actingAs($root)->get('/backend/project?q=khong-co&page=2')->assertRedirect(route('backend.projects.index', ['q' => 'khong-co', 'page' => 1]));
        $this->actingAs($root)->get('/backend/project?q=khong-co')->assertOk()
            ->assertSee('Không tìm thấy dự án nào')->assertDontSee('Chưa có dự án nào')
            // Bảng trống bỏ bề rộng tối thiểu: thông báo nằm gọn trong khung
            ->assertSee('class="product-table project-table min-w-0"', false);
        $this->actingAs($root)->get('/backend/project')->assertSee('class="product-table project-table"', false);
    }

    // ---- Form thêm / sửa ----

    public function test_validation_errors_show_under_each_field(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['name' => 'Tên cũ']);
        Project::factory()->create(['primary_domain' => 'dangdung.vn']);

        $this->actingAs($root)->from("/backend/project/{$project->id}/edit")
            ->put("/backend/project/{$project->id}", ['name' => '', 'customer_id' => $project->customer_id, 'status' => 'delivered', 'price' => 'năm triệu', 'primary_domain' => 'dangdung.vn'])
            ->assertRedirect("/backend/project/{$project->id}/edit");

        $html = $this->actingAs($root)->get("/backend/project/{$project->id}/edit")->assertOk()->getContent();
        $xp = $this->xpath($html);
        $error = fn (string $field) => $this->text($xp->query("//*[@name='{$field}']/ancestor::div[contains(@class, 'ant-form-item-has-error')]//*[@role='alert']")->item(0));

        $this->assertStringContainsString('tên dự án', mb_strtolower($error('name')));
        $this->assertStringContainsString('giá hợp đồng', mb_strtolower($error('price')));
        $this->assertStringContainsString('dangdung.vn', $error('primary_domain'));
        $this->assertSame('', $error('tech_stack'));
        // Giữ dữ liệu vừa nhập, form coi như chưa lưu, đầu trang chỉ nhắc một dòng
        $this->assertSame('năm triệu', $xp->query('//input[@name="price"]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $xp->query('//form[@id="project-form" and @data-dirty]')->length);
        $this->assertStringContainsString('Chưa lưu được: kiểm tra các ô báo đỏ bên dưới.', $html);
        $this->assertStringNotContainsString('Dữ liệu chưa hợp lệ', $html);
        // Thanh trên cùng giữ tên đã lưu
        $this->assertSame('Tên cũ', $this->text($xp->query('//h1[contains(@class, "page-topbar-title")]')->item(0)));
    }

    public function test_create_form(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create(['name' => 'Khách Chọn Sẵn', 'company' => null]);
        Project::factory()->create(['tech_stack' => 'WordPress']);
        Category::factory()->create(['name' => 'Coupon']);

        $xp = $this->xpath($this->actingAs($root)->get("/backend/project/create?customer_id={$customer->id}")->assertOk()->getContent());
        $this->assertSame('Khách Chọn Sẵn (0 dự án)', $this->text($xp->query('//select[@name="customer_id"]/option[@selected]')->item(0)));
        $this->assertSame(1, $xp->query('//datalist[@id="tech-list"]/option[@value="WordPress"]')->length);
        $this->assertSame(1, $xp->query('//select[@name="cat_id" and @data-allow-clear]')->length);
        $this->assertSame('Chưa có giá hợp đồng', $this->text($xp->query('//*[@data-payment-detail]')->item(0)));
        $this->assertSame(2, $xp->query('//button[@type="submit" and @form="project-form"]')->length);   // nút trên thanh + nút trong dải chưa lưu
        $this->assertSame(0, $xp->query('//*[@data-delete-url]')->length);
        $this->assertSame(1, $xp->query('//template[@id="customer-modal"]')->length);
        // Chọn khách hàng gõ để tìm được; hộp tạo nhanh không còn dòng giải thích
        $this->assertSame(1, $xp->query('//select[@name="customer_id" and @data-show-search]')->length);
        $this->assertStringNotContainsString('Tạo nhanh rồi chọn luôn', $this->actingAs($root)->get('/backend/project/create')->getContent());

        // Không có quyền tạo khách hàng: không có nút / hộp tạo nhanh
        $editor = User::factory()->create(['group_id' => Group::factory()->withPermissions('project', 'project/add')->create()->id]);
        $this->actingAs($editor)->get('/backend/project/create')->assertOk()->assertDontSee('id="new-customer"', false)->assertDontSee('customer-modal', false);
    }

    public function test_edit_form_payment_status_block(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 3_500_000]);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 2_000_000]);
        $project->recalculatePaymentStatus();

        $xp = $this->xpath($this->actingAs($root)->get("/backend/project/{$project->id}/edit")->getContent());
        $this->assertSame('2000000', $xp->query('//*[@id="payment-status"]')->item(0)->getAttribute('data-paid'));
        $this->assertSame('Thanh toán một phần', $this->text($xp->query('//*[@data-payment-tag]')->item(0)));
        $this->assertSame('Đã thu 2.000.000 / 3.500.000 VND', $this->text($xp->query('//*[@data-payment-detail]')->item(0)));
        // Đã có khoản thu: nút xóa trên thanh trên cùng bị khóa
        $this->assertSame(0, $xp->query('//*[@data-delete-url]')->length);
    }

    // ---- Chi tiết ----

    public function test_show_page_finance_and_history(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 10_000_000, 'next_renewal_date' => '2026-10-20', 'renewal_hosting_amount' => 1_000_000]);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 4_000_000, 'paid_at' => '2026-09-01']);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 1_000_000, 'type' => 'renewal', 'paid_at' => '2026-10-01']);

        $xp = $this->xpath($this->actingAs($root)->get("/backend/project/{$project->id}")->assertOk()->getContent());
        $finance = $this->text($xp->query('//*[@data-card="finance"]')->item(0));
        $this->assertStringContainsString('10.000.000 VND', $finance);
        $this->assertStringContainsString('40%', $finance);
        $this->assertStringContainsString('Đã thu (mới) 4.000.000 Còn nợ 6.000.000 Đã thu gia hạn 1.000.000', $finance);
        $this->assertStringContainsString('Tổng đã thu 5.000.000', $this->text($xp->query('//*[@data-card="payments"]//tfoot')->item(0)));
        $this->assertStringContainsString('Kỳ tới 20/10/2026 Còn 10 ngày', $this->text($xp->query('//*[@data-card="renewal"]')->item(0)));
        // Lịch sử: mỗi thay đổi là một mục timeline có màu theo hành động
        $this->assertSame(3, $xp->query('//*[@data-card="history"]//li[contains(@class, "ant-timeline-item")]')->length);
        $this->assertSame(1, $xp->query('//li[contains(@class, "ant-timeline-item-last")]')->length);
        $this->assertGreaterThan(0, $xp->query('//*[contains(@class, "ant-timeline-item-head-green")]')->length);
        // Có khoản thu: không xóa được dự án; sửa / xóa khoản thu ngay trên bảng
        $this->assertSame(0, $xp->query('//*[@data-delete-url="'.route('backend.projects.destroy', $project).'"]')->length);
        $this->assertSame(2, $xp->query('//*[@data-card="payments"]//tbody//*[@data-payment-open and @data-payment]')->length);
        $this->assertSame(2, $xp->query('//*[@data-card="payments"]//*[@data-delete-url]')->length);
    }

    public function test_show_page_permissions(): void
    {
        $project = Project::factory()->create();
        Payment::factory()->create(['project_id' => $project->id]);
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);

        $this->actingAs($viewer)->get("/backend/project/{$project->id}")->assertOk()
            ->assertDontSee('Sửa dự án')->assertDontSee('data-payment-open', false)->assertDontSee('id="payment-modal"', false)
            ->assertDontSee('data-delete-url', false);
    }

    public function test_payment_modal_reopens_with_errors(): void
    {
        $root = $this->root();
        $project = Project::factory()->create();
        $payment = Payment::factory()->create(['project_id' => $project->id, 'amount' => 1_000_000, 'note' => 'Cũ']);
        $show = "/backend/project/{$project->id}";

        // Thêm mới lỗi: mở lại hộp "thêm", giữ dữ liệu vừa nhập, lỗi dưới ô số tiền
        $this->actingAs($root)->from($show)->post('/backend/payment', ['project_id' => $project->id, 'amount' => '', 'type' => 'new', 'paid_at' => '2026-10-05', 'note' => 'Đợt 2']);
        $xp = $this->xpath($this->actingAs($root)->get($show)->getContent());
        $this->assertSame('new', $xp->query('//*[@id="payment-modal"]')->item(0)->getAttribute('data-reopen'));
        $this->assertNotSame('', $this->text($xp->query('//*[@name="amount"]/ancestor::div[contains(@class, "ant-form-item-has-error")]//*[@role="alert"]')->item(0)));
        $this->assertSame('2026-10-05', $xp->query('//*[@name="paid_at"]')->item(0)->getAttribute('value'));
        $this->assertSame('Đợt 2', $this->text($xp->query('//textarea[@name="note"]')->item(0)));

        // Sửa lỗi: mở lại đúng khoản đang sửa
        $this->actingAs($root)->from($show)->put("/backend/payment/{$payment->id}", ['project_id' => $project->id, 'amount' => '0', 'type' => 'new', 'paid_at' => '2026-10-05']);
        $xp = $this->xpath($this->actingAs($root)->get($show)->getContent());
        $this->assertSame((string) $payment->id, $xp->query('//*[@id="payment-modal"]')->item(0)->getAttribute('data-reopen'));

        // Mở bình thường: hộp ẩn, ngày thu mặc định hôm nay, không còn dữ liệu cũ
        $xp = $this->xpath($this->actingAs($root)->get($show)->getContent());
        $modal = $xp->query('//*[@id="payment-modal"]')->item(0);
        $this->assertSame('', $modal->getAttribute('data-reopen'));
        $this->assertStringContainsString('hidden', $modal->getAttribute('class'));
        $this->assertSame('2026-10-10', $xp->query('//*[@name="paid_at"]')->item(0)->getAttribute('value'));
        $this->assertSame('', $this->text($xp->query('//textarea[@name="note"]')->item(0)));
    }
}
