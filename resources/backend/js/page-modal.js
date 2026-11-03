import $ from 'jquery';

import { icon } from './core';

/*
 * Modal Ant Design đặt sẵn trong trang (.ant-modal-root.hidden — để server mở lại được khi báo lỗi), dùng chung cho các
 * hộp thoại của trang: hiện / ẩn có hiệu ứng như template, nút [data-modal-dismiss] để đóng, bấm nền mờ để đóng (chỉ khi
 * nhấn chuột cũng ở nền — bôi đen chữ kéo ra ngoài không mất dữ liệu), Esc để đóng (đang mở danh sách chọn / lịch thì Esc
 * chỉ đóng cái đó trước), nút [data-modal-submit] chuyển sang "đang lưu" khi form hợp lệ được gửi (chống bấm đúp).
 * Trả về { show, hide, isOpen }.
 */
export default function pageModal($root) {
  const $modal = $root.find('.ant-modal');
  const $mask = $root.find('.ant-modal-mask');
  const isOpen = () => !$root.hasClass('hidden');

  function show(focus) {
    $root.removeClass('hidden');
    $mask.addClass('fade-appear fade-appear-active');
    $modal.addClass('zoom-appear zoom-appear-active');
    setTimeout(() => {
      $mask.removeClass('fade-appear fade-appear-active');
      $modal.removeClass('zoom-appear zoom-appear-active');
    }, 300);
    if (focus) $(focus).trigger('focus');
  }

  function hide() {
    if (!isOpen()) return;
    $mask.addClass('fade-leave fade-leave-active');
    $modal.addClass('zoom-leave zoom-leave-active');
    setTimeout(() => {
      $root.addClass('hidden');
      $mask.removeClass('fade-leave fade-leave-active');
      $modal.removeClass('zoom-leave zoom-leave-active');
    }, 200);
  }

  $root.on('click', '[data-modal-dismiss]', hide);
  let downOnWrap = false;
  $root.on('mousedown', '.ant-modal-wrap', function (e) {
    downOnWrap = e.target === this;
  });
  $root.on('click', '.ant-modal-wrap', function (e) {
    if (e.target === this && downOnWrap) hide();
  });
  // Bắt ở pha capture để xem popup (danh sách chọn, lịch) còn mở không trước khi core.js đóng nó
  document.addEventListener(
    'keydown',
    e => {
      if (e.key === 'Escape' && isOpen() && !$('.popup-root').length) hide();
    },
    true,
  );

  // Gắn sau forms.js (cùng ở document) nên biết form có bị chặn vì còn lỗi không
  const $forms = $root.find('form');
  $forms.each((_, form) => {
    $(document).on('submit', `#${form.id}`, e => {
      if (e.isDefaultPrevented()) return;
      const $submit = $root.find('[data-modal-submit]');
      if ($submit.prop('disabled')) {
        e.preventDefault();
        return;
      }
      $submit
        .addClass('ant-btn-loading')
        .prop('disabled', true)
        .prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
    });
  });

  return { show, hide, isOpen };
}
