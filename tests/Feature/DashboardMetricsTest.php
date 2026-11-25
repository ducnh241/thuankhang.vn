<?php

namespace Tests\Feature;

use App\Http\Controllers\Backend\DashboardController;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\DashboardMetrics;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dashboard (A1): việc cần xử lý, doanh thu, thẻ số liệu, đơn theo trạng thái, thành viên theo cấp bậc, so sánh với kỳ
 * trước (tháng / quý / năm — cùng kỳ tới đúng thời điểm tương ứng), mỗi khối theo quyền.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function staff(string $group): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $group)->value('id')]);
    }

    private function paid(Member $member, int $amount, string $at, string $method = Order::PAYMENT_TRANSFER): Order
    {
        $this->travelTo(Carbon::parse($at)->subHour());
        $order = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => $amount, 'total_amount' => $amount, 'payment_method' => $method]);
        $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => Carbon::parse($at), 'paid_amount' => $amount])->save();

        return $order;
    }

    /** Dữ liệu mẫu, "bây giờ" = 20/11/2026 10:00. */
    private function seedData(): array
    {
        $this->travelTo(Carbon::create(2025, 6, 1, 9));
        $old = Member::factory()->create(['full_name' => 'Thành viên cũ']);
        $this->travelTo(Carbon::create(2026, 10, 5, 9));
        $oct = Member::factory()->count(2)->create();
        $this->travelTo(Carbon::create(2026, 11, 3, 9));
        $nov = Member::factory()->count(3)->create();
        $nov[0]->forceFill(['status' => Member::STATUS_LOCKED])->saveQuietly();
        $nov[1]->forceFill(['level_id' => Level::where('name', 'Cộng tác viên')->value('id'), 'activated_at' => now()])->saveQuietly();
        $oct[0]->forceFill(['level_id' => Level::where('name', 'Đại lý')->value('id'), 'activated_at' => Carbon::create(2026, 10, 6, 9)])->saveQuietly();

        // Doanh thu: năm trước, quý trước, tháng trước (trước và sau mốc cùng kỳ ngày 20), tháng này (chuyển khoản + ví)
        $this->paid($old, 1000000, '2025-11-10 09:00');
        $this->paid($old, 2000000, '2026-08-15 09:00');
        $this->paid($oct[0], 3000000, '2026-10-10 09:00');
        $this->paid($oct[1], 4000000, '2026-10-25 09:00');
        $novOrders = [$this->paid($nov[1], 5000000, '2026-11-05 09:00'), $this->paid($nov[2], 6000000, '2026-11-20 08:00', Order::PAYMENT_WALLET)];
        $this->travelTo(Carbon::create(2026, 11, 18, 9));
        Order::factory()->create(['member_id' => $nov[2]->id])->forceFill(['status' => Order::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();
        Order::factory()->create(['member_id' => $nov[2]->id])->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
        [$p1] = [Product::factory()->create(['category_id' => $category->id, 'status' => true, 'stock' => null])];
        Product::factory()->create(['category_id' => $category->id, 'status' => true, 'stock' => 0]);
        Product::factory()->create(['category_id' => $category->id, 'status' => false]);
        ProductReview::create(['product_id' => $p1->id, 'member_id' => $old->id, 'rating' => 5, 'comment' => 'Tốt']);
        ProductReview::create(['product_id' => $p1->id, 'member_id' => $oct[0]->id, 'rating' => 4, 'comment' => 'Ổn']);
        ProductReview::create(['product_id' => $p1->id, 'member_id' => $oct[1]->id, 'rating' => 1, 'comment' => 'Ẩn'])->forceFill(['is_visible' => false])->save();
        MemberKyc::factory()->create(['member_id' => $old->id, 'status' => MemberKyc::STATUS_APPROVED]);
        MemberKyc::factory()->create(['member_id' => $oct[0]->id, 'status' => MemberKyc::STATUS_PENDING]);
        MemberKyc::factory()->create(['member_id' => $oct[1]->id, 'status' => MemberKyc::STATUS_REJECTED]);
        $topup = WalletTopup::start($oct[0], 500000);
        $topup->forceFill(['status' => WalletTopup::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();

        $this->travelTo(Carbon::create(2026, 11, 20, 10));

        return compact('old', 'oct', 'nov', 'novOrders', 'category');
    }

    public function test_dashboard_figures_and_month_comparison(): void
    {
        $this->seedData();
        $html = $this->actingAs(User::factory()->superAdmin()->create(), 'web')->get(route('backend.dashboard'))->assertOk()
            // Việc cần xử lý
            ->assertSee('data-todo="orders" data-count="1"', false)->assertSee('data-todo="kyc" data-count="1"', false)
            ->assertSee('data-todo="topups" data-count="1"', false)->assertSee('data-todo="profit_share" data-count="0"', false)
            // Doanh thu tháng 11: 5tr + 6tr; hôm nay 6tr; tổng 21tr; theo hình thức
            ->assertSee('data-revenue-month>11.000.000 đ', false)->assertSee('data-revenue-today>6.000.000 đ', false)
            ->assertSee('data-revenue-total>21.000.000 đ', false)
            ->assertSeeInOrder(['data-method="transfer"', 'Chuyển khoản', '1 đơn', '5.000.000 đ', 'data-method="wallet"', 'Ví tái tiêu dùng', '1 đơn', '6.000.000 đ'], false)
            // Cùng kỳ tháng trước (1/10 – 20/10 10:00) = 3tr → +266,7%; cả tháng 10 = 7tr
            ->assertSee('▲ +267%')->assertSee('so với cùng kỳ tháng trước (3.000.000 đ)')->assertSee('Cả tháng trước: 7.000.000 đ')
            ->getContent();

        // Thẻ số liệu
        $this->assertStringContainsString('data-card="products"', $html);
        foreach ([['products', '3', ['Đang bán', '2', 'Ngừng bán', '1', 'Hết hàng', '1']], ['orders', '8', ['Đặt tháng này', '4', 'Đã thanh toán', '6', 'Đã hủy', '1']],
            ['reviews', '3', ['Điểm trung bình', '4,5 ★', 'Đang hiện', '2', 'Đang ẩn', '1']], ['members', '6', ['Đã kích hoạt', '2', 'Mới tháng này', '3', 'Bị khóa', '1']],
            ['kyc', '3', ['Chờ duyệt', '1', 'Đã duyệt', '1', 'Không duyệt', '1']]] as [$card, $main, $details]) {
            $chunk = substr($html, strpos($html, 'data-card="'.$card.'"'), 2500);
            $this->assertStringContainsString('data-main>'.$main.'<', $chunk, $card);
            $previous = 0;
            foreach ($details as $text) {
                $at = strpos($chunk, $text, $previous);
                $this->assertNotFalse($at, "{$card}: {$text}");
                $previous = $at;
            }
        }
        // Thành viên theo cấp bậc: 4 chưa kích hoạt, 1 CTV, 1 Đại lý (/6)
        $this->assertStringContainsString('data-level="Chưa kích hoạt" data-count="4"', $html);
        $this->assertStringContainsString('data-level="Cộng tác viên" data-count="1"', $html);
        $this->assertStringContainsString('data-level="Đại lý" data-count="1"', $html);
        $this->assertStringContainsString('(66,7%)', $html);

        // Bảng so sánh tháng
        $this->get(route('backend.dashboard'))->assertSeeInOrder(['So sánh với kỳ trước', 'Tháng 11/2026', '01/11 – 20/11/2026 10:00', 'Cùng kỳ tháng 10/2026', '01/10 – 20/10/2026 09:59',
            'Doanh thu', '11.000.000 đ', '3.000.000 đ', '▲ +266,7%', '7.000.000 đ',
            'Đơn đã thanh toán', '2', '1', '▲ +100%', '2',
            'Giá trị đơn trung bình', '5.500.000 đ', '3.000.000 đ', '▲ +83,3%', '3.500.000 đ',
            'Thành viên đăng ký mới', '3', '2', '▲ +50%', '2',
            'Thành viên kích hoạt mới', '1', '1', '0%', '1']);
    }

    public function test_quarter_and_year_comparison(): void
    {
        $this->seedData();
        $admin = User::factory()->superAdmin()->create();
        // Quý 4/2026 (1/10 – 20/11) = 3 + 4 + 5 + 6 = 18tr; cùng kỳ quý 3 (1/7 – 20/8 10:00) = 2tr → +800%
        $this->actingAs($admin, 'web')->get(route('backend.dashboard', ['ky' => 'quy']))->assertOk()
            ->assertSee('data-compare="quy"', false)->assertSeeInOrder(['data-period="thang"', 'ant-btn-primary', 'aria-current="true"', 'data-period="quy"', 'data-period="nam"'], false)
            ->assertSeeInOrder(['Quý 4/2026', '01/10 – 20/11/2026 10:00', 'Cùng kỳ quý 3/2026', '01/07 – 20/08/2026 09:59', 'Doanh thu', '18.000.000 đ', '2.000.000 đ', '▲ +800%', '2.000.000 đ']);
        // Năm 2026 = 20tr (8/2026 + quý 4); cùng kỳ 2025 (1/1 – 20/11/2025) = 1tr → +1.900%
        $this->get(route('backend.dashboard', ['ky' => 'nam']))->assertOk()
            ->assertSeeInOrder(['Năm 2026', 'Cùng kỳ năm 2025', 'Doanh thu', '20.000.000 đ', '1.000.000 đ', '▲ +1900%', '1.000.000 đ',
                'Thành viên đăng ký mới', '5', '1', '▲ +400%', '1']);
        // Kỳ lạ trên URL → tháng
        $this->get(route('backend.dashboard', ['ky' => 'abc']))->assertOk()->assertSee('data-compare="thang"', false);
    }

    public function test_same_period_is_capped_at_the_end_of_the_shorter_previous_period(): void
    {
        // 31/12 12:00 so với tháng 11 (30 ngày): cùng kỳ không vượt quá hết tháng 11
        [$start, $now, $prevStart, $prevToDate, $prevEnd] = DashboardController::ranges('thang', CarbonImmutable::create(2026, 12, 31, 12));
        $this->assertSame(['2026-12-01 00:00', '2026-11-01 00:00', '2026-12-01 00:00', '2026-12-01 00:00'],
            [$start->format('Y-m-d H:i'), $prevStart->format('Y-m-d H:i'), $prevToDate->format('Y-m-d H:i'), $prevEnd->format('Y-m-d H:i')]);
        // 1/3 so với tháng 2: 1/2 cùng giờ
        [, , , $toDate] = DashboardController::ranges('thang', CarbonImmutable::create(2027, 3, 1, 8, 30));
        $this->assertSame('2027-02-01 08:30', $toDate->format('Y-m-d H:i'));
        // Quý 1/2027 so với quý 4/2026; năm nhuận 29/2/2028 so với 2027
        [$q, , $pq] = DashboardController::ranges('quy', CarbonImmutable::create(2027, 2, 10));
        $this->assertSame(['2027-01-01', '2026-10-01'], [$q->format('Y-m-d'), $pq->format('Y-m-d')]);
        [, , $py, $pyTo] = DashboardController::ranges('nam', CarbonImmutable::create(2028, 12, 31, 23));
        $this->assertSame(['2027-01-01', '2028-01-01 00:00'], [$py->format('Y-m-d'), $pyTo->format('Y-m-d H:i')]);
    }

    public function test_sections_follow_permissions(): void
    {
        $this->seedData();
        // Chỉ quyền đánh giá: thấy thẻ đánh giá, không thấy doanh thu / so sánh / thành viên
        $reviewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'review')->create()->id]);
        $this->actingAs($reviewer, 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-card="reviews"', false)->assertDontSee('data-section="revenue"', false)->assertDontSee('data-section="compare"', false)
            ->assertDontSee('data-card="members"', false)->assertDontSee('data-section="levels"', false)->assertDontSee('data-todo=', false);
        // Kế toán: doanh thu, đơn chờ xác nhận, nạp ví, đồng chia; không thấy KYC / thành viên; so sánh không có dòng thành viên
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-section="revenue"', false)->assertSee('data-todo="orders"', false)->assertSee('data-todo="topups"', false)
            ->assertSee('data-todo="profit_share"', false)->assertDontSee('data-todo="kyc"', false)->assertDontSee('data-card="members"', false)
            ->assertSee('Hoa hồng phát sinh')->assertSee('Tiền nạp ví đã xác nhận')->assertDontSee('Thành viên đăng ký mới');
        // Giám đốc (chỉ xem đồng chia): chỉ có ô đồng chia
        $this->actingAs($this->staff('Giám đốc'), 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-todo="profit_share"', false)->assertDontSee('data-section="revenue"', false)->assertDontSee('data-section="compare"', false);
        // Trong hạn duyệt đồng chia: ô đồng chia sáng lên
        $this->travelTo(Carbon::create(2026, 12, 3, 9));
        $this->get(route('backend.dashboard'))->assertSee('data-todo="profit_share" data-count="1"', false)->assertSee('Tháng 11/2026 — hạn hết 05/12');
    }

    public function test_extended_metrics_follow_the_selected_period(): void
    {
        ['old' => $old, 'oct' => $oct, 'nov' => $nov, 'novOrders' => [$o1, $o2], 'category' => $category] = $this->seedData();
        // Sản phẩm bán ra trong tháng 11: B (2 đơn, 4 cái, 7tr) đứng trên A (1 đơn, 2 cái, 4tr)
        $a = Product::factory()->create(['category_id' => $category->id, 'name' => 'Sâm A']);
        $b = Product::factory()->create(['category_id' => $category->id, 'name' => 'Trà B']);
        foreach ([[$o1, $a, 2, 4000000], [$o1, $b, 1, 1000000], [$o2, $b, 3, 6000000]] as [$order, $product, $qty, $line]) {
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'qty' => $qty, 'unit_price' => intdiv($line, $qty), 'line_amount' => $line]);
        }
        // Đơn 5tr nhận tại VP Hà Nội
        $point = PickupPoint::factory()->create(['name' => 'VP Hà Nội', 'province' => 'ha-noi']);
        $o1->forceFill(['delivery_type' => Order::DELIVERY_PICKUP, 'pickup_point_id' => $point->id])->save();
        // Hủy: đơn hủy sẵn là khách tự hủy; thêm một đơn mới tạo 10/11 đã quá hạn (lệnh tự hủy chưa chạy)
        Order::where('status', Order::STATUS_CANCELLED)->update(['cancel_source' => Order::CANCEL_CUSTOMER]);
        $this->travelTo(Carbon::create(2026, 11, 10, 9));
        Order::factory()->create(['member_id' => $old->id, 'expires_at' => now()->addDay()]);
        $this->travelTo(Carbon::create(2026, 11, 20, 10));
        // Hoa hồng phát sinh tháng 11: 285.000 (ví rút 199.500 · TTD 57.000 · thuế 28.500); một dòng không được nhận không tính
        Commission::create(['order_id' => $o1->id, 'buyer_id' => $nov[1]->id, 'beneficiary_id' => $oct[0]->id, 'tier' => 'F1', 'goods_amount' => 5000000, 'fee_percent' => 5,
            'base_amount' => 4750000, 'amount' => 285000, 'cash_amount' => 199500, 'reconsume_amount' => 57000, 'tax_amount' => 28500, 'status' => Commission::STATUS_CREDITED, 'created_at' => $o1->paid_at]);
        Commission::create(['order_id' => $o1->id, 'buyer_id' => $nov[1]->id, 'beneficiary_id' => $old->id, 'tier' => 'F2', 'goods_amount' => 5000000, 'fee_percent' => 5,
            'base_amount' => 4750000, 'amount' => 0, 'status' => Commission::STATUS_SKIPPED, 'skip_reason' => 'thử', 'created_at' => $o1->paid_at]);
        // Ví: rút 600k (đủ mức rút 500k) + 100k; tái tiêu dùng 50k
        DB::transaction(function () use ($oct, $nov) {
            Wallet::post($oct[0], WalletTransaction::WALLET_CASH, WalletTransaction::TYPE_TOPUP, 600000, 'thử');
            Wallet::post($oct[1], WalletTransaction::WALLET_CASH, WalletTransaction::TYPE_TOPUP, 100000, 'thử');
            Wallet::post($nov[1], WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_TOPUP, 50000, 'thử');
        });
        // Giới thiệu trong tháng 11: oct[0] 2 người, old 1 người
        $nov[0]->forceFill(['referrer_id' => $oct[0]->id])->saveQuietly();
        $nov[1]->forceFill(['referrer_id' => $oct[0]->id])->saveQuietly();
        $nov[2]->forceFill(['referrer_id' => $old->id])->saveQuietly();
        // Cây nhị phân: old ← A: oct[0] ← A: nov[1] (5tr); old ← B: oct[1] ← A: nov[2] (6tr) → nhóm nhỏ của old = 5tr
        foreach ([[$oct[0], $old, 'L'], [$oct[1], $old, 'R'], [$nov[1], $oct[0], 'L'], [$nov[2], $oct[1], 'L']] as [$child, $parent, $side]) {
            $child->forceFill(['parent_id' => $parent->id, 'position' => $side])->saveQuietly();
        }
        $old->forceFill(['parent_id' => null, 'position' => null])->saveQuietly();

        $html = $this->actingAs(User::factory()->superAdmin()->create(), 'web')->get(route('backend.dashboard'))->assertOk()
            // 12 tháng: 12/2025 → 11/2026 (đơn 10/11/2025 nằm ngoài)
            ->assertSeeInOrder(['data-month="2025-12" data-total="0"', 'data-month="2026-08" data-total="2000000"', 'data-month="2026-10" data-total="7000000"', 'data-month="2026-11" data-total="11000000"'], false)
            ->assertDontSee('data-month="2025-11"', false)
            ->assertSeeInOrder(['Top 10 sản phẩm bán chạy', 'Tháng 11/2026 (tới nay)', 'Trà B', '4', '2', '7.000.000 đ', 'Sâm A', '2', '1', '4.000.000 đ'])
            ->assertSeeInOrder(['Doanh thu theo nơi nhận', 'data-pickup="Giao tận nơi" data-total="6000000"', 'data-pickup="VP Hà Nội" data-total="5000000"', 'Hà Nội'], false)
            // Chi trả: 285.000 / 11.000.000 = 2,6%
            ->assertSee('data-payout-ratio>2,6%', false)->assertSee('data-payout-total>285.000 đ', false)
            ->assertSee('→ ví rút 199.500 đ · ví tái tiêu dùng 57.000 đ · thuế TNCN 28.500 đ')
            ->assertSee('data-wallet-cash>700.000 đ', false)->assertSee('data-wallet-reconsume>50.000 đ', false)
            ->assertSeeInOrder(['data-withdrawable', '1', 'thành viên có ví rút tiền từ 500.000 đ', '600.000 đ'], false)
            // Hủy: 5 đơn đặt tháng 11 (2 đã thanh toán, 1 chờ, 2 hủy: khách 1, quá hạn 1) → 40%
            ->assertSee('data-cancel-rate>40%', false)
            ->assertSeeInOrder(['data-cancel-source="customer"', '1', 'data-cancel-source="admin"', '0', 'data-cancel-source="expired"', '1'], false)
            ->getContent();
        $this->assertMatchesRegularExpression('/data-list="recruiters".*?data-member="'.$oct[0]->id.'".*?2 người.*?data-member="'.$old->id.'".*?1 người/s', $html);
        $this->assertMatchesRegularExpression('/data-list="teams".*?data-member="'.$old->id.'".*?5\.000\.000 đ.*?A 5\.000\.000 đ · B 6\.000\.000 đ/s', $html);
        // Chưa kích hoạt sau 30 ngày: old (6/2025), oct[1] (5/10); oct[0] đã kích hoạt, nov còn mới
        $this->assertStringContainsString('data-inactive-count>2<', $html);
        $this->assertMatchesRegularExpression('/data-inactive-member="'.$old->id.'".*data-inactive-member="'.$oct[1]->id.'"/s', $html);

        // Chọn năm: top sản phẩm / nơi nhận / hủy tính cả năm 2026
        $this->get(route('backend.dashboard', ['ky' => 'nam']))->assertSee('Năm 2026 (tới nay)')->assertSee('data-pickup="Giao tận nơi" data-total="15000000"', false);
    }

    public function test_extended_sections_follow_permissions(): void
    {
        $this->seedData();
        // Kế toán (order + commission): biểu đồ, sản phẩm, chi trả, ví; không có danh sách thành viên
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-section="monthly"', false)->assertSee('data-section="top-products"', false)->assertSee('data-section="payout"', false)
            ->assertSee('data-section="wallets"', false)->assertSee('data-section="cancellations"', false)
            ->assertDontSee('data-section="top-members"', false)->assertDontSee('data-section="inactive"', false);
        // Chỉ quyền đánh giá: không khối mở rộng nào
        $reviewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'review')->create()->id]);
        $this->actingAs($reviewer, 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertDontSee('data-section="monthly"', false)->assertDontSee('data-section="wallets"', false)->assertDontSee('data-section="payout"', false)
            ->assertDontSee('data-section="top-members"', false)->assertDontSee('data-section="inactive"', false);
        // Chỉ quyền thành viên: thành viên nổi bật, chưa kích hoạt, số dư ví; không doanh thu / chi trả
        $memberOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'member')->create()->id]);
        $this->actingAs($memberOnly, 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-section="top-members"', false)->assertSee('data-section="inactive"', false)->assertSee('data-section="wallets"', false)
            ->assertDontSee('data-section="monthly"', false)->assertDontSee('data-section="payout"', false);
    }

    public function test_cumulative_revenue_chart_data_and_rendering(): void
    {
        $this->seedData(); // bây giờ 20/11/2026 10:00 — T11: 5tr (5/11) + 6tr (20/11); T10: 3tr (10/10) + 4tr (25/10)
        $now = CarbonImmutable::now();

        $month = DashboardMetrics::cumulativeRevenue('thang', $now);
        $this->assertCount(31, $month['points']); // tháng 10 có 31 ngày, tháng 11 có 30
        $this->assertSame([11000000, 7000000, 11000000], [$month['current'], $month['previous'], $month['max']]);
        $p = $month['points'];
        $this->assertSame([0, 5000000, 5000000, 11000000, null], [$p[3][2], $p[4][2], $p[18][2], $p[19][2], $p[20][2]]); // dừng ở hôm nay
        $this->assertSame([0, 3000000, 3000000, 7000000, 7000000], [$p[8][3], $p[9][3], $p[23][3], $p[24][3], $p[30][3]]); // cả tháng 10
        $this->assertSame(['1', 'Ngày 1', '5', '30', ''], [$p[0][0], $p[0][1], $p[4][0], $p[29][0], $p[30][0]]);

        $year = DashboardMetrics::cumulativeRevenue('nam', $now);
        $this->assertCount(12, $year['points']);
        $this->assertSame([0, 2000000, 9000000, 20000000, null], [$year['points'][6][2], $year['points'][7][2], $year['points'][9][2], $year['points'][10][2], $year['points'][11][2]]);
        $this->assertSame([1000000, 1000000], [$year['points'][11][3], $year['previous']]);
        $this->assertSame(['T1', 'T12'], [$year['points'][0][0], $year['points'][11][0]]);

        $quarter = DashboardMetrics::cumulativeRevenue('quy', $now);
        $this->assertCount(92, $quarter['points']); // quý 3 và quý 4 đều 92 ngày
        $this->assertSame(['T10', 'T11', 'T12'], [$quarter['points'][0][0], $quarter['points'][31][0], $quarter['points'][61][0]]);
        $this->assertSame([18000000, 2000000], [$quarter['current'], $quarter['previous']]);

        $this->actingAs(User::factory()->superAdmin()->create(), 'web')->get(route('backend.dashboard'))->assertOk()
            ->assertSee('data-trend="thang"', false)->assertSee('data-trend-current>11.000.000 đ', false)->assertSee('data-trend-previous>7.000.000 đ', false)
            ->assertSee('<title>Ngày 5&#10;Tháng 11/2026: 5.000.000 đ&#10;Tháng 10/2026: 0 đ</title>', false)
            ->assertSeeInOrder(['data-metric="Doanh thu"', 'data-bars', 'width: 100%', 'width: 27%'], false);
        // Không có quyền đơn hàng: không có biểu đồ
        $memberOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'member')->create()->id]);
        $this->actingAs($memberOnly, 'web')->get(route('backend.dashboard'))->assertOk()->assertDontSee('data-trend=', false)->assertSee('data-bars', false);
    }
}
