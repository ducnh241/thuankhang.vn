import $ from 'jquery';

import { icon } from './core';
import { syncCheckbox } from './controls';
import { openModal } from './modal';

/*
 * Chọn nhiều dòng để làm hàng loạt (template projects.js / orders.js: thanh "N đã chọn" phủ lên tiêu đề bảng), dữ liệu
 * gửi lên server bằng form thật. Khung: <form data-bulk-form> chứa
 *   [data-bulk-bar]       thanh thao tác (ẩn khi chưa chọn gì), trong đó [data-bulk-clear] (ô bỏ chọn tất cả),
 *                         [data-bulk-count], [data-bulk-total] (tổng data-amount của các dòng đã chọn),
 *                         [data-bulk-delete] (data-title "Xóa %d …?", data-content)
 *   [data-select-page]    ô chọn cả trang ở tiêu đề bảng
 *   [data-row-check]      ô chọn của từng dòng (name="ids[]"); bấm vào ô chứa nó cũng chọn; Shift + bấm chọn cả đoạn
 */

const vnd = n => n.toLocaleString('vi-VN', { maximumFractionDigits: 2 });

function setup($form) {
  const $bar = $form.find('[data-bulk-bar]');
  const $page = $form.find('[data-select-page]');
  const rows = () => $form.find('[data-row-check]').not(':disabled'); // dòng không chọn được (vd tag đang dùng) thì bỏ qua
  let last = null; // dòng bấm gần nhất (Shift + bấm chọn cả đoạn)

  const sync = () => {
    const $all = rows();
    const $checked = $all.filter(':checked');
    $all.each((_, el) => {
      syncCheckbox(el);
      $(el).closest('tr').toggleClass('ant-table-row-selected', el.checked);
    });
    const count = $checked.length;
    $page.prop('checked', count > 0 && count === $all.length);
    $page
      .closest('.ant-checkbox')
      .toggleClass('ant-checkbox-checked', count > 0 && count === $all.length)
      .toggleClass('ant-checkbox-indeterminate', count > 0 && count < $all.length);
    $page.closest('.ant-checkbox-wrapper').toggleClass('ant-checkbox-wrapper-checked', count > 0 && count === $all.length);

    $bar.toggleClass('hidden', !count);
    if (!count) return;
    // Thanh phủ đúng chiều cao tiêu đề bảng, ô chọn của thanh thẳng cột với ô chọn của các dòng
    $bar.css('height', $form.find('thead').outerHeight());
    $bar.css('padding-left', '');
    const offset =
      $page.closest('.ant-checkbox')[0].getBoundingClientRect().left -
      $bar.find('[data-bulk-clear]').closest('.ant-checkbox')[0].getBoundingClientRect().left;
    $bar.css('padding-left', `+=${offset}`);
    $bar.find('[data-bulk-count]').text(count.toLocaleString('vi-VN'));
    const total = $checked.toArray().reduce((sum, el) => sum + (Number($(el).attr('data-amount')) || 0), 0);
    $bar.find('[data-bulk-total]').text(vnd(total));
  };

  $form.on('change', '[data-row-check]', sync);
  $form.on('click', '[data-row-check]', function (e) {
    const $all = rows();
    const index = $all.index(this);
    if (e.shiftKey && last !== null && last !== index) {
      const [a, b] = [Math.min(last, index), Math.max(last, index)];
      $all.slice(a, b + 1).prop('checked', this.checked);
      sync();
    }
    last = index;
  });
  $page.on('change', function () {
    rows().prop('checked', this.checked);
    last = null;
    sync();
  });
  $bar.on('change', '[data-bulk-clear]', () => {
    rows().prop('checked', false);
    last = null;
    sync();
  });
  // Bấm vào ô chọn (ngoài ô vuông) cũng chọn dòng, như Ant Design
  $form.on('click', 'td.ant-table-selection-column', function (e) {
    if ($(e.target).closest('label, input').length) return;
    $(this).find('[data-row-check]').trigger('click');
  });

  $bar.on('click', '[data-bulk-delete]', function () {
    const count = rows().filter(':checked').length;
    if (!count) return;
    const $root = openModal('#delete-confirm', this);
    $root.find('.ant-modal-confirm-title').text($(this).attr('data-title').replace('%d', count.toLocaleString('vi-VN')));
    if ($(this).attr('data-content')) $root.find('.ant-modal-confirm-content').text($(this).attr('data-content'));
    $root.find('.modal-ok').on('click', function () {
      $(this).addClass('ant-btn-loading').prop('disabled', true).prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
      $form[0].submit();
    });
  });

  $(window).on('resize', () => rows().filter(':checked').length && sync());
  sync();
}

export default function initBulk() {
  $('form[data-bulk-form]').each((_, form) => setup($(form)));
}
