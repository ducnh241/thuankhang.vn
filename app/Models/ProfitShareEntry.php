<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suất đồng chia của một thành viên trong một nhóm (cấp). Chỉ được ghi lúc kế toán duyệt một tháng (App\Services\ProfitShare):
 * trước đó màn hình tính trực tiếp từ đơn hàng. received_amount = tổng đã duyệt; nhận đủ cap_amount thì exited_period.
 */
class ProfitShareEntry extends Model
{
    protected $fillable = ['level_id', 'member_id', 'order_id', 'joined_at', 'order_amount', 'cap_amount', 'received_amount', 'exited_period'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'order_amount' => 'integer', 'cap_amount' => 'integer', 'received_amount' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}
