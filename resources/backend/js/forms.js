import $ from 'jquery';

import { escapeHtml } from './core';
import { setRadioValue, syncCheckbox } from './controls';
import { getSelectValue } from './select';

/*
 * Kiểm tra form theo thuộc tính trên .ant-form-item (lấy từ template ProCMS, src/js/forms.js):
 *   data-required="thông báo"                     bắt buộc có giá trị
 *   data-type="email" data-type-message="..."     kiểm tra định dạng (email, number, money, url — http(s) đầy đủ)
 *   data-match="#o_khac" data-match-message="..." phải trùng giá trị ô khác
 *   data-min="8" data-min-message="..."           (ProCMS) có nhập thì phải đủ số ký tự
 *   data-checked="thông báo"                      ô checkbox phải được tích
 * Kiểm tra khi ô thay đổi và khi submit. Khác template: form hợp lệ được gửi thẳng lên server (form thật của
 * Laravel); chỉ chặn submit khi còn lỗi. Form hợp lệ nhận sự kiện `form:valid` ngay trước khi gửi.
 */

function fieldValue($item) {
  const $control = $item.find('.ant-form-item-control-input').first();
  const $select = $control.find('.ant-select:not(.ant-select-auto-complete)').last();
  if ($control.find('.ant-cascader-picker').length)
    return $control.find('.ant-cascader-picker').attr('data-value') || '';
  if ($control.find('.ant-picker').length) {
    const values = $control
      .find('.ant-picker input')
      .toArray()
      .map(i => i.value);
    return values.every(Boolean) ? values.join('|') : '';
  }
  if ($control.find('.ant-radio-group').length) return $control.find('input:checked').val() || '';
  if ($item.is('[data-checked]')) return $control.find('.ant-checkbox-input').prop('checked');
  const $input = $control
    .find('input.ant-input, textarea.ant-input, input.ant-input-number-input')
    .not('.ant-select-selection-search-input')
    .last();
  if ($input.length) return $input.val().trim();
  if ($select.length) {
    const v = getSelectValue($select);
    return Array.isArray(v) ? v.length > 0 : v || '';
  }
  return $control.find('input').last().val() || '';
}

const isHttpUrl = value => {
  try {
    const url = new URL(value);
    return ['http:', 'https:'].includes(url.protocol) && url.hostname.includes('.');
  } catch {
    return false;
  }
};

function errorOf($item) {
  const value = fieldValue($item);
  if ($item.is('[data-checked]') && !value) return $item.attr('data-checked');
  if ($item.is('[data-required]') && !value) return $item.attr('data-required');
  if (value && $item.attr('data-type') === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    return $item.attr('data-type-message');
  }
  if (value && $item.attr('data-type') === 'number' && !/^\d+(\.\d+)?$/.test(value)) {
    return $item.attr('data-type-message');
  }
  // Whole amounts with optional thousands dots, e.g. 3.500.000
  if (value && $item.attr('data-type') === 'money' && !/^\d{1,3}(\.?\d{3})*$/.test(value)) {
    return $item.attr('data-type-message');
  }
  if (value && $item.attr('data-type') === 'url' && !isHttpUrl(value)) return $item.attr('data-type-message');
  if ($item.is('[data-match]') && value && value !== $($item.attr('data-match')).val())
    return $item.attr('data-match-message');
  if ($item.is('[data-min]') && value && String(value).length < Number($item.attr('data-min')))
    return $item.attr('data-min-message');
  return null;
}

export function setItemError($item, message) {
  const $col = $item.children('.ant-form-item-control');
  $col.children('.ant-form-item-explain').remove();
  $item.toggleClass('ant-form-item-has-error ant-form-item-with-help', !!message);
  if (message) {
    $col
      .children('.ant-form-item-control-input')
      .after(
        // Template thiếu lớp -error nên chữ báo lỗi bị xám; Ant Design 4 thật tô đỏ (#f5222d)
        `<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">${escapeHtml(message)}</div></div>`,
      );
  }
}

export function validateItem($item) {
  if (!$item.is('[data-required], [data-type], [data-match], [data-checked], [data-min]')) return true;
  const message = errorOf($item);
  setItemError($item, message);
  return !message;
}

export function validateForm($form) {
  let ok = true;
  $form.find('.ant-form-item:visible').each(function () {
    if (!validateItem($(this))) ok = false;
  });
  if (ok) $form.trigger('form:valid');
  return ok;
}

function initValidation() {
  const events = 'input change select:change picker:change number:change radio:change';
  $(document).on(events, '.ant-form-item', function (e) {
    const $item = $(e.currentTarget);
    // Chỉ kiểm tra item trong cùng (có item bọc một item con không style).
    if ($(e.target).closest('.ant-form-item')[0] !== $item[0]) return;
    // Đang gõ tìm trong select (showSearch) chưa phải là chọn: đừng báo "Chọn khách hàng" khi đang gõ
    if (e.type === 'input' && $(e.target).is('.ant-select-selection-search-input')) return;
    $item.attr('data-touched', '');
    validateItem($item);
    // Ô mà ô khác phụ thuộc (mật khẩu -> nhập lại) thì kiểm tra lại các ô phụ thuộc đã chạm vào.
    const id = $(e.target).attr('id');
    if (id) $(`.ant-form-item[data-match="#${id}"][data-touched]`).each((_, el) => validateItem($(el)));
  });
  $(document).on('submit', '.ant-form', function (e) {
    if (e.isDefaultPrevented()) return; // trang tự xử lý submit (ajax…)
    if (validateForm($(this))) return; // hợp lệ: để trình duyệt gửi form
    e.preventDefault();
    $(this).find('.ant-form-item-has-error').first().find('input, textarea, select').filter(':visible').first().trigger('focus');
  });
}

/*
 * Ô tiền [data-money] (ProCMS): tự thêm dấu chấm hàng nghìn khi gõ, giữ phần lẻ sau dấu phẩy (vd 2.499.999,99).
 * Cùng quy tắc với server (App\Support\Money::parse): phần lẻ là dấu phẩy CUỐI theo sau tối đa 2 chữ số; mọi dấu
 * chấm / phẩy khác là phân cách nghìn — dán "12,500,000" vẫn là 12.500.000 chứ không thành 12,50.
 */
export function formatMoneyInput(value) {
  const v = String(value).replace(/\s/g, '');
  const m = v.match(/^(.*),(\d{0,2})$/); // "1.234," (đang gõ phần lẻ) cũng giữ dấu phẩy
  const [whole, decimals] = m ? [m[1], m[2]] : [v, null];
  const digits = whole.replace(/\D/g, '');
  const integer = digits ? digits.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
  return decimals === null ? integer : `${integer},${decimals}`;
}

function initMoney() {
  $(document).on('input', 'input[data-money]', function () {
    const formatted = formatMoneyInput(this.value);
    if (formatted !== this.value) this.value = formatted;
  });
}

function initialValues() {
  // Control có trạng thái ban đầu đặt bằng script thay vì markup.
  $('.ant-checkbox-input').each((_, el) => syncCheckbox(el));
  $('.ant-radio-group[data-value]').each(function () {
    setRadioValue($(this), $(this).attr('data-value'));
  });
}

export default function initForms() {
  initValidation();
  initMoney();
  initialValues();
}
