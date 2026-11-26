<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderExchange;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Đổi sản phẩm trong đơn (chốt 26/11/2026): quản trị (quyền order/exchange) bớt / bỏ sản phẩm đang có và thêm sản phẩm khác —
 * một đổi một, một đổi nhiều, nhiều đổi một — với điều kiện TỔNG TIỀN HÀNG MỚI BẰNG ĐÚNG TỔNG CŨ, nên tiền khách trả, hoa hồng,
 * doanh số lên cấp, đồng chia giữ nguyên. Chỉ đơn mới còn hạn / chờ xác nhận / đã thanh toán (chưa giao). Sản phẩm giữ lại
 * giữ giá trên đơn; sản phẩm thêm lấy giá bán hiện tại. Trả hàng bớt về kho, trừ kho hàng thêm (không đủ → chặn). Lý do bắt
 * buộc; lưu lịch sử trước / sau (order_exchanges) + nhật ký.
 */
class OrderExchanger
{
    public const MAX_QTY = 99;

    public const MAX_REASON = 300;

    public const MAX_ADDS = 30;

    /**
     * @param  array<int|string, mixed>  $keep  id dòng đơn => số lượng giữ lại (0 = bỏ; thiếu = giữ nguyên)
     * @param  list<array{product_id: mixed, variant_id?: mixed, qty: mixed}>  $adds  sản phẩm thêm
     * @return OrderExchange|string lần đổi đã lưu, hoặc lý do không đổi được
     */
    public static function exchange(Order $order, array $keep, array $adds, string $reason, User $user): OrderExchange|string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return 'Nhập lý do đổi (ít nhất 5 ký tự).';
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            return 'Lý do đổi tối đa '.self::MAX_REASON.' ký tự.';
        }
        if (count($adds) > self::MAX_ADDS) {
            return 'Thêm tối đa '.self::MAX_ADDS.' dòng sản phẩm mỗi lần đổi.';
        }

        return DB::transaction(function () use ($order, $keep, $adds, $reason, $user) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->canExchange()) {
                return 'Đơn ở trạng thái “'.$order->statusLabel().'” không đổi sản phẩm được.';
            }
            $items = $order->items()->get()->keyBy('id');
            $before = $items->map(fn (OrderItem $i) => self::snapshot($i->product_id, $i->variant_id, $i->product_name, $i->variant_name, $i->sku, $i->qty, $i->unit_price))->values()->all();

            // Dòng giữ lại: chỉ được bớt (0 … số lượng hiện có), giữ giá trên đơn
            $kept = [];
            foreach ($items as $id => $item) {
                $qty = array_key_exists($id, $keep) ? $keep[$id] : $item->qty;
                if (! is_numeric($qty) || (int) $qty != $qty || $qty < 0 || $qty > $item->qty) {
                    return "Số lượng giữ lại của “{$item->product_name}” phải từ 0 đến {$item->qty}.";
                }
                $kept[$id] = (int) $qty;
            }

            // Dòng thêm: sản phẩm đang bán; có biến thể đang bán thì phải chọn biến thể; giá bán hiện tại
            $added = [];
            foreach ($adds as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $variantId = ($row['variant_id'] ?? '') === '' || ($row['variant_id'] ?? null) === null ? null : (int) $row['variant_id'];
                $qty = $row['qty'] ?? null;
                if ($productId === 0 && ($qty === null || $qty === '' || (int) $qty === 0)) {
                    continue; // dòng trống trên form
                }
                $product = Product::with('activeVariants')->where('status', true)->find($productId);
                if (! $product) {
                    return 'Sản phẩm thêm không tồn tại hoặc đã ngừng bán.';
                }
                $variant = $variantId ? $product->activeVariants->firstWhere('id', $variantId) : null;
                if ($variantId ? ! $variant : $product->hasVariants()) {
                    return "“{$product->name}”: ".($variantId ? 'phân loại đã chọn không còn bán.' : 'chọn phân loại.');
                }
                if (! is_numeric($qty) || (int) $qty != $qty || $qty < 1 || $qty > self::MAX_QTY) {
                    return "Số lượng “{$product->name}” phải từ 1 đến ".self::MAX_QTY.'.';
                }
                $unit = $variant ? $variant->effectivePrice($product) : (int) $product->price;
                if ($unit <= 0) {
                    return "“{$product->name}” chưa có giá bán — không thêm vào đơn được.";
                }
                $added[] = ['product' => $product, 'variant' => $variant, 'qty' => (int) $qty, 'unit' => $unit];
            }

            // Không đổi sang chính sản phẩm (cùng phân loại) đang bớt / bỏ khỏi đơn — đổi phân loại khác của cùng sản phẩm thì được
            $reduced = collect($kept)->filter(fn ($q, $id) => $q < $items[$id]->qty)
                ->mapWithKeys(fn ($q, $id) => [$items[$id]->product_id.':'.$items[$id]->variant_id => $items[$id]]);
            foreach ($added as $a) {
                if ($same = $reduced->get($a['product']->id.':'.$a['variant']?->id)) {
                    return 'Không đổi sang chính sản phẩm đang bớt: “'.$same->product_name.($same->variant_name ? ' — '.$same->variant_name : '').'”.';
                }
            }

            $newTotal = (int) collect($kept)->map(fn ($q, $id) => $q * $items[$id]->unit_price)->sum()
                + (int) collect($added)->sum(fn ($a) => $a['qty'] * $a['unit']);
            if (collect($kept)->sum() + collect($added)->sum('qty') === 0) {
                return 'Đơn phải còn ít nhất một sản phẩm.';
            }
            if ($added === [] && collect($kept)->every(fn ($q, $id) => $q === $items[$id]->qty)) {
                return 'Chưa có thay đổi nào.';
            }
            if ($newTotal !== (int) $order->goods_amount) {
                return 'Tổng tiền hàng mới ('.Money::format($newTotal).' đ) phải bằng đúng tổng cũ ('.Money::format($order->goods_amount)
                    .' đ) — '.($newTotal > $order->goods_amount ? 'thừa ' : 'thiếu ').Money::format(abs($newTotal - $order->goods_amount)).' đ.';
            }

            // Tồn kho (đơn đang giữ hàng): trả phần bớt, trừ phần thêm — kiểm tra đủ hàng theo chênh lệch ròng của từng sản phẩm / biến thể
            if ($order->stock_reserved) {
                $delta = []; // "v:id" | "p:id" => số cần lấy thêm (âm = trả về)
                foreach ($kept as $id => $qty) {
                    $item = $items[$id];
                    $key = $item->variant_id ? 'v:'.$item->variant_id : 'p:'.$item->product_id;
                    $delta[$key] = ($delta[$key] ?? 0) - ($item->qty - $qty);
                }
                foreach ($added as $a) {
                    $key = $a['variant'] ? 'v:'.$a['variant']->id : 'p:'.$a['product']->id;
                    $delta[$key] = ($delta[$key] ?? 0) + $a['qty'];
                }
                $ids = fn (string $prefix) => collect($delta)->keys()->filter(fn ($k) => str_starts_with($k, $prefix))->map(fn ($k) => (int) substr($k, 2))->values()->all();
                $variantStock = ProductVariant::whereKey($ids('v:'))->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'stock', 'product_id'])->keyBy('id');
                $productStock = Product::withTrashed()->whereKey($ids('p:'))->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'stock'])->keyBy('id');
                foreach ($delta as $key => $need) {
                    $row = str_starts_with($key, 'v:') ? ($variantStock[(int) substr($key, 2)] ?? null) : ($productStock[(int) substr($key, 2)] ?? null);
                    if ($row && $need > 0 && $row->stock < $need) {
                        return "Không đủ hàng: “{$row->name}” còn ".max(0, $row->stock).", cần thêm {$need}.";
                    }
                }
                foreach ($delta as $key => $need) {
                    $row = str_starts_with($key, 'v:') ? ($variantStock[(int) substr($key, 2)] ?? null) : ($productStock[(int) substr($key, 2)] ?? null);
                    if ($row && $need !== 0) {
                        $row::query()->withoutGlobalScopes()->whereKey($row->id)->decrement('stock', $need);
                    }
                }
            }

            foreach ($kept as $id => $qty) {
                $item = $items[$id];
                if ($qty === 0) {
                    $item->delete();
                } elseif ($qty !== $item->qty) {
                    $item->forceFill(['qty' => $qty, 'line_amount' => $qty * $item->unit_price])->save();
                }
            }
            foreach ($added as $a) {
                // Cùng sản phẩm, phân loại, đơn giá với một dòng đang giữ → cộng vào dòng đó thay vì tạo dòng trùng
                $line = $order->items()->where('product_id', $a['product']->id)->where('variant_id', $a['variant']?->id)
                    ->where('unit_price', $a['unit'])->orderBy('id')->first();
                if ($line) {
                    $line->forceFill(['qty' => $line->qty + $a['qty'], 'line_amount' => ($line->qty + $a['qty']) * $line->unit_price])->save();

                    continue;
                }
                $order->items()->create([
                    'product_id' => $a['product']->id, 'variant_id' => $a['variant']?->id, 'product_name' => $a['product']->name,
                    'variant_name' => $a['variant']?->name, 'sku' => $a['variant']?->sku ?? $a['product']->sku, 'qty' => $a['qty'],
                    'unit_price' => $a['unit'], 'line_amount' => $a['qty'] * $a['unit'],
                ]);
            }
            $after = $order->items()->orderBy('id')->get()
                ->map(fn (OrderItem $i) => self::snapshot($i->product_id, $i->variant_id, $i->product_name, $i->variant_name, $i->sku, $i->qty, $i->unit_price))->all();

            $exchange = OrderExchange::create([
                'order_id' => $order->id, 'user_id' => $user->id, 'user_name' => $user->name, 'reason' => $reason,
                'before_items' => $before, 'after_items' => $after, 'goods_amount' => (int) $order->goods_amount, 'created_at' => now(),
            ]);
            $describe = fn (array $list) => collect($list)->map(fn ($l) => $l['name'].($l['variant'] ? ' — '.$l['variant'] : '').' × '.$l['qty'])->implode(', ') ?: '—';
            $change = OrderExchange::diff($before, $after);
            AuditLog::record('updated', $order, ['old' => ['items' => $describe($change['removed'])], 'new' => ['items' => $describe($change['added'])]],
                "Đổi sản phẩm đơn {$order->code} (tổng ".Money::format($order->goods_amount).' đ giữ nguyên): bớt '.$describe($change['removed']).' → thêm '.$describe($change['added']).". Lý do: {$reason}", $user);

            return $exchange;
        });
    }

    private static function snapshot(int $productId, ?int $variantId, string $name, ?string $variant, ?string $sku, int $qty, int $unit): array
    {
        return ['product_id' => $productId, 'variant_id' => $variantId, 'name' => $name, 'variant' => $variant, 'sku' => $sku,
            'qty' => $qty, 'unit_price' => $unit, 'line_amount' => $qty * $unit];
    }
}
