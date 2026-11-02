<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['referrer_customer_id', 'name', 'company', 'email', 'phone', 'address', 'note'];

    protected function casts(): array
    {
        return [
            // Số đếm từ withCount: một số driver MySQL trả về chuỗi ("0") → ép số nguyên để so sánh === 0 không sai
            'projects_count' => 'integer',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** KH đã giới thiệu KH này. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id')->withTrashed();
    }

    /** Những KH do KH này giới thiệu (trực tiếp). */
    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referrer_customer_id');
    }

    /**
     * id các KH nằm dưới KH này trong chuỗi giới thiệu (con, cháu…), không gồm chính nó.
     * Dùng để chặn chọn người giới thiệu tạo vòng lặp A → B → A.
     */
    public static function downlineIds(int $id): array
    {
        $children = self::withTrashed()->whereNotNull('referrer_customer_id')->get(['id', 'referrer_customer_id'])->groupBy('referrer_customer_id');
        $ids = [];
        $queue = [$id];

        while ($queue) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if ($child->id !== $id && ! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $ids;
    }

    /** Tổng đã thu (không tính phát sinh chưa thu) từ các dự án (chưa xóa) của mỗi KH: [customer_id => số tiền]. */
    public static function revenueMap(): array
    {
        return DB::table('payments')
            ->join('projects', 'projects.id', '=', 'payments.project_id')
            ->whereNull('projects.deleted_at')
            ->where('payments.status', 'paid')
            ->select('projects.customer_id', DB::raw('SUM(payments.amount) as total'))
            ->groupBy('projects.customer_id')
            ->pluck('total', 'customer_id')->map(fn ($v) => (float) $v)->all();
    }

    /** "Tên — Công ty" để hiển thị trong select. */
    public function displayName(): string
    {
        return $this->name.($this->company ? ' — '.$this->company : '');
    }

    /**
     * Số dự án của mỗi KH: trực tiếp, và tổng gồm cả dự án của các KH mà họ giới thiệu (đệ quy).
     *
     * @return array<int, array{direct:int, total:int}>
     */
    public static function projectCountMap(): array
    {
        $direct = DB::table('projects')->whereNull('deleted_at')
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('customer_id')->pluck('cnt', 'customer_id')->map(fn ($c) => (int) $c)->all();

        $children = [];
        foreach (self::query()->whereNotNull('referrer_customer_id')->get(['id', 'referrer_customer_id']) as $c) {
            $children[$c->referrer_customer_id][] = $c->id;
        }

        $totals = [];
        $compute = function (int $id, array $visiting = []) use (&$compute, &$totals, $children, $direct): int {
            if (isset($totals[$id])) {
                return $totals[$id];
            }
            if (in_array($id, $visiting, true)) {
                return 0; // chống vòng lặp A giới thiệu B, B giới thiệu A
            }
            $total = $direct[$id] ?? 0;
            foreach ($children[$id] ?? [] as $child) {
                $total += $compute($child, [...$visiting, $id]);
            }

            return $totals[$id] = $total;
        };

        $map = [];
        foreach (array_unique([...array_keys($direct), ...array_keys($children)]) as $id) {
            $map[$id] = ['direct' => $direct[$id] ?? 0, 'total' => $compute($id)];
        }

        return $map;
    }
}
