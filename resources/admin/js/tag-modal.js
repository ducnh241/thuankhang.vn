import $ from 'jquery';

import { icon } from './core';
import { setItemError } from './forms';

/*
 * Hộp thoại thêm / sửa tag (backend/tags/dialog.blade.php, Modal Ant Design có sẵn trong trang — như payment-modal.js).
 * Nút [data-tag-open] mở để thêm; có data-tag='{"id":..}' thì mở để sửa tag đó (điền sẵn). Chọn màu bằng các ô tròn
 * hoặc "Màu khác" (ô chọn màu của trình duyệt); xem trước tag ngay dưới; slug để trống thì gợi ý slug sẽ tự tạo.
 * Server trả lỗi validate thì trang tự mở lại hộp với dữ liệu vừa nhập (data-reopen).
 */

// Gần giống Str::slug của Laravel cho tiếng Việt: bỏ dấu, đ → d, ký tự khác chữ / số thành "-"
const slugify = text =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[đĐ]/g, 'd')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

export default function initTagModal() {
  const $root = $('#tag-modal');
  if (!$root.length) return;
  const $form = $root.find('#tag-form');
  const form = $form[0];
  const $modal = $root.find('.ant-modal');
  const $mask = $root.find('.ant-modal-mask');
  const $name = $form.find('[name=name]');
  const $slug = $form.find('[name=slug]');
  const $color = $form.find('[name=color]');
  const $custom = $color.closest('.tag-swatch-custom');
  const swatches = $form
    .find('[data-swatch]')
    .toArray()
    .map(el => el.getAttribute('data-swatch'));

  // Vẽ lại ô màu đang chọn, ô "Màu khác", tag xem trước, gợi ý slug
  const sync = () => {
    const color = $color.val().toLowerCase();
    const preset = swatches.includes(color);
    $form.find('[data-swatch]').each(function () {
      $(this).attr('aria-checked', $(this).attr('data-swatch') === color);
    });
    $custom.attr('aria-checked', !preset).css('background', preset ? '' : color);
    $root.find('[data-tag-preview] .project-tag-dot').css('background', color);
    $root.find('[data-tag-preview-name]').text($name.val().trim() || 'Tên tag');
    $slug.attr('placeholder', slugify($name.val()) || 'tu-dong-tao-tu-ten');
  };

  function show() {
    $root.removeClass('hidden');
    $mask.addClass('fade-appear fade-appear-active');
    $modal.addClass('zoom-appear zoom-appear-active');
    setTimeout(() => {
      $mask.removeClass('fade-appear fade-appear-active');
      $modal.removeClass('zoom-appear zoom-appear-active');
    }, 300);
    $name.trigger('focus');
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

  // keep: giữ nguyên dữ liệu đang có trong form (mở lại sau lỗi validate)
  function open(tag, keep = false) {
    const editing = tag && tag.id;
    if (!keep) {
      $name.val(editing ? tag.name : '');
      $slug.val(editing ? tag.slug : '');
      $color.val(editing && /^#[0-9a-f]{6}$/i.test(tag.color || '') ? tag.color : $root.attr('data-default-color'));
      $form.find('.ant-form-item').each((_, el) => setItemError($(el), null));
    }
    form.action = editing ? $root.attr('data-update-url').replace('__ID__', tag.id) : $root.attr('data-store-url');
    $form.find('[name=_method]').val(editing ? 'PUT' : 'POST');
    $root.find('[data-tag-title]').text(editing ? 'Sửa tag' : 'Thêm tag');
    sync();
    show();
  }

  $(document).on('click', '[data-tag-open]', function (e) {
    e.preventDefault();
    const raw = $(this).attr('data-tag');
    open(raw ? JSON.parse(raw) : null);
  });
  $form.on('click', '[data-swatch]', function () {
    $color.val($(this).attr('data-swatch'));
    sync();
  });
  $name.on('input', sync);
  $color.on('input change', sync);
  $root.on('click', '[data-tag-close]', hide);
  // Bấm ra nền mờ để đóng — chỉ khi nhấn chuột cũng ở nền (bôi đen chữ kéo ra ngoài không làm mất dữ liệu)
  let downOnWrap = false;
  $root.on('mousedown', '.ant-modal-wrap', function (e) {
    downOnWrap = e.target === this;
  });
  $root.on('click', '.ant-modal-wrap', function (e) {
    if (e.target === this && downOnWrap) hide();
  });
  // Esc: đang mở popup (tooltip…) thì core.js đóng nó trước — bắt ở pha capture để biết popup còn mở không
  document.addEventListener(
    'keydown',
    e => {
      if (e.key === 'Escape' && !$root.hasClass('hidden') && !$('.popup-root').length) hide();
    },
    true,
  );

  // Chống bấm lưu 2 lần tạo 2 tag (gắn sau forms.js nên biết form có bị chặn vì còn lỗi không)
  $(document).on('submit', '#tag-form', e => {
    if (e.isDefaultPrevented()) return;
    const $submit = $root.find('[data-tag-submit]');
    if ($submit.prop('disabled')) {
      e.preventDefault();
      return;
    }
    $submit
      .addClass('ant-btn-loading')
      .prop('disabled', true)
      .prepend(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`);
  });

  const reopen = $root.attr('data-reopen');
  if (reopen) open(reopen === 'new' ? null : { id: reopen }, true);
}
