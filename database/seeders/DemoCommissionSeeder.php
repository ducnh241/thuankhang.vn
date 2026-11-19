<?php

namespace Database\Seeders;

use App\Models\Commission;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderPayment;
use App\Support\AffiliateSettings;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dữ liệu mẫu để đối chiếu hoa hồng (chỉ chạy ở máy phát triển):
 *   php artisan db:seed --class=DemoCommissionSeeder
 *
 * F0 = thành viên id 1. F1 = hai người F0 đã giới thiệu. F2 = bốn thành viên mẫu tạo thêm (hai người dưới mỗi F1, tên có chữ
 * "(mẫu)"). Tạo 22 đơn đã thanh toán trong tháng 11/2026: 20 đơn của F1 / F2 và 2 đơn F0 tự mua để F0 đi qua ba mức tỉ lệ —
 * Khách hàng (0%) → Cộng tác viên (F1 10% / F2 7%) → Đại lý (F1 20% / F2 7%). Mỗi đơn đi đúng luồng thật
 * (OrderPayment::confirm): ghi hoa hồng vào ví, cộng doanh số, kích hoạt, lên cấp — với đồng hồ đặt về thời điểm của đơn.
 * Chạy lại không tạo trùng (nhận biết qua mã giao dịch DEMO-HH-xx).
 */
class DemoCommissionSeeder extends Seeder
{
    private const F0_ID = 1;

    private const REFERENCE = 'DEMO-HH-';

    /** Thành viên F2 mẫu: khóa => [họ tên, số điện thoại, dưới F1 thứ mấy (0 / 1)]. */
    private const F2_MEMBERS = [
        'A1' => ['Trần Văn An (mẫu)', '0900000101', 0],
        'A2' => ['Lê Thu Trang (mẫu)', '0900000102', 0],
        'B1' => ['Phạm Minh Khôi (mẫu)', '0900000103', 1],
        'B2' => ['Đỗ Ngọc Lan (mẫu)', '0900000104', 1],
    ];

    /** [thời điểm thanh toán, người mua (F0 | F1a | F1b | A1 | A2 | B1 | B2), [[id sản phẩm, số lượng], …]] */
    private const ORDERS = [
        ['2026-11-07 09:10', 'A1', [[9, 2]]],
        ['2026-11-07 15:30', 'F1a', [[6, 1], [18, 1]]],
        ['2026-11-08 10:00', 'F0', [[8, 1], [21, 1]]],          // F0 tự mua 3.280.000 → Cộng tác viên
        ['2026-11-08 14:20', 'F1b', [[2, 1]]],
        ['2026-11-09 09:05', 'B1', [[25, 1]]],
        ['2026-11-09 16:40', 'A1', [[13, 2]]],
        ['2026-11-10 11:15', 'F1a', [[26, 1], [9, 1]]],
        ['2026-11-10 19:30', 'A2', [[15, 3]]],
        ['2026-11-11 08:45', 'B2', [[11, 2]]],
        ['2026-11-11 13:00', 'F1b', [[17, 4], [20, 2]]],
        ['2026-11-12 10:30', 'B1', [[8, 1]]],
        ['2026-11-12 17:10', 'A1', [[5, 3]]],
        ['2026-11-13 09:00', 'F0', [[24, 1]]],                  // F0 tự mua 15.000.000 → Đại lý
        ['2026-11-13 14:45', 'F1a', [[24, 1]]],
        ['2026-11-14 10:20', 'A2', [[2, 1]]],
        ['2026-11-14 16:00', 'F1b', [[23, 1]]],
        ['2026-11-15 09:40', 'B2', [[26, 2]]],
        ['2026-11-15 15:15', 'A1', [[19, 1], [14, 1], [16, 1]]],
        ['2026-11-16 11:00', 'F1a', [[3, 2], [4, 1]]],
        ['2026-11-16 18:20', 'B1', [[6, 2]]],
        ['2026-11-17 09:30', 'F1b', [[9, 1]]],
        ['2026-11-17 14:00', 'A2', [[18, 2], [13, 1]]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCommissionSeeder chỉ dùng ở máy phát triển, không chạy trên production.');
        }
        if (Order::where('payment_reference', 'like', self::REFERENCE.'%')->exists()) {
            $this->command?->warn('Dữ liệu mẫu hoa hồng đã có (đơn có mã giao dịch '.self::REFERENCE.'xx) — bỏ qua.');

            return;
        }

        try {
            $buyers = $this->buyers();
            $rows = [];
            foreach (self::ORDERS as $index => [$paidAt, $key, $items]) {
                $rows[] = $this->paidOrder($index + 1, Carbon::parse($paidAt), $key, $buyers[$key], $items);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->table(['#', 'Thanh toán', 'Người mua', 'Tiền hàng', 'Cơ sở', 'F1 →', 'F2 →'], $rows);
        $f0 = Member::with('level')->find(self::F0_ID);
        $this->command?->info("F0 {$f0->full_name}: cấp {$f0->level?->name}, số dư ví ".Money::format($f0->walletBalance()).' đ.');
    }

    /** F0, hai F1 có sẵn của F0 và bốn F2 mẫu (tạo nếu chưa có). */
    private function buyers(): array
    {
        $f0 = Member::find(self::F0_ID) ?? throw new RuntimeException('Không có thành viên id '.self::F0_ID.' để làm F0.');
        $f1 = $f0->referrals()->orderBy('id')->limit(2)->get();
        if ($f1->count() < 2) {
            throw new RuntimeException("F0 {$f0->full_name} cần ít nhất 2 thành viên F1 (người do F0 giới thiệu).");
        }

        $buyers = ['F0' => $f0, 'F1a' => $f1[0], 'F1b' => $f1[1]];
        Carbon::setTestNow('2026-11-06 20:00');
        foreach (self::F2_MEMBERS as $key => [$name, $phone, $under]) {
            $buyers[$key] = Member::where('phone', $phone)->first() ?? Member::create([
                'full_name' => $name, 'phone' => $phone, 'password' => Str::random(40),
                'referral_code' => Member::generateReferralCode(), 'referrer_id' => $f1[$under]->id,
                'level_id' => Level::where('threshold_amount', 0)->orderBy('sort_order')->value('id'),
            ]);
        }

        return $buyers;
    }

    /** Tạo đơn giao tận nơi (đặt trước lúc thanh toán 20 phút) rồi xác nhận thanh toán đúng luồng thật. */
    private function paidOrder(int $number, Carbon $paidAt, string $key, Member $buyer, array $items): array
    {
        Carbon::setTestNow($paidAt->copy()->subMinutes(20));
        $lines = collect($items)->map(function (array $item) {
            [$productId, $qty] = $item;
            $product = Product::find($productId) ?? throw new RuntimeException("Không có sản phẩm id {$productId} cho dữ liệu mẫu.");

            return ['product' => $product, 'qty' => $qty, 'line_amount' => $product->price * $qty];
        });
        $goods = (int) $lines->sum('line_amount');

        $order = Order::create([
            'code' => Order::generateCode(), 'member_id' => $buyer->id, 'goods_amount' => $goods,
            'shipping_fee' => 0, 'total_amount' => $goods, 'delivery_type' => Order::DELIVERY_SHIP,
            'recipient_name' => $buyer->full_name, 'recipient_phone' => $buyer->phone, 'province' => 'ha-noi',
            'address' => 'Số 1 Đường Mẫu (dữ liệu thử hoa hồng)', 'note' => 'Đơn mẫu để đối chiếu hoa hồng',
            'expires_at' => now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
        ]);
        foreach ($lines as $line) {
            $product = $line['product'];
            $order->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'qty' => $line['qty'],
                'unit_price' => $product->price, 'line_amount' => $line['line_amount'],
            ]);
        }
        $order->load('items')->takeStock();

        Carbon::setTestNow($paidAt);
        $done = OrderPayment::confirm($order, [
            'payment_reference' => self::REFERENCE.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'paid_amount' => $order->total_amount,
            'payment_note' => 'Dữ liệu mẫu',
        ], null);
        if ($done === false) {
            throw new RuntimeException("Không xác nhận thanh toán được đơn mẫu {$order->code}.");
        }

        $describe = function (string $tier) use ($done) {
            $line = $done['commissions']->firstWhere('tier', $tier);

            return match (true) {
                $line === null => '—',
                $line->isCredited() => "#{$line->beneficiary_id} {$line->beneficiary->full_name} ({$line->level_name} ".Commission::percentLabel($line->rate_percent).') +'.Money::format($line->amount),
                default => "#{$line->beneficiary_id} {$line->beneficiary->full_name}: không nhận",
            };
        };
        $base = $done['commissions']->first()?->base_amount;

        return [$number, $paidAt->format('d/m H:i'), "{$key} #{$buyer->id} {$buyer->full_name}", Money::format($goods), $base !== null ? Money::format($base) : '—', $describe('F1'), $describe('F2')];
    }
}
