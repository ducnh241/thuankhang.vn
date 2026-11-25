<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Một thành viên trong bảng đồng chia đã duyệt: hạng, trạng thái (paid | waiting | skipped), đã nhận trước, nhận tháng này. */
class ProfitShareLine extends Model
{
    public const STATUS_PAID = 'paid';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = ['group_id', 'entry_id', 'member_id', 'rank', 'status', 'note', 'received_before', 'amount', 'exits'];

    protected function casts(): array
    {
        return ['rank' => 'integer', 'received_before' => 'integer', 'amount' => 'integer', 'exits' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProfitShareGroup::class, 'group_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProfitShareEntry::class, 'entry_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class, 'profit_share_line_id');
    }
}
