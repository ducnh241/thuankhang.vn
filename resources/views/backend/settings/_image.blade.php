{{-- Ô tải một ảnh của trang Cài đặt (logo, favicon, ảnh mã QR): chọn ảnh thì xem trước ngay, lưu form mới tải lên; ⓧ trên ảnh
     = bỏ ảnh (image-field.js qua settings.js). Biến: $field (tên ô tệp), $key (khóa lưu), $label, $accept, $maxSize (byte),
     $hint, $typeMessage, $sizeMessage; $settings, $hasError, $error từ view cha. --}}
@php $src = \App\Support\SiteSettings::imageUrl($settings[$key]); @endphp
{{-- Chọn ảnh thì xem trước ngay, lưu form mới tải lên; ⓧ trên ảnh = bỏ ảnh --}}
<div class="ant-row ant-form-item mb-0{{ $hasError($field) }}" data-image-field="{{ $field }}" data-max-size="{{ $maxSize }}" data-type-message="{{ $typeMessage }}" data-size-message="{{ $sizeMessage }}">
  <div class="ant-col ant-form-item-label"><label for="{{ $field }}-file" title="{{ $label }}">{{ $label }}</label></div>
  <div class="ant-col ant-form-item-control">
    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
      <div class="ant-upload-picture-card-wrapper">
        <div class="ant-upload-list ant-upload-list-picture-card settings-image" data-current="{{ $src }}">
          @if ($src)
            <div class="ant-upload-list-picture-card-container">
              <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
                <div class="ant-upload-list-item-info">
                  <span><a class="ant-upload-list-item-thumbnail" href="{{ $src }}" target="_blank" rel="noopener noreferrer"><img src="{{ $src }}" alt="" class="ant-upload-list-item-image" /></a></span>
                </div>
                <span class="ant-upload-list-item-actions"><a href="{{ $src }}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">{{ ant_icon('eye') }}</a><button title="Bỏ ảnh" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file">{{ ant_icon('delete') }}</button></span>
              </div>
            </div>
          @endif
          <div @class(['ant-upload ant-upload-select ant-upload-select-picture-card', 'hidden' => $src])>
            <span tabindex="0" class="ant-upload" role="button" data-image-pick aria-label="Chọn {{ mb_strtolower($label) }}">
              <div>{{ ant_icon('plus') }}<div class="mt-2">Tải lên</div></div>
            </span>
          </div>
        </div>
      </div>
      {{-- Ô chọn tệp thật nằm ngoài khung .ant-upload (controls.js xóa tệp của ô nằm trong khung sau khi đọc) --}}
      <input type="file" name="{{ $field }}" id="{{ $field }}-file" accept="{{ $accept }}" class="hidden" />
      <input type="checkbox" name="remove_{{ $field }}" value="1" class="hidden" data-image-remove />
    </div></div>
    {!! $error($field) !!}
    <div class="ant-form-item-extra">{{ $hint }}</div>
  </div>
</div>
