import $ from 'jquery';

import { closePopup, isPopupOpenFor, openPopup, plain } from './core';

/*
 * Trang danh sách (khung [data-list], vd dự án, khách hàng), theo template projects.js / users.js nhưng dữ liệu, lọc
 * và phân trang ở server: mỗi lựa chọn là một link. Ở đây chỉ còn:
 *   - nút lọc (pill) [data-filter="x"] mở danh sách chọn render sẵn trong <template id="filter-x">; danh sách dài có ô
 *     gõ để lọc (không phân biệt dấu), Enter mở lựa chọn đầu tiên còn hiện;
 *   - bấm vào dòng .product-row[data-href] (ngoài link / nút) mở trang của dòng đó; dòng có data-row-click="selector"
 *     thì bấm hộ nút đó (vd mở hộp sửa khoản thu);
 *   - bộ chọn khoảng ngày .ant-picker[data-url] lọc theo from / to.
 */


function openFilter($pill, key) {
  if (isPopupOpenFor($pill[0])) return closePopup();
  const template = document.getElementById(`filter-${key}`);
  if (!template) return;
  const { $popup } = openPopup($pill, template.innerHTML, { placementClass: 'ant-dropdown' });
  const $input = $popup.find('.project-filter-search input');
  if (!$input.length) return;

  $input.trigger('focus');
  $input.on('input', function () {
    const q = plain(this.value.trim());
    let shown = 0;
    $popup.find('.ant-dropdown-menu-item:not([data-empty])').each(function () {
      const match = !q || plain(this.textContent).includes(q);
      $(this).toggleClass('hidden', !match);
      if (match) shown++;
    });
    $popup.find('[data-empty]').toggleClass('hidden', shown > 0);
  });
  $input.on('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const link = $popup.find('.ant-dropdown-menu-item:not(.hidden):not([data-empty]) a')[0];
    if (link) link.click();
  });
}

export default function initLists() {
  // Bộ chọn khoảng ngày lọc danh sách (.ant-picker[data-url]): chọn đủ 2 ngày → tải lại với from / to; ⓧ → bỏ lọc ngày.
  // data-url là link danh sách đang lọc, đã bỏ from / to.
  $(document).on('picker:change', '[data-list] .ant-picker[data-url]', function (e, dates) {
    const url = new URL(this.dataset.url, window.location.origin);
    if (dates && dates[0] && dates[1]) {
      const [from, to] = $(this)
        .find('input')
        .toArray()
        .map(i => i.value);
      url.searchParams.set('from', from);
      url.searchParams.set('to', to);
    } else if (dates) {
      return; // mới chọn một đầu
    }
    window.location.href = url.toString();
  });

  $(document).on('click', '[data-list] .filter-pills [data-filter]', function () {
    openFilter($(this).closest('.filter-pill'), $(this).attr('data-filter'));
  });

  // Dòng bảng: bấm chỗ trống mở trang của dòng (data-href) hoặc bấm hộ nút của dòng (data-row-click="selector", vd mở
  // hộp sửa); bôi đen chữ hay bấm vào ô chọn thì không
  $(document).on('click', '[data-list] .product-row[data-href], [data-list] .product-row[data-row-click]', function (e) {
    if ($(e.target).closest('a, button, label, input, [data-tooltip], .ant-table-selection-column').length) return;
    if (window.getSelection().toString()) return;
    if ($(this).attr('data-href')) window.location.href = $(this).attr('data-href');
    else $(this).find($(this).attr('data-row-click')).first().trigger('click');
  });
}
