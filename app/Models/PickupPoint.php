<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Điểm nhận hàng / văn phòng VP tỉnh (A7). Khách chọn "Nhận tại VP tỉnh" ở checkout: tỉnh → điểm (chỉ điểm đang bật —
 * scope available()). Hàng tại điểm là hàng của VP, hệ thống không quản lý tồn kho.
 */
class PickupPoint extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'province', 'address', 'phone', 'opening_hours', 'owner_member_id', 'active', 'sort_order'];

    protected $attributes = [
        'active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_member_id');
    }

    /** Điểm hiện ở checkout: đang bật (chưa xóa). */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function provinceName(): string
    {
        return self::provinces()[$this->province] ?? $this->province;
    }

    /** Danh sách tỉnh / thành: khóa => tên (config/provinces.php). */
    public static function provinces(): array
    {
        return config('provinces');
    }
}
