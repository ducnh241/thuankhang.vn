<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Support\AffiliateSettings;
use Illuminate\Support\Collection;

/**
 * Engine hoa hồng (Step 8). Mỗi đơn ĐÃ THANH TOÁN sinh tối đa hai dòng hoa hồng cho tuyến trên của người mua:
 *   F1 → người giới thiệu của người mua (người mua là F1 của họ), tỉ lệ cột F1 theo CẤP CỦA NGƯỜI HƯỞNG;
 *   F2 → người giới thiệu của người đó (người mua là F2 của họ), tỉ lệ cột F2 theo cấp của người hưởng.
 * Người mua không nhận hoa hồng từ đơn của chính mình.
 *
 *   Cơ sở hoa hồng = tiền hàng của đơn (giá bán, đã gồm thuế; KHÔNG gồm phí giao hàng) × (1 − phí doanh nghiệp)
 *   Hoa hồng       = floor(cơ sở × tỉ lệ)          — tiền là số nguyên đồng, làm tròn xuống từng dòng
 *
 * Giá bán đã gồm thuế nên không tách VAT khi tính (chủ dự án chốt 17/11/2026): 15.000.000 × 95% × 20% = 2.850.000.
 *
 * Chia hoa hồng X (chủ dự án yêu cầu 19/11/2026, tỉ lệ ở Tham số chung — split()):
 *   thuế TNCN        = floor(X × pit_percent)        (10%) — khấu trừ ngay, không vào ví
 *   ví tái tiêu dùng = floor(X × reconsume_percent)  (20%)
 *   ví rút tiền    = X − tái tiêu dùng − thuế      (70%, nhận phần lẻ làm tròn)
 * VD 12.000.000 × 95% × 20% = 2.280.000 → rút được 1.596.000 · tái tiêu dùng 456.000 · thuế 228.000.
 *
 * Tỉ lệ lấy ở phiên bản ma trận hoa hồng có hiệu lực lúc đơn được thanh toán; cấp của người hưởng đọc tại lúc tính. Người
 * hưởng phải đã kích hoạt, không bị khóa và có tỉ lệ > 0; nếu không thì vẫn ghi dòng "skipped" kèm lý do (không cộng ví,
 * không tính bù về sau). Dòng được nhận thì cộng ví ngay. Mỗi đơn chỉ tính một lần (orders.commissioned_at).
 */
class CommissionEngine
{
    /** Trạng thái đơn được coi là đã thanh toán. */
    public const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];

    /**
     * Cơ sở tính của đơn: fee_percent ("5.00"), base (cơ sở hoa hồng = tiền hàng × (1 − phí)).
     *
     * @return array{fee_percent: string, base: int}
     */
    public static function baseOf(Order $order): array
    {
        $fee = (string) AffiliateSettings::get('business_fee_percent');

        return ['fee_percent' => $fee, 'base' => intdiv((int) $order->goods_amount * (10000 - self::basisPoints($fee)), 10000)];
    }

    /** floor(cơ sở × tỉ lệ %) bằng số nguyên. */
    public static function amountOf(int $base, string $ratePercent): int
    {
        return intdiv($base * self::basisPoints($ratePercent), 10000);
    }

    /**
     * Chia một khoản hoa hồng vào hai ví + thuế TNCN theo tỉ lệ hiện hành ở Tham số chung.
     *
     * @return array{cash: int, reconsume: int, tax: int, reconsume_percent: string, tax_percent: string, cash_percent: string}
     */
    public static function split(int $amount): array
    {
        $tax = (string) AffiliateSettings::get('pit_percent');
        $reconsume = (string) AffiliateSettings::get('reconsume_percent');
        $taxAmount = self::amountOf($amount, $tax);
        $reconsumeAmount = self::amountOf($amount, $reconsume);

        return [
            'cash' => $amount - $taxAmount - $reconsumeAmount, 'reconsume' => $reconsumeAmount, 'tax' => $taxAmount,
            'reconsume_percent' => $reconsume, 'tax_percent' => $tax,
            'cash_percent' => number_format((10000 - self::basisPoints($tax) - self::basisPoints($reconsume)) / 100, 2, '.', ''),
        ];
    }

    /**
     * Gọi TRONG transaction xác nhận thanh toán (đơn đã khóa và đã sang "Đã thanh toán"), trước khi xét lên cấp người mua.
     * Trả về các dòng hoa hồng vừa ghi (rỗng nếu đơn đã tính rồi hoặc người mua không có tuyến trên).
     *
     * $asOfPaidAt = true (tính bù đơn cũ — lệnh commissions:backfill): cấp và trạng thái kích hoạt của người hưởng lấy tại
     * THỜI ĐIỂM ĐƠN ĐƯỢC THANH TOÁN (xem stateAt()), không phải lúc chạy lệnh.
     *
     * @return Collection<int, Commission>
     */
    public static function orderPaid(Order $order, bool $asOfPaidAt = false): Collection
    {
        if ($order->commissioned_at !== null || $order->member_id === null) {
            return collect();
        }

        $lines = collect();
        foreach (self::plan($order, $asOfPaidAt, lock: true) as [$beneficiary, $attributes]) {
            $line = Commission::create($attributes);
            if ($line->isCredited()) {
                foreach ([WalletTransaction::WALLET_CASH => $line->cash_amount, WalletTransaction::WALLET_RECONSUME => $line->reconsume_amount] as $wallet => $amount) {
                    if ($amount > 0) {
                        WalletTransaction::create([
                            'member_id' => $beneficiary->id, 'wallet' => $wallet, 'type' => WalletTransaction::TYPE_COMMISSION,
                            'amount' => $amount, 'balance_after' => $beneficiary->walletBalance($wallet) + $amount,
                            'commission_id' => $line->id, 'created_at' => $line->created_at,
                            'note' => "Hoa hồng {$line->tier} đơn {$order->code}".($wallet === WalletTransaction::WALLET_RECONSUME ? ' — phần tái tiêu dùng' : ''),
                        ]);
                    }
                }
            }
            $lines->push($line->setRelation('beneficiary', $beneficiary));
        }
        $order->forceFill(['commissioned_at' => now()])->save();

        return $lines;
    }

    /**
     * Xem trước các dòng hoa hồng của một đơn mà KHÔNG ghi gì và không khóa dòng nào (dùng cho chế độ xem trước của lệnh
     * tính bù). Trả về các model Commission chưa lưu, đã gắn sẵn quan hệ beneficiary.
     *
     * @return Collection<int, Commission>
     */
    public static function preview(Order $order, bool $asOfPaidAt = false): Collection
    {
        return collect(self::plan($order, $asOfPaidAt, lock: false))
            ->map(fn (array $item) => (new Commission($item[1]))->setRelation('beneficiary', $item[0]));
    }

    /**
     * Cấp và trạng thái kích hoạt của một thành viên TẠI LÚC đơn $order được thanh toán — đúng như engine lên cấp đã / sẽ
     * xét nếu chạy đúng lúc đó:
     *   - đã kích hoạt: ngày kích hoạt không muộn hơn lúc đơn thanh toán, hoặc đã có đơn tự mua thanh toán trước đó;
     *   - cấp: cấp cao nhất đạt được nhờ tổng tiền hàng các đơn tự mua đã thanh toán TRƯỚC đơn này; đã kích hoạt (kể cả
     *     kích hoạt thủ công) thì tối thiểu là cấp hưởng hoa hồng thấp nhất; không bao giờ cao hơn cấp hiện tại.
     *
     * @return array{0: ?Level, 1: bool}
     */
    public static function stateAt(Member $member, Order $order): array
    {
        $paidAt = $order->paid_at ?? now();
        $bought = (int) Order::where('member_id', $member->id)->whereIn('status', self::PAID_STATUSES)->whereNotNull('paid_at')
            ->where(fn ($query) => $query->where('paid_at', '<', $paidAt)
                ->orWhere(fn ($query) => $query->where('paid_at', $paidAt)->where('id', '<', $order->id)))
            ->sum('goods_amount');
        $activated = $bought > 0 || ($member->activated_at !== null && $member->activated_at->lte($paidAt));

        $level = LevelEngine::levelFor($bought);
        $floor = $activated ? Level::earning()->firstWhere('active', true) : null;
        if ($floor && (! $level || $floor->threshold_amount > $level->threshold_amount)) {
            $level = $floor;
        }
        $current = $member->level;
        if ($current && (! $level || $level->threshold_amount > $current->threshold_amount)) {
            $level = $current;
        }

        return [$level, $activated];
    }

    /**
     * Các dòng hoa hồng của đơn dưới dạng [người hưởng, thuộc tính của dòng] — chưa ghi gì. $lock: khóa người mua + tuyến
     * trên theo thứ tự id (cùng thứ tự ở mọi đơn → không khóa chéo nhau); chỉ dùng trong transaction.
     *
     * @return list<array{0: Member, 1: array<string, mixed>}>
     */
    private static function plan(Order $order, bool $asOfPaidAt, bool $lock): array
    {
        $paidAt = $order->paid_at ?? now();
        $f1Id = Member::whereKey($order->member_id)->value('referrer_id');
        $f2Id = $f1Id ? Member::whereKey($f1Id)->value('referrer_id') : null;
        $find = fn () => Member::query()->when($lock, fn ($query) => $query->lockForUpdate());
        $members = $find()->whereIn('id', array_filter([$order->member_id, $f1Id, $f2Id]))->orderBy('id')->get()->keyBy('id');
        $f1 = $members->get($members->get($order->member_id)?->referrer_id);
        $f2 = $f1?->referrer_id ? ($members->get($f1->referrer_id) ?? $find()->find($f1->referrer_id)) : null;

        $base = self::baseOf($order);
        $plan = [];
        foreach (['F1' => $f1, 'F2' => $f2] as $tier => $beneficiary) {
            if (! $beneficiary || $beneficiary->id === $order->member_id) {
                continue;
            }
            [$level, $activated] = $asOfPaidAt ? self::stateAt($beneficiary, $order) : [$beneficiary->level, $beneficiary->isActivated()];
            $rate = $level ? (CommissionRate::rateFor($level->id, $tier, $paidAt) ?? '0.00') : '0.00';
            $amount = self::amountOf($base['base'], $rate);
            $reason = match (true) {
                $beneficiary->isLocked() => 'Tài khoản người hưởng đang bị khóa',
                ! $activated => 'Người hưởng chưa kích hoạt tài khoản'.($asOfPaidAt ? ' lúc đơn được thanh toán' : ''),
                self::basisPoints($rate) === 0 => 'Cấp '.($level?->name ?? '—')." chưa có hoa hồng {$tier} (tỉ lệ 0%)",
                $amount === 0 => 'Hoa hồng làm tròn xuống bằng 0 đ',
                default => null,
            };

            $split = self::split($reason === null ? $amount : 0);
            $plan[] = [$beneficiary, [
                'order_id' => $order->id, 'buyer_id' => $order->member_id, 'beneficiary_id' => $beneficiary->id, 'tier' => $tier,
                'level_id' => $level?->id, 'level_name' => $level?->name, 'rate_percent' => $rate,
                'goods_amount' => (int) $order->goods_amount, 'fee_percent' => $base['fee_percent'],
                'base_amount' => $base['base'], 'amount' => $reason === null ? $amount : 0,
                'reconsume_percent' => $split['reconsume_percent'], 'tax_percent' => $split['tax_percent'],
                'cash_amount' => $split['cash'], 'reconsume_amount' => $split['reconsume'], 'tax_amount' => $split['tax'],
                'status' => $reason === null ? Commission::STATUS_CREDITED : Commission::STATUS_SKIPPED, 'skip_reason' => $reason,
                'created_at' => $paidAt,
            ]];
        }

        return $plan;
    }

    /** "5.00" (%) → 500 (phần vạn). */
    private static function basisPoints(string $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }
}
