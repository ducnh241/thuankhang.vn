@extends('layouts.backend')

{{-- Chi tiết đơn (A8): sản phẩm (snapshot lúc đặt) + tiền, nhận hàng, lịch sử; cột phụ: thành viên đặt, chuyển trạng thái
     (order/status: Đã thanh toán → Đang xử lý → Hoàn tất), hủy đơn chưa thanh toán (order/cancel, bắt buộc lý do). --}}
@php
    use App\Models\Order;
    use App\Support\Money;
    use App\Support\Percent;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $status = $order->displayStatus();
    $next = $order->nextStatus();
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $timeline = array_filter([
        'Đặt hàng' => $order->created_at, 'Hạn thanh toán' => $order->status === Order::STATUS_PENDING ? $order->expires_at : null,
        'Thanh toán' => $order->paid_at, 'Bắt đầu xử lý' => $order->processing_at, 'Hoàn tất' => $order->completed_at, 'Hủy' => $order->cancelled_at,
    ]);
@endphp

@section('title', 'Đơn '.$order->code)
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.orders.index') }}" class="page-topbar-crumb" title="Về danh sách đơn hàng">Đơn hàng</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title font-mono">{{ $order->code }}</h1>
    <span class="ant-tag ant-tag-{{ Order::STATUS_COLORS[$status] }} m-0" id="order-status">{{ Order::STATUSES[$status] }}</span>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-3">
  <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
    <div class="ant-card ant-card-bordered" data-card="items">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Sản phẩm</div></div></div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto">
            <thead class="ant-table-thead">
              <tr>
                <th class="ant-table-cell">Sản phẩm</th>
                <th class="ant-table-cell" style="text-align: right">Đơn giá</th>
                <th class="ant-table-cell" style="text-align: right">SL</th>
                <th class="ant-table-cell max-sm:hidden" style="text-align: right">VAT</th>
                <th class="ant-table-cell" style="text-align: right">Thành tiền</th>
              </tr>
            </thead>
            <tbody class="ant-table-tbody">
              @foreach ($order->items as $item)
                <tr class="ant-table-row">
                  <td class="ant-table-cell">
                    @if ($item->product && ! $item->product->trashed() && auth()->user()->can('product/edit'))
                      <a href="{{ route('backend.products.edit', $item->product) }}">{{ $item->product_name }}</a>
                    @else
                      {{ $item->product_name }}
                    @endif
                    @if ($item->sku)<div class="text-xs font-mono {{ $muted }}">{{ $item->sku }}</div>@endif
                  </td>
                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($item->unit_price) }} đ</td>
                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ $item->qty }}</td>
                  <td class="ant-table-cell tabular-nums max-sm:hidden" style="text-align: right">{{ Percent::format($item->vat_rate) }}% · {{ Money::format($item->vat_amount) }} đ</td>
                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($item->line_amount) }} đ</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div></div>
      </div>
      <div class="ant-card-body">
        <dl class="ml-auto grid max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 m-0 tabular-nums">
          <dt class="{{ $muted }}">Tiền hàng (gồm VAT)</dt><dd class="m-0 text-right">{{ Money::format($order->goods_amount) }} đ</dd>
          <dt class="{{ $muted }}">Trong đó VAT</dt><dd class="m-0 text-right">{{ Money::format($order->vat_amount) }} đ</dd>
          <dt class="{{ $muted }}">Phí giao hàng</dt><dd class="m-0 text-right">{{ Money::format($order->shipping_fee) }} đ</dd>
          <dt class="font-medium">Tổng thanh toán</dt><dd class="m-0 text-right text-base font-semibold">{{ Money::format($order->total_amount) }} đ</dd>
        </dl>
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="delivery">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Nhận hàng — {{ Order::DELIVERIES[$order->delivery_type] ?? $order->delivery_type }}</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Người nhận</dt><dd>{{ $order->recipient_name }}</dd>
          <dt>Số điện thoại</dt><dd class="tabular-nums">{{ $order->recipient_phone }}</dd>
          @if ($order->isPickup())
            <dt>Điểm nhận hàng</dt>
            <dd>
              @if ($order->pickupPoint)
                @if (! $order->pickupPoint->trashed() && auth()->user()->can('pickup_point/edit'))
                  <a href="{{ route('backend.pickup-points.edit', $order->pickupPoint) }}">{{ $order->pickupPoint->name }}</a>
                @else
                  {{ $order->pickupPoint->name }}
                @endif
                @if ($order->pickupPoint->trashed())<span class="ant-tag m-0 ml-1">Đã xóa</span>@endif
                <div class="text-xs {{ $muted }}">{{ $order->pickupPoint->address }}, {{ $order->pickupPoint->provinceName() }}</div>
              @else
                —
              @endif
            </dd>
          @else
            <dt>Địa chỉ giao</dt><dd>{{ $order->shippingAddress() }}</dd>
          @endif
          <dt>Ghi chú</dt><dd>{{ $order->note ?: '—' }}</dd>
          @if ($order->cancel_reason)
            <dt>Lý do hủy</dt><dd>{{ $order->cancel_reason }}</dd>
          @endif
        </dl>
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="history">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử</div></div></div>
      <div class="ant-card-body">
        @foreach ($timeline as $label => $time)
          <div @class(['flex flex-wrap gap-x-2 text-sm', 'mt-2' => ! $loop->first])><span class="tabular-nums {{ $muted }}">{{ $time->format('d/m/Y H:i') }}</span><span>{{ $label }}</span></div>
        @endforeach
        @foreach ($history as $log)
          <div class="mt-2 flex flex-wrap gap-x-2 text-sm">
            <span class="tabular-nums {{ $muted }}">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
            <span class="font-medium">{{ $log->actorName() }}</span>
            <span>{{ $log->description ?: $log->actionLabel() }}</span>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <div class="flex min-w-0 flex-col gap-4">
    <div class="ant-card ant-card-bordered" data-card="member">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thành viên đặt</div></div></div>
      <div class="ant-card-body">
        @if ($order->member)
          <div class="font-medium">
            @can('member')
              <a href="{{ route('backend.members.show', $order->member) }}">{{ $order->member->full_name }}</a>
            @else
              {{ $order->member->full_name }}
            @endcan
          </div>
          <div class="text-xs {{ $muted }}"><span class="tabular-nums">{{ $order->member->phone }}</span> · <span class="font-mono">{{ $order->member->referral_code }}</span> · {{ $order->member->level?->name }}</div>
        @endif
      </div>
    </div>

    @if ($next)
      @can('order/status')
        <div class="ant-card ant-card-bordered" data-card="status">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Cập nhật trạng thái</div></div></div>
          <div class="ant-card-body">
            <form method="POST" action="{{ route('backend.orders.status', $order) }}" id="status-form">
              @csrf
              <input type="hidden" name="to" value="{{ $next }}" />
              <p class="mt-0 mb-3 {{ $muted }}">{{ $next === Order::STATUS_PROCESSING ? 'Đã nhận tiền. Chuyển sang “Đang xử lý” khi bắt đầu soạn / giao hàng.' : 'Chuyển sang “Hoàn tất” khi khách đã nhận hàng.' }}</p>
              <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Chuyển sang “{{ Order::STATUSES[$next] }}”</span></button>
            </form>
          </div>
        </div>
      @endcan
    @endif

    @if ($order->isCancellable())
      @can('order/cancel')
        <div class="ant-card ant-card-bordered" data-card="cancel">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Hủy đơn</div></div></div>
          <div class="ant-card-body">
            <form method="POST" action="{{ route('backend.orders.cancel', $order) }}" class="ant-form ant-form-vertical" novalidate id="cancel-form">
              @csrf
              <p class="mt-0 mb-3 {{ $muted }}">Chỉ hủy được đơn chưa thanh toán. Lý do hiện cho thành viên ở trang đơn hàng. Không hoàn tác được.</p>
              <div class="ant-row ant-form-item{{ $hasError('reason') }}">
                <div class="ant-col ant-form-item-label"><label for="cancel_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="cancel_reason" name="reason" rows="2" maxlength="300" class="ant-input" placeholder="VD: Khách yêu cầu hủy qua hotline">{{ is_string(old('reason')) ? old('reason') : '' }}</textarea>
                  </div></div>
                  {{ ant_form_error($errors->first('reason')) }}
                </div>
              </div>
              <button type="submit" class="ant-btn ant-btn-dangerous">{{ ant_icon('close-circle') }}<span>Hủy đơn</span></button>
            </form>
          </div>
        </div>
      @endcan
    @endif
  </div>
</div>
@endsection
