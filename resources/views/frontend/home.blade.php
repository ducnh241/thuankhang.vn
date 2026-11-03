@extends('frontend.layouts.app')

{{-- Trang chủ cửa hàng (S1): banner, danh mục, sản phẩm nổi bật, sản phẩm theo từng danh mục gốc. --}}
@section('content')
  {{-- Banner --}}
  <section class="bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-500 text-white">
    <div class="mx-auto grid max-w-7xl items-center gap-8 px-4 py-14 md:grid-cols-2 md:py-20">
      <div>
        <p class="text-sm font-semibold tracking-wider text-brand-100 uppercase">Chào mừng bạn đến với {{ $site['site_name'] ?: config('app.name') }}</p>
        <h1 class="mt-3 text-3xl leading-tight font-bold md:text-5xl">{{ $site['seo_title'] ?: 'Sản phẩm chất lượng cho sức khỏe và gia đình' }}</h1>
        <p class="mt-4 max-w-xl text-base text-brand-50/90">{{ $site['seo_description'] ?: 'Đặt hàng nhanh, thanh toán QR tiện lợi, giao tận nơi hoặc nhận tại văn phòng tỉnh gần bạn.' }}</p>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="#noi-bat" class="rounded-full bg-white px-6 py-3 text-sm font-semibold text-brand-800 shadow hover:bg-brand-50">Xem sản phẩm nổi bật</a>
          @if ($roots->isNotEmpty())
            <a href="{{ $roots->first()->url() }}" class="rounded-full border border-white/60 px-6 py-3 text-sm font-semibold text-white hover:bg-white/10">{{ $roots->first()->name }}</a>
          @endif
        </div>
      </div>
      @if ($featured->isNotEmpty())
        <div class="hidden grid-cols-2 gap-4 md:grid">
          @foreach ($featured->take(4) as $product)
            <a href="{{ $product->url() }}" @class(['overflow-hidden rounded-2xl bg-white/10 shadow-xl ring-1 ring-white/20', 'translate-y-6' => $loop->odd])>
              @if ($product->cover)<img src="{{ $product->cover->url() }}" alt="{{ $product->name }}" class="aspect-square w-full object-cover" />@endif
            </a>
          @endforeach
        </div>
      @endif
    </div>
  </section>

  {{-- Danh mục --}}
  @if ($roots->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 pt-12">
      <h2 class="text-xl font-bold text-slate-900 md:text-2xl">Danh mục sản phẩm</h2>
      <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($roots as $category)
          <a href="{{ $category->url() }}" class="group rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-brand-500 hover:shadow-md">
            <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-lg font-bold text-brand-700 group-hover:bg-brand-600 group-hover:text-white">{{ mb_strtoupper(mb_substr($category->name, 0, 1)) }}</span>
            <span class="mt-3 block font-semibold text-slate-900 group-hover:text-brand-700">{{ $category->name }}</span>
            @if ($category->description)<span class="mt-1 line-clamp-2 block text-xs text-slate-500">{{ $category->description }}</span>@endif
          </a>
        @endforeach
      </div>
    </section>
  @endif

  {{-- Nổi bật --}}
  @if ($featured->isNotEmpty())
    <section id="noi-bat" class="mx-auto max-w-7xl scroll-mt-32 px-4 pt-12">
      <h2 class="text-xl font-bold text-slate-900 md:text-2xl">Sản phẩm nổi bật</h2>
      <div class="mt-5 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        @foreach ($featured as $product)
          @include('frontend.partials.product-card')
        @endforeach
      </div>
    </section>
  @endif

  {{-- Theo danh mục --}}
  @foreach ($sections as $section)
    <section class="mx-auto max-w-7xl px-4 pt-12" data-section="{{ $section['category']->slug }}">
      <div class="flex items-end justify-between gap-4">
        <h2 class="text-xl font-bold text-slate-900 md:text-2xl">{{ $section['category']->name }}</h2>
        <a href="{{ $section['category']->url() }}" class="shrink-0 text-sm font-medium text-brand-700 hover:underline">Xem tất cả ›</a>
      </div>
      <div class="mt-5 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        @foreach ($section['products'] as $product)
          @include('frontend.partials.product-card')
        @endforeach
      </div>
    </section>
  @endforeach

  @if ($featured->isEmpty() && $sections->isEmpty())
    <section class="mx-auto max-w-7xl px-4 py-20 text-center text-slate-500">Cửa hàng đang cập nhật sản phẩm, mời bạn quay lại sau.</section>
  @endif
@endsection
