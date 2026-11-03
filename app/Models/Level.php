<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cấp bậc thành viên (A2). Lên cấp theo mốc doanh số cá nhân cộng dồn, không bao giờ hạ cấp (engine ở Step 7).
 * Cấp gốc = cấp có mốc 0 ("Chưa kích hoạt"): luôn đứng đầu, luôn bật, không nhận hoa hồng.
 */
class Level extends Model
{
    protected $fillable = ['name', 'sort_order', 'threshold_amount', 'is_vp', 'active'];

    protected $attributes = [
        'is_vp' => false,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'threshold_amount' => 'integer',
            'is_vp' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function commissionRates(): HasMany
    {
        return $this->hasMany(CommissionRate::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    public function isBase(): bool
    {
        return $this->threshold_amount === 0;
    }

    /** Cấp hưởng hoa hồng được (mọi cấp trừ cấp gốc), theo thứ tự. */
    public static function earning(): Collection
    {
        return static::ordered()->where('threshold_amount', '>', 0)->get();
    }
}
