{{-- Hộp xác nhận xóa (template src/partials/delete-confirm.html) + form gửi đi. Nút có data-delete-url mở hộp này
     (resources/admin/js/delete.js); bấm "Xóa" gửi DELETE tới url đó. Tuỳ chọn: data-delete-title (câu hỏi),
     data-delete-content (dòng giải thích). --}}
<template id="delete-confirm">
  <div class="ant-modal-root">
    <div class="ant-modal-mask"></div>
    <div tabindex="-1" class="ant-modal-wrap" role="dialog">
      <div role="document" class="ant-modal ant-modal-confirm ant-modal-confirm-confirm" style="width: 416px">
        <div class="ant-modal-content">
          <div class="ant-modal-body">
            <div class="ant-modal-confirm-body-wrapper">
              <div class="ant-modal-confirm-body">
                {{ ant_icon('exclamation-circle') }}<span class="ant-modal-confirm-title"></span>
                <div class="ant-modal-confirm-content">Thao tác này không thể hoàn tác.</div>
              </div>
              <div class="ant-modal-confirm-btns">
                <button type="button" class="ant-btn" data-modal-close><span>Hủy</span></button>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-dangerous modal-ok"><span>Xóa</span></button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<form method="POST" id="delete-form" class="hidden">@csrf @method('DELETE')</form>
