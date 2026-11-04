@extends('frontend.layouts.app')

{{-- Trang tài khoản thành viên (Step 3): thông tin, cấp, link / mã giới thiệu, người giới thiệu, số F1. Bảng điều khiển
     affiliate đầy đủ (S8: tiến độ lên cấp, F1 / F2, doanh số, hoa hồng) làm ở Step 10. --}}
@section('title', 'Tài khoản')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Xin chào, {{ $member->full_name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Thành viên từ {{ $member->created_at->format('d/m/Y') }}</p>
      </div>
      <form method="POST" action="{{ route('member.logout') }}">
        @csrf
        <button type="submit" class="rounded-full border border-slate-300 px-5 py-2 text-sm font-medium text-slate-700 hover:border-rose-400 hover:text-rose-600">Đăng xuất</button>
      </form>
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Cấp hiện tại</div>
        <div class="mt-2 text-xl font-bold text-brand-700" data-member-level>{{ $member->level?->name }}</div>
        @unless ($member->isActivated())
          <p class="mt-1 text-xs text-slate-500">Mua đơn hàng đầu tiên để kích hoạt tài khoản và bắt đầu nhận hoa hồng.</p>
        @endunless
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Người bạn đã giới thiệu (F1)</div>
        <div class="mt-2 text-xl font-bold text-slate-900">{{ number_format($f1Count, 0, ',', '.') }}</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Người giới thiệu bạn</div>
        <div class="mt-2 text-xl font-bold text-slate-900">{{ $member->referrer?->shortName() ?? '—' }}</div>
      </div>
    </div>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 class="text-lg font-bold text-slate-900">Giới thiệu bạn bè</h2>
      <p class="mt-1 text-sm text-slate-600">Gửi link hoặc mã dưới đây. Người đăng ký qua link / mã của bạn sẽ là F1 của bạn.</p>
      <div class="mt-4 grid gap-4 md:grid-cols-[200px_minmax(0,1fr)]">
        <div>
          <div class="text-xs text-slate-500">Mã giới thiệu</div>
          <div class="mt-1 flex items-center gap-2">
            <span class="rounded-lg bg-brand-50 px-3 py-2 font-mono text-lg font-bold tracking-wider text-brand-800" data-referral-code>{{ $member->referral_code }}</span>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:border-brand-500 hover:text-brand-700" data-copy="{{ $member->referral_code }}">Sao chép</button>
          </div>
        </div>
        <div class="min-w-0">
          <div class="text-xs text-slate-500">Link giới thiệu</div>
          <div class="mt-1 flex items-center gap-2">
            <input type="text" readonly value="{{ $member->referralLink() }}" class="w-full min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700" aria-label="Link giới thiệu" />
            <button type="button" class="shrink-0 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700" data-copy="{{ $member->referralLink() }}">Sao chép</button>
          </div>
        </div>
      </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 class="text-lg font-bold text-slate-900">Thông tin tài khoản</h2>
      <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[160px_minmax(0,1fr)]">
        <dt class="text-slate-500">Họ và tên</dt><dd class="font-medium text-slate-900">{{ $member->full_name }}</dd>
        <dt class="text-slate-500">Số điện thoại</dt><dd class="font-medium text-slate-900">{{ $member->phone }}</dd>
        <dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ $member->email ?: '—' }}</dd>
      </dl>
    </section>
  </div>
@endsection
