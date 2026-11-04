@extends('layouts.backend')

{{-- Chi tiết đơn (A8): sản phẩm (snapshot lúc đặt) + tiền, thanh toán, nhận hàng, lịch sử; cột phụ: thành viên đặt, xác nhận
     đã thanh toán / chưa nhận được tiền (order/confirm_payment), chuyển Đang giao → Hoàn tất (order/status), hủy đơn chưa
     thanh toán (order/cancel, bắt buộc lý do). Nhiều form trên trang: lỗi phân biệt bằng trường ẩn _form. --}}
@php
    use App\Models\Order;
    use App\Support\Money;
    use App\Support\Percent;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $status = $order->displayStatus();
    $next = $order->nextStatus();
    $me = auth()->user();
    $form = old('_form');
    $hasError = fn (string $name, string $field) => $form === $name && $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $fieldError = fn (string $name, string $field) => $form === $name ? ant_form_error($errors->first($field)) : '';
    $old = fn (string $name, string $field, $default = '') => $form === $name && is_string(old($field)) ? old($field) : $default;
    $timeline = array_filter([
        'Đặt hàng' => $order->created_at, 'Hạn thanh toán' => $order->status === Order::STATUS_NEW ? $order->expires_at : null,
        'Khách báo đã chuyển khoản' => $order->confirm_requested_at, 'Xác nhận thanh toán' => $order->paid_at,
        $order->isPickup() ? 'Hàng về VP, chờ khách nhận' : 'Bắt đầu giao' => $order->processing_at, 'Hoàn tất' => $order->completed_at,
        'Hủy' => $order->cancelled_at,
    ]);
@endphp

@section('title', 'Đơn '.$order->code)
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.orders.index') }}" class="page-topbar-crumb" title="Về danh sách đơn hàng">Đơn hàng</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title font-mono">{{ $order->code }}</h1>
    <span class="ant-tag ant-tag-{{ Order::STATUS_COLORS[$status] }} m-0" id="order-status">{{ $order->statusLabel() }}</span>
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

    @if ($order->paid_at || $order->status === Order::STATUS_CANCELLED || $order->isOverdue())
      <div class="ant-card ant-card-bordered" data-card="payment">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">{{ $order->paid_at ? 'Thanh toán' : 'Hủy đơn' }}</div></div></div>
        <div class="ant-card-body">
          <dl class="profile-facts m-0">
            @if ($order->paid_at)
              <dt>Mã giao dịch</dt><dd class="font-mono">{{ $order->payment_reference ?? '—' }}</dd>
              <dt>Số tiền thực nhận</dt>
              <dd class="tabular-nums">{{ $order->paid_amount !== null ? Money::format($order->paid_amount).' đ' : '—' }}
                @if ($order->paid_amount > $order->total_amount)<span class="ant-tag ant-tag-gold m-0 ml-1">Thừa {{ Money::format($order->paid_amount - $order->total_amount) }} đ</span>@endif
              </dd>
              <dt>Xác nhận</dt><dd>{{ $order->paid_at->format('d/m/Y H:i') }} · {{ $order->confirmer?->name ?? 'Hệ thống' }}</dd>
              @if ($order->payment_note)<dt>Ghi chú</dt><dd>{{ $order->payment_note }}</dd>@endif
            @else
              <dt>Nguồn hủy</dt><dd>{{ Order::CANCEL_SOURCES[$order->isOverdue() ? Order::CANCEL_EXPIRED : $order->cancel_source] ?? '—' }}</dd>
              <dt>Lý do</dt><dd>{{ $order->cancelReasonText() ?: '—' }}</dd>
            @endif
          </dl>
        </div>
      </div>
    @endif

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

    {{-- Xác nhận thanh toán (Kế toán): đối chiếu sao kê rồi nhập mã giao dịch + số tiền thực nhận --}}
    @if ($order->canConfirmPayment() && $me->can('order/confirm_payment'))
      <div class="ant-card ant-card-bordered" data-card="confirm-payment">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Xác nhận thanh toán</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.orders.confirm-payment', $order) }}" class="ant-form ant-form-vertical" novalidate id="confirm-payment-form">
            @csrf
            <input type="hidden" name="_form" value="confirm" />
            @if ($status === Order::STATUS_CANCELLED)
              <div class="ant-alert ant-alert-warning mb-3"><div class="ant-alert-content"><div class="ant-alert-message">Đơn đã hủy. Chỉ xác nhận khi tiền về muộn — đơn sẽ được khôi phục thành “Đã thanh toán”.</div></div></div>
            @elseif ($status === Order::STATUS_AWAITING)
              <p class="mt-0 mb-3 {{ $muted }}">Khách báo đã chuyển lúc {{ $order->confirm_requested_at?->format('H:i d/m/Y') }}. Đối chiếu sao kê: số tiền {{ Money::format($order->total_amount) }} đ, nội dung <span class="font-mono">{{ $order->code }}</span>.</p>
            @else
              <p class="mt-0 mb-3 {{ $muted }}">Đối chiếu sao kê: số tiền {{ Money::format($order->total_amount) }} đ, nội dung <span class="font-mono">{{ $order->code }}</span>.</p>
            @endif
            <div class="ant-row ant-form-item{{ $hasError('confirm', 'payment_reference') }}">
              <div class="ant-col ant-form-item-label"><label for="payment_reference" class="ant-form-item-required" title="Mã giao dịch">Mã giao dịch ngân hàng</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="payment_reference" name="payment_reference" value="{{ $old('confirm', 'payment_reference') }}" maxlength="100" class="ant-input [&:not(:placeholder-shown)]:uppercase" placeholder="VD: FT24315ABC123" />
                </div></div>
                {{ $fieldError('confirm', 'payment_reference') }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('confirm', 'paid_amount') }}">
              <div class="ant-col ant-form-item-label"><label for="paid_amount" class="ant-form-item-required" title="Số tiền thực nhận">Số tiền thực nhận (đ)</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="paid_amount" name="paid_amount" inputmode="numeric" value="{{ $old('confirm', 'paid_amount', Money::format($order->total_amount)) }}" class="ant-input tabular-nums" />
                </div></div>
                {{ $fieldError('confirm', 'paid_amount') }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('confirm', 'payment_note') }}">
              <div class="ant-col ant-form-item-label"><label for="payment_note" @class(['ant-form-item-required' => $status === Order::STATUS_CANCELLED]) title="Ghi chú">Ghi chú</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="payment_note" name="payment_note" rows="2" maxlength="300" class="ant-input">{{ $old('confirm', 'payment_note') }}</textarea>
                </div></div>
                {{ $fieldError('confirm', 'payment_note') }}
              </div>
            </div>
            <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Xác nhận đã thanh toán</span></button>
            <div class="mt-2 text-xs {{ $muted }}">Không hoàn tác được. Mỗi mã giao dịch chỉ dùng cho một đơn.</div>
          </form>

          @if ($status === Order::STATUS_AWAITING)
            <form method="POST" action="{{ route('backend.orders.reject-payment', $order) }}" class="ant-form ant-form-vertical mt-4 border-t border-[#f0f0f0] pt-4" novalidate id="reject-payment-form">
              @csrf
              <input type="hidden" name="_form" value="reject" />
              <p class="mt-0 mb-3 {{ $muted }}">Chưa thấy tiền về: trả đơn về “Đơn mới”, hạn thanh toán tính lại từ bây giờ.</p>
              <div class="ant-row ant-form-item{{ $hasError('reject', 'reason') }}">
                <div class="ant-col ant-form-item-label"><label for="reject_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="reject_reason" name="reason" rows="2" maxlength="300" class="ant-input" placeholder="VD: Sao kê đến 15:00 chưa có giao dịch">{{ $old('reject', 'reason') }}</textarea>
                  </div></div>
                  {{ $fieldError('reject', 'reason') }}
                </div>
              </div>
              <button type="submit" class="ant-btn">{{ ant_icon('reload') }}<span>Chưa nhận được tiền</span></button>
            </form>
          @endif
        </div>
      </div>
    @endif

    @if ($next && $me->can('order/status'))
      <div class="ant-card ant-card-bordered" data-card="status">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Cập nhật trạng thái</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.orders.status', $order) }}" id="status-form">
            @csrf
            <input type="hidden" name="to" value="{{ $next }}" />
            <p class="mt-0 mb-3 {{ $muted }}">
              @if ($next === Order::STATUS_PROCESSING)
                {{ $order->isPickup() ? 'Hàng đã chuyển tới VP: chuyển sang “Chờ nhận tại VP”.' : 'Bắt đầu soạn / giao hàng: chuyển sang “Đang giao”.' }}
              @else
                Khách đã nhận hàng: chuyển sang “Hoàn tất”.
              @endif
            </p>
            <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Chuyển sang “{{ Order::labelFor($next, $order->delivery_type) }}”</span></button>
          </form>
        </div>
      </div>
    @endif

    @if ($order->isCancellable() && $me->can('order/cancel'))
      <div class="ant-card ant-card-bordered" data-card="cancel">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Hủy đơn</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.orders.cancel', $order) }}" class="ant-form ant-form-vertical" novalidate id="cancel-form">
            @csrf
            <input type="hidden" name="_form" value="cancel" />
            <p class="mt-0 mb-3 {{ $muted }}">Chỉ hủy được đơn chưa thanh toán. Lý do hiện cho thành viên ở trang đơn hàng.</p>
            <div class="ant-row ant-form-item{{ $hasError('cancel', 'reason') }}">
              <div class="ant-col ant-form-item-label"><label for="cancel_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="cancel_reason" name="reason" rows="2" maxlength="300" class="ant-input" placeholder="VD: Khách yêu cầu hủy qua hotline">{{ $old('cancel', 'reason') }}</textarea>
                </div></div>
                {{ $fieldError('cancel', 'reason') }}
              </div>
            </div>
            <button type="submit" class="ant-btn ant-btn-dangerous">{{ ant_icon('close-circle') }}<span>Hủy đơn</span></button>
          </form>
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
