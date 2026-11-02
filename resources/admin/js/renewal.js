import $ from 'jquery';

import { setItemError } from './forms';
import pageModal from './page-modal';
import { setSelectValue } from './select';

/*
 * Trang Nhắc gia hạn (backend/renewals/index.blade.php + dialogs.blade.php):
 *   - [data-message-open="id"][data-message-kind="hosting|full"]: xem / sửa / sao chép thông báo gửi khách (nội dung soạn
 *     sẵn ở server, khối JSON #renewal-messages);
 *   - [data-renew-open='{"id":..,"hosting":..}']: ghi nhận đã gia hạn — điền sẵn phí, tính tổng thu khi gõ;
 *   - [data-cancel-open='{"id":..,"name":..}']: khách hủy gia hạn.
 * Server báo lỗi thì hộp mở lại đúng dự án (data-reopen = id), giữ dữ liệu vừa nhập.
 */

// 2499999.99 → "2.499.999,99"
const money = n => {
  const [int, dec] = Number(n || 0)
    .toFixed(2)
    .split('.');
  const integer = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return dec === '00' ? integer : `${integer},${dec}`;
};
// "2.499.999,99" → 2499999.99 (cùng quy tắc với ô tiền / Money::parse: phần lẻ là dấu phẩy cuối + tối đa 2 số)
const parseMoney = value => {
  const v = String(value || '').replace(/\s/g, '');
  const m = v.match(/^(.*),(\d{0,2})$/);
  const whole = (m ? m[1] : v).replace(/\D/g, '');
  return Number(`${whole || 0}${m && m[2] ? `.${m[2]}` : ''}`) || 0;
};

function initMessages() {
  const $root = $('#message-modal');
  if (!$root.length) return;
  const modal = pageModal($root);
  const messages = JSON.parse($('#renewal-messages').text() || '{}');
  const $text = $root.find('[data-message-text]');
  const $copied = $root.find('[data-message-copied]');

  $(document).on('click', '[data-message-open]', function () {
    const item = messages[$(this).attr('data-message-open')] || {};
    const kind = $(this).attr('data-message-kind');
    $root.find('[data-message-title]').text(kind === 'full' ? 'Thông báo gia hạn hosting + domain' : 'Thông báo gia hạn hosting');
    $text.val(item[kind] || '');
    $copied.addClass('hidden');
    modal.show($text);
    $text[0].setSelectionRange(0, 0);
  });
  // Sao chép: http (không có navigator.clipboard) thì dùng execCommand
  $root.on('click', '[data-message-copy]', () => {
    const done = () => $copied.removeClass('hidden');
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText($text.val()).then(done);
    } else {
      $text[0].select();
      if (document.execCommand('copy')) done();
    }
  });
  $text.on('input', () => $copied.addClass('hidden'));
}

function initRenew() {
  const $root = $('#renew-modal');
  if (!$root.length) return;
  const modal = pageModal($root);
  const $form = $root.find('#renew-form');
  const field = name => $form.find(`[name="${name}"]`);
  const total = () =>
    $root.find('[data-renew-total]').text(money(parseMoney(field('hosting_amount').val()) + parseMoney(field('domain_amount').val())));

  function open(data, keep = false) {
    $form.attr('action', $root.attr('data-url').replace('__ID__', data.id));
    field('project_id').val(data.id);
    if (data.due) field('due').val(data.due);
    $root.find('[data-renew-name]').text(data.name || '');
    $root.find('[data-renew-due]').text(data.due_label || '');
    $root.find('[data-renew-current]').text(data.current || '');
    $root.find('[data-renew-next]').text(data.next || '');
    if (!keep) {
      field('hosting_amount').val(money(data.hosting));
      field('domain_amount').val(money(data.domain));
      const today = $root.attr('data-default-date');
      const $picker = field('paid_at').val(today).closest('.ant-picker');
      $picker.find('.ant-picker-clear').remove();
      setSelectValue(field('method').prev('.ant-select'), 'bank_transfer');
      field('note').val('');
      $form.find('.ant-form-item').each((_, el) => setItemError($(el), null));
      $root.find('.ant-alert').remove();
    }
    total();
    modal.show(field('hosting_amount'));
  }

  $(document).on('click', '[data-renew-open]', function () {
    open(JSON.parse($(this).attr('data-renew-open')));
  });
  $form.on('input', '[data-money]', total);

  const reopen = $root.attr('data-reopen');
  if (reopen) {
    const source = $(`[data-renew-open]`).filter((_, el) => JSON.parse(el.getAttribute('data-renew-open')).id === Number(reopen))[0];
    open(source ? JSON.parse(source.getAttribute('data-renew-open')) : { id: reopen }, true);
  }
}

function initCancel() {
  const $root = $('#cancel-modal');
  if (!$root.length) return;
  const modal = pageModal($root);
  const $form = $root.find('#cancel-form');

  function open(data, keep = false) {
    $form.attr('action', $root.attr('data-url').replace('__ID__', data.id));
    $form.find('[name=project_id]').val(data.id);
    $root.find('[data-cancel-name]').text(data.name || '');
    if (!keep) {
      $form.find('[name=reason]').val('');
      $form.find('.ant-form-item').each((_, el) => setItemError($(el), null));
    }
    modal.show($form.find('[name=reason]'));
  }

  $(document).on('click', '[data-cancel-open]', function () {
    open(JSON.parse($(this).attr('data-cancel-open')));
  });

  const reopen = $root.attr('data-reopen');
  if (reopen) {
    const source = $(`[data-cancel-open]`).filter((_, el) => JSON.parse(el.getAttribute('data-cancel-open')).id === Number(reopen))[0];
    open(source ? JSON.parse(source.getAttribute('data-cancel-open')) : { id: reopen }, true);
  }
}

export default function initRenewals() {
  initMessages();
  initRenew();
  initCancel();
}
