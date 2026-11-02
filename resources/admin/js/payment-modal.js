import $ from 'jquery';

import { setRadioValue } from './controls';
import { icon } from './core';
import { setItemError } from './forms';
import { setSelectValue } from './select';

/*
 * Hộp thoại thêm / sửa khoản thu (backend/payments/dialog.blade.php, Modal Ant Design có sẵn trong trang).
 * Nút [data-payment-open] mở để thêm; có data-payment='{"id":..}' thì mở để sửa khoản đó (điền sẵn), không có id thì
 * là giá trị điền sẵn cho khoản mới (vd ghi nợ phát sinh); thêm data-payment-receive = ghi nhận đã thu một khoản phát
 * sinh chưa thu. Server trả lỗi validate thì trang tự mở lại hộp với dữ liệu vừa nhập (data-reopen).
 */

// 2499999.99 → "2.499.999,99"
const money = n => {
  const [int, dec] = Number(n || 0)
    .toFixed(2)
    .split('.');
  const integer = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return dec === '00' ? integer : `${integer},${dec}`;
};

export default function initPaymentModal() {
  const $root = $('#payment-modal');
  if (!$root.length) return;
  const $form = $root.find('#payment-form');
  const form = $form[0];
  const $modal = $root.find('.ant-modal');
  const $mask = $root.find('.ant-modal-mask');
  const field = name => $form.find(`[name="${name}"]`);
  const setSelect = (name, value) => {
    const $select = field(name).prev('.ant-select');
    if ($select.length) setSelectValue($select, value || undefined);
  };
  const $status = $root.find('[data-payment-status] .ant-radio-group');
  const setStatus = value => setRadioValue($status, value);
  let mode = 'add'; // add | edit | receive
  // Loại Phát sinh mới có Trạng thái; chưa thu: ô ngày là ngày phát sinh, chưa có phương thức thanh toán
  const syncStatus = () => {
    const extra = field('type').val() === 'extra';
    const unpaid = extra && $status.find('input:checked').val() === 'unpaid';
    const label = unpaid ? 'Ngày phát sinh' : 'Ngày thu';
    $root.find('[data-payment-status]').toggleClass('hidden', !extra);
    $root.find('[data-payment-method]').toggleClass('hidden', unpaid);
    $root.find('[data-payment-date-label]').text(label).attr('title', label);
    $root.find('[data-payment-date]').attr('data-required', `Chọn ${label.toLowerCase()}.`);
    const title = { add: unpaid ? 'Ghi nợ phát sinh' : 'Thêm khoản thu', edit: 'Sửa khoản thu', receive: 'Ghi nhận đã thu' };
    $root.find('[data-payment-title]').text(title[mode]);
  };
  field('type').on('change', syncStatus);
  $status.on('radio:change', syncStatus);
  const setDate = value => {
    const $picker = field('paid_at').val(value).closest('.ant-picker');
    $picker.find('.ant-picker-clear').remove();
    if (value) $picker.append(`<span class="ant-picker-clear" role="button">${icon('close-circle')}</span>`);
  };

  function show() {
    $root.removeClass('hidden');
    $mask.addClass('fade-appear fade-appear-active');
    $modal.addClass('zoom-appear zoom-appear-active');
    setTimeout(() => {
      $mask.removeClass('fade-appear fade-appear-active');
      $modal.removeClass('zoom-appear zoom-appear-active');
    }, 300);
    field('amount').trigger('focus');
  }

  function hide() {
    if ($root.hasClass('hidden')) return;
    $mask.addClass('fade-leave fade-leave-active');
    $modal.addClass('zoom-leave zoom-leave-active');
    setTimeout(() => {
      $root.addClass('hidden');
      $mask.removeClass('fade-leave fade-leave-active');
      $modal.removeClass('zoom-leave zoom-leave-active');
    }, 200);
  }

  // keep: giữ nguyên dữ liệu đang có trong form (mở lại sau lỗi validate); receive: ghi nhận đã thu khoản phát sinh
  function open(payment, keep = false, receive = false) {
    if (!keep) {
      field('amount').val('');
      field('note').val('');
      setDate($root.attr('data-default-date'));
      setSelect('type', 'new');
      setStatus('paid');
      setSelect('method', 'bank_transfer');
      if (field('project_id').is('select')) setSelect('project_id', undefined);
      $form.find('.ant-form-item').each((_, el) => setItemError($(el), null));
    }
    const editing = payment && payment.id;
    form.action = editing ? $root.attr('data-update-url').replace('__ID__', payment.id) : $root.attr('data-store-url');
    field('_method').val(editing ? 'PUT' : 'POST');
    if (payment && !keep) {
      if (editing) {
        field('amount').val(money(payment.amount));
        setDate(payment.paid_at || '');
        setSelect('method', payment.method || '');
        field('note').val(payment.note || '');
        if (field('project_id').is('select')) setSelect('project_id', String(payment.project_id));
      }
      setSelect('type', payment.type || 'new');
      setStatus(payment.status || 'paid');
      // Khách vừa trả: đổi sang Đã thu, ngày thu mặc định hôm nay
      if (receive) {
        setStatus('paid');
        setDate($root.attr('data-default-date'));
        setSelect('method', 'bank_transfer');
      }
    }
    mode = receive ? 'receive' : editing ? 'edit' : 'add';
    $root.find('[data-payment-submit-text]').text(receive ? 'Xác nhận đã thu' : 'Lưu khoản thu');
    syncStatus();
    show();
  }

  $(document).on('click', '[data-payment-open]', function (e) {
    e.preventDefault();
    const raw = $(this).attr('data-payment');
    open(raw ? JSON.parse(raw) : null, false, $(this).is('[data-payment-receive]'));
  });
  $root.on('click', '[data-payment-close]', hide);
  // Bấm ra nền mờ để đóng — chỉ khi nhấn chuột cũng ở nền (bôi đen chữ kéo ra ngoài không làm mất dữ liệu)
  let downOnWrap = false;
  $root.on('mousedown', '.ant-modal-wrap', function (e) {
    downOnWrap = e.target === this;
  });
  $root.on('click', '.ant-modal-wrap', function (e) {
    if (e.target === this && downOnWrap) hide();
  });
  // Esc: đang mở danh sách chọn / lịch thì Esc chỉ đóng nó (core.js), lần sau mới đóng hộp — bắt ở pha capture để
  // xem được popup còn mở hay không trước khi core.js đóng nó
  document.addEventListener(
    'keydown',
    e => {
      if (e.key === 'Escape' && !$root.hasClass('hidden') && !$('.popup-root').length) hide();
    },
    true,
  );

  // Chống bấm lưu 2 lần tạo 2 khoản thu (gắn sau forms.js nên biết form có bị chặn vì còn lỗi không)
  $(document).on('submit', '#payment-form', e => {
    if (e.isDefaultPrevented()) return;
    $root
      .find('[data-payment-submit]')
      .addClass('ant-btn-loading')
      .prop('disabled', true)
      .prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
  });

  const reopen = $root.attr('data-reopen');
  if (reopen) open(reopen === 'new' ? null : { id: reopen }, true);
}
