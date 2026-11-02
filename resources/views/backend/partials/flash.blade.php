{{-- Thông báo lỗi đầu nội dung trang, kiểu Ant Design (thông báo thành công ở backend.partials.toast):
       session('error')    → khung đỏ, bấm × để đóng
       lỗi validate        → khung đỏ liệt kê từng lỗi; trang có @section('inline-errors') tự hiện lỗi dưới từng ô
                              nên chỉ nhắc một dòng --}}
@if (session('error') || $errors->any())
  <div class="mb-4 flex flex-col gap-2">
    @if (session('error'))
      <div class="ant-alert ant-alert-error ant-alert-closable" role="alert">
        {{ ant_icon('close-circle', 'ant-alert-icon') }}
        <div class="ant-alert-content"><div class="ant-alert-message">{{ session('error') }}</div></div>
        <button type="button" class="ant-alert-close-icon" tabindex="0" aria-label="Đóng">{{ ant_icon('close') }}</button>
      </div>
    @endif
    @if ($errors->any() && View::hasSection('inline-errors'))
      {{-- Trang tự hiện lỗi dưới từng ô (vd form dự án): chỉ nhắc một dòng --}}
      <div class="ant-alert ant-alert-error" role="alert">
        {{ ant_icon('close-circle', 'ant-alert-icon') }}
        <div class="ant-alert-content"><div class="ant-alert-message">Chưa lưu được: kiểm tra các ô báo đỏ bên dưới.</div></div>
      </div>
    @elseif ($errors->any())
      <div class="ant-alert ant-alert-error ant-alert-with-description" role="alert">
        {{ ant_icon('close-circle', 'ant-alert-icon') }}
        <div class="ant-alert-content">
          <div class="ant-alert-message">Dữ liệu chưa hợp lệ</div>
          <div class="ant-alert-description">
            <ul class="m-0 list-disc pl-5">
              @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
          </div>
        </div>
      </div>
    @endif
  </div>
@endif
