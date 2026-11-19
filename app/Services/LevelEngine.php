<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\LevelHistory;
use App\Models\Member;
use App\Models\Order;
use App\Support\Money;

/**
 * Engine lên cấp (Step 7). Mỗi đơn ĐÃ THANH TOÁN của thành viên: cộng tiền hàng (giá bán, không tách VAT, không gồm phí giao hàng) vào
 * doanh số cá nhân cộng dồn; đơn đầu tiên thì kích hoạt tài khoản; rồi xét cấp = cấp đang bật có mốc cao nhất mà doanh số
 * cộng dồn đã ĐẠT (≥ mốc). Một đơn có thể nhảy nhiều cấp. Không bao giờ hạ cấp. Cấp xét theo mốc trong cấu hình
 * (Affiliate → Levels), không theo tên — tên cấp sửa tự do.
 *
 * Mặc định: có đơn đầu tiên → cấp mốc 1 đ ("Nhân viên"); đạt 3 triệu → "Cộng tác viên"; 15 triệu → "Đại lý";
 * 150 triệu → cấp kế; 300 triệu → cấp cao nhất.
 */
class LevelEngine
{
    /** Cấp đang bật có mốc cao nhất mà doanh số cộng dồn đã đạt. */
    public static function levelFor(int $cumulative): ?Level
    {
        return Level::where('active', true)->where('threshold_amount', '<=', $cumulative)->orderByDesc('threshold_amount')->first();
    }

    /** Cấp kế tiếp phải phấn đấu: cấp đang bật có mốc thấp nhất lớn hơn cả doanh số cộng dồn lẫn mốc cấp hiện tại. */
    public static function nextLevel(Member $member): ?Level
    {
        $floor = max((int) $member->cumulative_amount, (int) ($member->level?->threshold_amount ?? 0));

        return Level::where('active', true)->where('threshold_amount', '>', $floor)->orderBy('threshold_amount')->first();
    }

    /**
     * Gọi TRONG transaction xác nhận thanh toán, sau khi đơn đã sang "Đã thanh toán". Mỗi đơn chỉ được cộng một lần
     * (orders.sales_counted_at). Trả về: counted (đơn có được cộng lần này không), activated (vừa kích hoạt), from / to
     * (cấp cũ / cấp mới — to = null nếu không lên cấp), cumulative (doanh số cộng dồn sau khi cộng).
     */
    public static function orderPaid(Order $order): array
    {
        $none = ['counted' => false, 'activated' => false, 'from' => null, 'to' => null, 'cumulative' => null];
        if ($order->sales_counted_at !== null || $order->member_id === null) {
            return $none;
        }
        $member = Member::lockForUpdate()->find($order->member_id);
        if (! $member) {
            return $none;
        }

        $before = (int) $member->cumulative_amount;
        $after = $before + (int) $order->goods_amount;
        $from = $member->level;
        $target = self::levelFor($after);
        // Chỉ đi lên: cấp mới phải có mốc cao hơn cấp đang giữ (đổi mốc / tắt cấp trong cấu hình không làm ai tụt cấp)
        $to = $target && (! $from || $target->threshold_amount > $from->threshold_amount) ? $target : null;
        $activated = ! $member->isActivated();

        $member->forceFill([
            'cumulative_amount' => $after,
            'activated_at' => $member->activated_at ?? ($order->paid_at ?? now()),
            'level_id' => $to?->id ?? $member->level_id,
        ])->saveQuietly();
        $order->forceFill(['sales_counted_at' => now()])->save();

        if ($to) {
            LevelHistory::create([
                'member_id' => $member->id, 'from_level_id' => $from?->id, 'to_level_id' => $to->id,
                'order_id' => $order->id, 'cumulative_after' => $after,
            ]);
        }
        AuditLog::record('updated', $member, [
            'old' => array_filter(['cumulative_amount' => $before, 'level' => $to ? $from?->name : null, 'activated_at' => $activated ? 'Chưa kích hoạt' : null], fn ($v) => $v !== null),
            'new' => array_filter(['cumulative_amount' => $after, 'level' => $to?->name, 'activated_at' => $activated ? $member->activated_at->toDateTimeString() : null], fn ($v) => $v !== null),
        ], "Đơn {$order->code} đã thanh toán: doanh số cộng dồn ".Money::format($before).' → '.Money::format($after).' đ'
            .($activated ? '; kích hoạt tài khoản' : '')
            .($to ? '; lên cấp '.($from?->name ?? '—').' → '.$to->name : ''));

        return ['counted' => true, 'activated' => $activated, 'from' => $from, 'to' => $to, 'cumulative' => $after];
    }
}
