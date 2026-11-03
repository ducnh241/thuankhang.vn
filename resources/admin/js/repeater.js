import $ from 'jquery';

/*
 * Bảng nhiều dòng sửa tại chỗ (vd cấp bậc, bậc KPI): khung [data-repeater] có <tbody data-repeater-rows> và
 * <template data-repeater-template> (một dòng mới, tên ô dùng __INDEX__). Nút [data-repeater-add] thêm dòng,
 * [data-repeater-remove] bỏ dòng chứa nó. Thêm / bỏ dòng thì form coi như đã đổi (data-dirty): "Hủy thay đổi" tải lại
 * trang thay vì form.reset() (reset không gỡ được dòng đã thêm).
 */
let counter = 0;

export default function initRepeaters() {
  $(document).on('click', '[data-repeater] [data-repeater-add]', function () {
    const $root = $(this).closest('[data-repeater]');
    const html = $root.find('template[data-repeater-template]').html().replaceAll('__INDEX__', `new${(counter += 1)}`);
    const $row = $(html.trim());
    $root.find('[data-repeater-rows]').append($row);
    $root.find('[data-repeater-empty]').addClass('hidden');
    $row.find('input:not([type=hidden])').first().trigger('focus');
    changed($root);
  });

  $(document).on('click', '[data-repeater] [data-repeater-remove]', function () {
    const $root = $(this).closest('[data-repeater]');
    $(this).closest('[data-repeater-row]').remove();
    if (!$root.find('[data-repeater-row]').length) $root.find('[data-repeater-empty]').removeClass('hidden');
    changed($root);
  });
}

function changed($root) {
  const form = $root.closest('form')[0];
  if (!form) return;
  form.setAttribute('data-dirty', '');
  $(form).trigger('change');
}
