import $ from 'jquery';

import { closePopup, message } from './core';
import { setItemError, validateForm } from './forms';
import { openModal } from './modal';
import { setSelectValue } from './select';

/*
 * Form thêm / sửa dự án (backend/projects/form.blade.php), theo template project-edit.js nhưng form gửi thẳng lên
 * server. Phần chung của trang sửa (dải chưa lưu, hủy thay đổi…) ở edit-form.js. Ở đây chỉ còn:
 *   - trạng thái thanh toán tính lại khi sửa giá (cùng quy tắc Project::recalculatePaymentStatus);
 *   - "+ Khách hàng mới": hộp tạo nhanh, gửi fetch tới customers.quick-store rồi chọn luôn khách vừa tạo;
 *   - danh sách domain alias: thêm / bỏ dòng.
 */

const PAYMENT = {
  paid: ['Đã thanh toán', 'ant-tag-green'],
  partial: ['Thanh toán một phần', 'ant-tag-gold'],
  unpaid: ['Chưa thanh toán', 'ant-tag-red'],
};

// "2.499.999,99" → 2499999.99
const parseMoney = v => Number(String(v || '').replace(/\./g, '').replace(',', '.')) || 0;
const vnd = n => n.toLocaleString('vi-VN', { maximumFractionDigits: 2 });

function refreshPayment($form) {
  const $box = $('#payment-status');
  const paid = Number($box.attr('data-paid')) || 0;
  // Phát sinh chưa thu: thu đủ giá hợp đồng vẫn là "một phần" (như Project::recalculatePaymentStatus)
  const unpaid = Number($box.attr('data-unpaid')) || 0;
  const price = parseMoney($form.find('[name="price"]').val());
  const currency = $form.find('select[name="currency"]').val() || 'VND';
  const status = price <= 0 || paid <= 0 ? 'unpaid' : paid + 0.01 < price || unpaid > 0 ? 'partial' : 'paid';
  const [label, tag] = PAYMENT[status];
  $box
    .find('[data-payment-tag]')
    .attr('class', `ant-tag m-0 ${status === 'unpaid' && price <= 0 ? '' : tag}`)
    .text(label);
  $('[data-payment-detail]').text(
    price > 0
      ? `Đã thu ${vnd(paid)} / ${vnd(price)} ${currency}`
      : paid > 0
        ? `Đã thu ${vnd(paid)} ${currency}`
        : 'Chưa có giá hợp đồng',
  );
}

function addCustomer() {
  const $root = openModal('#customer-modal');
  const $form = $root.find('#customer-form');
  const $ok = $root.find('.modal-ok');
  $root.find('#qc_name').trigger('focus');

  const create = async () => {
    if (!validateForm($form)) return;
    $ok.addClass('ant-btn-loading').prop('disabled', true);
    try {
      const res = await fetch($form.attr('action'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('#project-form [name="_token"]').val() },
        body: new FormData($form[0]),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        // Lỗi validate của server: hiện dưới đúng ô
        const errors = data.errors || { name: [data.message || 'Không tạo được khách hàng.'] };
        Object.entries(errors).forEach(([field, list]) => {
          const $item = $form.find(`[name="${field}"]`).closest('.ant-form-item');
          setItemError($item.length ? $item : $form.find('.ant-form-item').first(), list[0]);
        });
        return;
      }
      // Thêm vào ô chọn khách hàng và chọn luôn
      const $native = $('select[name="customer_id"]');
      const $select = $native.prev('.ant-select');
      const label = `${data.label} (0 dự án)`;
      $native.append(new Option(label, data.id));
      const options = JSON.parse($select.attr('data-options') || '[]');
      options.unshift({ value: String(data.id), label });
      $select.attr('data-options', JSON.stringify(options));
      setSelectValue($select, String(data.id));
      $root.data('close')();
      message(`Đã tạo khách hàng “${data.label}”`);
    } catch {
      setItemError($form.find('.ant-form-item').first(), 'Lỗi kết nối, thử lại.');
    } finally {
      $ok.removeClass('ant-btn-loading').prop('disabled', false);
    }
  };
  $ok.on('click', create);
  $form.on('submit', e => {
    e.preventDefault();
    create();
  });
}

/*
 * Domain alias (cùng website, chung source code + database): "+ Thêm domain alias" thêm một dòng (domain + ghi chú) từ
 * mẫu #domain-alias-template, ⊖ bỏ dòng. Tên ô domains[n][…] với n tăng dần (không trùng dòng đang có). "Hủy thay đổi"
 * (form.reset) đưa danh sách về như lúc mở trang — làm ngay trong sự kiện reset để edit-form.js chụp lại đúng.
 */
function initDomainAliases($form) {
  const $list = $form.find('[data-domain-list]');
  if (!$list.length) return;
  const initial = $list.html();
  const template = document.getElementById('domain-alias-template').innerHTML;
  let next = Number($list.attr('data-next')) || 0;

  $form.on('click', '[data-domain-add]', () => {
    const $row = $(template.replaceAll('__I__', String(next++)));
    $list.append($row);
    $row.find('input').first().trigger('focus');
    $form.trigger('change');
  });
  $form.on('click', '[data-domain-remove]', function () {
    closePopup(); // tooltip của nút đang mở
    $(this).closest('[data-domain-row]').remove();
    $form.trigger('change');
  });
  $form.on('reset', () => $list.html(initial));
}

export default function initProjectForm() {
  const form = document.getElementById('project-form');
  if (!form) return;
  const $form = $(form);

  // Dải chưa lưu, hủy thay đổi, chặn bấm đúp…: edit-form.js (form có data-edit-form)
  $form.on('edit-form:refresh', () => refreshPayment($form));
  refreshPayment($form);
  initDomainAliases($form);

  $('#new-customer').on('click keydown', e => {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    addCustomer();
  });
}
