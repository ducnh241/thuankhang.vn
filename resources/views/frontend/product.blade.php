@extends('frontend.layouts.app')

{{-- Chi tiết sản phẩm (S2, kiểu Shoplife): ảnh (bấm ảnh nhỏ đổi ảnh chính), tên, mã / danh mục / lượt bán, giá đã gồm VAT,
     mô tả ngắn, chọn biến thể (nếu có — giá / tồn theo biến thể), chọn số lượng (−/+) + Thêm vào giỏ / Mua ngay, Chat Zalo / gọi hotline, giới thiệu sản phẩm (thành viên đã
     kích hoạt: link ?ref=, chưa kích hoạt / khách: lời nhắc), mô tả chi tiết (đã lọc HTML), sản phẩm cùng danh mục.
     Điện thoại: thanh mua hàng cố định phía dưới (nút gắn vào form chính qua form="buy-form"). --}}
@php
    use App\Support\Money;
    use App\Support\Percent;
    use App\Support\RichText;

    $images = $product->images;
    // Ảnh chính: ảnh của biến thể đang chọn sẵn (nếu có), không thì ảnh đại diện
    $preselected = $product->activeVariants->first(fn ($v) => ! $v->isOutOfStock()) ?? $product->activeVariants->first();
    $mainImage = ($preselected?->image_id ? $images->firstWhere('id', $preselected->image_id) : null) ?? $images->first();
    $cover = $images->first()?->url();
    $hotline = $site['site_hotline'] ?? '';
    $tel = preg_replace('/[^0-9+]/', '', $hotline);
    [$zalo, $zaloExternal] = \App\Models\Menu::safeLink($site['social_zalo'] ?? '');
    $zalo = $zaloExternal ? $zalo : null; // chỉ nhận link http(s)
    $description = RichText::clean($product->description);
    $sold = (int) ($product->sold_qty ?? 0);
    $soldOut = $product->isOutOfStock();
    // Biến thể: chọn sẵn biến thể đầu tiên còn hàng; giá / tồn / số lượng tối đa theo biến thể đang chọn (JS đổi khi chọn khác)
    $variants = $product->activeVariants;
    $selected = $variants->first(fn ($v) => ! $v->isOutOfStock()) ?? $variants->first();
    $stockOwner = $selected ?? $product;
    $maxQty = max(1, $stockOwner->maxOrderQty());
    $displayPrice = $selected ? $selected->effectivePrice($product) : $product->price;
    $stockText = fn ($owner) => ! $owner->tracksStock() ? '' : ($owner->stock > 0 ? 'Còn '.Money::format($owner->stock).' sản phẩm' : 'Hết hàng');
    $stars = fn (float $value) => str_repeat('★', (int) round($value)).str_repeat('☆', 5 - (int) round($value));
    $rating = old('rating', $myReview?->rating);
@endphp

@section('title', $product->meta_title ?: $product->name)
@section('description', (string) ($product->meta_description ?: $product->short_description))
@section('image', (string) $cover)

@if ($preview)
  @section('robots', 'noindex, nofollow')
@endif

@section('content')
  @if ($preview)
    {{-- Xem trước: sản phẩm đang ẩn, người xem có quyền sản phẩm --}}
    <div class="border-b border-amber-200 bg-amber-50 text-sm text-amber-900" data-preview-banner>
      <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <span class="font-semibold">Đang xem trước</span>
        <span>Sản phẩm đang ẩn với khách (tắt "Đang bán" hoặc danh mục đang ẩn) — khách mở link này sẽ báo không tìm thấy; bạn xem được vì đang đăng nhập quản trị.</span>
        <span class="ml-auto flex gap-3">
          @if ($preview['editUrl'])<a href="{{ $preview['editUrl'] }}" class="font-medium underline underline-offset-2">Sửa sản phẩm</a>@endif
        </span>
      </div>
    </div>
  @endif
  <div class="mx-auto max-w-7xl px-4 pt-6">
    @include('frontend.partials.breadcrumb', ['trail' => $trail, 'current' => $product->name])

    <div class="mt-6 grid gap-8 lg:grid-cols-2">
      {{-- Ảnh --}}
      <div>
        <div class="aspect-square overflow-hidden rounded-2xl border border-slate-200 bg-white">
          @if ($cover)
            <img src="{{ $mainImage->url() }}" alt="{{ $product->name }}" class="size-full object-contain" data-gallery-main />
          @else
            <div class="flex size-full items-center justify-center text-slate-300">Chưa có ảnh</div>
          @endif
        </div>
        @if ($images->count() > 1)
          <div class="mt-3 grid grid-cols-5 gap-3">
            @foreach ($images as $image)
              <button type="button" class="overflow-hidden rounded-xl border-2 border-transparent bg-white aria-[current=true]:border-brand-500" data-gallery-thumb
                data-src="{{ $image->url() }}" data-alt="{{ $product->name }}" aria-current="{{ $image->id === $mainImage?->id ? 'true' : 'false' }}" aria-label="Xem ảnh {{ $loop->iteration }}">
                <img src="{{ $image->url() }}" alt="" loading="lazy" class="aspect-square w-full object-contain" />
              </button>
            @endforeach
          </div>
        @endif
      </div>

      {{-- Thông tin --}}
      <div>
        <h1 class="text-2xl leading-snug font-bold text-slate-900 md:text-3xl">{{ $product->name }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
          @if ($reviewStats['count'])
            <a href="#danh-gia" class="flex items-center gap-1 font-medium text-amber-600 hover:underline" data-rating-summary><span aria-hidden="true">★</span>{{ number_format($reviewStats['avg'], 1, ',', '') }} <span class="font-normal text-slate-500">({{ $reviewStats['count'] }} đánh giá)</span></a>
          @endif
          <span data-sold>{{ $sold > 0 ? 'Đã bán '.Money::format($sold) : 'Sản phẩm mới' }}</span>
          @if ($product->sku)<span>Mã: <span class="font-medium text-slate-700">{{ $product->sku }}</span></span>@endif
          @if ($product->category)<span>Danh mục: <a href="{{ $product->category->url() }}" class="font-medium text-brand-700 hover:underline">{{ $product->category->name }}</a></span>@endif
        </div>

        @if ($product->brand || $product->origin)
          <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm" data-brand-origin>
            @if ($product->brand)<div class="flex gap-1.5"><dt class="text-slate-500">Thương hiệu:</dt><dd class="font-medium text-slate-800">{{ $product->brand }}</dd></div>@endif
            @if ($product->origin)<div class="flex gap-1.5"><dt class="text-slate-500">Xuất xứ:</dt><dd class="font-medium text-slate-800">{{ $product->origin }}</dd></div>@endif
          </dl>
        @endif

        <div class="mt-5 rounded-2xl bg-brand-50 p-5">
          <div class="text-3xl font-bold text-brand-700" data-variant-price>{{ Money::format($displayPrice) }}đ</div>
          <div class="mt-1 text-xs text-slate-500">Giá đã gồm VAT {{ Percent::format($product->vat_rate) }}%, chưa gồm phí giao hàng</div>
        </div>

        @if ($product->short_description)
          <p class="mt-5 text-[15px] leading-7 text-slate-700">{{ $product->short_description }}</p>
        @endif

        {{-- Thêm vào giỏ / Mua ngay (buy_now=1 → sang thanh toán) --}}
        <form method="POST" action="{{ route('shop.cart.store') }}" class="mt-6" id="buy-form" data-buy-form>
          @csrf
          <input type="hidden" name="product_id" value="{{ $product->id }}" />
          @if ($variants->isNotEmpty())
            <fieldset class="mb-5" data-variants>
              <legend class="text-sm font-medium text-slate-700">{{ $product->variant_label ?: 'Phân loại' }}: <span class="font-semibold text-slate-900" data-variant-name>{{ $selected?->name }}</span></legend>
              <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($variants as $variant)
                  @php $out = $variant->isOutOfStock(); @endphp
                  <label @class(['relative cursor-pointer rounded-lg border px-3.5 py-2 text-sm transition has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:font-semibold has-[:checked]:text-brand-800 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-200', 'border-slate-300 hover:border-brand-500' => ! $out, 'cursor-not-allowed border-dashed border-slate-200 text-slate-400 line-through' => $out])>
                    <input type="radio" name="variant_id" value="{{ $variant->id }}" class="sr-only" @checked($selected?->id === $variant->id) @disabled($out)
                      data-image="{{ $variant->image_id ? $images->firstWhere('id', $variant->image_id)?->url() : '' }}"
                      data-price="{{ Money::format($variant->effectivePrice($product)) }}đ" data-name="{{ $variant->name }}" data-max="{{ max(1, $variant->maxOrderQty()) }}"
                      data-stock-text="{{ $stockText($variant) }}" data-stock-low="{{ $variant->tracksStock() && $variant->stock <= 5 ? '1' : '' }}" />
                    {{ $variant->name }}
                  </label>
                @endforeach
              </div>
            </fieldset>
          @endif
          <div class="flex flex-wrap items-center gap-4">
            <label for="buy-qty" class="text-sm font-medium text-slate-700">Số lượng</label>
            <div class="flex items-center rounded-full border border-slate-300 bg-white" data-qty>
              <button type="button" class="flex size-10 items-center justify-center rounded-l-full text-lg text-slate-600 hover:text-brand-700 disabled:text-slate-300" data-qty-step="-1" aria-label="Giảm số lượng">−</button>
              <input id="buy-qty" type="number" name="qty" value="1" min="1" max="{{ $maxQty }}" inputmode="numeric"
                class="w-12 [appearance:textfield] border-0 bg-transparent text-center text-sm font-semibold outline-none [&::-webkit-inner-spin-button]:appearance-none" />
              <button type="button" class="flex size-10 items-center justify-center rounded-r-full text-lg text-slate-600 hover:text-brand-700 disabled:text-slate-300" data-qty-step="1" aria-label="Tăng số lượng">+</button>
            </div>
            @if ($soldOut)
              <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">Hết hàng</span>
            @else
              <span @class(['text-sm', 'font-medium text-rose-600' => $stockOwner->tracksStock() && $stockOwner->stock <= 5, 'text-slate-500' => ! ($stockOwner->tracksStock() && $stockOwner->stock <= 5)]) data-stock>{{ $stockText($stockOwner) }}</span>
            @endif
          </div>
          <div class="mt-4 flex gap-3 max-md:hidden">
            <button type="submit" @disabled($soldOut) class="flex-1 rounded-full border-2 border-brand-600 px-6 py-3 text-sm font-semibold text-brand-700 hover:bg-brand-50 disabled:cursor-not-allowed disabled:border-slate-300 disabled:text-slate-400 disabled:hover:bg-transparent">Thêm vào giỏ</button>
            <button type="submit" name="buy_now" value="1" @disabled($soldOut) class="flex-1 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none">{{ $soldOut ? 'Hết hàng' : 'Mua ngay' }}</button>
          </div>
        </form>

        {{-- Tư vấn: Zalo (Cài đặt website → Mạng xã hội) / hotline --}}
        @if ($zalo || $hotline)
          <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
            <span class="text-slate-500">Cần tư vấn?</span>
            @if ($zalo)<a href="{{ $zalo }}" target="_blank" rel="noopener" class="rounded-full border border-sky-200 bg-sky-50 px-4 py-1.5 font-semibold text-sky-700 hover:border-sky-400" data-chat>Chat Zalo</a>@endif
            @if ($hotline)<a href="tel:{{ $tel }}" class="rounded-full border border-slate-200 px-4 py-1.5 font-semibold text-slate-700 hover:border-brand-500 hover:text-brand-700">Gọi {{ $hotline }}</a>@endif
          </div>
        @endif

        {{-- Giới thiệu sản phẩm --}}
        <div class="mt-6 rounded-2xl border border-dashed border-brand-300 bg-white p-4" data-share>
          @if ($shareLink)
            <div class="text-sm font-semibold text-slate-900">Giới thiệu sản phẩm này</div>
            <p class="mt-1 text-xs text-slate-500">Người mua đăng ký qua link của bạn sẽ là F1 của bạn — bạn nhận hoa hồng từ các đơn của họ.</p>
            <div class="mt-3 flex items-center gap-2">
              <input type="text" readonly value="{{ $shareLink }}" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600" aria-label="Link giới thiệu sản phẩm" onfocus="this.select()" />
              <button type="button" class="shrink-0 rounded-full bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700" data-copy="{{ $shareLink }}">Sao chép link giới thiệu</button>
            </div>
          @elseif ($member)
            <p class="text-sm text-slate-600">Mua đơn hàng đầu tiên để kích hoạt tài khoản, sau đó bạn có thể chia sẻ link sản phẩm và nhận hoa hồng khi bạn bè mua.</p>
          @else
            <p class="text-sm text-slate-600"><a href="{{ route('member.register') }}" class="font-semibold text-brand-700 hover:underline">Đăng ký thành viên</a> để chia sẻ sản phẩm và nhận hoa hồng khi bạn bè mua hàng.</p>
          @endif
        </div>

        <ul class="mt-6 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
          <li class="flex gap-2"><span class="text-brand-600">✓</span>Thanh toán chuyển khoản qua mã QR</li>
          <li class="flex gap-2"><span class="text-brand-600">✓</span>Giao tận nơi hoặc nhận tại VP tỉnh</li>
        </ul>
      </div>
    </div>

    @if ($description !== '')
      <section class="mt-12 rounded-2xl border border-slate-200 bg-white p-6 md:p-8">
        <h2 class="text-lg font-bold text-slate-900">Mô tả sản phẩm</h2>
        <div class="rich-text mt-4">{!! $description !!}</div>
      </section>
    @endif

    {{-- Đánh giá: tổng quan + phân bố sao, form (người đã mua), danh sách đánh giá đang hiện --}}
    <section class="mt-12 scroll-mt-32 rounded-2xl border border-slate-200 bg-white p-6 md:p-8" id="danh-gia">
      <h2 class="text-lg font-bold text-slate-900">Đánh giá sản phẩm</h2>
      <div class="mt-5 grid gap-8 md:grid-cols-[220px_minmax(0,1fr)]">
        <div>
          @if ($reviewStats['count'])
            <div class="text-4xl font-bold text-slate-900">{{ number_format($reviewStats['avg'], 1, ',', '') }}<span class="text-lg font-medium text-slate-400">/5</span></div>
            <div class="mt-1 text-lg tracking-wider text-amber-400" aria-label="{{ $reviewStats['avg'] }} trên 5 sao">{{ $stars($reviewStats['avg']) }}</div>
            <div class="text-sm text-slate-500">{{ $reviewStats['count'] }} đánh giá</div>
            <ul class="mt-4 space-y-1.5 text-xs text-slate-500">
              @foreach ($reviewStats['bars'] as $star => $total)
                <li class="flex items-center gap-2">
                  <span class="w-6 shrink-0">{{ $star }}★</span>
                  <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-amber-400" style="width: {{ round($total * 100 / $reviewStats['count']) }}%"></span></span>
                  <span class="w-6 shrink-0 text-right tabular-nums">{{ $total }}</span>
                </li>
              @endforeach
            </ul>
          @else
            <p class="text-sm text-slate-500">Chưa có đánh giá nào.</p>
          @endif
        </div>

        <div>
          @if ($canReview)
            <form method="POST" action="{{ route('shop.product.review', $product->slug) }}" class="rounded-xl bg-slate-50 p-4" data-review-form>
              @csrf
              <div class="text-sm font-semibold text-slate-900">{{ $myReview ? 'Sửa đánh giá của bạn' : 'Viết đánh giá' }}</div>
              @if ($myReview && ! $myReview->is_visible)
                <p class="mt-1 text-xs text-rose-600">Đánh giá của bạn đang bị ẩn bởi quản trị viên.</p>
              @endif
              <div class="star-input mt-2" role="radiogroup" aria-label="Số sao">
                @foreach ([5, 4, 3, 2, 1] as $star)
                  <input type="radio" id="rating-{{ $star }}" name="rating" value="{{ $star }}" @checked((string) $rating === (string) $star) />
                  <label for="rating-{{ $star }}" title="{{ $star }} sao">★</label>
                @endforeach
              </div>
              @error('rating')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
              <label for="review-comment" class="sr-only">Nhận xét</label>
              <textarea id="review-comment" name="comment" rows="3" maxlength="{{ \App\Models\ProductReview::MAX_COMMENT }}" placeholder="Chia sẻ cảm nhận của bạn về sản phẩm (không bắt buộc)"
                class="mt-3 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100">{{ old('comment', $myReview?->comment) }}</textarea>
              @error('comment')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
              <button type="submit" class="mt-3 rounded-full bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ $myReview ? 'Cập nhật đánh giá' : 'Gửi đánh giá' }}</button>
            </form>
          @elseif ($member)
            <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Bạn có thể đánh giá sau khi mua sản phẩm này (đơn đã thanh toán).</p>
          @else
            <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600"><a href="{{ route('member.login') }}" class="font-semibold text-brand-700 hover:underline">Đăng nhập</a> để đánh giá sản phẩm bạn đã mua.</p>
          @endif

          @if ($reviews->isNotEmpty())
            <ul class="mt-6 divide-y divide-slate-100">
              @foreach ($reviews as $review)
                <li class="py-4" data-review="{{ $review->id }}">
                  <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium text-slate-900">{{ $review->member?->shortName() ?? 'Thành viên' }}</span>
                    <span class="tracking-wider text-amber-400" aria-label="{{ $review->rating }} sao">{{ $stars($review->rating) }}</span>
                    <span class="text-xs text-slate-400">{{ $review->created_at->format('d/m/Y') }}</span>
                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700">Đã mua hàng</span>
                  </div>
                  @if ($review->comment)<p class="mt-1.5 text-sm leading-6 whitespace-pre-line text-slate-700">{{ $review->comment }}</p>@endif
                </li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    </section>

    @if ($related->isNotEmpty())
      <section class="mt-12">
        <h2 class="text-xl font-bold text-slate-900">Sản phẩm cùng danh mục</h2>
        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 md:gap-4 lg:grid-cols-4">
          @foreach ($related as $item)
            @include('frontend.partials.product-card', ['product' => $item])
          @endforeach
        </div>
      </section>
    @endif
  </div>

  {{-- Điện thoại: thanh mua hàng cố định ngay trên thanh điều hướng dưới --}}
  <div class="h-16 md:hidden" aria-hidden="true"></div>
  <div class="fixed inset-x-0 bottom-16 z-30 border-t border-slate-200 bg-white/95 px-4 py-2.5 backdrop-blur md:hidden" data-mobile-buy>
    <div class="flex items-center gap-2">
      <div class="min-w-0 flex-1"><div class="truncate text-xs text-slate-500">{{ $product->name }}</div><div class="font-bold text-brand-700" data-variant-price>{{ Money::format($displayPrice) }}đ</div></div>
      @if ($soldOut)
        <span class="rounded-full bg-slate-300 px-5 py-2.5 text-sm font-semibold text-white">Hết hàng</span>
      @else
        <button type="submit" form="buy-form" class="rounded-full border-2 border-brand-600 px-4 py-2 text-sm font-semibold text-brand-700">Thêm vào giỏ</button>
        <button type="submit" form="buy-form" name="buy_now" value="1" class="rounded-full bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white">Mua ngay</button>
      @endif
    </div>
  </div>
@endsection
