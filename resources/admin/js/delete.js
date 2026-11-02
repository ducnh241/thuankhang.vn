import $ from 'jquery';

import { icon } from './core';
import { openModal } from './modal';

/*
 * Xóa có hỏi lại (template: hộp delete-confirm). Nút [data-delete-url] mở hộp xác nhận
 * (backend/partials/delete-confirm, có trong layout); bấm "Xóa" gửi form DELETE #delete-form tới url đó.
 *   data-delete-title    câu hỏi, vd: Xóa dự án “ABC”?
 *   data-delete-content  dòng giải thích (mặc định: Thao tác này không thể hoàn tác.)
 */
export default function initDeleteConfirm() {
  $(document).on('click', '[data-delete-url]', function (e) {
    e.preventDefault();
    const $trigger = $(this);
    const $root = openModal('#delete-confirm', this);
    $root.find('.ant-modal-confirm-title').text($trigger.attr('data-delete-title') || 'Xóa mục này?');
    if ($trigger.attr('data-delete-content')) $root.find('.ant-modal-confirm-content').text($trigger.attr('data-delete-content'));
    $root.find('.modal-ok').on('click', function () {
      // Đang gửi: nút quay, không bấm lại được (chống gửi 2 lần)
      $(this).addClass('ant-btn-loading').prop('disabled', true).prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
      const form = document.getElementById('delete-form');
      form.action = $trigger.attr('data-delete-url');
      form.submit();
    });
  });
}
