{{-- Gợi ý "Cài ứng dụng" (thêm website vào màn hình chính). Ẩn sẵn; JS ([data-install-app] trong frontend/js/app.js) chỉ
     hiện trên điện thoại / máy tính bảng khi CHƯA mở ở chế độ ứng dụng và khách chưa bấm đóng:
       - Android (Chrome…): có nút "Cài ứng dụng" (sự kiện beforeinstallprompt); trình duyệt không hỗ trợ thì hiện cách làm tay;
       - iPhone / iPad: iOS không cho cài bằng nút, chỉ hiện hướng dẫn (Safari → Chia sẻ → Thêm vào Màn hình chính). --}}
@php $appName = \App\Http\Controllers\Frontend\ManifestController::shortName($site['site_name'] ?: config('app.name')); @endphp
<section class="{{ $class ?? 'mt-4' }} rounded-2xl border border-brand-100 bg-brand-50 p-4" hidden data-install-app aria-label="Cài ứng dụng">
  <div class="flex items-start gap-3">
    <img src="{{ \App\Support\AppIcon::url('icon-192', ($site['site_favicon'] ?? null) ?: null) }}" alt="" width="48" height="48" class="size-12 shrink-0 rounded-xl border border-brand-100 bg-white" />
    <div class="min-w-0 flex-1">
      <div class="font-semibold text-slate-900">Cài ứng dụng {{ $appName }}</div>
      <p class="mt-0.5 text-xs text-slate-600">Thêm vào màn hình chính để mở nhanh như một ứng dụng, không cần gõ địa chỉ.</p>
      <button type="button" class="mt-3 rounded-full bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700" hidden data-install-button>Cài ứng dụng</button>
      <ol class="mt-2 list-decimal space-y-0.5 pl-4 text-xs text-slate-700" hidden data-install-ios>
        <li>Mở trang này bằng <strong>Safari</strong>.</li>
        <li>Bấm nút <strong>Chia sẻ</strong> <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15V3.5m0 0L8.5 7M12 3.5 15.5 7M6.5 10.5h-1a1.5 1.5 0 0 0-1.5 1.5v7A1.5 1.5 0 0 0 5.5 20.5h13A1.5 1.5 0 0 0 20 19v-7a1.5 1.5 0 0 0-1.5-1.5h-1" /></svg> ở thanh công cụ.</li>
        <li>Chọn <strong>Thêm vào Màn hình chính</strong>, rồi bấm <strong>Thêm</strong>.</li>
      </ol>
      <ol class="mt-2 list-decimal space-y-0.5 pl-4 text-xs text-slate-700" hidden data-install-android>
        <li>Mở trang này bằng <strong>Chrome</strong>.</li>
        <li>Bấm nút menu <strong>⋮</strong> ở góc trên.</li>
        <li>Chọn <strong>Thêm vào màn hình chính</strong> (hoặc <strong>Cài đặt ứng dụng</strong>).</li>
      </ol>
    </div>
    <button type="button" class="-mt-1 -mr-1 shrink-0 rounded-full p-2 text-slate-500 hover:bg-brand-100 hover:text-slate-700" aria-label="Đóng gợi ý cài ứng dụng" data-install-close>
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg>
    </button>
  </div>
</section>
