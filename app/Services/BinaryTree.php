<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cây nhị phân của thành viên (kết hợp với cây giới thiệu "mặt trời"). Mỗi người có tối đa 2 nhánh: trái (L), phải (R).
 * Thành viên đầu tiên của hệ thống là "ông tổng" (node gốc, không có parent).
 *
 * Quy tắc xếp người mới (gán một lần, cố định):
 *   - Bắt đầu từ người giới thiệu X (không có người giới thiệu → từ ông tổng).
 *   - Đang đứng ở một người còn trống nhánh → vào nhánh trống đó (trái trước, phải sau).
 *   - Cả hai nhánh đã có người → đi xuống NHÁNH YẾU HƠN rồi lặp lại. Nhánh yếu hơn = nhánh có doanh số trong tháng thấp
 *     hơn (tổng tiền hàng các đơn đã thanh toán trong tháng của mọi người trong nhánh); bằng nhau → nhánh ít người hơn;
 *     vẫn bằng → nhánh trái.
 * Vì luôn bắt đầu từ X nên người do X giới thiệu luôn nằm trong nhánh của X.
 */
class BinaryTree
{
    public const LEFT = 'L';

    public const RIGHT = 'R';

    public const SIDES = [self::LEFT => 'Nhánh trái', self::RIGHT => 'Nhánh phải'];

    /** Ông tổng: thành viên đăng ký đầu tiên. */
    public static function root(): ?Member
    {
        return Member::orderBy('id')->first();
    }

    /**
     * Xếp một thành viên vào cây (không làm gì nếu đã có chỗ). $asOf: thời điểm xét doanh số tháng — mặc định bây giờ; khi
     * xếp lại thành viên cũ thì là ngày họ đăng ký. Chạy trong transaction, khóa ông tổng để hai người đăng ký cùng lúc
     * không tranh một chỗ.
     */
    public static function place(Member $member, ?CarbonInterface $asOf = null): void
    {
        DB::transaction(function () use ($member, $asOf) {
            $root = Member::orderBy('id')->lockForUpdate()->first();
            if (! $root || $root->id === $member->id) {
                return; // ông tổng: không có parent
            }
            // Thành viên đăng ký trước mình mà chưa có chỗ (dữ liệu cũ): xếp họ trước, đúng thứ tự
            Member::whereNull('parent_id')->where('id', '!=', $root->id)->where('id', '<', $member->id)->orderBy('id')->get()
                ->each(fn (Member $earlier) => self::assign($earlier, $root, $earlier->created_at));
            self::assign($member, $root, $asOf);
        });
    }

    /** Xếp mọi thành viên chưa có chỗ, theo thứ tự đăng ký (xét doanh số tháng tại ngày đăng ký của từng người). */
    public static function placeUnplaced(): int
    {
        return DB::transaction(function () {
            $root = Member::orderBy('id')->lockForUpdate()->first();
            if (! $root) {
                return 0;
            }
            $pending = Member::whereNull('parent_id')->where('id', '!=', $root->id)->orderBy('id')->get();
            $pending->each(fn (Member $member) => self::assign($member, $root, $member->created_at));

            return $pending->count();
        });
    }

    /** Xóa toàn bộ vị trí rồi xếp lại từ đầu theo thứ tự đăng ký (chỉ dùng trước khi vận hành thật / khi đổi quy tắc). */
    public static function rebuild(): int
    {
        return DB::transaction(function () {
            Member::query()->update(['parent_id' => null, 'position' => null, 'placed_at' => null]);

            return self::placeUnplaced();
        });
    }

    /**
     * Thông tin hai nhánh của một thành viên: mỗi nhánh gồm head (người đứng đầu nhánh, null nếu trống), count (số thành
     * viên), sales (doanh số tháng của cả nhánh); weaker = nhánh yếu hơn theo đúng quy tắc xếp (null nếu chưa có nhánh nào).
     */
    public static function legs(Member $member, ?CarbonInterface $asOf = null): array
    {
        $nodes = self::subtree($member->id);
        $sales = self::monthSales($nodes->keys()->all(), $asOf ?? now());
        $children = self::childrenMap($nodes);
        $legs = [];
        foreach ([self::LEFT, self::RIGHT] as $side) {
            $headId = $children[$member->id][$side] ?? null;
            $legs[$side] = ['head' => null, 'count' => 0, 'sales' => 0, ...($headId ? self::totals($headId, $children, $sales) : [])];
            $legs[$side]['head'] = $headId ? Member::with('level:id,name')->find($headId) : null;
        }
        $weaker = $legs[self::LEFT]['count'] + $legs[self::RIGHT]['count'] === 0 ? null : self::weakerSide($legs[self::LEFT], $legs[self::RIGHT]);

        return [...$legs, 'weaker' => $weaker];
    }

    /** Id mọi người nằm dưới một thành viên trong cây nhị phân (không gồm chính họ). */
    public static function branchIds(int $memberId): array
    {
        return self::subtree($memberId)->keys()->reject(fn ($id) => $id === $memberId)->values()->all();
    }

    /** Gán chỗ cho một thành viên chưa có chỗ: bắt đầu từ người giới thiệu (hoặc ông tổng), đi xuống theo nhánh yếu hơn. */
    private static function assign(Member $member, Member $root, ?CarbonInterface $asOf): void
    {
        if ($member->parent_id !== null || $member->id === $root->id) {
            return;
        }
        $start = $member->referrer_id && $member->referrer_id !== $member->id ? (int) $member->referrer_id : $root->id;
        // Người giới thiệu phải có chỗ trước (mọi người đăng ký trước đều đã được xếp ở place() / placeUnplaced())
        $sponsor = Member::find($start);
        if (! $sponsor || ($sponsor->id !== $root->id && $sponsor->parent_id === null)) {
            $start = $root->id;
        }

        $nodes = self::subtree($start);
        $children = self::childrenMap($nodes);
        $sales = self::monthSales($nodes->keys()->all(), $asOf ?? now());
        $current = $start;
        while (true) {
            $kids = $children[$current] ?? [];
            if (! isset($kids[self::LEFT]) || ! isset($kids[self::RIGHT])) {
                $side = isset($kids[self::LEFT]) ? self::RIGHT : self::LEFT;
                break;
            }
            $weaker = self::weakerSide(self::totals($kids[self::LEFT], $children, $sales), self::totals($kids[self::RIGHT], $children, $sales));
            $current = $kids[$weaker];
        }

        $member->forceFill(['parent_id' => $current, 'position' => $side, 'placed_at' => now()])->saveQuietly();
    }

    /** Nhánh yếu hơn: doanh số tháng thấp hơn → ít người hơn → trái. */
    private static function weakerSide(array $left, array $right): string
    {
        if ($left['sales'] !== $right['sales']) {
            return $left['sales'] < $right['sales'] ? self::LEFT : self::RIGHT;
        }

        return $right['count'] < $left['count'] ? self::RIGHT : self::LEFT;
    }

    /** Số người + doanh số tháng của nhánh có người đứng đầu là $headId (tính cả người đứng đầu). */
    private static function totals(int $headId, array $children, array $sales): array
    {
        $count = 0;
        $sum = 0;
        $stack = [$headId];
        while ($stack !== []) {
            $id = array_pop($stack);
            $count++;
            $sum += $sales[$id] ?? 0;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return ['count' => $count, 'sales' => $sum];
    }

    /** Mọi node của cây con (kể cả gốc): id => (object) [id, parent_id, position], nạp theo từng tầng. */
    private static function subtree(int $rootId): Collection
    {
        $nodes = collect([$rootId => (object) ['id' => $rootId, 'parent_id' => null, 'position' => null]]);
        $level = [$rootId];
        while ($level !== []) {
            $rows = Member::whereIn('parent_id', $level)->get(['id', 'parent_id', 'position']);
            $level = [];
            foreach ($rows as $row) {
                if (! $nodes->has($row->id)) {
                    $nodes[$row->id] = (object) ['id' => $row->id, 'parent_id' => $row->parent_id, 'position' => $row->position];
                    $level[] = $row->id;
                }
            }
        }

        return $nodes;
    }

    /** parent_id => [L => id con trái, R => id con phải]. */
    private static function childrenMap(Collection $nodes): array
    {
        $children = [];
        foreach ($nodes as $node) {
            if ($node->parent_id !== null && $node->position !== null) {
                $children[$node->parent_id][$node->position] = $node->id;
            }
        }

        return $children;
    }

    /** member_id => tiền hàng các đơn đã thanh toán từ đầu tháng (của $asOf) đến $asOf. */
    private static function monthSales(array $memberIds, CarbonInterface $asOf): array
    {
        if ($memberIds === []) {
            return [];
        }

        return Order::whereIn('member_id', $memberIds)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
            ->whereBetween('paid_at', [$asOf->copy()->startOfMonth(), $asOf])
            ->groupBy('member_id')->selectRaw('member_id, SUM(goods_amount) AS total')->pluck('total', 'member_id')
            ->map(fn ($total) => (int) $total)->all();
    }
}
