import $ from 'jquery';

import { plain } from './core';

/*
 * Trang thêm / sửa hosting (backend/hostings/form.blade.php): danh sách dự án chạy trên hosting — lọc ngay trên trang
 * (không dấu, theo tên / domain / khách / #id), "Chỉ hiện đã chọn", đếm số đã chọn. Thay đổi chưa lưu: edit-form.js.
 */

export default function initHostingForm() {
  const $card = $('#hosting-projects');
  if (!$card.length) return;
  const $search = $card.find('[data-project-search]');
  const $only = $card.find('[data-only-picked]');
  const $rows = $card.find('[data-project-row]');
  const terms = $rows.toArray().map(row => plain(row.getAttribute('data-search')));

  const apply = () => {
    const q = plain($search.val().trim());
    let shown = 0;
    $rows.each((i, row) => {
      const visible = (!q || terms[i].includes(q)) && (!$only.prop('checked') || $(row).find('input').prop('checked'));
      $(row).toggleClass('hidden', !visible);
      if (visible) shown++;
    });
    $card.find('[data-project-empty]').toggleClass('hidden', shown > 0 || !$rows.length);
    $card.find('[data-picked-count]').text($rows.find('input:checked').length);
  };

  $search.on('input', apply);
  // Enter trong ô lọc không gửi form
  $search.on('keydown', e => {
    if (e.key === 'Enter') e.preventDefault();
  });
  $only.on('change', apply);
  // Tích / bỏ một dự án chỉ đổi số đếm (đang "Chỉ hiện đã chọn" thì dòng vừa bỏ vẫn hiện tới lần lọc sau)
  $card.on('change', '[data-project-row] input', () => $card.find('[data-picked-count]').text($rows.find('input:checked').length));
  $('#hosting-form').on('edit-form:refresh', () => $card.find('[data-picked-count]').text($rows.find('input:checked').length));
  apply();
}
