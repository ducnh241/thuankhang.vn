<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Một lần đổi sản phẩm trong đơn: danh sách trước / sau (tên, biến thể, SL, đơn giá, thành tiền), người đổi, lý do. Chỉ ghi thêm. */
class OrderExchange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'user_id', 'user_name', 'reason', 'before_items', 'after_items', 'goods_amount', 'created_at'];

    protected function casts(): array
    {
        return ['before_items' => 'array', 'after_items' => 'array', 'goods_amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lịch sử đổi sản phẩm chỉ ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Lịch sử đổi sản phẩm chỉ ghi thêm.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Người đổi để hiện ra giao diện — cùng quy ước nhật ký: tài khoản ẩn ghi "Hệ thống". */
    public function actorName(): string
    {
        return $this->user_id === null || in_array((int) $this->user_id, User::hiddenIds(), true) ? 'Hệ thống' : ($this->user_name ?: 'Hệ thống');
    }

    /** Mô tả ngắn: "bớt … → thêm …" (chỉ phần thay đổi). */
    public function summary(): string
    {
        $describe = fn (array $list) => collect($list)->map(fn ($l) => $l['name'].($l['variant'] ? ' — '.$l['variant'] : '').' × '.$l['qty'])->implode(', ') ?: '—';
        $change = $this->changes();

        return 'bớt '.$describe($change['removed']).' → thêm '.$describe($change['added']);
    }

    /** Phần thay đổi của lần đổi này: dòng bị bớt / bỏ, dòng được thêm / tăng, số dòng giữ nguyên. */
    public function changes(): array
    {
        return self::diff($this->before_items ?? [], $this->after_items ?? []);
    }

    /**
     * So danh sách trước / sau theo (sản phẩm, biến thể, đơn giá) — chỉ lấy chênh lệch số lượng.
     *
     * @return array{removed: list<array>, added: list<array>, unchanged: int}
     */
    public static function diff(array $before, array $after): array
    {
        $group = function (array $items) {
            $out = [];
            foreach ($items as $l) {
                $key = $l['product_id'].':'.($l['variant_id'] ?? '').':'.$l['unit_price'];
                $out[$key] ??= ['qty' => 0] + $l;
                $out[$key]['qty'] = ($out[$key]['qty'] ?? 0) + (int) $l['qty'];
            }

            return $out;
        };
        $old = $group($before);
        $new = $group($after);
        $line = fn (array $l, int $qty) => ['name' => $l['name'], 'variant' => $l['variant'] ?? null, 'qty' => $qty, 'unit_price' => (int) $l['unit_price'], 'line_amount' => $qty * (int) $l['unit_price']];
        $removed = $added = [];
        $unchanged = 0;
        foreach ($old as $key => $l) {
            $diff = $l['qty'] - ($new[$key]['qty'] ?? 0);
            if ($diff > 0) {
                $removed[] = $line($l, $diff);
            } elseif ($diff === 0) {
                $unchanged++;
            }
        }
        foreach ($new as $key => $l) {
            $diff = $l['qty'] - ($old[$key]['qty'] ?? 0);
            if ($diff > 0) {
                $added[] = $line($l, $diff);
            }
        }

        return ['removed' => $removed, 'added' => $added, 'unchanged' => $unchanged];
    }
}
