<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Order;
use App\Support\AffiliateSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cây nhị phân của thành viên (kết hợp với cây giới thiệu "mặt trời"). Mỗi người có tối đa 2 nhánh: L và R — trên giao
 * diện gọi là "Nhánh A" (L, vẽ bên trái) và "Nhánh B" (R, vẽ bên phải).
 * "Ông tổng" = gốc của cây chính: thành viên có mã cấu hình ở Tham số chung (root_member_code); chưa cấu hình thì là
 * thành viên không có người giới thiệu đăng ký sớm nhất. Thành viên KHÔNG có người giới thiệu khác (tài khoản thử, dữ liệu
 * cũ) là gốc của một cây riêng — không bị trộn vào cây của ông tổng.
 *
 * Quy tắc xếp người mới (gán một lần, cố định) — từ 18/11/2026 thành viên TỰ CHỌN nhánh lúc đăng ký (members.sponsor_side,
 * người giới thiệu hướng dẫn nên chọn A hay B), KHÔNG còn tự động chia vào nhánh yếu:
 *   - Bắt đầu từ người giới thiệu X, đi xuống đúng nhánh đã chọn (A = L, B = R): nhánh đó của X còn trống → đứng ngay dưới
 *     X; đã có người → đi tiếp xuống cùng phía đó cho tới vị trí trống đầu tiên (vị trí ngoài cùng của nhánh).
 * Vì luôn bắt đầu từ X nên người do X giới thiệu luôn nằm trong nhánh đã chọn của X.
 *
 * Thành viên KHÔNG có lựa chọn (sponsor_side = NULL: dữ liệu cũ trước 18/11/2026, tạo từ công cụ / seeder) vẫn theo quy
 * tắc cũ: chỗ trống của X trước (A rồi B); cả hai đã có người → đi xuống nhánh yếu hơn (doanh số tháng thấp hơn → ít
 * người hơn → nhánh A). "Nhánh yếu hơn" còn dùng để hiển thị và để tính thưởng nhóm sau này.
 */
class BinaryTree
{
    public const LEFT = 'L';

    public const RIGHT = 'R';

    public const SIDES = [self::LEFT => 'Nhánh A', self::RIGHT => 'Nhánh B'];

    /** Lựa chọn trên form đăng ký (A / B) → nhánh lưu trong cơ sở dữ liệu. */
    public const BRANCHES = ['A' => self::LEFT, 'B' => self::RIGHT];

    /** Ông tổng: thành viên có mã cấu hình ở Tham số chung; chưa cấu hình → người không có người giới thiệu đăng ký sớm nhất. */
    public static function root(): ?Member
    {
        $configured = Member::findByReferralCode(AffiliateSettings::get('root_member_code'));

        return $configured ?? Member::whereNull('referrer_id')->orderBy('id')->first() ?? Member::orderBy('id')->first();
    }

    /**
     * Xếp một thành viên vào cây (không làm gì nếu đã có chỗ, hoặc không có người giới thiệu — khi đó họ là gốc một cây).
     * $asOf: thời điểm xét doanh số tháng — mặc định bây giờ; khi xếp lại thành viên cũ thì là ngày họ đăng ký. Chạy trong
     * transaction có khóa để hai người đăng ký cùng lúc không tranh một chỗ.
     */
    public static function place(Member $member, ?CarbonInterface $asOf = null): void
    {
        DB::transaction(function () use ($member, $asOf) {
            self::lock();
            // Thành viên đăng ký trước mình mà chưa có chỗ (dữ liệu cũ): xếp họ trước, đúng thứ tự
            self::pending()->where('id', '<', $member->id)->get()->each(fn (Member $earlier) => self::assign($earlier, $earlier->created_at));
            self::assign($member, $asOf);
        });
    }

    /** Xếp mọi thành viên chưa có chỗ, theo thứ tự đăng ký (xét doanh số tháng tại ngày đăng ký của từng người). */
    public static function placeUnplaced(): int
    {
        return DB::transaction(function () {
            self::lock();
            $pending = self::pending()->get();
            $pending->each(fn (Member $member) => self::assign($member, $member->created_at));

            return $pending->count();
        });
    }

    /**
     * Xóa toàn bộ vị trí rồi xếp lại từ đầu theo thứ tự đăng ký (chỉ dùng trước khi vận hành thật). Nhánh thành viên đã tự
     * chọn lúc đăng ký (sponsor_side) được giữ và áp dụng lại; thành viên không có lựa chọn xếp theo quy tắc cũ.
     */
    public static function rebuild(): int
    {
        return DB::transaction(function () {
            Member::query()->update(['parent_id' => null, 'position' => null, 'placed_at' => null]);

            return self::placeUnplaced();
        });
    }

    /** Thành viên cần xếp mà chưa có chỗ: có người giới thiệu nhưng chưa có parent. Theo thứ tự đăng ký. */
    private static function pending()
    {
        return Member::whereNull('parent_id')->whereNotNull('referrer_id')->orderBy('id');
    }

    /** Khóa toàn cây trong transaction hiện tại (khóa dòng thành viên đầu tiên làm mutex). */
    private static function lock(): void
    {
        Member::orderBy('id')->lockForUpdate()->first();
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

    /**
     * Cây nhị phân từ một thành viên trở xuống, tối đa $depth tầng bên dưới (để vẽ ở trang quản trị). Mỗi node:
     *   member (kèm level), sales (doanh số tháng của riêng người đó), branch_count / branch_sales (cả nhánh, gồm chính
     *   họ), children ([L => node|null, R => node|null], null khi không có ai bên dưới hoặc bị cắt vì hết tầng),
     *   more (còn người bên dưới nhưng chưa vẽ), weaker (L | R: nhánh yếu hơn trong hai nhánh con — null nếu chưa đủ hai nhánh).
     */
    public static function tree(Member $member, int $depth = 4, ?CarbonInterface $asOf = null): array
    {
        $nodes = self::subtree($member->id);
        $children = self::childrenMap($nodes);
        $sales = self::monthSales($nodes->keys()->all(), $asOf ?? now());

        // Chỉ nạp model của những người sẽ vẽ
        $visible = [$member->id];
        $level = [$member->id];
        for ($i = 0; $i < $depth && $level !== []; $i++) {
            $level = collect($level)->flatMap(fn ($id) => array_values($children[$id] ?? []))->all();
            $visible = [...$visible, ...$level];
        }
        $members = Member::with('level:id,name,sort_order,threshold_amount')->whereIn('id', $visible)->get()->keyBy('id');

        $build = function (int $id, int $at) use (&$build, $children, $sales, $members, $depth) {
            $kids = $children[$id] ?? [];
            $totals = self::totals($id, $children, $sales);
            $node = [
                'member' => $members[$id], 'sales' => $sales[$id] ?? 0,
                'branch_count' => $totals['count'], 'branch_sales' => $totals['sales'],
                'children' => null, 'more' => false, 'weaker' => null,
            ];
            if ($kids === []) {
                return $node;
            }
            if ($at >= $depth) {
                return [...$node, 'more' => true];
            }
            $node['children'] = [
                self::LEFT => isset($kids[self::LEFT]) ? $build($kids[self::LEFT], $at + 1) : null,
                self::RIGHT => isset($kids[self::RIGHT]) ? $build($kids[self::RIGHT], $at + 1) : null,
            ];
            if (isset($kids[self::LEFT], $kids[self::RIGHT])) {
                $node['weaker'] = self::weakerSide(self::totals($kids[self::LEFT], $children, $sales), self::totals($kids[self::RIGHT], $children, $sales));
            }

            return $node;
        };

        return $build($member->id, 0);
    }

    /** Id mọi người nằm dưới một thành viên trong cây nhị phân (không gồm chính họ). */
    public static function branchIds(int $memberId): array
    {
        return self::subtree($memberId)->keys()->reject(fn ($id) => $id === $memberId)->values()->all();
    }

    /**
     * Gán chỗ cho một thành viên chưa có chỗ: bắt đầu từ người giới thiệu, đi xuống nhánh thành viên đã chọn lúc đăng ký
     * (sponsor_side) tới vị trí trống ngoài cùng; không có lựa chọn (dữ liệu cũ) thì theo nhánh yếu hơn. Không có người giới
     * thiệu → là gốc của một cây, không gán. Người giới thiệu chưa có chỗ (vd quản trị đổi người giới thiệu sang người đăng
     * ký sau) thì xếp người giới thiệu trước.
     */
    private static function assign(Member $member, ?CarbonInterface $asOf, int $depth = 0): void
    {
        if ($member->parent_id !== null || $member->referrer_id === null || $member->referrer_id === $member->id) {
            return;
        }
        $sponsor = Member::find($member->referrer_id);
        if (! $sponsor) {
            return;
        }
        if ($sponsor->parent_id === null && $sponsor->referrer_id !== null && $depth < 50) {
            self::assign($sponsor, $sponsor->created_at, $depth + 1);
        }

        $nodes = self::subtree($sponsor->id);
        if ($nodes->has($member->id)) {
            return; // chặn vòng: mình đang là tuyến trên của người giới thiệu
        }
        $children = self::childrenMap($nodes);
        $current = $sponsor->id;
        if (in_array($member->sponsor_side, [self::LEFT, self::RIGHT], true)) {
            // Nhánh tự chọn: đi xuống đúng phía đó cho tới vị trí trống đầu tiên
            $side = $member->sponsor_side;
            while (isset($children[$current][$side])) {
                $current = $children[$current][$side];
            }
            $member->forceFill(['parent_id' => $current, 'position' => $side, 'placed_at' => now()])->saveQuietly();

            return;
        }

        // Không có lựa chọn (dữ liệu cũ): chỗ trống trước, rồi nhánh yếu hơn
        $sales = self::monthSales($nodes->keys()->all(), $asOf ?? now());
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

    /** Nhánh yếu hơn: doanh số tháng thấp hơn → ít người hơn → nhánh A (L). */
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
