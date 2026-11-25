<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\ProfitShareEntry;
use App\Models\ProfitShareGroup;
use App\Models\ProfitShareLine;
use App\Models\ProfitSharePeriod;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\AffiliateSettings;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Đồng chia (chủ dự án chốt 24/11/2026 — document/dong-chia-va-thuong-nam.html):
 *   - Nhóm = mỗi cấp đang bật có mốc ≥ "Mốc gói nhỏ nhất tính đồng chia" (mặc định 3tr). Một ĐƠN đã thanh toán có tiền hàng
 *     từ mốc cấp đó tới dưới mốc cấp kế tiếp là "đơn vào gói" của nhóm (không cộng dồn nhiều đơn).
 *   - Thành viên vào nhóm bằng đơn vào gói đầu tiên của mình (mỗi nhóm một suất), xếp hạng theo lúc đơn đó được thanh toán.
 *   - Quỹ tháng của nhóm = tổng tiền hàng các đơn vào gói THANH TOÁN TRONG THÁNG đó × (1 − phí DN) × tỉ lệ đồng chia.
 *   - Chia đều (làm tròn xuống) cho những người còn trong nhóm, tối đa "top" người vào trước (mặc định 100); người bị khóa
 *     không được xếp; người đã nhận đủ mốc cấp (cap) thì đã ra; tháng cuối chỉ nhận phần còn thiếu. Phần lẻ / phần dư công
 *     ty giữ.
 *   - Kế toán duyệt từ ngày 1 đến ngày 5 tháng sau → cộng ví tái tiêu dùng + lưu ảnh chụp bảng chia. Chưa duyệt thì chỉ
 *     TÍNH TRỰC TIẾP (calculate), không ghi gì; lúc duyệt tính lại và so dấu vân tay với bảng kế toán đang xem.
 */
class ProfitShare
{
    /** Ngày cuối (của tháng sau) còn được duyệt bảng chia. */
    public const APPROVE_LAST_DAY = 5;

    /** Trạng thái đơn đã thanh toán (đơn hủy không bao giờ có ở đây vì đã thanh toán thì không hủy được). */
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];

    /** "2026-11" hợp lệ → [đầu tháng, đầu tháng sau]; sai → null. */
    public static function range(string $period): ?array
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m)) {
            return null;
        }
        $start = Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0);

        return [$start, $start->copy()->addMonthNoOverflow()];
    }

    /** Khoảng được duyệt: [ngày 1, hết ngày 5] của tháng sau. */
    public static function approveWindow(string $period): array
    {
        [, $end] = self::range($period);

        return [$end->copy(), $end->copy()->addDays(self::APPROVE_LAST_DAY - 1)->endOfDay()];
    }

    public static function canApproveNow(string $period): bool
    {
        [$from, $until] = self::approveWindow($period);

        return now()->betweenIncluded($from, $until);
    }

    /**
     * Các nhóm theo cấu hình cấp hiện tại: [level, min, max (null = không giới hạn), cap].
     *
     * @return list<array{level: Level, min: int, max: ?int, cap: int}>
     */
    public static function bands(): array
    {
        $min = (int) AffiliateSettings::get('profit_share_min_amount');
        $levels = Level::where('active', true)->where('threshold_amount', '>=', $min)->orderBy('threshold_amount')->get()->values();

        return $levels->map(fn (Level $level, int $i) => [
            'level' => $level, 'min' => (int) $level->threshold_amount,
            'max' => isset($levels[$i + 1]) ? (int) $levels[$i + 1]->threshold_amount : null, 'cap' => (int) $level->threshold_amount,
        ])->all();
    }

    /**
     * Bảng chia của một tháng, tính trực tiếp (không ghi gì). Mỗi nhóm:
     *   orders (đơn vào gói thanh toán trong tháng), order_total, base (sau phí), pool, rows (người được xếp hạng: rank,
     *   status paid | waiting, received_before, amount, exits), skipped (bị khóa), exited (đã nhận đủ từ trước),
     *   member_count, paid_count, per_head, distributed, remainder. Kèm tổng và fingerprint (dấu vân tay để duyệt).
     */
    public static function calculate(string $period): array
    {
        [$start, $end] = self::range($period) ?? throw new \InvalidArgumentException("Tháng không hợp lệ: {$period}");
        $feePercent = (string) AffiliateSettings::get('business_fee_percent');
        $sharePercent = (string) AffiliateSettings::get('profit_share_percent');
        $slots = (int) AffiliateSettings::get('profit_share_slots');
        $bands = self::bands();
        $lowest = $bands[0]['min'] ?? null;

        $orders = $lowest === null ? collect() : Order::query()
            ->whereIn('status', self::PAID_STATUSES)->whereNotNull('paid_at')->where('paid_at', '<', $end)
            ->whereNotNull('member_id')->where('goods_amount', '>=', $lowest)
            ->orderBy('paid_at')->orderBy('id')
            ->get(['id', 'code', 'member_id', 'goods_amount', 'paid_at', 'payment_method']);
        $entries = ProfitShareEntry::all()->keyBy(fn (ProfitShareEntry $e) => $e->level_id.':'.$e->member_id);
        $members = Member::with('level:id,name')
            ->whereIn('id', $orders->pluck('member_id')->merge($entries->pluck('member_id'))->unique()->all())
            ->get(['id', 'full_name', 'phone', 'referral_code', 'status', 'level_id'])->keyBy('id');
        $ordersById = $orders->keyBy('id');

        $groups = [];
        foreach ($bands as $band) {
            $inBand = fn (Order $o) => $o->goods_amount >= $band['min'] && ($band['max'] === null || $o->goods_amount < $band['max']);
            $bandOrders = $orders->filter($inBand);
            $monthOrders = $bandOrders->filter(fn (Order $o) => $o->paid_at->gte($start))->values();

            // Suất trong nhóm: suất đã lưu (từ lần duyệt trước) giữ nguyên ngày vào / mức ra; người mới = đơn vào gói đầu tiên
            $seats = [];
            foreach ($entries->where('level_id', $band['level']->id) as $entry) {
                $seats[$entry->member_id] = [
                    'entry_id' => $entry->id, 'member_id' => $entry->member_id, 'order' => $ordersById[$entry->order_id] ?? $entry->order,
                    'joined_at' => $entry->joined_at, 'cap' => $entry->cap_amount, 'received_before' => $entry->received_amount,
                ];
            }
            foreach ($bandOrders as $order) {
                $seats[$order->member_id] ??= [
                    'entry_id' => null, 'member_id' => $order->member_id, 'order' => $order,
                    'joined_at' => $order->paid_at, 'cap' => $band['cap'], 'received_before' => 0,
                ];
            }
            $seats = collect($seats)->filter(fn ($s) => $s['joined_at']->lt($end))
                ->map(fn ($s) => [...$s, 'member' => $members[$s['member_id']] ?? Member::find($s['member_id'])])
                ->sortBy(fn ($s) => [$s['joined_at']->getTimestamp(), $s['order']?->id ?? 0])->values();

            $exited = $seats->filter(fn ($s) => $s['received_before'] >= $s['cap'])->values();
            $active = $seats->reject(fn ($s) => $s['received_before'] >= $s['cap']);
            $skipped = $active->filter(fn ($s) => $s['member']?->isLocked() ?? true)
                ->map(fn ($s) => [...$s, 'status' => ProfitShareLine::STATUS_SKIPPED, 'rank' => null, 'amount' => 0, 'exits' => false,
                    'note' => 'Tài khoản đang bị khóa'])->values();
            $ranked = $active->reject(fn ($s) => $s['member']?->isLocked() ?? true)->values();

            $orderTotal = (int) $monthOrders->sum('goods_amount');
            $base = (int) floor($orderTotal * (10000 - self::basisPoints($feePercent)) / 10000);
            $pool = (int) floor($base * self::basisPoints($sharePercent) / 10000);
            $paidCount = min($slots, $ranked->count());
            $perHead = $paidCount > 0 ? intdiv($pool, $paidCount) : 0;

            $rows = $ranked->map(function ($s, int $i) use ($slots, $perHead) {
                $inTop = $i < $slots;
                $amount = $inTop ? min($perHead, $s['cap'] - $s['received_before']) : 0;

                return [...$s, 'rank' => $i + 1, 'status' => $inTop ? ProfitShareLine::STATUS_PAID : ProfitShareLine::STATUS_WAITING,
                    'amount' => $amount, 'exits' => $inTop && $s['cap'] <= $s['received_before'] + $amount,
                    'note' => match (true) {
                        ! $inTop => 'Ngoài top, chờ người trước ra khỏi nhóm',
                        $amount === 0 => 'Không có tiền chia (quỹ tháng này bằng 0 hoặc quá nhỏ)',
                        $amount < $perHead => 'Chỉ nhận phần còn thiếu cho đủ mức',
                        default => null,
                    }];
            });
            $distributed = (int) $rows->sum('amount');

            $groups[] = [
                'level' => $band['level'], 'min' => $band['min'], 'max' => $band['max'], 'cap' => $band['cap'],
                'orders' => $monthOrders->map(fn (Order $o) => [
                    'order' => $o, 'member' => $members[$o->member_id] ?? null,
                    'new_seat' => $seats->first(fn ($s) => $s['order']?->id === $o->id) !== null,
                ]),
                'order_total' => $orderTotal, 'base' => $base, 'pool' => $pool,
                'rows' => $rows, 'skipped' => $skipped, 'exited' => $exited,
                'member_count' => $ranked->count(), 'paid_count' => $paidCount, 'per_head' => $perHead,
                'distributed' => $distributed, 'remainder' => $pool - $distributed,
            ];
        }

        $result = [
            'period' => $period, 'start' => $start, 'end' => $end, 'fee_percent' => $feePercent, 'share_percent' => $sharePercent,
            'slots' => $slots, 'min_amount' => (int) AffiliateSettings::get('profit_share_min_amount'), 'groups' => $groups,
            'order_total' => array_sum(array_column($groups, 'order_total')), 'pool_total' => array_sum(array_column($groups, 'pool')),
            'distributed_total' => array_sum(array_column($groups, 'distributed')),
            'paid_count' => array_sum(array_map(fn ($g) => $g['rows']->where('status', ProfitShareLine::STATUS_PAID)->where('amount', '>', 0)->count(), $groups)),
        ];

        return [...$result, 'fingerprint' => self::fingerprint($result)];
    }

    /**
     * Duyệt bảng chia tháng $period: chỉ trong ngày 1–5 tháng sau, tháng chưa duyệt, và số liệu tính lại phải trùng
     * $fingerprint (bảng kế toán đang xem). Cùng một transaction: lưu ảnh chụp, tạo / cập nhật suất, cộng ví tái tiêu dùng.
     *
     * @return ProfitSharePeriod|string đã duyệt, hoặc lý do không duyệt được
     */
    public static function approve(string $period, string $fingerprint, User $user): ProfitSharePeriod|string
    {
        if (self::range($period) === null) {
            return 'Tháng không hợp lệ.';
        }
        if (! self::canApproveNow($period)) {
            [$from, $until] = self::approveWindow($period);

            return 'Chỉ duyệt được từ '.$from->format('d/m/Y').' đến hết '.$until->format('d/m/Y').'.';
        }

        // Mọi lần duyệt (mọi tháng) xếp hàng qua một khóa: bấm nhiều lần / nhiều người cùng lúc thì lần sau chờ lần trước xong
        // rồi thấy "đã duyệt" — không khóa chéo trong MySQL, không cộng ví hai lần
        $lock = Cache::lock('profit-share-approve', 120);
        if (! $lock->block(20)) {
            return 'Đang có người khác duyệt đồng chia. Thử lại sau ít giây.';
        }
        try {
            return DB::transaction(function () use ($period, $fingerprint, $user) {
                if (ProfitSharePeriod::where('period', $period)->exists()) {
                    return 'Tháng này đã được duyệt.';
                }
                ProfitShareEntry::query()->lockForUpdate()->get(['id']);
                $data = self::calculate($period);
                if (! hash_equals($data['fingerprint'], $fingerprint)) {
                    return 'Số liệu đã thay đổi so với bảng đang xem (có đơn mới được xác nhận, thành viên bị khóa / mở khóa hoặc đổi cấu hình). Tải lại trang để xem bảng mới rồi duyệt.';
                }

                $record = ProfitSharePeriod::create([
                    'period' => $period, 'fee_percent' => $data['fee_percent'], 'share_percent' => $data['share_percent'],
                    'slots' => $data['slots'], 'order_total' => $data['order_total'], 'pool_total' => $data['pool_total'],
                    'distributed_total' => $data['distributed_total'], 'paid_count' => $data['paid_count'],
                    'fingerprint' => $data['fingerprint'], 'approved_by' => $user->id, 'approved_at' => now(),
                ]);
                $payees = collect($data['groups'])->flatMap(fn ($g) => $g['rows']->where('amount', '>', 0)->pluck('member_id'))->unique()->all();
                $locked = $payees ? Wallet::lock(...$payees) : collect();
                $monthLabel = Carbon::parse($period.'-01')->format('m/Y');

                foreach ($data['groups'] as $g) {
                    $group = ProfitShareGroup::create([
                        'period_id' => $record->id, 'level_id' => $g['level']->id, 'level_name' => $g['level']->name,
                        'min_amount' => $g['min'], 'max_amount' => $g['max'], 'order_count' => $g['orders']->count(),
                        'order_total' => $g['order_total'], 'base_amount' => $g['base'], 'pool' => $g['pool'],
                        'member_count' => $g['member_count'], 'paid_count' => $g['paid_count'], 'per_head' => $g['per_head'],
                        'distributed' => $g['distributed'],
                    ]);
                    foreach ($g['rows']->concat($g['skipped']) as $row) {
                        $entry = self::entryFor($g['level'], $row);
                        $line = ProfitShareLine::create([
                            'group_id' => $group->id, 'entry_id' => $entry->id, 'member_id' => $row['member_id'], 'rank' => $row['rank'],
                            'status' => $row['status'], 'note' => $row['note'], 'received_before' => $row['received_before'],
                            'amount' => $row['amount'], 'exits' => $row['exits'],
                        ]);
                        if ($row['amount'] > 0) {
                            Wallet::post($locked[$row['member_id']], WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_PROFIT_SHARE,
                                $row['amount'], "Đồng chia tháng {$monthLabel} — nhóm {$g['level']->name}", ['profit_share_line_id' => $line->id]);
                            $entry->forceFill([
                                'received_amount' => $entry->received_amount + $row['amount'],
                                'exited_period' => $row['exits'] ? $period : null,
                            ])->save();
                        }
                    }
                }
                AuditLog::record('created', $record, null, "Duyệt đồng chia tháng {$monthLabel}: quỹ ".Money::format($data['pool_total'])
                    .' đ, đã chia '.Money::format($data['distributed_total']).' đ cho '.$data['paid_count'].' lượt người vào ví tái tiêu dùng', $user);

                return $record;
            });
        } catch (QueryException $e) {
            // Lớp chặn cuối (khóa cache hết hạn giữa chừng…): lần sau vướng unique(period) hoặc khóa chéo
            if (ProfitSharePeriod::where('period', $period)->exists()) {
                return 'Tháng này đã được duyệt.';
            }
            if (in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                return 'Đang có người khác duyệt đồng chia. Tải lại trang để xem kết quả.';
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Suất của dòng: đã có thì khóa dòng để cập nhật, chưa có (người mới) thì tạo theo đơn vào gói đầu tiên. */
    private static function entryFor(Level $level, array $row): ProfitShareEntry
    {
        if ($row['entry_id']) {
            return ProfitShareEntry::lockForUpdate()->findOrFail($row['entry_id']);
        }

        return ProfitShareEntry::create([
            'level_id' => $level->id, 'member_id' => $row['member_id'], 'order_id' => $row['order']->id, 'joined_at' => $row['joined_at'],
            'order_amount' => (int) $row['order']->goods_amount, 'cap_amount' => $row['cap'], 'received_amount' => 0,
        ]);
    }

    /** Dấu vân tay của bảng chia: thay đổi khi bất kỳ con số / người / thứ tự nào thay đổi. */
    private static function fingerprint(array $result): string
    {
        $data = [$result['period'], $result['fee_percent'], $result['share_percent'], $result['slots'], $result['min_amount']];
        foreach ($result['groups'] as $g) {
            $data[] = [$g['level']->id, $g['min'], $g['max'], $g['order_total'], $g['pool'], $g['orders']->map(fn ($o) => $o['order']->id)->all(),
                $g['rows']->map(fn ($r) => [$r['member_id'], $r['rank'], $r['amount'], $r['received_before']])->all(),
                $g['skipped']->pluck('member_id')->all()];
        }

        return hash('sha256', json_encode($data));
    }

    private static function basisPoints(string $percent): int
    {
        return (int) round((float) $percent * 100);
    }

    /** Danh sách tháng để chọn: từ tháng có đơn vào gói đầu tiên (hoặc tháng đã duyệt đầu tiên) tới tháng hiện tại, mới nhất trước. */
    public static function periods(): Collection
    {
        $lowest = self::bands()[0]['min'] ?? null;
        $first = collect([
            $lowest === null ? null : Order::whereIn('status', self::PAID_STATUSES)->where('goods_amount', '>=', $lowest)->min('paid_at'),
            ProfitSharePeriod::min('period') ? ProfitSharePeriod::min('period').'-01' : null,
        ])->filter()->map(fn ($d) => Carbon::parse($d)->startOfMonth())->min() ?? now()->startOfMonth();

        $months = collect();
        for ($m = now()->startOfMonth(); $m->gte($first) && $months->count() < 120; $m = $m->copy()->subMonthNoOverflow()) {
            $months->push($m->format('Y-m'));
        }

        return $months;
    }
}
