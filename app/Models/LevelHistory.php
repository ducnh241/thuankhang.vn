<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vết lên cấp của thành viên (Step 7): từ cấp nào lên cấp nào, do đơn nào (null = kích hoạt thủ công), doanh số cộng dồn
 * ngay sau đó. Chỉ ghi thêm khi cấp thay đổi; không có dòng "hạ cấp" (Phase 1 không hạ cấp).
 */
class LevelHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['member_id', 'from_level_id', 'to_level_id', 'order_id', 'cumulative_after', 'note'];

    protected function casts(): array
    {
        return ['cumulative_after' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'from_level_id');
    }

    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'to_level_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
