{{-- Thanh trên cùng chung của các trang Cấu hình affiliate: "Cấu hình affiliate › {tiêu đề}" + dải "Chưa lưu thay đổi"
     cho form #{form} (edit-form.js). Biến: $title, $form, $canEdit. --}}
<div class="page-topbar-left">
  <span class="page-topbar-crumb">Cấu hình affiliate</span>
  <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
  <h1 class="page-topbar-title">{{ $title }}</h1>
</div>
@if ($canEdit)
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="{{ $form }}" class="save-bar-save">Lưu</button>
  </div>
@endif
<div class="page-topbar-right"></div>
