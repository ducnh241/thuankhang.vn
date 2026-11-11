import $ from 'jquery';

import { escapeHtml, icon, message } from './core';
import { formatMoneyInput } from './forms';
import { toSlug } from './tree-page';

/*
 * Trang thêm / sửa sản phẩm (backend/products/form.blade.php):
 *   - đường dẫn tự theo tên cho tới khi sửa tay (như page-form.js);
 *   - mô tả chi tiết: trình soạn thảo Tiptap (editor.js) ghi HTML vào ô ẩn description;
 *   - giá trước VAT và cơ sở hoa hồng tính lại khi gõ giá / VAT (công thức như Product::priceBeforeVat, đặc tả 8.3);
 *   - ảnh: thêm nhiều ảnh (gom vào ô images[] bằng DataTransfer), bỏ ảnh, ★ đưa ảnh đang có lên đầu (ảnh đại diện).
 *     Đổi ảnh thì form coi như đã đổi (data-dirty): "Hủy thay đổi" tải lại trang.
 */

const parseMoney = v => Number(String(v).replace(/\D/g, '')) || 0;
const parsePercent = v => {
  const n = Number(String(v).trim().replace(',', '.'));
  return Number.isFinite(n) && n >= 0 && n <= 100 ? n : null;
};

function initPrice($form) {
  const $box = $form.find('#price-breakdown');
  const fee = Number($box.attr('data-fee')) || 0;
  const refresh = () => {
    const price = parseMoney($('#product_price').val());
    const vat = parsePercent($('#product_vat').val());
    const ok = $('#product_price').val().trim() !== '' && vat !== null;
    // Số nguyên như server: floor(giá × 10000 / (10000 + VAT×100)), rồi × (1 − phí)
    const before = ok ? Math.floor((price * 10000) / (10000 + Math.round(vat * 100))) : null;
    const base = before === null ? null : Math.floor((before * (10000 - Math.round(fee * 100))) / 10000);
    $box.find('[data-price-before-vat]').text(before === null ? '—' : formatMoneyInput(String(before)));
    $box.find('[data-commission-base]').text(base === null ? '—' : formatMoneyInput(String(base)));
  };
  $form.on('input change', '#product_price, #product_vat', refresh);
  $form.on('reset', () => setTimeout(refresh));
  refresh();
}

function initGallery($form) {
  const $field = $form.find('[data-gallery]');
  if (!$field.length) return;
  const $list = $field.find('.product-gallery');
  const $add = $list.find('.ant-upload-select');
  const input = $field.find('[data-gallery-input]')[0];
  const max = Number($field.attr('data-max'));
  const maxSize = Number($field.attr('data-max-size'));
  const files = new Map(); // thẻ ảnh mới → File

  const sync = () => {
    const dt = new DataTransfer();
    $list.find('[data-gallery-item][data-new]').each(function () {
      dt.items.add(files.get(this));
    });
    input.files = dt.files;
    $add.toggleClass('hidden', $list.find('[data-gallery-item]').length >= max);
    $form[0].setAttribute('data-dirty', '');
    $form.trigger('change');
  };

  $list.on('click keydown', '[data-gallery-pick]', e => {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    e.stopPropagation();
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.multiple = true;
    picker.accept = input.accept;
    picker.addEventListener('change', () => {
      let room = max - $list.find('[data-gallery-item]').length;
      [...picker.files].forEach(file => {
        if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return message(`${file.name}: chọn ảnh PNG, JPG hoặc WEBP.`, 'error');
        if (file.size > maxSize) return message(`${file.name}: mỗi ảnh tối đa 4 MB.`, 'error');
        if (room <= 0) return message(`Tối đa ${max} ảnh.`, 'error');
        room -= 1;
        const src = URL.createObjectURL(file);
        const $card = $(`<div class="ant-upload-list-picture-card-container relative" data-gallery-item data-new>
          <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
            <div class="ant-upload-list-item-info"><span><a class="ant-upload-list-item-thumbnail" href="${src}" target="_blank" rel="noopener noreferrer"
              ><img src="${src}" alt="${escapeHtml(file.name)}" class="ant-upload-list-item-image" /></a></span></div>
            <span class="ant-upload-list-item-actions"
              ><a href="${src}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">${icon('eye')}</a
              ><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only" title="Bỏ ảnh" data-gallery-remove>${icon('delete')}</button></span>
          </div>
          <span class="ant-tag ant-tag-blue product-gallery-cover">Ảnh đại diện</span>
        </div>`);
        files.set($card[0], file);
        $add.before($card);
      });
      sync();
    });
    picker.click();
  });

  $list.on('click', '[data-gallery-remove]', function () {
    const card = $(this).closest('[data-gallery-item]')[0];
    files.delete(card);
    card.remove();
    sync();
  });

  // ★: ảnh đang có lên đầu danh sách (thứ tự keep_images[] = thứ tự lưu)
  $list.on('click', '[data-gallery-cover]', function () {
    $list.prepend($(this).closest('[data-gallery-item]'));
    sync();
  });
}

// Chữ thuần của HTML mô tả (DOMParser: không chạy script, không tải ảnh)
const textOf = html => new DOMParser().parseFromString(html || '', 'text/html').body.textContent.replace(/\s+/g, ' ').trim();

// Hiển thị trên Google: xem trước + đếm ký tự; nút bút mở / đóng ô tiêu đề, mô tả SEO, đường dẫn
function initSeo($form) {
  const $slug = $('#product_slug');
  const $name = $('#product_name');
  const refresh = () => {
    const slug = toSlug($slug.val()) || toSlug($name.val()) || 'duong-dan';
    $('#seo-preview-url').text($('#seo-preview-url').attr('data-base') + slug);
    $('#seo-full-url').text($('#seo-full-url').attr('data-base') + slug);
    $('#seo-preview-title').text($('#product_meta_title').val().trim() || $name.val().trim() || 'Tên sản phẩm');
    const description =
      $('#product_meta_description').val().trim() ||
      $('#product_short_description').val().trim() ||
      textOf($form.find('[name=description]').val()).slice(0, 160);
    $('#seo-preview-description').text(description || 'Thêm mô tả ngắn hoặc mô tả SEO để xem sản phẩm hiện thế nào trong kết quả tìm kiếm.');
    const price = $('#product_price').val().trim();
    $('#seo-preview-price').text(price ? `${formatMoneyInput(String(parseMoney(price)))} ₫` : '');
    $form.find('[data-counter-for]').each(function () {
      const len = $(`#${$(this).attr('data-counter-for')}`).val().length;
      $(this).text(`${len} / ${$(this).attr('data-max')} ký tự`);
    });
  };
  $form.on('input change', refresh);
  $form.on('reset', () => setTimeout(refresh));
  refresh();

  $('#seo-edit').on('click', function () {
    const $fields = $('#seo-fields').toggleClass('hidden');
    const open = !$fields.hasClass('hidden');
    $(this).attr('aria-expanded', open);
    if (open) $('#product_meta_title').trigger('focus');
  });
}

// Biến thể: phần nhập (tên nhóm + bảng) chỉ hiện khi có dòng biến thể; bấm "Thêm biến thể" thì hiện (repeater.js thêm dòng)
function initVariants($form) {
  const $card = $form.find('[data-card="variants"]');
  const $body = $card.find('[data-variant-body]');
  const sync = () => {
    if ($card.find('[data-repeater-row]').length) $body.removeClass('hidden');
    else if (!$body.find('.ant-form-item-has-error').length) $body.addClass('hidden');
  };
  // Chạy sau xử lý thêm / bỏ dòng của repeater.js
  $card.on('click', '[data-repeater-remove]', () => setTimeout(sync));
  $card.on('click', '[data-repeater-add]', () =>
    setTimeout(() => {
      sync();
      $card.find('[data-repeater-row]').last().find('input[type=text]').first().trigger('focus');
    }),
  );
  $form.on('reset', () => setTimeout(sync));
}

export default async function initProductForm() {
  const $form = $('#product-form');
  if (!$form.length) return;

  // Đường dẫn theo tên
  const $name = $('#product_name');
  const $slug = $('#product_slug');
  $slug.on('input', function () {
    if (this.value.trim() === '') this.removeAttribute('data-touched');
    else this.setAttribute('data-touched', '');
  });
  $slug.on('blur', () => {
    const slug = toSlug($slug.val());
    if (slug !== $slug.val()) $slug.val(slug).trigger('change');
  });
  $name.on('input', () => {
    if (!$slug[0].hasAttribute('data-touched')) $slug.val(toSlug($name.val())).trigger('change');
  });
  $form.on('reset', () => setTimeout(() => $slug.attr('data-touched', $slug.val() !== '' ? '' : null)));

  initSeo($form);
  initVariants($form);
  initPrice($form);
  initGallery($form);

  // Mô tả: Tiptap tải sau cùng, ghi HTML vào ô ẩn description
  const $content = $form.find('[name=description]');
  const $editor = $form.find('.rich-editor');
  let editorApi = null;
  const sync = () => {
    const html = editorApi.getEditorHtml($editor);
    if ($content.val() !== html) $content.val(html).trigger('change');
  };
  $form.on('submit', () => {
    if (!editorApi) return;
    editorApi.leaveSource($editor);
    sync();
  });
  editorApi = await import('./editor');
  editorApi.default($form);
  editorApi.setEditorHtml($editor, $content.val());
  $editor.find('.rich-editor-content').on('input', sync);
  $form.on('reset', () => setTimeout(() => editorApi.setEditorHtml($editor, $content.val())));
}
