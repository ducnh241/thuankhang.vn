{{-- Khung website cửa hàng: thanh liên hệ, đầu trang (tên / logo, ô tìm kiếm), thanh danh mục + menu chính, chân trang.
     Dữ liệu chung ($site, $mainMenu, $footerMenu, $shopCategories) do View::composer trong AppServiceProvider đưa vào.
     Trang con: @section('title'), @section('description'), (tuỳ chọn) @section('image'), @section('content'). --}}
@php
    use App\Support\SiteSettings;

    $siteName = $site['site_name'] ?: config('app.name');
    $logo = SiteSettings::imageUrl($site['site_logo'] ?? null);
    $favicon = SiteSettings::imageUrl($site['site_favicon'] ?? null);
    $title = trim($__env->yieldContent('title'));
    $description = trim($__env->yieldContent('description')) ?: ($site['seo_description'] ?: null);
    $image = trim($__env->yieldContent('image')) ?: $logo;
    $socials = collect(SiteSettings::SOCIALS)->filter(fn ($s, $key) => ($site['social_'.$key] ?? '') !== '');
    $q = is_string(request('q')) ? request('q') : '';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $title !== '' ? $title.' - '.$siteName : ($site['seo_title'] ?: $siteName) }}</title>
  @if ($description)<meta name="description" content="{{ \Illuminate\Support\Str::limit($description, 160) }}" />@endif
  @hasSection('robots')<meta name="robots" content="@yield('robots')" />@endif
  <link rel="canonical" href="{{ url()->current() }}" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="{{ $siteName }}" />
  <meta property="og:title" content="{{ $title !== '' ? $title : $siteName }}" />
  @if ($description)<meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 200) }}" />@endif
  @if ($image)<meta property="og:image" content="{{ $image }}" />@endif
  @if ($favicon)<link rel="icon" href="{{ $favicon }}" />@endif
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap" rel="stylesheet" />
  {{-- Build riêng của website (vite.frontend.config.js), không dùng CSS / JS admin. Đối tượng Vite riêng: không đổi
       hot file / thư mục build của Vite dùng chung (admin) --}}
  {{ (new \Illuminate\Foundation\Vite)->useHotFile(public_path('hot-frontend'))->useBuildDirectory('assets/frontend/build')->withEntryPoints(['resources/frontend/css/app.css', 'resources/frontend/js/app.js']) }}
  {!! $site['code_head'] !!}
</head>
<body class="flex min-h-screen flex-col">
  {!! $site['code_body'] !!}

  {{-- Thanh liên hệ --}}
  @if ($site['site_hotline'] || $site['site_email'])
    <div class="hidden bg-brand-900 text-xs text-brand-50 sm:block">
      <div class="mx-auto flex max-w-7xl items-center justify-end gap-6 px-4 py-2">
        @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="hover:text-white">Hotline: <strong>{{ $site['site_hotline'] }}</strong></a>@endif
        @if ($site['site_email'])<a href="mailto:{{ $site['site_email'] }}" class="hover:text-white">{{ $site['site_email'] }}</a>@endif
      </div>
    </div>
  @endif

  <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3">
      <button type="button" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden" data-nav-toggle aria-controls="site-nav" aria-expanded="false" aria-label="Mở menu">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
      </button>
      <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2" aria-label="{{ $siteName }} — Trang chủ">
        @if ($logo)
          <img src="{{ $logo }}" alt="{{ $siteName }}" class="h-9 w-auto" />
        @else
          <span class="flex size-9 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">{{ mb_strtoupper(mb_substr($siteName, 0, 1)) }}</span>
          <span class="text-lg font-bold text-slate-900">{{ $siteName }}</span>
        @endif
      </a>
      {{-- Tài khoản thành viên (guard "member") --}}
      <div class="ml-auto flex shrink-0 items-center gap-2 text-sm md:order-last md:ml-0">
        {{-- Giỏ hàng: số sản phẩm trong giỏ (session) --}}
        @php $cartCount = request()->hasSession() ? app(\App\Support\Cart::class)->count() : 0; @endphp
        <a href="{{ route('shop.cart') }}" class="relative rounded-full p-2 text-slate-600 hover:bg-slate-100 hover:text-brand-700" aria-label="Giỏ hàng{{ $cartCount ? " ($cartCount sản phẩm)" : '' }}" data-cart-link>
          <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L20 8H6.2" /><circle cx="9.5" cy="20" r="1.3" /><circle cx="17" cy="20" r="1.3" /></svg>
          @if ($cartCount)
            <span class="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[11px] font-bold text-white" data-cart-count>{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
          @endif
        </a>
        @if ($currentMember = auth('member')->user())
          <a href="{{ route('member.account') }}" class="flex items-center gap-2 rounded-full border border-slate-200 py-1.5 pr-4 pl-1.5 font-medium text-slate-700 hover:border-brand-500 hover:text-brand-700" data-member-menu>
            <span class="flex size-7 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{{ mb_strtoupper(mb_substr($currentMember->full_name, 0, 1)) }}</span>
            <span class="max-w-32 truncate max-sm:hidden">{{ $currentMember->full_name }}</span>
          </a>
        @else
          {{-- Điện thoại: một nút biểu tượng (trang đăng nhập có link sang đăng ký) --}}
          <a href="{{ route('member.login') }}" class="rounded-full p-2 text-slate-600 hover:bg-slate-100 sm:hidden" aria-label="Đăng nhập">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5" /></svg>
          </a>
          <a href="{{ route('member.login') }}" class="rounded-full px-3 py-2 font-medium text-slate-700 hover:text-brand-700 max-sm:hidden">Đăng nhập</a>
          <a href="{{ route('member.register') }}" class="rounded-full bg-brand-600 px-4 py-2 font-semibold text-white hover:bg-brand-700 max-sm:hidden">Đăng ký</a>
        @endif
      </div>
      {{-- Điện thoại: ô tìm xuống dòng riêng, đủ rộng --}}
      <form action="{{ route('shop.search') }}" method="GET" role="search" class="order-last w-full md:order-none md:ml-auto md:max-w-md">
        <label class="relative block">
          <span class="sr-only">Tìm sản phẩm</span>
          <svg class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-3.5-3.5" /></svg>
          <input type="search" name="q" value="{{ $q }}" placeholder="Tìm sản phẩm…" maxlength="100"
            class="w-full rounded-full border border-slate-200 bg-slate-50 py-2 pr-4 pl-10 text-sm outline-none transition focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-100" />
        </label>
      </form>
    </div>
    {{-- Thanh danh mục + menu chính; điện thoại: xổ xuống khi bấm nút menu --}}
    <nav id="site-nav" class="hidden border-t border-slate-100 md:block" aria-label="Danh mục">
      <ul class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-2 text-sm font-medium md:flex-row md:flex-wrap md:items-center md:gap-6 md:py-0">
        @foreach ($shopCategories as $category)
          <li><a href="{{ $category->url() }}" @class(['block py-2 md:py-3 hover:text-brand-700', 'text-brand-700' => request()->is(ltrim($category->url(), '/'))])>{{ $category->name }}</a></li>
        @endforeach
        {{-- Menu chính (chỉ mục cấp 1) đứng bên phải thanh danh mục --}}
        @foreach ($mainMenu as $item)
          @php [$href, $external] = \App\Models\Menu::safeLink($item['url']); @endphp
          <li @class(['md:ml-auto' => $loop->first]) data-menu-item>
            <a href="{{ $href }}" class="block py-2 text-slate-500 hover:text-brand-700 md:py-3"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a>
          </li>
        @endforeach
      </ul>
    </nav>
  </header>

  <main class="flex-1">
    @include('frontend.partials.flash')
    @yield('content')
  </main>

  <footer class="mt-16 border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4">
      <div class="lg:col-span-2">
        <div class="text-lg font-bold text-slate-900">{{ $siteName }}</div>
        @if ($site['site_address'])<p class="mt-2 text-sm text-slate-600">{{ $site['site_address'] }}</p>@endif
        @if ($site['site_hotline'])<p class="mt-1 text-sm text-slate-600">Hotline: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-medium text-brand-700">{{ $site['site_hotline'] }}</a></p>@endif
        @if ($site['site_email'])<p class="mt-1 text-sm text-slate-600">Email: <a href="mailto:{{ $site['site_email'] }}" class="text-brand-700">{{ $site['site_email'] }}</a></p>@endif
        @if ($socials->isNotEmpty())
          <div class="mt-4 flex flex-wrap gap-3 text-sm">
            @foreach ($socials as $key => [$label])
              <a href="{{ $site['social_'.$key] }}" target="_blank" rel="noopener" class="rounded-full border border-slate-200 px-3 py-1 text-slate-600 hover:border-brand-500 hover:text-brand-700">{{ $label }}</a>
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <div class="text-sm font-semibold text-slate-900">Danh mục</div>
        <ul class="mt-3 space-y-2 text-sm text-slate-600">
          @foreach ($shopCategories as $category)
            <li><a href="{{ $category->url() }}" class="hover:text-brand-700">{{ $category->name }}</a></li>
          @endforeach
        </ul>
      </div>
      @if ($footerMenu)
        <div>
          <div class="text-sm font-semibold text-slate-900">Thông tin</div>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @foreach ($footerMenu as $item)
              @php [$href, $external] = \App\Models\Menu::safeLink($item['url']); @endphp
              <li><a href="{{ $href }}" class="hover:text-brand-700"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a></li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
    <div class="border-t border-slate-100 py-4 text-center text-xs text-slate-500">
      @if ($site['site_footer']){!! \App\Support\RichText::clean($site['site_footer']) !!}@else© {{ date('Y') }} {{ $siteName }}@endif
    </div>
  </footer>
  {!! $site['code_bottom'] !!}
</body>
</html>
