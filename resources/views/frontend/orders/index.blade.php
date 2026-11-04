@extends('frontend.layouts.app')

{{-- Đơn hàng của tôi (S7). --}}
@php use App\Models\Order; use App\Support\Money; @endphp
@section('title', 'Đơn hàng của tôi')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold text-slate-900">Đơn hàng của tôi</h1>
      <a href="{{ route('member.account') }}" class="text-sm text-brand-700 hover:underline">‹ Tài khoản</a>
    </div>
    @if ($orders->isEmpty())
      <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">Bạn chưa có đơn hàng nào.</div>
    @else
      <div class="mt-6 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
        @foreach ($orders as $order)
          <a href="{{ route('member.orders.show', $order) }}" class="flex flex-wrap items-center gap-x-6 gap-y-2 p-4 hover:bg-slate-50" data-order="{{ $order->code }}">
            <div class="min-w-36">
              <div class="font-mono font-semibold text-slate-900">{{ $order->code }}</div>
              <div class="text-xs text-slate-500">{{ $order->created_at->format('d/m/Y H:i') }} · {{ $order->items_count }} sản phẩm</div>
            </div>
            <div class="text-sm text-slate-600">{{ Order::DELIVERIES[$order->delivery_type] }}</div>
            <div class="ml-auto font-semibold text-slate-900">{{ Money::format($order->total_amount) }}đ</div>
            @include('frontend.orders.status', ['order' => $order])
          </a>
        @endforeach
      </div>
      {{ $orders->links('frontend.partials.pagination') }}
    @endif
  </div>
@endsection
