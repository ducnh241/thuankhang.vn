@extends('frontend.layouts.app')

{{-- Lịch sử giao dịch ví: mọi bút toán (hoa hồng, mua hàng, nạp, chuyển đi / nhận, chuyển giữa hai ví), lọc theo ví ?vi=. --}}
@php
    use App\Models\WalletTransaction;
    use App\Support\Money;

    $tabs = ['' => 'Tất cả', ...WalletTransaction::WALLETS];
@endphp
@section('title', 'Giao dịch ví')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-3xl px-4 pt-8">
    <a href="{{ route('member.wallet') }}" class="text-sm text-brand-700 hover:underline">‹ Ví</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Giao dịch ví</h1>

    <div class="mt-4 grid grid-cols-2 gap-3">
      <div class="rounded-2xl border border-brand-100 bg-brand-50 p-4">
        <div class="text-xs font-medium tracking-wide text-brand-800 uppercase">Ví rút tiền</div>
        <div class="mt-1 text-xl font-bold text-brand-800 tabular-nums" data-wallet-balance>{{ Money::format($balances[WalletTransaction::WALLET_CASH]) }}đ</div>
      </div>
      <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4">
        <div class="text-xs font-medium tracking-wide text-amber-800 uppercase">Ví tái tiêu dùng</div>
        <div class="mt-1 text-xl font-bold text-amber-800 tabular-nums" data-wallet-reconsume>{{ Money::format($balances[WalletTransaction::WALLET_RECONSUME]) }}đ</div>
      </div>
    </div>

    <nav class="mt-5 flex flex-wrap gap-1.5" aria-label="Chọn ví">
      @foreach ($tabs as $key => $label)
        <a href="{{ route('member.wallet.history', $key === '' ? [] : ['vi' => $key]) }}"
           @class(['rounded-full border px-3 py-1 text-sm', 'border-brand-600 bg-brand-600 font-medium text-white' => (string) $wallet === $key, 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' => (string) $wallet !== $key])
           @if ((string) $wallet === $key) aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>

    @if ($rows->isEmpty())
      <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">Chưa có giao dịch nào.</div>
    @else
      <div class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-wallet-history>
        @foreach ($rows as $row)
          <div class="flex items-start gap-x-4 p-4" data-wallet-row="{{ $row->type }}">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-x-2">
                <span class="font-medium text-slate-900">{{ $row->typeLabel() }}</span>
                <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-brand-50 text-brand-800' => $row->wallet === WalletTransaction::WALLET_CASH, 'bg-amber-50 text-amber-800' => $row->wallet !== WalletTransaction::WALLET_CASH])>{{ WalletTransaction::WALLETS[$row->wallet] ?? $row->wallet }}</span>
              </div>
              <div class="mt-1 text-xs text-slate-500 tabular-nums">
                {{ $row->created_at?->format('d/m/Y H:i') }}
                @if ($row->order) · đơn <a href="{{ route('member.orders.show', $row->order) }}" class="font-mono text-brand-700 hover:underline">{{ $row->order->code }}</a>@endif
                @if ($row->topup) · lệnh <a href="{{ route('member.topups.show', $row->topup) }}" class="font-mono text-brand-700 hover:underline">{{ $row->topup->code }}</a>@endif
                @if ($row->counterpart) · {{ $row->amount < 0 ? 'tới' : 'từ' }} {{ $row->counterpart->shortName() }} <span class="font-mono">({{ $row->counterpart->referral_code }})</span>@endif
              </div>
            </div>
            <div class="text-right">
              <div @class(['font-semibold whitespace-nowrap tabular-nums', 'text-brand-700' => $row->amount >= 0, 'text-rose-700' => $row->amount < 0]) data-row-amount>{{ $row->amount >= 0 ? '+' : '−' }}{{ Money::format(abs($row->amount)) }}đ</div>
              <div class="text-xs text-slate-500 tabular-nums">Số dư {{ Money::format($row->balance_after) }}đ</div>
            </div>
          </div>
        @endforeach
      </div>
      {{ $rows->links('frontend.partials.pagination') }}
    @endif
  </div>
@endsection
