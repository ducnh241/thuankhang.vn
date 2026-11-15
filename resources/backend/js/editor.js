import $ from 'jquery';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import Image from '@tiptap/extension-image';
import { CharacterCount, Placeholder } from '@tiptap/extensions';

import { closePopup, dropdownMenu, escapeHtml, icon, message, openPopup } from './core';
import { openModal } from './modal';
import { enhanceSelects, setSelectValue } from './select';

/*
 * Trình soạn thảo (Tiptap, MIT, ProseMirror) với thanh công cụ kiểu Ant Design — chép từ template-admin/src/js/editor.js,
 * Việt hóa. Chưa có bảng. Chèn ảnh: nút "Chèn ảnh", dán ảnh, hoặc kéo thả ảnh vào nội dung — ảnh tải lên thư viện
 * (POST tới <meta name="editor-upload">, màn Quản lý file) rồi chèn bằng link /storage/…; không nhận ảnh dạng data URL.
 * Trang không có thẻ meta đó (người dùng không có quyền tải) thì không có nút chèn ảnh.
 *
 * Markup: <div class="rich-editor" data-placeholder="…" data-label="…">
 *           <div class="rich-editor-toolbar"></div>      (điền ở đây)
 *           <div class="rich-editor-content"></div>
 *           <textarea class="rich-editor-source hidden"></textarea>   (sửa HTML trực tiếp)
 *           <div class="rich-editor-footer"></div>
 *         </div>
 * Nội dung đổi thì phần tử .rich-editor-content bắn `input`. Gõ kiểu Markdown được ("## " tiêu đề, "- " danh sách,
 * "> " trích dẫn, "**đậm**", "---" đường kẻ…). Link chỉ nhận http(s), mailto, tel… (Tiptap chặn javascript:).
 * Trang nào cần thì import động (`await import('./editor')`) để trang khác không phải tải Tiptap.
 */

const BLOCKS = [
  { value: 'p', label: 'Đoạn văn' },
  { value: 'h2', label: 'Tiêu đề 2' },
  { value: 'h3', label: 'Tiêu đề 3' },
  { value: 'h4', label: 'Tiêu đề 4' },
  { value: 'blockquote', label: 'Trích dẫn' },
];

// Căn lề: đang chọn ảnh thì căn ảnh, không thì căn chữ của đoạn / tiêu đề
const alignActive = value => e =>
  e.isActive('image') ? (e.getAttributes('image').align || 'left') === value : e.isActive({ textAlign: value });
const alignRun = value => (c, e) =>
  e.isActive('image') ? c.updateAttributes('image', { align: value === 'left' ? null : value }) : c.setTextAlign(value);

// Nút trên thanh công cụ: [icon, tên, đang bật?(editor), chạy(chain, editor), chạy được?(editor)]
const BUTTONS = {
  undo: ['undo', 'Hoàn tác (Ctrl+Z)', null, c => c.undo(), e => e.can().undo()],
  redo: ['redo', 'Làm lại (Ctrl+Shift+Z)', null, c => c.redo(), e => e.can().redo()],
  bold: ['bold', 'Đậm (Ctrl+B)', e => e.isActive('bold'), c => c.toggleBold()],
  italic: ['italic', 'Nghiêng (Ctrl+I)', e => e.isActive('italic'), c => c.toggleItalic()],
  underline: ['underline', 'Gạch chân (Ctrl+U)', e => e.isActive('underline'), c => c.toggleUnderline()],
  strike: ['strikethrough', 'Gạch ngang', e => e.isActive('strike'), c => c.toggleStrike()],
  alignLeft: ['align-left', 'Căn trái', alignActive('left'), alignRun('left')],
  alignCenter: ['align-center', 'Căn giữa', alignActive('center'), alignRun('center')],
  alignRight: ['align-right', 'Căn phải', alignActive('right'), alignRun('right')],
  bulletList: ['unordered-list', 'Danh sách chấm', e => e.isActive('bulletList'), c => c.toggleBulletList()],
  orderedList: ['ordered-list', 'Danh sách số', e => e.isActive('orderedList'), c => c.toggleOrderedList()],
  divider: ['line', 'Đường kẻ ngang', null, c => c.setHorizontalRule()],
  link: ['link', 'Chèn link (Ctrl+K) — đang chọn ảnh: gắn link cho ảnh', e => e.isActive('link') || !!(e.isActive('image') && e.getAttributes('image').href), null],
  image: ['picture', 'Chèn ảnh (đang chọn ảnh: sửa mô tả / kích thước)', e => e.isActive('image'), null],
  clear: ['clear', 'Xóa định dạng', null, c => c.unsetAllMarks().clearNodes()],
  source: ['code', 'Sửa HTML', null, null],
};

/*
 * Nhóm nút từ trái sang phải. Thanh hẹp quá thì cả nhóm dồn vào menu "···" theo COLLAPSE_ORDER, thanh luôn nằm
 * trên một dòng. Ô kiểu chữ và nhóm đậm / nghiêng ở lại lâu nhất.
 */
// Nơi tải ảnh chèn vào nội dung (thẻ meta do backend/partials/head in ra khi người dùng được tải); null = không chèn ảnh
const uploadMeta = document.querySelector('meta[name="editor-upload"]');
const UPLOAD = uploadMeta ? { url: uploadMeta.content, maxBytes: Number(uploadMeta.dataset.maxBytes) || 0 } : null;
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

/*
 * Ảnh trong nội dung: một khối riêng, ngoài src / alt / width của Tiptap còn có
 *   align   căn lề — in ra data-align="center" | "right" (trái là mặc định, không in gì)
 *   href, target   link gắn vào ảnh — in ra <a href="…"><img …></a> (đọc lại đúng dạng đó: <a> chỉ chứa một ảnh)
 * CSS .rich-editor-prose (admin) và .rich-text (website) căn ảnh theo data-align; App\Support\RichText giữ data-align,
 * width và thẻ <a> bao ngoài khi lọc HTML.
 */
// Link gắn vào ảnh chỉ nhận http(s), mailto, tel, đường dẫn trong site (/…, #…) — như link chữ (Tiptap chặn javascript:)
const safeHref = value => {
  const href = String(value || '').trim();
  return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(href) ? href : null;
};
const imgOf = el => (el.tagName === 'IMG' ? el : el.querySelector('img'));
const fromImg = name => el => imgOf(el)?.getAttribute(name) || null;
const AlignedImage = Image.extend({
  addAttributes() {
    return {
      src: { default: null, parseHTML: fromImg('src') },
      alt: { default: null, parseHTML: fromImg('alt') },
      title: { default: null, parseHTML: fromImg('title') },
      // Chỉ đặt chiều rộng: số px, hoặc "100%" (rộng hết khung nội dung); chiều cao luôn tự theo tỉ lệ
      width: {
        default: null,
        parseHTML: el => (/^(\d{1,4}|100%)$/.test(imgOf(el)?.getAttribute('width') || '') ? imgOf(el).getAttribute('width') : null),
      },
      height: { default: null, parseHTML: () => null, rendered: false },
      align: {
        default: null,
        parseHTML: el => {
          const value = imgOf(el)?.getAttribute('data-align') || imgOf(el)?.getAttribute('align');
          return ['center', 'right'].includes(value) ? value : null;
        },
        renderHTML: attrs => (attrs.align ? { 'data-align': attrs.align } : {}),
      },
      href: { default: null, rendered: false, parseHTML: el => (el.tagName === 'A' ? safeHref(el.getAttribute('href')) : null) },
      target: { default: null, rendered: false, parseHTML: el => (el.tagName === 'A' && el.getAttribute('target') === '_blank' ? '_blank' : null) },
    };
  },
  parseHTML() {
    const img = 'img[src]:not([src^="data:"])';
    return [
      // <a> chỉ bọc đúng một ảnh (không chữ): ảnh có link — xét trước luật link chữ của Tiptap (priority 50)
      {
        tag: 'a[href]',
        priority: 60,
        getAttrs: el => (el.children.length === 1 && el.firstElementChild.matches(img) && el.textContent.trim() === '' ? null : false),
      },
      { tag: img },
    ];
  },
  renderHTML({ node, HTMLAttributes }) {
    const img = ['img', HTMLAttributes];
    if (!node.attrs.href) return img;
    const blank = node.attrs.target === '_blank';
    return ['a', { href: node.attrs.href, target: blank ? '_blank' : null, rel: blank ? 'noopener noreferrer' : null }, img];
  },
});

const GROUPS = [
  ['history', ['undo', 'redo']],
  ['block', ['block']],
  ['marks', ['bold', 'italic', 'underline', 'strike']],
  ['format', ['align', 'bulletList', 'orderedList', 'divider']],
  ['insert', UPLOAD ? ['link', 'image'] : ['link']],
];
const COLLAPSE_ORDER = ['extra', 'insert', 'history', 'format', 'marks'];
const ALIGNMENTS = [
  ['alignLeft', 'left', 'align-left'],
  ['alignCenter', 'center', 'align-center'],
  ['alignRight', 'right', 'align-right'],
];

const BTN = 'ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only';
const button = name => {
  const [ic, title] = BUTTONS[name];
  return `<button type="button" class="${BTN}" data-command="${name}" title="${title}" aria-label="${title}">${icon(ic)}</button>`;
};
// Một nút cho 3 kiểu căn lề: hiện kiểu đang dùng, bấm mở menu nhỏ
const alignButton = () =>
  `<button type="button" class="ant-btn ant-btn-text ant-btn-sm rich-editor-align" data-align-menu title="Căn lề" aria-label="Căn lề" aria-haspopup="menu">${icon(
    'align-left',
    'rich-editor-align-icon',
  )}${icon('down', 'rich-editor-caret')}</button>`;
const blockSelect = () =>
  `<select data-antd class="ant-select-sm w-28" data-editor-block aria-label="Kiểu chữ">${BLOCKS.map(
    b => `<option value="${b.value}"${b.value === 'p' ? ' selected' : ''}>${b.label}</option>`,
  ).join('')}</select>`;
const DIVIDER = '<div class="ant-divider ant-divider-vertical" role="separator"></div>';
const group = (key, inner, first = false) =>
  `<span class="rich-editor-group" data-group="${key}">${first ? '' : DIVIDER}${inner}</span>`;

function toolbarHtml() {
  const main = GROUPS.map(([key, items], i) =>
    group(
      key,
      items.map(n => (n === 'block' ? blockSelect() : n === 'align' ? alignButton() : button(n))).join(''),
      i === 0,
    ),
  ).join('');
  return `${main}
    <span class="rich-editor-group ml-auto" data-group="extra">${button('clear')}${button('source')}</span>
    <button type="button" class="${BTN} hidden" data-more title="Công cụ khác" aria-label="Công cụ khác" aria-haspopup="menu">${icon('ellipsis')}</button>`;
}

/* ---------- hộp thoại chèn link (Modal Ant Design, tạo một lần) ---------- */

function ensureLinkModal() {
  if ($('#editor-link-modal').length) return;
  $('body').append(`<template id="editor-link-modal">
    <div class="ant-modal-root">
      <div class="ant-modal-mask"></div>
      <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="editor-link-title">
        <div role="document" class="ant-modal" style="width: 440px">
          <div class="ant-modal-content">
            <button type="button" aria-label="Đóng" class="ant-modal-close"><span class="ant-modal-close-x">${icon('close', 'ant-modal-close-icon')}</span></button>
            <div class="ant-modal-header"><div class="ant-modal-title" id="editor-link-title">Chèn link</div></div>
            <div class="ant-modal-body">
              <form class="ant-form ant-form-vertical" novalidate>
                <div class="ant-row ant-form-item" data-required="Nhập link.">
                  <div class="ant-col ant-form-item-label"><label for="editor-link-url" class="ant-form-item-required" title="Link">Link</label></div>
                  <div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="editor-link-url" class="ant-input" placeholder="https://example.com hoặc /trang" />
                  </div></div></div>
                </div>
                <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" id="editor-link-blank" /><span class="ant-checkbox-inner"></span></span><span>Mở ở tab mới</span></label>
              </form>
            </div>
            <div class="ant-modal-footer">
              <button type="button" class="ant-btn ant-btn-dangerous float-left link-remove"><span>Bỏ link</span></button>
              <button type="button" class="ant-btn" data-modal-close><span>Hủy</span></button>
              <button type="button" class="ant-btn ant-btn-primary link-apply"><span>Áp dụng</span></button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>`);
}

function editLink(editor) {
  ensureLinkModal();
  // Đang chọn một ảnh: link gắn vào ảnh (thuộc tính href / target của ảnh), không phải link chữ
  const onImage = editor.isActive('image');
  const current = onImage ? editor.getAttributes('image') : editor.getAttributes('link');
  const $root = openModal('#editor-link-modal');
  if (onImage) $root.find('.ant-modal-title').text('Gắn link cho ảnh');
  const $url = $root.find('#editor-link-url').val(current.href || '');
  const $blank = $root.find('#editor-link-blank').prop('checked', current.target === '_blank');
  $blank.closest('.ant-checkbox').toggleClass('ant-checkbox-checked', $blank.prop('checked'));
  $blank.closest('.ant-checkbox-wrapper').toggleClass('ant-checkbox-wrapper-checked', $blank.prop('checked'));
  $root.find('.link-remove').toggle(!!current.href);
  setTimeout(() => $url.trigger('focus').trigger('select'));

  const apply = () => {
    let href = $url.val().trim();
    // Ảnh: tự kiểm tra link (link chữ đã có Tiptap kiểm); gõ thiếu "https://" thì thêm vào
    if (onImage && href) href = safeHref(href) || (/^[^\s:]+\.[^\s:]+$/.test(href) ? `https://${href}` : '');
    if (!href) {
      $root.find('.ant-form-item').addClass('ant-form-item-has-error');
      return;
    }
    if (onImage) {
      editor
        .chain()
        .focus()
        .updateAttributes('image', { href, target: $blank.prop('checked') ? '_blank' : null })
        .run();
      $root.data('close')();
      return;
    }
    const attrs = {
      href,
      target: $blank.prop('checked') ? '_blank' : null,
      rel: $blank.prop('checked') ? 'noopener noreferrer' : null,
    };
    const chain = editor.chain().focus().extendMarkRange('link');
    // Chưa chọn chữ nào: chèn chính link làm chữ
    if (editor.state.selection.empty && !current.href) {
      chain
        .insertContent(`<a href="${escapeHtml(href)}">${escapeHtml(href)}</a>`)
        .setLink(attrs)
        .run();
    } else {
      chain.setLink(attrs).run();
    }
    $root.data('close')();
  };
  $root.find('.link-apply').on('click', apply);
  $root.find('form').on('submit', e => {
    e.preventDefault();
    e.stopImmediatePropagation();
    apply();
  });
  $root.find('.link-remove').on('click', () => {
    if (onImage) editor.chain().focus().updateAttributes('image', { href: null, target: null }).run();
    else editor.chain().focus().extendMarkRange('link').unsetLink().run();
    $root.data('close')();
  });
}

/* ---------- chèn ảnh: tải lên thư viện rồi chèn link ---------- */

const mb = bytes => `${(bytes / 1024 / 1024).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} MB`;

// Tải một ảnh lên; trả { url, name } hoặc ném Error kèm câu báo lỗi để hiện cho người dùng
async function uploadImage(file) {
  if (!IMAGE_TYPES.includes(file.type)) throw new Error(`“${file.name}” không phải ảnh JPG, PNG, WEBP hay GIF.`);
  if (UPLOAD.maxBytes && file.size > UPLOAD.maxBytes) throw new Error(`“${file.name}” nặng ${mb(file.size)} — mỗi ảnh tối đa ${mb(UPLOAD.maxBytes)}.`);
  const body = new FormData();
  body.append('image', file);
  let res;
  try {
    res = await fetch(UPLOAD.url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('#delete-form [name="_token"]').val() },
      body,
    });
  } catch {
    throw new Error('Không kết nối được máy chủ, thử lại sau.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
    throw new Error(first || (res.status === 413 ? `“${file.name}” quá nặng, máy chủ không nhận.` : res.status === 419 ? 'Phiên làm việc đã hết hạn — tải lại trang rồi thử lại.' : 'Không tải được ảnh, thử lại sau.'));
  }
  return data;
}

/*
 * Tải lần lượt các ảnh rồi chèn vào vị trí `pos` (mặc định: chỗ con trỏ). Ảnh lỗi thì báo và bỏ qua, ảnh khác vẫn chèn.
 * Trong lúc tải, editor có class is-uploading (nút Chèn ảnh quay).
 */
async function insertImages($editor, editor, files, pos = null) {
  const images = Array.from(files);
  if (!UPLOAD || !images.length) return;
  $editor.addClass('is-uploading');
  let at = pos;
  for (const file of images) {
    try {
      const { url, name } = await uploadImage(file);
      const node = { type: 'image', attrs: { src: url, alt: (name || '').replace(/\.[a-z0-9]+$/i, '') } };
      if (at === null) editor.chain().focus().insertContent(node).run();
      else editor.chain().focus().insertContentAt(at, node).run();
      // Ảnh vừa chèn đang được chọn: đưa con trỏ ra sau ảnh, không thì ảnh / chữ chèn tiếp sẽ thay thế nó
      editor.commands.setTextSelection(editor.state.selection.to);
      if (at !== null) at = editor.state.selection.to;
    } catch (e) {
      message(e.message, 'error');
    }
  }
  $editor.removeClass('is-uploading');
}

/* ---------- hộp thoại sửa ảnh đang chọn: mô tả (alt), chiều rộng (px hoặc 100%), xóa (căn lề / link: nút trên thanh công cụ) ---------- */

const IMAGE_MIN_WIDTH = 20;
const IMAGE_MAX_WIDTH = 4000;

function ensureImageModal() {
  if ($('#editor-image-modal').length) return;
  $('body').append(`<template id="editor-image-modal">
    <div class="ant-modal-root">
      <div class="ant-modal-mask"></div>
      <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="editor-image-title">
        <div role="document" class="ant-modal" style="width: 440px">
          <div class="ant-modal-content">
            <button type="button" aria-label="Đóng" class="ant-modal-close"><span class="ant-modal-close-x">${icon('close', 'ant-modal-close-icon')}</span></button>
            <div class="ant-modal-header"><div class="ant-modal-title" id="editor-image-title">Sửa ảnh</div></div>
            <div class="ant-modal-body">
              <form class="ant-form ant-form-vertical" novalidate>
                <div class="rich-editor-image-preview"><img alt="" /></div>
                <div class="ant-row ant-form-item">
                  <div class="ant-col ant-form-item-label"><label for="editor-image-alt" title="Mô tả ảnh (alt)">Mô tả ảnh (alt)</label></div>
                  <div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="editor-image-alt" class="ant-input" maxlength="200" placeholder="VD: Chai nước giặt ROW 3.6kg hương Baccarat" />
                  </div></div>
                  <div class="ant-form-item-extra">Google và trình đọc màn hình dùng dòng này; cũng hiện khi ảnh không tải được.</div></div>
                </div>
                <div class="ant-row ant-form-item mb-0" data-field="width">
                  <div class="ant-col ant-form-item-label"><label for="editor-image-width" title="Chiều rộng">Chiều rộng</label></div>
                  <div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                      <span class="ant-input-affix-wrapper" style="width: 140px"><input type="text" id="editor-image-width" class="ant-input" inputmode="numeric" maxlength="4" placeholder="Gốc" autocomplete="off" /><span class="ant-input-suffix">px</span></span>
                      <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" id="editor-image-full" /><span class="ant-checkbox-inner"></span></span><span>Rộng 100% (full-width)</span></label>
                    </div>
                  </div></div>
                  <div class="ant-form-item-explain ant-form-item-explain-error hidden" role="alert">Nhập số từ ${IMAGE_MIN_WIDTH} đến ${IMAGE_MAX_WIDTH}, hoặc để trống.</div>
                  <div class="ant-form-item-extra">Để trống = kích thước gốc<span data-natural></span>. Rộng 100% = ảnh giãn hết bề ngang khung nội dung. Chiều cao tự theo tỉ lệ; ảnh không bao giờ rộng hơn khung nội dung.</div></div>
                </div>
              </form>
            </div>
            <div class="ant-modal-footer">
              <button type="button" class="ant-btn ant-btn-dangerous float-left image-remove"><span>Xóa ảnh</span></button>
              <button type="button" class="ant-btn" data-modal-close><span>Hủy</span></button>
              <button type="button" class="ant-btn ant-btn-primary image-apply"><span>Áp dụng</span></button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>`);
}

function editImage(editor) {
  ensureImageModal();
  const current = editor.getAttributes('image');
  const $root = openModal('#editor-image-modal');
  $root
    .find('.rich-editor-image-preview img')
    .on('load', function () {
      $root.find('[data-natural]').text(` (${this.naturalWidth} × ${this.naturalHeight} px)`);
    })
    .attr('src', current.src || '');
  const $alt = $root.find('#editor-image-alt').val(current.alt || '');
  const isFull = current.width === '100%';
  const $width = $root.find('#editor-image-width').val(isFull ? '' : current.width || '');
  const $widthItem = $root.find('[data-field="width"]');
  // Tick "Rộng 100%": ô px khóa lại (số đã gõ giữ nguyên để bỏ tick là dùng lại được)
  const $full = $root.find('#editor-image-full').prop('checked', isFull);
  const syncFull = () => {
    const on = $full.prop('checked');
    $full.closest('.ant-checkbox').toggleClass('ant-checkbox-checked', on);
    $full.closest('.ant-checkbox-wrapper').toggleClass('ant-checkbox-wrapper-checked', on);
    $width.prop('disabled', on).closest('.ant-input-affix-wrapper').toggleClass('ant-input-affix-wrapper-disabled', on);
    if (on) $widthItem.removeClass('ant-form-item-has-error').find('.ant-form-item-explain').addClass('hidden');
  };
  $full.on('change', syncFull);
  syncFull();
  setTimeout(() => $alt.trigger('focus').trigger('select'));

  const apply = () => {
    const full = $full.prop('checked');
    const raw = full ? '' : $width.val().trim();
    const width = Number(raw);
    const invalid = raw !== '' && (!/^\d+$/.test(raw) || width < IMAGE_MIN_WIDTH || width > IMAGE_MAX_WIDTH);
    $widthItem.toggleClass('ant-form-item-has-error', invalid).find('.ant-form-item-explain').toggleClass('hidden', !invalid);
    if (invalid) {
      $width.trigger('focus');
      return;
    }
    editor
      .chain()
      .focus()
      .updateAttributes('image', { alt: $alt.val().trim() || null, width: full ? '100%' : raw === '' ? null : String(width) })
      .run();
    $root.data('close')();
  };
  $root.find('.image-apply').on('click', apply);
  // Form có hai ô nhập và không có nút submit nên trình duyệt không tự gửi khi nhấn Enter: tự bắt phím
  $root.find('form').on('submit', e => {
    e.preventDefault();
    e.stopImmediatePropagation();
  });
  $root.find('.ant-input').on('keydown', e => {
    if (e.key !== 'Enter' || e.originalEvent?.isComposing) return;
    e.preventDefault();
    apply();
  });
  $root.find('.image-remove').on('click', () => {
    editor.chain().focus().deleteSelection().run();
    $root.data('close')();
  });
}

/* ---------- editor ---------- */

export const editorOf = $el => $el.data('tiptap');

const inSource = $editor => !$editor.find('.rich-editor-source').hasClass('hidden');

// HTML hiện tại; đang sửa HTML thì lấy đúng chữ trong ô HTML
export const getEditorHtml = $editor => {
  if (inSource($editor)) return $editor.find('.rich-editor-source').val();
  const editor = editorOf($editor);
  return !editor || editor.isEmpty ? '' : editor.getHTML();
};

// Đang sửa HTML: đưa HTML qua trình soạn thảo (bỏ thẻ / thuộc tính lạ như <script>, onclick) rồi trở về chế độ soạn
export const leaveSource = $editor => {
  if (inSource($editor)) $editor.find('[data-command="source"]').trigger('click');
};

export const setEditorHtml = ($editor, html) => {
  leaveSource($editor);
  const editor = editorOf($editor);
  // Nạp nội dung không phải là sửa: không vào lịch sử hoàn tác, không bắn `input`
  if (editor)
    editor
      .chain()
      .setMeta('addToHistory', false)
      .setContent(html || '', { emitUpdate: false })
      .run();
  $editor.find('.rich-editor-source').val(html || '');
};

function initEditor($editor) {
  const $toolbar = $editor.find('.rich-editor-toolbar').attr('role', 'toolbar').html(toolbarHtml());
  enhanceSelects($toolbar);
  const $content = $editor.find('.rich-editor-content');
  const $source = $editor.find('.rich-editor-source');
  const $footer = $editor.find('.rich-editor-footer');
  // Ô chọn ảnh ẩn cho nút "Chèn ảnh" (không có name: không gửi kèm form)
  const $file = $(`<input type="file" class="hidden" accept="${IMAGE_TYPES.join(',')}" multiple tabindex="-1" aria-hidden="true" />`).appendTo($editor);
  let syncing = false;
  let editor = null;

  editor = new Editor({
    element: $content[0],
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3, 4] },
        // Mặc định Tiptap gắn target="_blank" cho mọi link: tắt đi, chỉ link chọn "Mở ở tab mới" mới mở tab mới
        link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { target: null, rel: null } },
      }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      // Ảnh là một khối riêng; không nhận data URL (dán HTML có ảnh nhúng thì ảnh đó bị bỏ)
      AlignedImage.configure({ inline: false, allowBase64: false }),
      Placeholder.configure({ placeholder: $editor.attr('data-placeholder') || 'Bắt đầu viết…' }),
      CharacterCount,
    ],
    editorProps: {
      attributes: { class: 'rich-editor-prose', 'aria-label': $editor.attr('data-label') || 'Nội dung' },
      // Dán / kéo thả file ảnh từ máy: tải lên thư viện rồi chèn (không có quyền tải thì để Tiptap xử lý như cũ)
      handlePaste: (view, event) => {
        const files = Array.from(event.clipboardData?.files || []).filter(f => f.type.startsWith('image/'));
        if (!UPLOAD || !files.length) return false;
        event.preventDefault();
        insertImages($editor, editor, files);
        return true;
      },
      handleDrop: (view, event, slice, moved) => {
        const files = Array.from(event.dataTransfer?.files || []).filter(f => f.type.startsWith('image/'));
        if (!UPLOAD || moved || !files.length) return false;
        event.preventDefault();
        event.stopPropagation(); // không để trang (vd màn Quản lý file) coi là thả file vào trang
        const at = view.posAtCoords({ left: event.clientX, top: event.clientY });
        insertImages($editor, editor, files, at ? at.pos : null);
        return true;
      },
    },
    onUpdate: () => $content.trigger('input'),
    onTransaction: () => refresh(),
  });
  $editor.data('tiptap', editor);

  function refresh() {
    if (!editor) return; // các transaction đầu chạy khi editor đang được tạo
    $toolbar.find('[data-command]').each(function () {
      const [, , isActive, , canRun] = BUTTONS[$(this).attr('data-command')];
      $(this).toggleClass('is-active', !!(isActive && isActive(editor)));
      if (canRun) $(this).prop('disabled', !canRun(editor));
    });
    const align = (ALIGNMENTS.find(([name]) => BUTTONS[name][2](editor)) || ALIGNMENTS[0])[2];
    $toolbar.find('.rich-editor-align-icon use').attr('href', `#i-${align}`);
    $toolbar.find('[data-align-menu]').toggleClass('is-active', align !== 'align-left');
    const block = editor.isActive('blockquote')
      ? 'blockquote'
      : ([2, 3, 4].map(l => (editor.isActive('heading', { level: l }) ? `h${l}` : null)).find(Boolean) ?? 'p');
    const $select = $toolbar.find('.ant-select');
    if ($select.attr('data-value') !== block) {
      syncing = true;
      setSelectValue($select, block);
      syncing = false;
    }
    const words = editor.storage.characterCount.words();
    const chars = editor.storage.characterCount.characters();
    // Đang chọn ảnh: nhắc cách sửa ảnh ngay ở chân ô soạn thảo
    const hint = editor.isActive('image') ? 'Đang chọn ảnh: bấm đúp để sửa mô tả (alt) / kích thước; căn lề và gắn link bằng nút trên thanh công cụ · ' : '';
    $footer.text(`${hint}${words.toLocaleString('vi-VN')} từ · ${chars.toLocaleString('vi-VN')} ký tự`);
  }

  $toolbar.on('mousedown', '.ant-btn', e => e.preventDefault());

  // Dồn nhóm vào "···" tới khi thanh vừa một dòng
  function fit() {
    const $groups = $toolbar.find('[data-group]');
    $groups.removeClass('is-collapsed');
    $toolbar.find('[data-more]').addClass('hidden');
    for (const key of COLLAPSE_ORDER) {
      if ($toolbar[0].scrollWidth <= $toolbar[0].clientWidth) break;
      $toolbar.find(`[data-group="${key}"]`).addClass('is-collapsed');
      $toolbar.find('[data-more]').removeClass('hidden');
    }
    // Không có vạch ngăn trước nhóm đầu tiên còn hiện
    $groups.removeClass('no-divider').not('.is-collapsed').first().addClass('no-divider');
  }
  new ResizeObserver(fit).observe($toolbar[0]);

  const menuFor = ($anchor, rows, onPick) => {
    const { $popup } = openPopup($anchor, dropdownMenu(rows), { placementClass: 'ant-dropdown' });
    $popup.on('mousedown', e => e.preventDefault());
    $popup.on('click', '.ant-dropdown-menu-item:not(.ant-dropdown-menu-item-disabled)', function () {
      closePopup();
      onPick($(this).attr('data-key'));
    });
  };

  $toolbar.on('click', '[data-align-menu]', function () {
    menuFor(
      $(this),
      ALIGNMENTS.map(([name, , ic]) => [name, ic, BUTTONS[name][1], { selected: BUTTONS[name][2](editor) }]),
      name => BUTTONS[name][3](editor.chain().focus(), editor).run(),
    );
  });

  // "···": các lệnh của nhóm đã dồn; chọn một lệnh = bấm nút ẩn tương ứng
  $toolbar.on('click', '[data-more]', function () {
    const rows = [];
    $toolbar.find('.is-collapsed').each(function () {
      if (rows.length) rows.push(['-']);
      $(this)
        .find('[data-command]')
        .each(function () {
          const name = $(this).attr('data-command');
          rows.push([name, BUTTONS[name][0], BUTTONS[name][1], { selected: $(this).hasClass('is-active'), disabled: this.disabled }]);
        });
      if ($(this).find('[data-align-menu]').length) {
        ALIGNMENTS.forEach(([name, , ic]) => rows.push([name, ic, BUTTONS[name][1], { selected: BUTTONS[name][2](editor) }]));
      }
    });
    menuFor($(this), rows, name => {
      const $btn = $toolbar.find(`[data-command="${name}"]`);
      if ($btn.length) $btn.trigger('click');
      else BUTTONS[name][3](editor.chain().focus(), editor).run();
    });
  });
  $toolbar.on('click', '[data-command]', function () {
    const name = $(this).attr('data-command');
    if (name === 'source') {
      const show = $source.hasClass('hidden');
      if (show) $source.val(editor.isEmpty ? '' : editor.getHTML());
      else editor.commands.setContent($source.val(), { emitUpdate: true });
      $source.toggleClass('hidden', !show);
      $content.toggleClass('hidden', show);
      $(this).toggleClass('is-active', show);
      $toolbar.find('.ant-btn, .ant-select').not(this).toggleClass('pointer-events-none opacity-40', show);
      if (show) $source.trigger('focus');
      return;
    }
    if (name === 'link') return editLink(editor);
    // Đang chọn một ảnh: nút Chèn ảnh mở hộp sửa ảnh đó; không thì mở hộp chọn file để chèn ảnh mới
    if (name === 'image') return editor.isActive('image') ? editImage(editor) : $file.trigger('click');
    BUTTONS[name][3](editor.chain().focus(), editor).run();
  });
  // Ảnh có link là <a> không sửa được (contenteditable=false) nên bấm vào sẽ mở link: chặn lại, chỉ chọn ảnh
  $content.on('click', 'a', e => e.preventDefault());
  // Bấm đúp vào ảnh (lần bấm đầu đã chọn ảnh) → sửa mô tả / kích thước
  $content.on('dblclick', 'img', () => {
    if (editor.isActive('image')) editImage(editor);
  });
  $file.on('change', function () {
    insertImages($editor, editor, this.files);
    this.value = ''; // chọn lại đúng ảnh đó lần nữa vẫn bắn change
  });
  $toolbar.on('select:change', '.ant-select', (e, value) => {
    if (syncing) return;
    e.stopPropagation(); // đổi kiểu chữ không phải đổi một ô của form
    const chain = editor.chain().focus();
    if (value === 'blockquote') chain.toggleBlockquote().run();
    else if (value === 'p') chain.setParagraph().run();
    else chain.setHeading({ level: Number(value.slice(1)) }).run();
  });
  // Ctrl/Cmd+K mở hộp chèn link
  $content.on('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      editLink(editor);
    }
  });
  $source.on('input', () => $content.trigger('input'));
  refresh();
}

// Gọi nhiều lần không sao: editor đã tạo thì bỏ qua
export default function initEditors(root = document) {
  $(root)
    .find('.rich-editor')
    .each(function () {
      if (!$(this).data('tiptap')) initEditor($(this));
    });
}
