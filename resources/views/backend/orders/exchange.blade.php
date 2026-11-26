@extends('layouts.backend')

{{-- Đổi sản phẩm trong đơn (order/exchange): bảng sản phẩm đang có (sửa số lượng giữ lại: 0 = bỏ), bảng thêm sản phẩm
     (repeater.js), tổng cũ / tổng mới / chênh lệch tính trực tiếp (JS order-exchange.js — [data-exchange]); nút xác nhận chỉ
     bật khi tổng mới bằng đúng tổng cũ. Máy chủ kiểm tra lại toàn bộ (App\Services\OrderExchanger). --}}
@php
    use App\Models\Order;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $money = fn ($v) => Money::format((int) $v).' đ';
    $right = 'style="text-align: right"';
    $oldKeep = old('keep', []);
    $oldAdds = is_array(old('adds')) ? array_values(array_filter(old('adds'), 'is_array')) : [];
    $byValue = $options->keyBy('value');
    $optionLabel = fn (array $o) => $o['label'].' — '.$money($o['price']);
    // Một ô chọn sản phẩm: ô ẩn gửi đi (value "id" hoặc "id:biến thể") + nút mở bảng chọn có tìm kiếm (order-exchange.js)
    $picker = function (string $name, ?string $selected = null) use ($byValue, $optionLabel) {
        $o = $selected !== null ? $byValue->get($selected) : null;

        return '<div class="relative" data-picker>'
            .'<input type="hidden" name="'.e($name).'" value="'.e($o ? $o['value'] : '').'" data-picker-value />'
            .'<button type="button" class="ant-input flex w-full items-center justify-between gap-2 text-left" aria-haspopup="listbox" aria-expanded="false" data-picker-toggle>'
            .'<span class="min-w-0 truncate'.($o ? '' : ' text-[rgba(0,0,0,0.35)]').'" data-picker-label>'.e($o ? $optionLabel($o) : 'Chọn sản phẩm…').'</span>'
            .'<span class="shrink-0 text-[rgba(0,0,0,0.35)]" aria-hidden="true">▾</span></button>'
            .'<div class="absolute left-0 top-full z-50 mt-1 hidden w-[min(640px,calc(100vw-3rem))] min-w-full rounded-lg border border-[#f0f0f0] bg-white shadow-lg" data-picker-panel>'
            .'<div class="border-b border-[#f0f0f0] p-2"><input type="search" class="ant-input" placeholder="Tìm theo tên sản phẩm, phân loại…" autocomplete="off" aria-label="Tìm sản phẩm" data-picker-search /></div>'
            .'<div class="px-3 pt-2 text-xs text-[rgba(0,0,0,0.45)]" data-picker-hint></div>'
            .'<ul class="m-0 max-h-80 list-none overflow-auto p-1" role="listbox" data-picker-list></ul>'
            .'<label class="flex cursor-pointer items-center gap-2 border-t border-[#f0f0f0] px-3 py-2 text-xs text-[rgba(0,0,0,0.55)]"><input type="checkbox" data-picker-all /> Cho chọn cả sản phẩm không chia hết số tiền (để ghép nhiều sản phẩm)</label>'
            .'</div></div>';
    };
@endphp

@section('title', 'Đổi sản phẩm — đơn '.$order->code)

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.orders.index') }}" class="page-topbar-crumb max-md:hidden">Đơn hàng</a>
    <span class="page-topbar-sep max-md:hidden" aria-hidden="true">/</span>
    <a href="{{ route('backend.orders.show', $order) }}" class="page-topbar-crumb font-mono">{{ $order->code }}</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">Đổi sản phẩm</h1>
    <span class="ant-tag ant-tag-{{ Order::STATUS_COLORS[$order->displayStatus()] }} m-0">{{ $order->statusLabel() }}</span>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<form method="POST" action="{{ route('backend.orders.exchange.store', $order) }}" class="mx-auto flex max-w-[1100px] flex-col gap-4" novalidate
      data-exchange data-old-total="{{ $order->goods_amount }}">
  @csrf
  <script type="application/json" data-exchange-options>@json($options)</script>
  <div class="ant-alert ant-alert-info"><div class="ant-alert-content"><div class="ant-alert-message">
    Đổi sang sản phẩm khác giá trị tương đương, một đổi nhiều hoặc nhiều đổi một. <strong>Tổng tiền hàng mới phải bằng đúng tổng cũ
    {{ $money($order->goods_amount) }}</strong> — tiền khách trả, hoa hồng, doanh số không thay đổi. Sản phẩm giữ lại giữ giá trên đơn,
    sản phẩm thêm lấy giá bán hiện tại. Hàng bớt được trả về kho, hàng thêm bị trừ kho.
  </div></div></div>

  <div class="ant-card ant-card-bordered" data-card="current">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Sản phẩm đang có — số lượng giữ lại (0 = bỏ)</div></div></div>
    <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
      {{-- Độ rộng cột cố định: nội dung đổi (thành tiền, tên dài) không làm các cột nhảy --}}
      <table class="w-full min-w-[680px]" style="table-layout: fixed">
        <thead class="ant-table-thead"><tr>
          <th class="ant-table-cell">Sản phẩm</th><th class="ant-table-cell" style="width: 120px; text-align: right">Đơn giá</th>
          <th class="ant-table-cell" style="width: 100px; text-align: right">SL trên đơn</th><th class="ant-table-cell" style="width: 110px">Giữ lại</th><th class="ant-table-cell" style="width: 140px; text-align: right">Thành tiền mới</th>
        </tr></thead>
        <tbody class="ant-table-tbody">
          @foreach ($order->items as $item)
            <tr class="ant-table-row" data-keep-row data-item-value="{{ $item->product_id }}{{ $item->variant_id ? ':'.$item->variant_id : '' }}">
              <td class="ant-table-cell">
                @php $thumb = $item->variant?->image ?? $item->product?->cover; @endphp
                <div class="flex items-center gap-3">
                  <span class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border border-[#f0f0f0] bg-white text-[rgba(0,0,0,0.25)]" data-item-thumb>
                    @if ($thumb)<img src="{{ $thumb->url() }}" alt="" loading="lazy" class="size-full object-contain" />@else{{ ant_icon('picture') }}@endif
                  </span>
                  <div class="min-w-0">{{ $item->product_name }}@if ($item->variant_name)<div class="text-xs {{ $muted }}">Phân loại: {{ $item->variant_name }}</div>@endif</div>
                </div>
              </td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ $money($item->unit_price) }}</td>
              <td class="ant-table-cell tabular-nums" {!! $right !!}>{{ $item->qty }}</td>
              <td class="ant-table-cell">
                <input type="number" name="keep[{{ $item->id }}]" value="{{ is_scalar($oldKeep[$item->id] ?? null) ? $oldKeep[$item->id] : $item->qty }}" min="0" max="{{ $item->qty }}" step="1"
                       class="ant-input tabular-nums" data-unit="{{ $item->unit_price }}" aria-label="Giữ lại {{ $item->product_name }}" />
              </td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!} data-line-total>{{ $money($item->line_amount) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div></div></div>
  </div>

  <div class="ant-card ant-card-bordered relative z-10" data-card="adds" data-repeater>
    <div class="ant-card-head"><div class="ant-card-head-wrapper">
      <div class="ant-card-head-title">Thêm sản phẩm</div>
      <div class="ant-card-extra"><button type="button" class="ant-btn ant-btn-dashed" data-repeater-add>{{ ant_icon('plus') }}<span>Thêm dòng</span></button></div>
    </div></div>
    <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-visible">
      <table class="w-full" style="table-layout: fixed">
        <thead class="ant-table-thead"><tr>
          <th class="ant-table-cell">Sản phẩm (giá bán hiện tại)</th><th class="ant-table-cell w-20 md:w-[110px]">Số lượng</th>
          <th class="ant-table-cell max-md:hidden" style="width: 140px; text-align: right">Thành tiền</th><th class="ant-table-cell" style="width: 56px"></th>
        </tr></thead>
        <tbody class="ant-table-tbody" data-repeater-rows>
          @foreach ($oldAdds as $i => $row)
            <tr class="ant-table-row" data-repeater-row data-add-row>
              <td class="ant-table-cell">{!! $picker("adds[old{$i}][product]", is_string($row['product'] ?? null) ? $row['product'] : null) !!}</td>
              <td class="ant-table-cell"><input type="number" name="adds[old{{ $i }}][qty]" value="{{ is_scalar($row['qty'] ?? null) ? $row['qty'] : 1 }}" min="1" max="99" step="1" class="ant-input tabular-nums" aria-label="Số lượng thêm" /></td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden" {!! $right !!} data-line-total>0 đ</td>
              <td class="ant-table-cell"><button type="button" class="ant-btn ant-btn-text" data-repeater-remove aria-label="Bỏ dòng">{{ ant_icon('delete') }}</button></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div></div></div>
    <p @class(['m-0 px-4 py-3 text-sm', $muted, 'hidden' => $oldAdds !== []]) data-repeater-empty>Chưa thêm sản phẩm nào. Bấm “Thêm dòng”.</p>
    <template data-repeater-template>
      <tr class="ant-table-row" data-repeater-row data-add-row>
        <td class="ant-table-cell">{!! $picker('adds[__INDEX__][product]') !!}</td>
        <td class="ant-table-cell"><input type="number" name="adds[__INDEX__][qty]" value="1" min="1" max="99" step="1" class="ant-input tabular-nums" aria-label="Số lượng thêm" /></td>
        <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden" {!! $right !!} data-line-total>0 đ</td>
        <td class="ant-table-cell"><button type="button" class="ant-btn ant-btn-text" data-repeater-remove aria-label="Bỏ dòng">{{ ant_icon('delete') }}</button></td>
      </tr>
    </template>
  </div>

  <div class="ant-card ant-card-bordered" data-card="confirm">
    <div class="ant-card-body flex flex-col gap-4">
      <dl class="m-0 grid max-w-md grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-base tabular-nums" data-totals>
        <dt>Tổng tiền hàng cũ</dt><dd class="m-0 text-right font-medium">{{ $money($order->goods_amount) }}</dd>
        <dt>Tổng tiền hàng mới</dt><dd class="m-0 text-right font-semibold" data-new-total>{{ $money($order->goods_amount) }}</dd>
        <dt>Chênh lệch</dt><dd class="m-0 text-right font-semibold" data-diff>0 đ</dd>
      </dl>
      <div class="ant-row ant-form-item mb-0">
        <div class="ant-col ant-form-item-label"><label for="reason" class="ant-form-item-required">Lý do đổi</label></div>
        <div class="ant-col ant-form-item-control">
          <textarea id="reason" name="reason" rows="3" maxlength="300" class="ant-input" placeholder="VD: Khách đổi sang mùi khác, cùng giá">{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="ant-btn ant-btn-primary" data-exchange-submit>{{ ant_icon('check-circle') }}<span>Xác nhận đổi sản phẩm</span></button>
        <a href="{{ route('backend.orders.show', $order) }}" class="ant-btn"><span>Hủy</span></a>
        <span class="text-xs {{ $muted }}" data-exchange-hint>Tổng mới phải bằng đúng tổng cũ mới đổi được.</span>
      </div>
    </div>
  </div>
</form>
@endsection
