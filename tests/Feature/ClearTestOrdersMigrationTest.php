<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\Level;
use App\Models\LevelHistory;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migration 2026_11_23_000001_clear_test_orders: xóa sạch đơn thử trước khi nhận đơn thật — trả tồn kho đang giữ, đưa doanh
 * số cộng dồn về 0, gỡ cấp / kích hoạt có được nhờ đơn thử; không đụng tới thành viên không liên quan đến đơn.
 */
class ClearTestOrdersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private int $reference = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
    }

    private function payOrder(Member $member, int $goods): Order
    {
        $order = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => $goods, 'shipping_fee' => 30000, 'total_amount' => $goods + 30000]);
        $this->actingAs($this->accountant, 'web')->post(route('backend.orders.confirm-payment', $order),
            ['payment_reference' => 'FT'.(++$this->reference), 'paid_amount' => (string) $order->total_amount])->assertSessionHas('success');

        return $order->fresh();
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_23_000001_clear_test_orders.php'))->up();
    }

    /** [tên cấp, doanh số cộng dồn, đã kích hoạt?] */
    private function state(Member $member): array
    {
        $member = $member->fresh();

        return [$member->level->name, $member->cumulative_amount, $member->activated_at !== null];
    }

    public function test_it_deletes_every_order_and_what_the_orders_produced(): void
    {
        // A: lên cấp và kích hoạt hoàn toàn nhờ đơn thử
        $a = Member::factory()->create();
        $this->payOrder($a, 5_000_000);
        $this->assertSame(['Cộng tác viên', 5_000_000, true], $this->state($a));

        // B: được quản trị kích hoạt thủ công trước, rồi mua đơn thử lên Đại lý
        $b = Member::factory()->create();
        $b->activateManually('Kích hoạt khi duyệt KYC');
        $this->payOrder($b, 20_000_000);
        $this->assertSame(['Đại lý', 20_000_000, true], $this->state($b));

        // C: đã kích hoạt, chưa từng có đơn, không có vết lên cấp (như các tài khoản thật đang có trên live)
        $c = Member::factory()->create(['level_id' => Level::where('threshold_amount', 1)->value('id'), 'activated_at' => now()->subDays(3)]);

        // D: đơn chưa thanh toán đang giữ 4 sản phẩm có theo dõi tồn + 2 sản phẩm không theo dõi tồn
        $category = Category::factory()->create();
        $tracked = Product::factory()->create(['category_id' => $category->id, 'stock' => 6]);
        $free = Product::factory()->create(['category_id' => $category->id, 'stock' => null]);
        $d = Member::factory()->create();
        $held = Order::factory()->create(['member_id' => $d->id, 'stock_reserved' => true]);
        foreach ([[$tracked, 4], [$free, 2]] as [$product, $qty]) {
            OrderItem::create(['order_id' => $held->id, 'product_id' => $product->id, 'product_name' => $product->name, 'qty' => $qty,
                'unit_price' => 100000, 'vat_rate' => 10, 'line_amount' => 100000 * $qty, 'vat_amount' => 0]);
        }
        // Đơn đã hủy (không giữ hàng): xóa nhưng không cộng tồn
        $cancelled = Order::factory()->create(['member_id' => $d->id, 'status' => Order::STATUS_CANCELLED, 'stock_reserved' => false]);
        OrderItem::create(['order_id' => $cancelled->id, 'product_id' => $tracked->id, 'product_name' => $tracked->name, 'qty' => 9,
            'unit_price' => 100000, 'vat_rate' => 10, 'line_amount' => 900000, 'vat_amount' => 0]);

        $this->assertSame([4, 3], [Order::count(), LevelHistory::count()]);
        $membersBefore = Member::count();

        $this->runMigration();

        $this->assertSame([0, 0], [Order::count(), OrderItem::count()]);
        $this->assertSame($membersBefore, Member::count());
        // A về cấp gốc, hết kích hoạt; B về cấp của lần kích hoạt thủ công và vẫn kích hoạt; C, D nguyên vẹn
        $this->assertSame(['Chưa kích hoạt', 0, false], $this->state($a));
        $this->assertSame(['Nhân viên', 0, true], $this->state($b));
        $this->assertSame(['Nhân viên', 0, true], $this->state($c));
        $this->assertSame(['Chưa kích hoạt', 0, false], $this->state($d));
        // Chỉ còn vết kích hoạt thủ công của B
        $history = LevelHistory::sole();
        $this->assertSame([$b->id, null, 'Kích hoạt thủ công', 0], [$history->member_id, $history->order_id, $history->note, $history->cumulative_after]);
        // Tồn kho: trả 4 của đơn đang giữ; đơn đã hủy không cộng; sản phẩm không theo dõi tồn vẫn NULL
        $this->assertSame([10, null], [$tracked->fresh()->stock, $free->fresh()->stock]);

        // Sau đó đơn thật chạy bình thường từ đầu
        $this->payOrder($a, 200_000);
        $this->assertSame(['Nhân viên', 200_000, true], $this->state($a));
        $this->assertSame(1, Order::count());
    }

    public function test_it_is_safe_on_a_database_without_orders(): void
    {
        $member = Member::factory()->create(['level_id' => Level::where('threshold_amount', 1)->value('id'), 'activated_at' => now()]);

        $this->runMigration();

        $this->assertSame(['Nhân viên', 0, true], $this->state($member));
        $this->assertSame(0, Order::count());
    }
}
