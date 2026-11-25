<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một nhóm (cấp) trong bảng đồng chia đã duyệt: đơn vào gói của tháng, quỹ, số người, mức chia. */
class ProfitShareGroup extends Model
{
    protected $fillable = [
        'period_id', 'level_id', 'level_name', 'min_amount', 'max_amount', 'order_count', 'order_total', 'base_amount', 'pool',
        'member_count', 'paid_count', 'per_head', 'distributed',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer', 'max_amount' => 'integer', 'order_count' => 'integer', 'order_total' => 'integer',
            'base_amount' => 'integer', 'pool' => 'integer', 'member_count' => 'integer', 'paid_count' => 'integer',
            'per_head' => 'integer', 'distributed' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ProfitSharePeriod::class, 'period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProfitShareLine::class, 'group_id');
    }
}
