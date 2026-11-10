@extends('frontend.layouts.app')

{{-- Trang chủ cửa hàng (S1), theo luồng Shoplife: thẻ thành viên / lời mời đăng ký → nổi bật (hàng cuộn ngang) → link danh
     mục gốc + số sản phẩm + sắp xếp → lưới sản phẩm (lượt bán, nút "+" thêm nhanh vào giỏ). --}}
@php use App\Support\Money; @endphp

@section('content')
  <div class="mx-auto max-w-7xl px-4 pt-5 md:pt-8">
    {{-- Thẻ thành viên (đã đăng nhập) / lời mời tham gia --}}
    @if ($member)
      <section class="rounded-2xl bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-500 p-5 text-white shadow-sm md:p-6" data-member-card>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-4">
          <div class="flex min-w-0 items-center gap-3">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white/20 text-lg font-bold">{{ mb_strtoupper(mb_substr($member->full_name, 0, 1)) }}</span>
            <div class="min-w-0">
              <div class="truncate font-semibold">Xin chào, {{ $member->full_name }}</div>
              <div class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-brand-50">
                <span class="rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-semibold tracking-wide uppercase">{{ $member->level?->name }}</span>
                @unless ($member->isActivated())<span class="text-xs">Mua đơn đầu tiên để kích hoạt và nhận hoa hồng</span>@endunless
              </div>
            </div>
          </div>
          <div class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-2 text-sm max-md:justify-start">
            <span class="text-brand-50">Mã giới thiệu <strong class="font-mono text-white">{{ $member->referral_code }}</strong></span>
            <button type="button" class="rounded-full bg-white px-4 py-1.5 font-semibold text-brand-800 hover:bg-brand-50" data-copy="{{ $member->referralLink() }}">Sao chép link mời</button>
            <a href="{{ route('member.account') }}" class="rounded-full border border-white/60 px-4 py-1.5 font-semibold hover:bg-white/10">Đội nhóm</a>
          </div>
        </div>
        @if ($pendingOrders)
          <a href="{{ route('member.orders.index') }}" class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-white/15 px-4 py-2.5 text-sm hover:bg-white/20" data-pending-orders>
            <span>Bạn có <strong>{{ $pendingOrders }}</strong> đơn chờ thanh toán</span><span aria-hidden="true">›</span>
          </a>
        @endif
      </section>
    @else
      <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-500 p-5 text-white shadow-sm md:p-7" data-join-card>
        <div class="max-w-2xl">
          <h1 class="text-xl leading-snug font-bold md:text-3xl">{{ $site['seo_title'] ?: 'Mua sắm thông minh, giới thiệu bạn bè nhận hoa hồng' }}</h1>
          <p class="mt-2 text-sm text-brand-50/90 md:text-base">{{ $site['seo_description'] ?: 'Thanh toán QR tiện lợi, giao tận nơi hoặc nhận tại văn phòng tỉnh. Mời bạn bè mua hàng để nhận hoa hồng 2 tầng.' }}</p>
        </div>
        <div class="flex gap-3">
          <a href="{{ route('member.register') }}" class="rounded-full bg-white px-6 py-2.5 text-sm font-semibold text-brand-800 shadow hover:bg-brand-50">Đăng ký</a>
          <a href="{{ route('member.login') }}" class="rounded-full border border-white/60 px-6 py-2.5 text-sm font-semibold hover:bg-white/10">Đăng nhập</a>
        </div>
      </section>
    @endif

    {{-- Nổi bật: hàng cuộn ngang --}}
    @if ($featured->isNotEmpty())
      <section class="pt-8" id="noi-bat">
        <h2 class="text-lg font-bold text-slate-900 md:text-xl">Sản phẩm nổi bật</h2>
        <div class="-mx-4 mt-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none] md:gap-4" data-featured>
          @foreach ($featured as $product)
            <div class="w-40 shrink-0 snap-start sm:w-48 lg:w-56">@include('frontend.partials.product-card')</div>
          @endforeach
        </div>
      </section>
    @endif

    {{-- Tab nhóm sản phẩm + lưới --}}
    <section class="scroll-mt-32 pt-8" id="san-pham">
      {{-- Danh mục gốc: link sang trang danh mục; "Tất cả" = toàn bộ sản phẩm ngay tại trang chủ --}}
      <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" aria-label="Danh mục sản phẩm" data-group-tabs>
        <a href="{{ route('home') }}#san-pham" aria-current="page" class="shrink-0 rounded-full border border-brand-600 bg-brand-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white">Tất cả</a>
        @foreach ($roots as $category)
          <a href="{{ $category->url() }}" data-group="{{ $category->slug }}" class="shrink-0 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium whitespace-nowrap text-slate-700 transition hover:border-brand-500 hover:text-brand-700">{{ $category->name }}</a>
        @endforeach
      </nav>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500" data-product-count><strong class="text-slate-900">{{ Money::format($products->total()) }}</strong> sản phẩm</p>
        @include('frontend.partials.sort')
      </div>

      @if ($products->isEmpty())
        <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">Cửa hàng đang cập nhật sản phẩm, mời bạn quay lại sau.</div>
      @else
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-4 xl:grid-cols-5">
          @foreach ($products as $product)
            @include('frontend.partials.product-card')
          @endforeach
        </div>
        {{ $products->links('frontend.partials.pagination') }}
      @endif
    </section>
  </div>
@endsection
