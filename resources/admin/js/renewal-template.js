import $ from 'jquery';

/*
 * Trang Mẫu thông báo gia hạn (backend/renewals/template.blade.php): xem trước từng mẫu với dữ liệu thật (khối JSON
 * #template-preview-values), bấm biến để chèn vào chỗ con trỏ của ô đang soạn, "Khôi phục mặc định" (hoàn tác được
 * bằng "Hủy thay đổi" — edit-form.js).
 */

export default function initTemplateForm() {
  const $form = $('#template-form');
  if (!$form.length) return;
  const values = JSON.parse($('#template-preview-values').text() || '{}');
  const $areas = $form.find('[data-template]');
  let last = $areas[0];

  const render = area => {
    let text = area.value;
    Object.keys(values).forEach(key => {
      text = text.split(key).join(values[key]);
    });
    $form.find(`[data-preview="${area.name}"]`).text(text);
  };
  const renderAll = () => $areas.each((_, area) => render(area));

  $areas.on('input', function () {
    render(this);
  });
  $areas.on('focus', function () {
    last = this;
  });
  // mousedown: giữ con trỏ trong ô đang soạn (bấm nút không làm ô mất focus / vị trí con trỏ)
  $form.on('mousedown', '[data-insert]', e => e.preventDefault());
  $form.on('click', '[data-insert]', function () {
    last.focus();
    last.setRangeText($(this).attr('data-insert'), last.selectionStart, last.selectionEnd, 'end');
    $(last).trigger('input');
  });
  $form.on('click', '[data-reset]', function () {
    const area = document.getElementById($(this).attr('data-reset'));
    area.value = $(this).attr('data-default');
    $(area).trigger('input');
  });
  $form.on('edit-form:refresh', renderAll);
  renderAll();
}
