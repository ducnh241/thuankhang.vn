<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bậc thưởng KPI tháng theo doanh số nhóm (A4). Phase 1 chỉ lưu tham số, Phase 2 mới tính thưởng. */
class KpiTier extends Model
{
    protected $fillable = ['min_amount', 'max_amount', 'rate_percent', 'min_f1'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'min_f1' => 'integer',
            'rate_percent' => 'decimal:2',
        ];
    }
}
