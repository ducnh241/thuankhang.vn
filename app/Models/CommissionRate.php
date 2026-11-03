<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Ma trận hoa hồng cấp người hưởng × tầng (A3), có lịch sử theo effective_from. Mỗi lần lưu là một phiên bản đủ
 * mọi cấp; đơn thanh toán lúc T dùng phiên bản mới nhất có effective_from ≤ T. Chỉ ghi thêm.
 */
class CommissionRate extends Model
{
    public const UPDATED_AT = null;

    public const TIERS = ['F1' => 'F1 (trực tiếp)', 'F2' => 'F2 (gián tiếp)'];

    protected $fillable = ['level_id', 'tier', 'rate_percent', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            // MySQL trả "10.00", SQLite trả "10" → luôn chuẩn "10.00"
            'rate_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Tỉ lệ hoa hồng chỉ được thêm phiên bản mới, không được sửa.'));
        static::deleting(fn () => throw new LogicException('Tỉ lệ hoa hồng chỉ được thêm phiên bản mới, không được xóa.'));
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tỉ lệ % ("10.00") của cấp + tầng có hiệu lực lúc $at; chưa từng cấu hình → null (coi như 0). */
    public static function rateFor(int $levelId, string $tier, CarbonInterface $at): ?string
    {
        return static::where('level_id', $levelId)->where('tier', $tier)->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->first()?->rate_percent;
    }

    /**
     * Các phiên bản, mới nhất trước: [effective_from (Carbon), rates [level_id][tier] => "10.00", creator (tên|null)].
     *
     * @return Collection<int, array{effective_from: Carbon, rates: array, creator: ?string}>
     */
    public static function versions(): Collection
    {
        return static::with('creator:id,name')->orderByDesc('effective_from')->get()
            ->groupBy(fn (self $rate) => $rate->effective_from->format('Y-m-d H:i:s'))
            ->map(fn (Collection $rows) => [
                'effective_from' => $rows->first()->effective_from,
                'rates' => $rows->groupBy('level_id')->map(fn ($r) => collect(self::TIERS)
                    ->map(fn ($label, $tier) => $r->firstWhere('tier', $tier)?->rate_percent)->filter(fn ($v) => $v !== null)->all())->all(),
                'creator' => $rows->first()->creator?->name,
            ])->values();
    }
}
