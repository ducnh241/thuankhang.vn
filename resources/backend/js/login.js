import $ from 'jquery';

import { icon } from './core';

/*
 * Trang đăng nhập (auth/login.blade.php). Form gửi thẳng lên server (POST /backend/login); ô trống và email sai
 * định dạng do forms.js chặn trước. Lỗi đăng nhập (sai mật khẩu, bị khoá tạm…) do server trả về và hiện ở khung báo lỗi.
 */

// Nút đang tải như Ant Design: icon quay, không bấm được nữa (chống gửi 2 lần).
function setLoading($btn, loading) {
  $btn.toggleClass('ant-btn-loading', loading).prop('disabled', loading);
  $btn.find('.ant-btn-loading-icon').remove();
  if (loading) $btn.prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
  const $label = $btn.find('[data-label]');
  $label.text(loading ? $label.attr('data-loading-text') : $label.attr('data-text'));
}

export default function initLogin() {
  const $form = $('#login_form');
  if (!$form.length) return;
  const $btn = $('#login-submit');

  $form.on('form:valid', () => setLoading($btn, true));

  // Bấm Back quay lại trang (trình duyệt lấy trang từ bộ nhớ đệm): trả nút về trạng thái thường.
  window.addEventListener('pageshow', e => {
    if (e.persisted) setLoading($btn, false);
  });
}
