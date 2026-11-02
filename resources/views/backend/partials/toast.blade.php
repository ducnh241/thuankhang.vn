{{-- Thông báo thành công session('success') kiểu Ant Design message: nổi giữa đầu trang, tự ẩn sau 3 giây
     (resources/admin/js/layout.js). Đặt ngoài vùng nội dung vì vùng đó có clip-path (bo góc) sẽ cắt mất. --}}
@if (session('success'))
  <div class="ant-message">
    <div>
      <div class="ant-message-notice move-up-appear move-up-appear-active" data-flash-message>
        <div class="ant-message-notice-content">
          <div class="ant-message-custom-content ant-message-success" role="status">{{ ant_icon('check-circle') }}<span>{{ session('success') }}</span></div>
        </div>
      </div>
    </div>
  </div>
@endif
