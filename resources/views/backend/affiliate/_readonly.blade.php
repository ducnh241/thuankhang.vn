@unless ($canEdit)
  <div class="ant-alert ant-alert-info mb-4" role="alert">
    <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
    <div class="ant-alert-content"><div class="ant-alert-message">Bạn chỉ có quyền xem mục này.</div></div>
  </div>
@endunless
