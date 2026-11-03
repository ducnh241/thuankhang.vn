import $ from 'jquery';

import { syncCheckbox } from './controls';

/*
 * Bảng quyền ở trang Nhóm quyền (backend/groups/index.blade.php): mỗi dòng một mục, cột Xem / Thêm / Sửa / Xóa.
 *   - tích Thêm / Sửa / Xóa (hay việc riêng của mục) thì tự tích Xem của dòng đó; bỏ Xem thì bỏ luôn các việc;
 *   - ô dưới tên cột chọn / bỏ cả cột (nửa chọn khi cột mới tích một phần);
 *   - "Chọn tất cả" / "Bỏ chọn tất cả" cho mọi ô được phép cấp, kể cả công tắc đăng nhập;
 *   - tắt công tắc đăng nhập: cả bảng mờ đi và khóa (inert) — các ô vẫn giữ nguyên, bật lại là dùng lại được.
 * Chỉ đụng các ô không bị khóa (quyền người sửa không có). Thay đổi chưa lưu, "Hủy thay đổi": edit-form.js.
 */

export default function initGroupForm() {
  const $form = $('#group-form[data-edit-form]');
  if (!$form.length) return;
  const $table = $('#perm-table');
  const $all = $('#perm-all');
  const $login = $form.find('#perm-login').siblings('.switch-input');
  const $hint = $('#perm-login-hint');

  const set = (el, checked) => {
    el.checked = checked;
    syncCheckbox(el);
  };
  const setLogin = checked => {
    if ($login.prop('disabled')) return;
    $login.prop('checked', checked);
    $('#perm-login').toggleClass('ant-switch-checked', checked).attr('aria-checked', checked);
  };
  const enabled = () => $table.find('.perm-module, .perm-action').not(':disabled');
  const rowOf = el => $(el).closest('.perm-row');
  const columnInputs = col =>
    $table.find(col === '_' ? '.perm-module' : `.perm-action[data-action="${col}"]`).not(':disabled');

  // Tô dòng đang bật, trạng thái ô chọn cả cột, chữ "Chọn tất cả", dòng nhắc của công tắc đăng nhập
  const sync = () => {
    $table.find('.perm-row').each(function () {
      $(this).toggleClass('is-on', $(this).find('.perm-module').prop('checked'));
    });
    $table.find('[data-perm-col]').each(function () {
      const $inputs = columnInputs($(this).attr('data-perm-col'));
      const on = $inputs.filter(':checked').length;
      const full = on > 0 && on === $inputs.length;
      this.checked = full;
      this.indeterminate = on > 0 && !full;
      this.disabled = !$inputs.length;
      syncCheckbox(this);
      $(this)
        .closest('.ant-checkbox')
        .toggleClass('ant-checkbox-indeterminate', this.indeterminate)
        .toggleClass('ant-checkbox-disabled', this.disabled);
    });
    const everything =
      enabled().not(':checked').length === 0 && ($login.prop('disabled') || $login.prop('checked'));
    $all.text($all.attr(everything ? 'data-clear' : 'data-select'));
    const login = $login.prop('checked');
    $hint.text($hint.attr(login ? 'data-on' : 'data-off'));
    $('#perm-body').toggleClass('is-off', !login).prop('inert', !login);
  };

  $table.on('change', '.perm-action', function () {
    if (this.checked) rowOf(this).find('.perm-module').not(':disabled').each((_, el) => set(el, true));
  });
  $table.on('change', '.perm-module', function () {
    if (!this.checked) rowOf(this).find('.perm-action').not(':disabled').each((_, el) => set(el, false));
  });
  // Ô chọn cả cột: sự kiện `input` (đến trước `change`) không cho lên form — edit-form.js sẽ gọi sync() vẽ lại ô này
  // theo các ô cũ, xóa mất lần bấm vừa rồi trước khi kịp áp cho cả cột
  $table.on('input', '[data-perm-col]', e => e.stopPropagation());
  $table.on('change', '[data-perm-col]', function () {
    const col = $(this).attr('data-perm-col');
    const checked = this.checked;
    columnInputs(col).each((_, el) => {
      set(el, checked);
      if (col === '_' && !checked) rowOf(el).find('.perm-action').not(':disabled').each((__, a) => set(a, false));
      if (col !== '_' && checked) rowOf(el).find('.perm-module').not(':disabled').each((__, m) => set(m, true));
    });
  });
  $all.on('click', e => {
    e.preventDefault();
    const everything = $all.text() === $all.attr('data-clear');
    enabled().each((_, el) => set(el, !everything));
    setLogin(!everything);
    $form.trigger('change');
  });
  $form.on('edit-form:refresh', sync);
  sync();
}
