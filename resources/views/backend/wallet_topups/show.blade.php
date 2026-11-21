@extends('layouts.backend')

{{-- Chi tiết lệnh nạp ví (dựng như chi tiết đơn): thông tin lệnh, thanh toán / hủy, lịch sử; cột phụ: thành viên, ảnh bill, xác
     nhận đã nhận tiền / chưa nhận được tiền, hủy lệnh (wallet_topup/confirm). Nhiều form: lỗi phân biệt bằng trường ẩn _form. --}}
@php
    use App\Models\WalletTopup;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $status = $topup->displayStatus();
    $canAct = auth()->user()->can('wallet_topup/confirm');
    $form = old('_form');
    $hasError = fn (string $name, string $field) => $form === $name && $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $fieldError = fn (string $name, string $field) => $form === $name ? ant_form_error($errors->first($field)) : '';
    $old = fn (string $name, string $field, $default = '') => $form === $name && is_string(old($field)) ? old($field) : $default;
    $timeline = array_filter([
        'Tạo lệnh' => $topup->created_at, 'Hạn chuyển khoản' => $topup->status === WalletTopup::STATUS_NEW ? $topup->expires_at : null,
        'Thành viên báo đã chuyển khoản' => $topup->confirm_requested_at, 'Xác nhận, cộng ví' => $topup->paid_at, 'Hủy' => $topup->cancelled_at,
    ]);
@endphp

@section('title', 'Lệnh nạp '.$topup->code)
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.wallet-topups.index') }}" class="page-topbar-crumb" title="Về danh sách lệnh nạp">Nạp ví</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title font-mono">{{ $topup->code }}</h1>
    <span class="ant-tag ant-tag-{{ WalletTopup::STATUS_COLORS[$status] }} m-0" id="topup-status">{{ $topup->statusLabel() }}</span>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-3">
  <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
    <div class="ant-card ant-card-bordered" data-card="topup">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lệnh nạp ví tái tiêu dùng</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Số tiền trên lệnh</dt><dd class="text-base font-semibold tabular-nums">{{ Money::format($topup->amount) }} đ</dd>
          <dt>Nội dung chuyển khoản</dt><dd class="font-mono">{{ $topup->code }}</dd>
          @if ($topup->paid_at)
            <dt>Mã giao dịch</dt><dd class="font-mono">{{ $topup->payment_reference }}</dd>
            <dt>Số tiền thực nhận</dt>
            <dd class="tabular-nums" data-paid-amount>{{ Money::format($topup->paid_amount) }} đ
              @if ($topup->paid_amount > $topup->amount)<span class="ant-tag ant-tag-gold m-0 ml-1">Thừa {{ Money::format($topup->paid_amount - $topup->amount) }} đ</span>@endif
            </dd>
            <dt>Đã cộng ví</dt><dd class="tabular-nums">+{{ Money::format($topup->walletTransaction?->amount ?? 0) }} đ · số dư sau: {{ Money::format($topup->walletTransaction?->balance_after ?? 0) }} đ</dd>
            <dt>Xác nhận</dt><dd>{{ $topup->paid_at->format('d/m/Y H:i') }} · {{ $topup->confirmer?->name ?? '—' }}</dd>
            @if ($topup->payment_note)<dt>Ghi chú</dt><dd>{{ $topup->payment_note }}</dd>@endif
          @elseif ($status === WalletTopup::STATUS_CANCELLED)
            <dt>Nguồn hủy</dt><dd>{{ WalletTopup::CANCEL_SOURCES[$topup->isOverdue() ? WalletTopup::CANCEL_EXPIRED : $topup->cancel_source] ?? '—' }}</dd>
            <dt>Lý do</dt><dd>{{ $topup->cancelReasonText() ?: '—' }}</dd>
          @endif
        </dl>
        <p class="m-0 mt-3 text-xs {{ $muted }}">Tiền nạp chỉ cộng vào ví tái tiêu dùng, không tính doanh số / hoa hồng. Doanh số và hoa hồng tính khi thành viên dùng ví mua hàng.</p>
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
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thành viên</div></div></div>
      <div class="ant-card-body">
        @if ($topup->member)
          <div class="font-medium">
            @can('member')<a href="{{ route('backend.members.show', $topup->member) }}">{{ $topup->member->full_name }}</a>@else{{ $topup->member->full_name }}@endcan
          </div>
          <div class="text-xs {{ $muted }}"><span class="tabular-nums">{{ $topup->member->phone }}</span> · <span class="font-mono">{{ $topup->member->referral_code }}</span> · {{ $topup->member->level?->name }}</div>
          <div class="mt-2 text-sm">Ví tái tiêu dùng: <strong class="tabular-nums">{{ Money::format($topup->member->walletBalance(\App\Models\WalletTransaction::WALLET_RECONSUME)) }} đ</strong></div>
        @endif
      </div>
    </div>

    @if ($topup->hasBill())
      @php $billSrc = route('backend.wallet-topups.bill', $topup).'?v='.$topup->confirm_requested_at?->timestamp; @endphp
      <div class="ant-card ant-card-bordered" data-card="bill">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Ảnh bill chuyển khoản</div></div></div>
        <div class="ant-card-body">
          <a href="{{ $billSrc }}" target="_blank" rel="noopener" class="kyc-photo" style="aspect-ratio: 3 / 4; max-width: 280px" title="Mở ảnh cỡ đầy đủ ở tab mới"><img src="{{ $billSrc }}" alt="Ảnh bill lệnh {{ $topup->code }}" loading="lazy" /></a>
          <div class="mt-2 text-xs {{ $muted }}">Thành viên gửi lúc {{ $topup->confirm_requested_at?->format('H:i d/m/Y') }}. Ảnh do thành viên tự tải lên — vẫn phải đối chiếu với sao kê ngân hàng.</div>
        </div>
      </div>
    @endif

    @if ($topup->canConfirmPayment() && $canAct)
      <div class="ant-card ant-card-bordered" data-card="confirm-payment">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Xác nhận đã nhận tiền</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.wallet-topups.confirm', $topup) }}" class="ant-form ant-form-vertical" novalidate id="confirm-topup-form">
            @csrf
            <input type="hidden" name="_form" value="confirm" />
            @if ($status === WalletTopup::STATUS_CANCELLED)
              <div class="ant-alert ant-alert-warning mb-3"><div class="ant-alert-content"><div class="ant-alert-message">Lệnh đã hủy. Chỉ xác nhận khi tiền về muộn — lệnh được khôi phục và cộng ví.</div></div></div>
            @else
              <p class="mt-0 mb-3 {{ $muted }}">Đối chiếu sao kê: số tiền {{ Money::format($topup->amount) }} đ, nội dung <span class="font-mono">{{ $topup->code }}</span>. Ví được cộng theo số tiền thực nhận.</p>
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
                  <input type="text" id="paid_amount" name="paid_amount" inputmode="numeric" value="{{ $old('confirm', 'paid_amount', Money::format($topup->amount)) }}" class="ant-input tabular-nums" />
                </div></div>
                {{ $fieldError('confirm', 'paid_amount') }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('confirm', 'payment_note') }}">
              <div class="ant-col ant-form-item-label"><label for="payment_note" @class(['ant-form-item-required' => $status === WalletTopup::STATUS_CANCELLED]) title="Ghi chú">Ghi chú</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="payment_note" name="payment_note" rows="2" maxlength="300" class="ant-input">{{ $old('confirm', 'payment_note') }}</textarea>
                </div></div>
                {{ $fieldError('confirm', 'payment_note') }}
              </div>
            </div>
            <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Xác nhận, cộng ví</span></button>
            <div class="mt-2 text-xs {{ $muted }}">Không hoàn tác được. Mỗi mã giao dịch chỉ dùng một lần (chung với đơn hàng).</div>
          </form>

          @if ($status === WalletTopup::STATUS_AWAITING)
            <form method="POST" action="{{ route('backend.wallet-topups.reject', $topup) }}" class="ant-form ant-form-vertical mt-4 border-t border-[#f0f0f0] pt-4" novalidate id="reject-topup-form">
              @csrf
              <input type="hidden" name="_form" value="reject" />
              <p class="mt-0 mb-3 {{ $muted }}">Chưa thấy tiền về / chuyển thiếu: trả lệnh về “Chờ chuyển khoản” để thành viên chuyển bù, hạn tính lại từ bây giờ.</p>
              <div class="ant-row ant-form-item{{ $hasError('reject', 'reason') }}">
                <div class="ant-col ant-form-item-label"><label for="reject_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="reject_reason" name="reason" rows="2" maxlength="300" class="ant-input" placeholder="VD: Mới nhận 80.000 đ, thiếu 20.000 đ">{{ $old('reject', 'reason') }}</textarea>
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

    @if ($topup->isCancellable() && $canAct)
      <div class="ant-card ant-card-bordered" data-card="cancel">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Hủy lệnh</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.wallet-topups.cancel', $topup) }}" class="ant-form ant-form-vertical" novalidate id="cancel-topup-form">
            @csrf
            <input type="hidden" name="_form" value="cancel" />
            <div class="ant-row ant-form-item{{ $hasError('cancel', 'reason') }}">
              <div class="ant-col ant-form-item-label"><label for="cancel_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="cancel_reason" name="reason" rows="2" maxlength="300" class="ant-input" placeholder="VD: Thành viên tạo nhầm lệnh">{{ $old('cancel', 'reason') }}</textarea>
                </div></div>
                {{ $fieldError('cancel', 'reason') }}
              </div>
            </div>
            <button type="submit" class="ant-btn ant-btn-dangerous">{{ ant_icon('close-circle') }}<span>Hủy lệnh</span></button>
          </form>
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
