@extends('frontend.layouts.app')

{{-- KYC của thành viên (S10): chưa gửi / không được duyệt → form (thông tin CCCD, tài khoản ngân hàng, ảnh 2 mặt CCCD);
     chờ duyệt / đã duyệt → trạng thái + thông tin đã gửi (số che bớt, ảnh xem lại qua route riêng của chính thành viên).
     JS ([data-kyc-image] trong frontend/js/app.js): xem trước ảnh vừa chọn, thu nhỏ ảnh lớn trước khi gửi. --}}
@section('title', 'Xác minh tài khoản (KYC)')
@section('robots', 'noindex, nofollow')

@php
    use App\Models\MemberKyc;

    $showForm = ! $kyc || $kyc->isRejected();
    // Gửi lại sau khi bị từ chối: điền sẵn thông tin cũ (ảnh phải chọn lại)
    $value = fn (string $field, $default = '') => old($field, $kyc && $kyc->isRejected() ? ($field === 'birth_date' ? $kyc->birth_date?->format('Y-m-d') : $kyc->{$field}) : $default);
    // Ngày sinh hiện dạng ngày/tháng/năm: hồ sơ cũ lưu Y-m-d → đổi lại; gửi lỗi thì hiện lại đúng chữ thành viên đã gõ
    $birthDate = (string) $value('birth_date');
    $birthDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDate, $m) ? "$m[3]/$m[2]/$m[1]" : $birthDate;
    $error = fn (string $field) => $errors->has($field) ? '<p class="mt-1 text-sm text-rose-600">'.e($errors->first($field)).'</p>' : '';
    $input = 'w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2';
    $inputState = fn (string $field) => $errors->has($field) ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100';
    $maxLabel = $maxKilobytes >= 1024 ? rtrim(rtrim(number_format($maxKilobytes / 1024, 1, ',', '.'), '0'), ',').' MB' : $maxKilobytes.' KB';
@endphp

@section('content')
  <div class="mx-auto max-w-2xl px-4 pt-8">
    <a href="{{ route('member.account') }}" class="text-sm font-medium text-brand-700 hover:underline">← Tài khoản</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Xác minh tài khoản (KYC)</h1>
    <p class="mt-1 text-sm text-slate-600">Xác minh danh tính và tài khoản ngân hàng nhận tiền của bạn. Thông tin được bảo mật, chỉ dùng để xác minh và chi trả hoa hồng.</p>

    {{-- Trạng thái hồ sơ --}}
    @if ($kyc?->isPending())
      <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status" data-kyc-status="pending">
        <div class="font-semibold">Hồ sơ đang chờ duyệt</div>
        <p class="mt-1">Bạn đã gửi hồ sơ lúc {{ $kyc->submitted_at->format('H:i d/m/Y') }}. Chúng tôi sẽ kiểm tra và thông báo kết quả tại trang này.</p>
      </div>
    @elseif ($kyc?->isApproved())
      <div class="mt-5 rounded-2xl border border-brand-100 bg-brand-50 p-4 text-sm text-brand-900" role="status" data-kyc-status="approved">
        <div class="font-semibold">Tài khoản đã được xác minh</div>
        <p class="mt-1">Hồ sơ KYC của bạn đã được duyệt{{ $kyc->reviewed_at ? ' ngày '.$kyc->reviewed_at->format('d/m/Y') : '' }}.</p>
      </div>
    @elseif ($kyc?->isRejected())
      <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" data-kyc-status="rejected">
        <div class="font-semibold">Hồ sơ không được duyệt</div>
        <p class="mt-1"><span class="font-medium">Lý do:</span> {{ $kyc->reject_reason ?: 'Thông tin không hợp lệ.' }}</p>
        <p class="mt-1">Vui lòng kiểm tra, sửa lại thông tin bên dưới và gửi lại.</p>
      </div>
    @endif

    @if ($tooLarge)
      <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">Ảnh tải lên quá lớn nên chưa gửi được hồ sơ. Mỗi ảnh tối đa {{ $maxLabel }} — hãy chọn ảnh nhẹ hơn rồi gửi lại.</div>
    @endif

    @if ($showForm)
      <form method="POST" action="{{ route('member.kyc.store') }}" enctype="multipart/form-data" class="mt-5 space-y-5" novalidate data-kyc-form>
        @csrf
        @if ($errors->any())
          <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">Hồ sơ chưa gửi được. Kiểm tra các ô báo đỏ bên dưới rồi gửi lại (ảnh CCCD cần chọn lại).</div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <h2 class="font-semibold text-slate-900">Thông tin trên căn cước</h2>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label for="f_full_name" class="mb-1.5 block text-sm font-medium text-slate-700">Họ và tên (theo CCCD) <span class="text-rose-500">*</span></label>
              <input id="f_full_name" name="full_name" value="{{ $value('full_name', $member->full_name) }}" maxlength="100" autocomplete="name" class="{{ $input }} {{ $inputState('full_name') }}" />
              {!! $error('full_name') !!}
            </div>
            <div>
              <label for="f_id_number" class="mb-1.5 block text-sm font-medium text-slate-700">Số CCCD <span class="text-rose-500">*</span></label>
              <input id="f_id_number" name="id_number" value="{{ $value('id_number') }}" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="12 chữ số" class="{{ $input }} {{ $inputState('id_number') }}" />
              {!! $error('id_number') !!}
            </div>
            <div>
              <label for="f_birth_date" class="mb-1.5 block text-sm font-medium text-slate-700">Ngày sinh <span class="text-rose-500">*</span></label>
              {{-- Ô chữ ngày/tháng/năm (không dùng lịch của điện thoại — chọn năm sinh phải lướt rất lâu): bàn phím số, JS tự
                   chèn dấu "/" ([data-date-input] trong frontend/js/app.js); máy chủ nhận dd/mm/yyyy --}}
              <input id="f_birth_date" name="birth_date" type="text" value="{{ $birthDate }}" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="VD: 20/05/1979" data-date-input aria-describedby="h_birth_date" class="{{ $input }} {{ $inputState('birth_date') }}" />
              <p class="mt-1 text-sm text-rose-600" hidden data-date-error></p>
              {!! $error('birth_date') !!}
              <p id="h_birth_date" class="mt-1 text-xs text-slate-500">Nhập ngày/tháng/năm, chỉ cần gõ số.</p>
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <h2 class="font-semibold text-slate-900">Tài khoản ngân hàng nhận tiền</h2>
          <p class="mt-1 text-sm text-slate-500">Phải là tài khoản chính chủ: tên chủ tài khoản trùng với họ tên trên CCCD.</p>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label for="f_bank_code" class="mb-1.5 block text-sm font-medium text-slate-700">Ngân hàng <span class="text-rose-500">*</span></label>
              <select id="f_bank_code" name="bank_code" class="{{ $input }} {{ $inputState('bank_code') }}">
                <option value="">Chọn ngân hàng</option>
                @foreach ($banks as $code => $name)
                  <option value="{{ $code }}" @selected($value('bank_code') === $code)>{{ $name }}</option>
                @endforeach
              </select>
              {!! $error('bank_code') !!}
            </div>
            <div>
              <label for="f_bank_account_number" class="mb-1.5 block text-sm font-medium text-slate-700">Số tài khoản <span class="text-rose-500">*</span></label>
              <input id="f_bank_account_number" name="bank_account_number" value="{{ $value('bank_account_number') }}" inputmode="numeric" maxlength="30" autocomplete="off" class="{{ $input }} {{ $inputState('bank_account_number') }}" />
              {!! $error('bank_account_number') !!}
            </div>
            <div>
              <label for="f_bank_account_name" class="mb-1.5 block text-sm font-medium text-slate-700">Tên chủ tài khoản <span class="text-rose-500">*</span></label>
              <input id="f_bank_account_name" name="bank_account_name" value="{{ $value('bank_account_name') }}" maxlength="100" autocomplete="off" placeholder="VD: NGUYEN VAN AN" style="text-transform: uppercase" class="{{ $input }} {{ $inputState('bank_account_name') }}" />
              {!! $error('bank_account_name') !!}
            </div>
          </div>

          {{-- Mã QR nhận tiền (không bắt buộc): ảnh QR của chính tài khoản ngân hàng trên, kế toán quét khi chi hoa hồng --}}
          <div class="mt-4 grid gap-4 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-center" data-kyc-image data-max-bytes="{{ $maxKilobytes * 1024 }}" data-target-bytes="{{ 200 * 1024 }}">
            <div class="w-full max-w-[200px]">
              <label for="f_qr_image" @class(['relative flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 overflow-hidden rounded-xl border-2 border-dashed bg-slate-50 px-3 text-center transition hover:border-brand-500',
                'border-rose-300' => $errors->has('qr_image'), 'border-slate-300' => ! $errors->has('qr_image')])>
                <svg class="size-8 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3.5" y="3.5" width="6" height="6" rx="1" /><rect x="14.5" y="3.5" width="6" height="6" rx="1" /><rect x="3.5" y="14.5" width="6" height="6" rx="1" /><path stroke-linecap="round" d="M14.5 14.5h2.5v2.5M20.5 14.5v0M14.5 20.5h0M17 20.5h3.5v-3" /></svg>
                <span class="text-sm font-medium text-slate-700">{{ $member->hasQr() ? 'Đổi ảnh mã QR' : 'Chọn ảnh mã QR' }}</span>
                @if ($member->hasQr())<img src="{{ route('member.qr') }}?v={{ $member->qr_uploaded_at?->timestamp }}" alt="Mã QR hiện tại" class="absolute inset-0 size-full bg-white object-contain" data-kyc-current />@endif
                <img alt="" class="absolute inset-0 size-full bg-white object-contain" hidden data-kyc-preview />
              </label>
              <input id="f_qr_image" name="qr_image" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="l_qr_image" />
            </div>
            <div class="min-w-0">
              <div class="text-sm font-medium text-slate-700" id="l_qr_image">Mã QR nhận tiền <span class="font-normal text-slate-500">(không bắt buộc)</span></div>
              <p class="mt-1 text-xs text-slate-500">Ảnh mã QR của đúng tài khoản ngân hàng ở trên (lấy trong ứng dụng ngân hàng: mục "QR nhận tiền"). Giúp kế toán chuyển hoa hồng nhanh và chính xác. Có thể thêm hoặc đổi sau ở trang Thông tin tài khoản.</p>
              <p class="mt-1 text-xs break-words text-slate-500" hidden data-kyc-note></p>
              {!! $error('qr_image') !!}
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <h2 class="font-semibold text-slate-900">Ảnh căn cước công dân</h2>
          <p class="mt-1 text-sm text-slate-500">Chụp đủ 4 góc, rõ chữ, không lóa. Ảnh JPG, PNG hoặc WEBP, mỗi ảnh tối đa {{ $maxLabel }}.</p>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach (['front_image' => 'Mặt trước', 'back_image' => 'Mặt sau'] as $field => $side)
              <div class="min-w-0" data-kyc-image data-max-bytes="{{ $maxKilobytes * 1024 }}">
                <div class="mb-1.5 text-sm font-medium text-slate-700" id="l_{{ $field }}">{{ $side }} CCCD <span class="text-rose-500">*</span></div>
                <label for="f_{{ $field }}" @class(['relative flex aspect-[8/5] cursor-pointer flex-col items-center justify-center gap-1 overflow-hidden rounded-xl border-2 border-dashed bg-slate-50 px-3 text-center transition hover:border-brand-500',
                  'border-rose-300' => $errors->has($field), 'border-slate-300' => ! $errors->has($field)])>
                  <svg class="size-8 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="11" r="2" /><path stroke-linecap="round" d="M14 10h4M14 13h4M6 16c.6-1.2 1.7-2 3-2s2.4.8 3 2" /></svg>
                  <span class="text-sm font-medium text-slate-700">Chụp hoặc chọn ảnh</span>
                  <span class="text-xs text-slate-500">{{ $side }} căn cước</span>
                  <img alt="" class="absolute inset-0 size-full bg-slate-100 object-contain" hidden data-kyc-preview />
                </label>
                <input id="f_{{ $field }}" name="{{ $field }}" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="l_{{ $field }}" />
                <p class="mt-1 text-xs break-words text-slate-500" hidden data-kyc-note></p>
                {!! $error($field) !!}
              </div>
            @endforeach
          </div>
        </section>

        <button type="submit" class="w-full rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700 disabled:opacity-60" data-kyc-submit>{{ $kyc ? 'Gửi lại hồ sơ' : 'Gửi hồ sơ' }}</button>
        <p class="text-center text-xs text-slate-500">Sau khi gửi, hồ sơ ở trạng thái chờ duyệt và không sửa được cho tới khi có kết quả.</p>
      </form>
    @else
      {{-- Thông tin đã gửi: số CCCD / số tài khoản che bớt --}}
      <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
        <h2 class="font-semibold text-slate-900">Thông tin đã gửi</h2>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[170px_minmax(0,1fr)]">
          <dt class="text-slate-500">Họ và tên</dt><dd class="font-medium text-slate-900">{{ $kyc->full_name }}</dd>
          <dt class="text-slate-500">Số CCCD</dt><dd class="font-medium text-slate-900 tabular-nums">{{ $kyc->maskedIdNumber() }}</dd>
          <dt class="text-slate-500">Ngày sinh</dt><dd class="font-medium text-slate-900">{{ $kyc->birth_date->format('d/m/Y') }}</dd>
          <dt class="text-slate-500">Ngân hàng</dt><dd class="font-medium text-slate-900">{{ $kyc->bankName() }}</dd>
          <dt class="text-slate-500">Số tài khoản</dt><dd class="font-medium text-slate-900 tabular-nums">{{ $kyc->maskedAccountNumber() }}</dd>
          <dt class="text-slate-500">Tên chủ tài khoản</dt><dd class="font-medium text-slate-900">{{ $kyc->bank_account_name }}</dd>
        </dl>
        <div class="mt-5 grid grid-cols-2 gap-3">
          @foreach (MemberKyc::SIDES as $side => $label)
            <figure>
              <img src="{{ route('member.kyc.image', $side) }}?v={{ $kyc->submitted_at->timestamp }}" alt="{{ $label }} CCCD" loading="lazy" class="aspect-[8/5] w-full rounded-xl border border-slate-200 bg-slate-100 object-contain" />
              <figcaption class="mt-1 text-center text-xs text-slate-500">{{ $label }}</figcaption>
            </figure>
          @endforeach
        </div>
        {{-- Mã QR nhận tiền: xem lại / thêm / đổi ở trang thông tin tài khoản (không cần gửi lại hồ sơ) --}}
        <div class="mt-5 flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 p-4" data-kyc-qr>
          @if ($member->hasQr())
            <img src="{{ route('member.qr') }}?v={{ $member->qr_uploaded_at?->timestamp }}" alt="Mã QR nhận tiền" loading="lazy" class="size-24 rounded-lg border border-slate-200 bg-white object-contain" />
          @endif
          <div class="min-w-0 flex-1 text-sm">
            <div class="font-medium text-slate-900">Mã QR nhận tiền</div>
            <p class="mt-0.5 text-xs text-slate-500">{{ $member->hasQr() ? 'Đã tải lên '.$member->qr_uploaded_at?->format('d/m/Y').'.' : 'Chưa có. Thêm ảnh mã QR của tài khoản ngân hàng để nhận hoa hồng nhanh hơn.' }}</p>
          </div>
          <a href="{{ route('member.profile.edit') }}" class="shrink-0 rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:border-brand-500 hover:text-brand-700">{{ $member->hasQr() ? 'Đổi mã QR' : 'Thêm mã QR' }}</a>
        </div>
        @if ($kyc->isApproved())
          <p class="mt-4 text-xs text-slate-500">Cần đổi tài khoản ngân hàng hoặc thông tin đã xác minh? Vui lòng liên hệ hotline để được hỗ trợ.</p>
        @endif
      </section>
    @endif
  </div>
@endsection
