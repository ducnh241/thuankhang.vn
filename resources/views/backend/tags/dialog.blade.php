{{-- Hộp thoại thêm / sửa tag (Modal Ant Design, như hộp khoản thu). Nút [data-tag-open] mở để thêm; có
     data-tag='{"id":..,"name":..}' thì mở để sửa tag đó (điền sẵn). Server báo lỗi (túi lỗi 'tag' + session('tag_dialog')
     = 'new' / id) thì hộp mở lại với dữ liệu vừa nhập, lỗi hiện dưới từng ô. JS: resources/admin/js/tag-modal.js --}}
@php
    $bag = $errors->getBag('tag');
    $reopen = session('tag_dialog'); // 'new' hoặc id tag đang sửa khi validate lỗi
    $default = '#6b7280'; // màu mặc định khi để trống (TagRequest)
    // Bảng màu: xám mặc định + các màu có sẵn của Ant Design (red, volcano, orange, gold, lime, green, cyan, primary, geekblue, purple, magenta)
    $palette = ['#6b7280' => 'Xám', '#f5222d' => 'Đỏ', '#fa541c' => 'Đỏ cam', '#fa8c16' => 'Cam', '#faad14' => 'Vàng', '#a0d911' => 'Xanh chanh',
        '#52c41a' => 'Xanh lá', '#13c2c2' => 'Xanh ngọc', '#007bff' => 'Xanh dương', '#2f54eb' => 'Xanh đậm', '#722ed1' => 'Tím', '#eb2f96' => 'Hồng'];
    $value = fn (string $field, $fallback = '') => $reopen ? old($field, $fallback) : $fallback;
    $color = $value('color', $default);
    $color = is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : $default;
    $hasError = fn (string $field) => $bag->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $bag->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($bag->first($field)).'</div></div>' : '';
@endphp
<div class="ant-modal-root hidden" id="tag-modal" data-store-url="{{ route('backend.tags.store') }}" data-update-url="{{ route('backend.tags.update', '__ID__') }}"
  data-default-color="{{ $default }}" @if ($reopen) data-reopen="{{ $reopen }}" @endif>
  <div class="ant-modal-mask"></div>
  <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="tag-modal-title">
    <div role="document" class="ant-modal" style="width: 460px">
      <div class="ant-modal-content">
        <button type="button" aria-label="Đóng" class="ant-modal-close" data-tag-close><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
        <div class="ant-modal-header"><div class="ant-modal-title" id="tag-modal-title" data-tag-title>Thêm tag</div></div>
        <div class="ant-modal-body">
          <form method="POST" action="{{ route('backend.tags.store') }}" id="tag-form" class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off">
            @csrf
            <input type="hidden" name="_method" value="POST" />
            <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên tag.">
              <div class="ant-col ant-form-item-label"><label for="tag_name" class="ant-form-item-required" title="Tên tag">Tên tag</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="tag_name" name="name" value="{{ $value('name') }}" class="ant-input" maxlength="100" placeholder="VD: Ngọc Quang, cloudflare…" />
                </div></div>
                {!! $error('name') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('slug') }}">
              <div class="ant-col ant-form-item-label"><label for="tag_slug" title="Slug">Slug</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="tag_slug" name="slug" value="{{ $value('slug') }}" class="ant-input font-mono" maxlength="120" placeholder="tu-dong-tao-tu-ten" />
                </div></div>
                {!! $error('slug') !!}
                <div class="ant-form-item-extra">Để trống sẽ tự tạo từ tên. Chỉ gồm chữ không dấu, số và dấu gạch ngang.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('color') }}">
              <div class="ant-col ant-form-item-label"><label for="tag_color" title="Màu">Màu</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <div class="tag-swatches" role="radiogroup" aria-label="Màu tag">
                    @foreach ($palette as $swatch => $name)
                      <button type="button" class="tag-swatch" style="background: {{ $swatch }}" data-swatch="{{ $swatch }}" role="radio" aria-checked="{{ $color === $swatch ? 'true' : 'false' }}" aria-label="{{ $name }}" data-tooltip="{{ $name }}"></button>
                    @endforeach
                    {{-- Màu khác: ô chọn màu của trình duyệt --}}
                    <label class="tag-swatch tag-swatch-custom" data-tooltip="Màu khác" @if (! array_key_exists($color, $palette)) aria-checked="true" style="background: {{ $color }}" @endif>
                      {{ ant_icon('plus') }}<input type="color" id="tag_color" name="color" value="{{ $color }}" aria-label="Chọn màu khác" />
                    </label>
                  </div>
                </div></div>
                {!! $error('color') !!}
              </div>
            </div>
            <div class="tag-preview">
              <span class="text-[rgba(0,0,0,0.45)]">Xem trước</span>
              <span class="ant-tag project-tag m-0" data-tag-preview><span class="project-tag-dot" style="background: {{ $color }}"></span><span class="truncate" data-tag-preview-name>{{ $value('name') ?: 'Tên tag' }}</span></span>
            </div>
          </form>
        </div>
        <div class="ant-modal-footer">
          <button type="button" class="ant-btn" data-tag-close><span>Hủy</span></button>
          <button type="submit" form="tag-form" class="ant-btn ant-btn-primary" data-tag-submit><span>Lưu tag</span></button>
        </div>
      </div>
    </div>
  </div>
</div>
