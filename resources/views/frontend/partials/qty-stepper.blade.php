{{-- Ô số lượng có nút −/+ (giỏ hàng, thanh toán): nút 40×40px dễ bấm trên điện thoại. JS [data-qty] trong frontend/js/app.js
     giữ số trong khoảng min–max, khóa nút ở hai đầu và phát sự kiện "change" để cập nhật giỏ ([data-cart-qty]).
     Tham số (truyền đủ, kể cả 'name' => null — @include thừa hưởng biến của trang gọi): $id, $value, $max, $name (null = không
     gửi kèm form bao ngoài), $label (tên sản phẩm cho trình đọc màn hình). --}}
<div class="inline-flex items-center rounded-full border border-slate-300 bg-white" data-qty>
  <button type="button" class="flex size-10 items-center justify-center rounded-l-full text-lg text-slate-600 hover:bg-slate-50 hover:text-brand-700 disabled:text-slate-300 disabled:hover:bg-transparent"
          data-qty-step="-1" aria-label="Giảm số lượng {{ $label }}" @disabled($value <= 1)>−</button>
  <input id="{{ $id }}" type="number"{!! $name ? ' name="'.e($name).'"' : '' !!} value="{{ $value }}" min="1" max="{{ max(1, $max) }}" inputmode="numeric"
         aria-label="Số lượng {{ $label }}"
         class="w-11 [appearance:textfield] border-0 bg-transparent text-center text-sm font-semibold tabular-nums outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
  <button type="button" class="flex size-10 items-center justify-center rounded-r-full text-lg text-slate-600 hover:bg-slate-50 hover:text-brand-700 disabled:text-slate-300 disabled:hover:bg-transparent"
          data-qty-step="1" aria-label="Tăng số lượng {{ $label }}" @disabled($value >= max(1, $max))>+</button>
</div>
