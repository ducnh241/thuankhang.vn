@extends('layouts.backend')

{{-- Mẫu thông báo gia hạn (nội dung soạn sẵn để sao chép gửi khách ở trang Nhắc gia hạn). Dựng như các trang sửa: thanh
     trên cùng có dải "Chưa lưu thay đổi"; 2 thẻ mẫu (ô soạn + xem trước với dự án thật) + thẻ biến (bấm để chèn).
     JS: edit-form.js (thay đổi chưa lưu), renewal-template.js (xem trước, chèn biến, khôi phục mặc định). --}}
@php
    use App\Support\Money;
    use App\Support\Renewal;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $fields = [
        'template_hosting' => ['Thông báo gia hạn hosting', 'Gửi khi khách chỉ gia hạn hosting.', $templates['hosting'], Renewal::DEFAULT_TEMPLATE_HOSTING],
        'template_full' => ['Thông báo gia hạn hosting + domain', 'Gửi khi khách gia hạn cả hosting lẫn tên miền.', $templates['full'], Renewal::DEFAULT_TEMPLATE_FULL],
    ];
    // Dữ liệu xem trước: dự án thật (nếu có) hoặc dữ liệu mẫu
    $preview = [
        '{customer_name}' => $sample?->customer?->name ?? 'Nguyễn Văn A',
        '{project_name}' => $sample?->name ?? 'Website bán hàng',
        '{primary_domain}' => $sample?->primary_domain ?? 'example.com',
        '{hosting_amount}' => Money::format($sample?->renewal_hosting_amount ?? 1500000),
        '{domain_amount}' => Money::format($sample?->renewal_domain_amount ?? 450000),
        '{total_amount}' => Money::format($sample ? $sample->renewalTotal() : 1950000),
        '{next_renewal_date}' => $sample?->next_renewal_date?->format('d/m/Y') ?? now()->addMonth()->format('d/m/Y'),
        '{year}' => (string) now()->year,
        '{currency}' => $sample?->currency ?: 'VND',
    ];
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
@endphp

@section('title', 'Mẫu thông báo gia hạn')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.renewals.index') }}" class="page-topbar-crumb" title="Về trang Nhắc gia hạn">Nhắc gia hạn</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">Mẫu thông báo</h1>
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="template-form" class="save-bar-save">Lưu</button>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<form method="POST" id="template-form" action="{{ route('backend.renewals.template.update') }}"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @method('PUT')

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      @foreach ($fields as $name => [$title, $hint, $value, $default])
        <div class="ant-card ant-card-bordered">
          <div class="ant-card-head">
            <div class="ant-card-head-wrapper">
              <div class="ant-card-head-title">{{ $title }}</div>
              <div class="ant-card-extra"><button type="button" class="ant-btn ant-btn-sm" data-reset="{{ $name }}" data-default="{{ $default }}">{{ ant_icon('undo') }}<span>Khôi phục mặc định</span></button></div>
            </div>
          </div>
          <div class="ant-card-body">
            <p class="settings-card-desc">{{ $hint }}</p>
            <div class="ant-row ant-form-item{{ $hasError($name) }}" data-required="Nhập nội dung mẫu.">
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="{{ $name }}" name="{{ $name }}" rows="10" maxlength="5000" class="ant-input font-mono text-[13px]" data-template aria-label="{{ $title }}">{{ session()->hasOldInput() ? (is_string(old($name)) ? old($name) : '') : $value }}</textarea>
                </div></div>
                {!! $error($name) !!}
              </div>
            </div>
            <div class="text-xs font-medium {{ $muted }}">Xem trước {{ $sample ? 'với dự án “'.$sample->name.'”' : 'với dữ liệu mẫu' }}</div>
            <div class="template-preview mt-2" data-preview="{{ $name }}"></div>
          </div>
        </div>
      @endforeach
    </div>

    <div class="flex min-w-0 flex-col gap-4 lg:sticky lg:top-20">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Biến có thể dùng</div></div></div>
        <div class="ant-card-body">
          <p class="settings-card-desc">Bấm để chèn vào chỗ con trỏ trong ô đang soạn.</p>
          <div class="template-vars">
            @foreach (Renewal::PLACEHOLDERS as $key => $text)
              <button type="button" class="template-var" data-insert="{{ $key }}"><code>{{ $key }}</code><span class="{{ $muted }}">{{ $text }}</span></button>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
<script type="application/json" id="template-preview-values">@json($preview)</script>
@endsection
