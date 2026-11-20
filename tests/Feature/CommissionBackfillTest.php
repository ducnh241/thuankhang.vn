<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\WalletTransaction;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lệnh commissions:backfill — tính bù hoa hồng cho đơn đã thanh toán từ trước khi có engine hoa hồng (dữ liệu live).
 * Mặc định chỉ xem trước; --apply mới ghi. Cấp / kích hoạt của người hưởng lấy tại lúc đơn được thanh toán. Chỉ thêm dòng
 * vào sổ hoa hồng + ví, không xóa hay sửa đơn / thành viên. Ma trận: Nhân viên 0 / 0 · Cộng tác viên 10 / 7 · Đại lý 20 / 7.
 */
class CommissionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $reference = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        // Chia ví 70 / 20 / 10 có test riêng (WalletSplitTest); ở đây để 100% hoa hồng vào ví rút tiền cho dễ đối chiếu
        \App\Models\Setting::put('aff_pit_percent', '0.00');
        \App\Models\Setting::put('aff_reconsume_percent', '0.00');
        // Phiên bản tỉ lệ có hiệu lực từ 60 ngày trước (trước mọi đơn cũ); bản mặc định của seeder có hiệu lực từ "bây giờ"
        foreach (['Nhân viên' => [0, 0], 'Cộng tác viên' => [10, 7], 'Đại lý' => [20, 7]] as $name => [$f1, $f2]) {
            $levelId = Level::where('name', $name)->value('id');
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => $f1, 'effective_from' => now()->subDays(60)]);
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F2', 'rate_percent' => $f2, 'effective_from' => now()->subDays(60)]);
        }
    }

    /** Đơn cũ đã thanh toán $daysAgo ngày trước, chưa tính hoa hồng, chưa cộng doanh số (như dữ liệu trước khi có engine). */
    private function oldPaidOrder(Member $buyer, int $goods, int $daysAgo, string $status = Order::STATUS_PAID): Order
    {
        $paidAt = Carbon::now()->subDays($daysAgo)->startOfHour();

        return Order::factory()->create([
            'member_id' => $buyer->id, 'goods_amount' => $goods, 'vat_amount' => 0, 'shipping_fee' => 30000, 'total_amount' => $goods + 30000,
            'status' => $status, 'paid_at' => $paidAt, 'paid_amount' => $goods + 30000, 'payment_reference' => 'OLD'.(++$this->reference),
            'created_at' => $paidAt->copy()->subHour(),
        ]);
    }

    /** [id người hưởng, cấp, tỉ lệ, cơ sở, tiền, trạng thái] */
    private function line(Order $order, string $tier): ?array
    {
        $line = Commission::where('order_id', $order->id)->where('tier', $tier)->first();

        return $line ? [$line->beneficiary_id, $line->level_name, $line->rate_percent, $line->base_amount, $line->amount, $line->status] : null;
    }

    /**
     * A ← B ← C, năm đơn cũ theo thời gian:
     *   20 ngày trước  A tự mua 16.500.000            → A lên Đại lý từ đây
     *   15 ngày trước  C mua 1.100.000                → F1 = B (lúc đó chưa kích hoạt), F2 = A (Đại lý)
     *   10 ngày trước  B tự mua 5.500.000             → F1 = A; B lên Cộng tác viên từ đây
     *    5 ngày trước  C mua 2.200.000                → F1 = B (lúc đó Cộng tác viên), F2 = A
     *    2 ngày trước  B tự mua 11.000.000            → F1 = A; B lên Đại lý (cấp HIỆN TẠI của B)
     */
    private function oldData(): array
    {
        $a = Member::factory()->create(['full_name' => 'Trần Văn Gốc']);
        $b = Member::factory()->create(['full_name' => 'Lê Thị Bình', 'referrer_id' => $a->id]);
        $c = Member::factory()->create(['full_name' => 'Phạm Văn Cường', 'referrer_id' => $b->id]);
        $orders = [
            'a1' => $this->oldPaidOrder($a, 16_500_000, 20, Order::STATUS_COMPLETED),
            'c1' => $this->oldPaidOrder($c, 1_100_000, 15, Order::STATUS_COMPLETED),
            'b1' => $this->oldPaidOrder($b, 5_500_000, 10, Order::STATUS_PROCESSING),
            'c2' => $this->oldPaidOrder($c, 2_200_000, 5),
            'b2' => $this->oldPaidOrder($b, 11_000_000, 2),
        ];
        // Đơn chưa thanh toán / đã hủy: không bao giờ được tính
        $orders['unpaid'] = Order::factory()->create(['member_id' => $c->id, 'goods_amount' => 9_900_000]);
        $orders['cancelled'] = Order::factory()->create(['member_id' => $c->id, 'goods_amount' => 9_900_000, 'status' => Order::STATUS_CANCELLED]);

        return [$a, $b, $c, $orders];
    }

    public function test_it_stops_until_paid_orders_have_been_counted_for_levels(): void
    {
        [$a, $b] = $this->oldData();

        $this->artisan('commissions:backfill --apply')
            ->expectsOutputToContain('Còn 5 đơn đã thanh toán chưa được cộng doanh số')
            ->expectsOutputToContain('php artisan levels:count-paid-orders')
            ->assertFailed();
        $this->assertSame([0, 0], [Commission::count(), WalletTransaction::count()]);
    }

    public function test_preview_writes_nothing_and_apply_pays_by_level_at_payment_time(): void
    {
        [$a, $b, $c, $orders] = $this->oldData();
        $this->artisan('levels:count-paid-orders')->assertSuccessful();
        $this->assertSame(['Đại lý', 'Đại lý', 'Cộng tác viên'], [$a->fresh()->level->name, $b->fresh()->level->name, $c->fresh()->level->name]);
        $snapshot = fn () => [Order::count(), Member::count(), Order::orderBy('id')->pluck('status')->all(), Member::orderBy('id')->get(['level_id', 'cumulative_amount', 'activated_at'])->toArray()];
        $before = $snapshot();

        // Xem trước (mặc định): in bảng, không ghi gì
        $this->artisan('commissions:backfill')
            ->expectsOutputToContain('XEM TRƯỚC — chưa ghi gì. 5 đơn, tổng 3.563.450 đ.')
            ->assertSuccessful();
        $this->assertSame([0, 0, 0], [Commission::count(), WalletTransaction::count(), Order::whereNotNull('commissioned_at')->count()]);

        // Ghi thật
        $this->artisan('commissions:backfill --apply')
            ->expectsOutputToContain('Đã tính bù hoa hồng cho 5 đơn, tổng 3.563.450 đ.')
            ->assertSuccessful();

        // C mua lúc B chưa kích hoạt → B không nhận (dù hiện tại B là Đại lý); A nhận F2 7%
        $this->assertSame([$b->id, 'Chưa kích hoạt', '0.00', 1_045_000, 0, 'skipped'], $this->line($orders['c1'], 'F1'));
        $this->assertSame('Người hưởng chưa kích hoạt tài khoản lúc đơn được thanh toán', Commission::where('order_id', $orders['c1']->id)->where('tier', 'F1')->value('skip_reason'));
        $this->assertSame([$a->id, 'Đại lý', '7.00', 1_045_000, 73_150, 'credited'], $this->line($orders['c1'], 'F2'));
        // B tự mua: A nhận F1 20%
        $this->assertSame([$a->id, 'Đại lý', '20.00', 5_225_000, 1_045_000, 'credited'], $this->line($orders['b1'], 'F1'));
        // C mua lúc B là Cộng tác viên → 10% (không phải 20% của cấp Đại lý hiện tại)
        $this->assertSame([$b->id, 'Cộng tác viên', '10.00', 2_090_000, 209_000, 'credited'], $this->line($orders['c2'], 'F1'));
        $this->assertSame([$a->id, 'Đại lý', '7.00', 2_090_000, 146_300, 'credited'], $this->line($orders['c2'], 'F2'));
        $this->assertSame([$a->id, 'Đại lý', '20.00', 10_450_000, 2_090_000, 'credited'], $this->line($orders['b2'], 'F1'));
        // A tự mua, không có tuyến trên: không có dòng nào nhưng đơn vẫn được đánh dấu đã tính
        $this->assertSame(0, Commission::where('order_id', $orders['a1']->id)->count());
        $this->assertNotNull($orders['a1']->fresh()->commissioned_at);

        // Ví + ngày ghi = ngày thanh toán của đơn
        $this->assertSame([73_150 + 1_045_000 + 146_300 + 2_090_000, 209_000, 0], [$a->walletBalance(), $b->walletBalance(), $c->walletBalance()]);
        $this->assertSame(3_354_450, WalletTransaction::where('member_id', $a->id)->latest('id')->value('balance_after'));
        $line = Commission::where('order_id', $orders['c2']->id)->where('tier', 'F1')->first();
        $this->assertTrue($line->created_at->equalTo($orders['c2']->paid_at));
        $this->assertTrue($line->walletTransaction->created_at->equalTo($orders['c2']->paid_at));

        // Không đụng tới đơn / thành viên: số lượng, trạng thái, cấp, doanh số, kích hoạt y nguyên; đơn chưa thanh toán / đã hủy không được tính
        $this->assertSame($before, $snapshot());
        $this->assertSame([null, null], [$orders['unpaid']->fresh()->commissioned_at, $orders['cancelled']->fresh()->commissioned_at]);
        $this->assertSame(0, Commission::whereIn('order_id', [$orders['unpaid']->id, $orders['cancelled']->id])->count());
        // Mỗi đơn có một dòng nhật ký
        $this->assertSame("Tính bù hoa hồng đơn {$orders['c2']->code}: F1 Lê Thị Bình +209.000 đ, F2 Trần Văn Gốc +146.300 đ",
            AuditLog::where('auditable_type', Order::class)->where('auditable_id', $orders['c2']->id)->latest('id')->value('description'));

        // Chạy lại: không còn gì để tính, không cộng đôi
        $this->artisan('commissions:backfill --apply')->expectsOutputToContain('Không có đơn đã thanh toán nào chưa tính hoa hồng.')->assertSuccessful();
        $this->assertSame([6, 5, 3_354_450], [Commission::count(), WalletTransaction::count(), $a->walletBalance()]);
    }

    public function test_manual_activation_date_and_locked_beneficiary_are_respected(): void
    {
        // A được kích hoạt thủ công 8 ngày trước (cấp Nhân viên 0%), sau đó tự mua 3.300.000 (4 ngày trước) → Cộng tác viên
        $a = Member::factory()->create(['level_id' => Level::where('name', 'Nhân viên')->value('id'), 'activated_at' => now()->subDays(8)]);
        $locked = Member::factory()->create(['referrer_id' => $a->id, 'level_id' => Level::where('name', 'Đại lý')->value('id'), 'activated_at' => now()->subDays(30), 'status' => Member::STATUS_LOCKED]);
        $buyer = Member::factory()->create(['referrer_id' => $a->id]);
        $deep = Member::factory()->create(['referrer_id' => $locked->id]);

        $early = $this->oldPaidOrder($buyer, 1_100_000, 12);        // A chưa kích hoạt lúc đó
        $middle = $this->oldPaidOrder($buyer, 1_100_000, 6);        // A đã kích hoạt, cấp Nhân viên 0%
        $own = $this->oldPaidOrder($a, 3_300_000, 4);
        $late = $this->oldPaidOrder($buyer, 1_100_000, 1);          // A là Cộng tác viên 10%
        $viaLocked = $this->oldPaidOrder($deep, 1_100_000, 1);      // F1 đang bị khóa; F2 = A 7%
        $this->artisan('levels:count-paid-orders')->assertSuccessful();
        $this->artisan('commissions:backfill --apply')->assertSuccessful();

        $reason = fn (Order $order, string $tier) => Commission::where('order_id', $order->id)->where('tier', $tier)->value('skip_reason');
        $this->assertSame('Người hưởng chưa kích hoạt tài khoản lúc đơn được thanh toán', $reason($early, 'F1'));
        $this->assertSame('Cấp Nhân viên chưa có hoa hồng F1 (tỉ lệ 0%)', $reason($middle, 'F1'));
        $this->assertSame([$a->id, 'Cộng tác viên', '10.00', 1_045_000, 104_500, 'credited'], $this->line($late, 'F1'));
        $this->assertSame('Tài khoản người hưởng đang bị khóa', $reason($viaLocked, 'F1'));
        $this->assertSame([$a->id, 'Cộng tác viên', '7.00', 1_045_000, 73_150, 'credited'], $this->line($viaLocked, 'F2'));
        $this->assertSame([104_500 + 73_150, 0], [$a->walletBalance(), $locked->walletBalance()]);
    }

    public function test_orders_already_commissioned_by_the_live_flow_are_left_alone(): void
    {
        [$a, $b, $c, $orders] = $this->oldData();
        $this->artisan('levels:count-paid-orders')->assertSuccessful();
        // Đơn c2 đã được tính theo luồng thật (ví dụ xác nhận sau khi triển khai) với một dòng có sẵn
        $existing = Commission::create(['order_id' => $orders['c2']->id, 'buyer_id' => $c->id, 'beneficiary_id' => $b->id, 'tier' => 'F1', 'rate_percent' => '20.00',
            'goods_amount' => 2_200_000, 'fee_percent' => '5.00', 'base_amount' => 2_090_000, 'amount' => 418_000, 'status' => 'credited']);
        $orders['c2']->forceFill(['commissioned_at' => now()])->save();

        $this->artisan('commissions:backfill --apply')->expectsOutputToContain('Đã tính bù hoa hồng cho 4 đơn')->assertSuccessful();

        $this->assertSame([$existing->id], Commission::where('order_id', $orders['c2']->id)->pluck('id')->all());
        $this->assertSame(418_000, $existing->fresh()->amount);
    }
}
