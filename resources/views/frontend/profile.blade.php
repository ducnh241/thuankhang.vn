@extends('frontend.layouts.app')

{{-- Sửa thông tin tài khoản: họ tên hiển thị + ảnh mã QR nhận tiền (không bắt buộc). Ô ảnh dùng chung JS [data-kyc-image]
     của form KYC (xem trước, thu nhỏ ảnh trước khi gửi). Số điện thoại / email không sửa ở đây. --}}
@section('title', 'Sửa thông tin tài khoản')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-2xl px-4 pt-8">
    <a href="{{ route('member.account') }}" class="text-sm font-medium text-brand-700 hover:underline">← Tài khoản</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Sửa thông tin tài khoản</h1>

    <form method="POST" action="{{ route('member.profile.update') }}" enctype="multipart/form-data" class="mt-5 space-y-5" novalidate>
      @csrf
      @method('PUT')

      <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
        <h2 class="font-semibold text-slate-900">Thông tin</h2>
        <div class="mt-4 space-y-4">
          @include('frontend.partials.field', ['name' => 'full_name', 'label' => 'Họ và tên', 'required' => true, 'value' => old('full_name', $member->full_name), 'attrs' => 'autocomplete="name" maxlength="100"'])
          <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[160px_minmax(0,1fr)]">
            <dt class="text-slate-500">Số điện thoại</dt><dd class="font-medium text-slate-900 tabular-nums">{{ $member->phone }}</dd>
            <dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ $member->email ?: '—' }}</dd>
          </dl>
          <p class="text-xs text-slate-500">Số điện thoại là tên đăng nhập nên không đổi được ở đây. Cần đổi số điện thoại hoặc email, vui lòng liên hệ{{ $site['site_hotline'] ? ' hotline '.$site['site_hotline'] : ' bộ phận hỗ trợ' }}.</p>
        </div>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
        <h2 class="font-semibold text-slate-900" id="l_qr_image">Mã QR nhận tiền</h2>
        <p class="mt-1 text-sm text-slate-500">Ảnh mã QR tài khoản ngân hàng của bạn (lấy trong ứng dụng ngân hàng: mục "QR nhận tiền"). Kế toán quét mã này khi chuyển hoa hồng. Ảnh JPG, PNG hoặc WEBP.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-[200px_minmax(0,1fr)] sm:items-center" data-kyc-image data-max-bytes="{{ $maxKilobytes * 1024 }}" data-target-bytes="{{ 300 * 1024 }}">
          <div class="w-full max-w-[200px]">
            <label for="f_qr_image" @class(['relative flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 overflow-hidden rounded-xl border-2 border-dashed bg-slate-50 px-3 text-center transition hover:border-brand-500',
              'border-rose-300' => $errors->has('qr_image'), 'border-slate-300' => ! $errors->has('qr_image')])>
              <svg class="size-8 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3.5" y="3.5" width="6" height="6" rx="1" /><rect x="14.5" y="3.5" width="6" height="6" rx="1" /><rect x="3.5" y="14.5" width="6" height="6" rx="1" /><path stroke-linecap="round" d="M14.5 14.5h2.5v2.5M14.5 20.5h0M17 20.5h3.5v-3" /></svg>
              <span class="text-sm font-medium text-slate-700">Chọn ảnh mã QR</span>
              @if ($member->hasQr())<img src="{{ route('member.qr') }}?v={{ $member->qr_uploaded_at?->timestamp }}" alt="Mã QR hiện tại" class="absolute inset-0 size-full bg-white object-contain" data-kyc-current />@endif
              <img alt="" class="absolute inset-0 size-full bg-white object-contain" hidden data-kyc-preview />
            </label>
            <input id="f_qr_image" name="qr_image" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="l_qr_image" />
          </div>
          <div class="min-w-0 text-sm">
            @if ($member->hasQr())
              <p class="font-medium text-slate-900" data-qr-status>Đã có mã QR (tải lên {{ $member->qr_uploaded_at?->format('d/m/Y') }}).</p>
              <p class="mt-1 text-xs text-slate-500">Bấm vào ảnh để chọn ảnh khác thay thế. Không chọn ảnh mới thì giữ nguyên mã hiện tại.</p>
            @else
              <p class="font-medium text-slate-900" data-qr-status>Chưa có mã QR.</p>
              <p class="mt-1 text-xs text-slate-500">Bấm vào ô ảnh để chọn. Không bắt buộc.</p>
            @endif
            @if ($member->kyc?->isApproved())
              <p class="mt-2 text-xs text-slate-500">Mã QR phải là của đúng tài khoản ngân hàng đã xác minh ({{ $member->kyc->bankName() }} · {{ $member->kyc->maskedAccountNumber() }}).</p>
            @endif
            <p class="mt-1 text-xs break-words text-slate-500" hidden data-kyc-note></p>
            @error('qr_image')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
          </div>
        </div>
      </section>

      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">Lưu thay đổi</button>
        <a href="{{ route('member.account') }}" class="rounded-full border border-slate-300 px-6 py-3 text-sm font-medium text-slate-700 hover:border-slate-400">Hủy</a>
      </div>
    </form>
  </div>
@endsection
