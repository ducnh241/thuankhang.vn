import $ from 'jquery';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import { CharacterCount, Placeholder } from '@tiptap/extensions';

import { closePopup, dropdownMenu, escapeHtml, icon, openPopup } from './core';
import { openModal } from './modal';
import { enhanceSelects, setSelectValue } from './select';

/*
 * Trình soạn thảo (Tiptap, MIT, ProseMirror) với thanh công cụ kiểu Ant Design — chép từ template-admin/src/js/editor.js,
 * Việt hóa. Bản này chưa có chèn ảnh / bảng (chân trang không cần; ảnh của template lưu dạng data URL rất nặng).
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

// Nút trên thanh công cụ: [icon, tên, đang bật?, chạy, chạy được?]
const BUTTONS = {
  undo: ['undo', 'Hoàn tác (Ctrl+Z)', null, c => c.undo(), e => e.can().undo()],
  redo: ['redo', 'Làm lại (Ctrl+Shift+Z)', null, c => c.redo(), e => e.can().redo()],
  bold: ['bold', 'Đậm (Ctrl+B)', e => e.isActive('bold'), c => c.toggleBold()],
  italic: ['italic', 'Nghiêng (Ctrl+I)', e => e.isActive('italic'), c => c.toggleItalic()],
  underline: ['underline', 'Gạch chân (Ctrl+U)', e => e.isActive('underline'), c => c.toggleUnderline()],
  strike: ['strikethrough', 'Gạch ngang', e => e.isActive('strike'), c => c.toggleStrike()],
  alignLeft: ['align-left', 'Căn trái', e => e.isActive({ textAlign: 'left' }), c => c.setTextAlign('left')],
  alignCenter: ['align-center', 'Căn giữa', e => e.isActive({ textAlign: 'center' }), c => c.setTextAlign('center')],
  alignRight: ['align-right', 'Căn phải', e => e.isActive({ textAlign: 'right' }), c => c.setTextAlign('right')],
  bulletList: ['unordered-list', 'Danh sách chấm', e => e.isActive('bulletList'), c => c.toggleBulletList()],
  orderedList: ['ordered-list', 'Danh sách số', e => e.isActive('orderedList'), c => c.toggleOrderedList()],
  divider: ['line', 'Đường kẻ ngang', null, c => c.setHorizontalRule()],
  link: ['link', 'Chèn link (Ctrl+K)', e => e.isActive('link'), null],
  clear: ['clear', 'Xóa định dạng', null, c => c.unsetAllMarks().clearNodes()],
  source: ['code', 'Sửa HTML', null, null],
};

/*
 * Nhóm nút từ trái sang phải. Thanh hẹp quá thì cả nhóm dồn vào menu "···" theo COLLAPSE_ORDER, thanh luôn nằm
 * trên một dòng. Ô kiểu chữ và nhóm đậm / nghiêng ở lại lâu nhất.
 */
const GROUPS = [
  ['history', ['undo', 'redo']],
  ['block', ['block']],
  ['marks', ['bold', 'italic', 'underline', 'strike']],
  ['format', ['align', 'bulletList', 'orderedList', 'divider']],
  ['insert', ['link']],
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
  const current = editor.getAttributes('link');
  const $root = openModal('#editor-link-modal');
  const $url = $root.find('#editor-link-url').val(current.href || '');
  const $blank = $root.find('#editor-link-blank').prop('checked', current.target === '_blank');
  $blank.closest('.ant-checkbox').toggleClass('ant-checkbox-checked', $blank.prop('checked'));
  $blank.closest('.ant-checkbox-wrapper').toggleClass('ant-checkbox-wrapper-checked', $blank.prop('checked'));
  $root.find('.link-remove').toggle(!!current.href);
  setTimeout(() => $url.trigger('focus').trigger('select'));

  const apply = () => {
    const href = $url.val().trim();
    if (!href) {
      $root.find('.ant-form-item').addClass('ant-form-item-has-error');
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
    editor.chain().focus().extendMarkRange('link').unsetLink().run();
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
      Placeholder.configure({ placeholder: $editor.attr('data-placeholder') || 'Bắt đầu viết…' }),
      CharacterCount,
    ],
    editorProps: {
      attributes: { class: 'rich-editor-prose', 'aria-label': $editor.attr('data-label') || 'Nội dung' },
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
    const align = (ALIGNMENTS.find(([, value]) => editor.isActive({ textAlign: value })) || ALIGNMENTS[0])[2];
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
    $footer.text(`${words.toLocaleString('vi-VN')} từ · ${chars.toLocaleString('vi-VN')} ký tự`);
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
      ALIGNMENTS.map(([name, value, ic]) => [name, ic, BUTTONS[name][1], { selected: editor.isActive({ textAlign: value }) }]),
      name => BUTTONS[name][3](editor.chain().focus()).run(),
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
        ALIGNMENTS.forEach(([name, value, ic]) =>
          rows.push([name, ic, BUTTONS[name][1], { selected: editor.isActive({ textAlign: value }) }]),
        );
      }
    });
    menuFor($(this), rows, name => {
      const $btn = $toolbar.find(`[data-command="${name}"]`);
      if ($btn.length) $btn.trigger('click');
      else BUTTONS[name][3](editor.chain().focus()).run();
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
    BUTTONS[name][3](editor.chain().focus()).run();
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
