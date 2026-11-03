import $ from 'jquery';

import { enhanceSelects } from './select';

/*
 * ProCMS (ducnh.com): chép từ template-admin/src/js/modal.js, thêm Esc để đóng như Ant Design (keyboard: true).
 *
 * Modals are kept in <template> elements. A trigger with data-modal="#template-id" opens a fresh copy;
 * [data-modal-close] buttons, the × button and the mask close it. `modal:open` fires with the modal element.
 */
export function openModal(selector, trigger) {
  const $root = $($(selector).html()).appendTo('body');
  enhanceSelects($root);
  const $modal = $root.find('.ant-modal');
  if (trigger) {
    const t = trigger.getBoundingClientRect();
    const m = $modal[0].getBoundingClientRect();
    $modal.css('transform-origin', `${t.left + t.width / 2 - m.left}px ${t.top + t.height / 2 - m.top}px`);
  }
  $root.find('.ant-modal-mask').addClass('fade-appear fade-appear-active');
  $modal.addClass('zoom-appear zoom-appear-active');
  setTimeout(() => {
    $root.find('.ant-modal-mask').removeClass('fade-appear fade-appear-active');
    $modal.removeClass('zoom-appear zoom-appear-active');
  }, 300);

  const close = () => {
    $root.find('.ant-modal-mask').addClass('fade-leave fade-leave-active');
    $modal.addClass('zoom-leave zoom-leave-active');
    setTimeout(() => $root.remove(), 200);
  };
  $root.on('click', '.ant-modal-close, [data-modal-close]', close);
  $root.on('mousedown', '.ant-modal-wrap', function (e) {
    if (e.target === this) close();
  });
  $root.on('keydown', e => {
    if (e.key === 'Escape') close();
  });
  $root.data('close', close);
  $(document).trigger('modal:open', [$root, selector]);
  // Focus moves into the dialog (the trigger loses its focus style), as in Ant Design.
  $root.find('.ant-modal-wrap').trigger('focus');
  return $root;
}

export default function initModals() {
  $(document).on('click', '[data-modal]', function () {
    openModal($(this).attr('data-modal'), this);
  });
}
