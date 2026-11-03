import $ from 'jquery';

import { icon } from './core';
import { setRadioValue, syncCheckbox } from './controls';
import { setItemError } from './forms';
import { setSelectValue } from './select';

/*
 * Trang sửa / thêm (form có data-edit-form, theo template project-edit.html / product-edit.html):
 *   - dải "Chưa lưu thay đổi" (#save-bar) trên thanh trên cùng khi form khác lúc mở; "Hủy thay đổi" (#discard-changes)
 *     đưa form về như cũ; phần tử [data-hide-when-dirty] ẩn đi khi đang có thay đổi (vd nút Tạo trùng với dải);
 *   - form có data-dirty (server vừa báo lỗi): coi như chưa lưu ngay từ đầu; "Hủy thay đổi" tải lại trang để về dữ
 *     liệu đã lưu;
 *   - đã gửi thì chặn lần bấm sau (bấm đúp tạo 2 bản ghi), nút chuyển sang "đang lưu";
 *   - hỏi lại khi rời trang mà chưa lưu; cuộn tới ô lỗi đầu tiên server báo.
 * Sự kiện `edit-form:refresh` bắn ra mỗi khi form đổi, để trang làm thêm việc riêng (vd tính trạng thái thanh toán);
 * trang bắn `edit-form:leave` lên form khi đã tự hỏi người dùng và sắp chuyển trang (không hỏi "rời trang?" nữa).
 */

// Giá trị form để so: bỏ _token / _method; ô chọn tệp so theo tên tệp
const snapshot = form =>
  new URLSearchParams(
    [...new FormData(form)].filter(([k]) => !k.startsWith('_')).map(([k, v]) => [k, v instanceof File ? v.name : v]),
  ).toString();

// Đưa form về giá trị lúc mở trang (form.reset) rồi vẽ lại các control Ant Design theo control gốc
function discard(form) {
  form.reset();
  $(form)
    .find('select.hidden')
    .each(function () {
      const $select = $(this).prev('.ant-select');
      if (!$select.length) return;
      if (this.multiple) setSelectValue($select, [...this.selectedOptions].map(o => o.value).filter(v => v !== ''));
      else setSelectValue($select, this.value === '' ? undefined : this.value);
    });
  $(form)
    .find('.ant-checkbox-input')
    .each((_, el) => syncCheckbox(el));
  $(form)
    .find('.ant-radio-group')
    .each(function () {
      const checked = $(this).find('.ant-radio-input:checked').val();
      if (checked !== undefined) setRadioValue($(this), checked);
    });
  $(form)
    .find('.switch-input')
    .each((_, el) => $(el).siblings('.ant-switch').toggleClass('ant-switch-checked', el.checked).attr('aria-checked', el.checked));
  $(form)
    .find('.ant-picker')
    .each(function () {
      const $picker = $(this);
      const hasValue = !!$picker.find('input').val();
      $picker.find('.ant-picker-clear').remove();
      if (hasValue) $picker.append(`<span class="ant-picker-clear" role="button">${icon('close-circle')}</span>`);
    });
  $(form)
    .find('.ant-form-item')
    .each((_, el) => setItemError($(el), null));
}

function setup(form) {
  const $form = $(form);
  let baseline = snapshot(form);
  let submitting = false;
  let forcedDirty = form.hasAttribute('data-dirty');
  const dirty = () => forcedDirty || snapshot(form) !== baseline;

  const refresh = () => {
    const changed = dirty();
    $('#save-bar').toggleClass('hidden', !changed);
    $('#page-topbar').toggleClass('is-dirty', changed);
    $('[data-hide-when-dirty]').toggleClass('hidden', changed);
    $form.trigger('edit-form:refresh');
  };
  $form.on('input change select:change picker:change number:change', refresh);
  refresh();

  $('#discard-changes').on('click', () => {
    // Server vừa báo lỗi: các ô đang chứa dữ liệu vừa nhập (old input), form.reset() chỉ về lại đúng dữ liệu đó →
    // tải lại trang để hiện dữ liệu đã lưu (trang tạo mới: form trống)
    if (form.hasAttribute('data-dirty')) {
      submitting = true; // không hỏi "rời trang?" khi tải lại
      window.location.replace(window.location.href);
      return;
    }
    discard(form);
    forcedDirty = false;
    baseline = snapshot(form);
    refresh();
  });

  // Gắn sau forms.js (cùng ở document) nên biết form có bị chặn vì còn lỗi không. Nút Lưu nằm ngoài form (thanh trên
  // cùng, form="…") vẫn đi qua đây.
  const $buttons = $(`button[form="${form.id}"]`);
  $(document).on('submit', `#${form.id}`, e => {
    if (e.isDefaultPrevented()) return;
    if (submitting) {
      e.preventDefault();
      return;
    }
    submitting = true;
    $buttons.prop('disabled', true);
    $(e.originalEvent?.submitter || $buttons[0])
      .addClass('ant-btn-loading')
      .prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
  });
  // Trang tự hỏi "Bỏ thay đổi?" bằng hộp thoại của mình rồi chuyển trang: không hỏi lần nữa (vd đổi danh mục ở categories.js)
  $form.on('edit-form:leave', () => {
    submitting = true;
  });
  // Quay lại trang này bằng nút Back (trang lấy từ bộ nhớ đệm): mở lại các nút
  window.addEventListener('pageshow', e => {
    if (!e.persisted) return;
    submitting = false;
    $buttons.prop('disabled', false).removeClass('ant-btn-loading').find('.ant-btn-loading-icon').remove();
  });
  window.addEventListener('beforeunload', e => {
    if (submitting || !dirty()) return;
    e.preventDefault();
    e.returnValue = '';
  });

  // Báo lỗi server: cuộn tới ô lỗi đầu tiên
  const $error = $form.find('.ant-form-item-has-error').first();
  if ($error.length) $error[0].scrollIntoView({ block: 'center' });
}

export default function initEditForms() {
  $('form[data-edit-form]').each((_, form) => setup(form));
}
