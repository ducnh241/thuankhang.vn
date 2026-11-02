import $ from 'jquery';

import { closePopup, escapeHtml, icon, isPopupOpenFor, openPopup, plain } from './core';

/*
 * Ô chọn liên kết nhiều cấp trong một ô (trang Menu, backend/menus/index.blade.php): mở ra là các loại link — Trang chủ,
 * Danh mục dự án ›, Danh mục tin tức ›, Trang ›, Tự nhập đường dẫn…; bấm loại có "›" thì danh sách trong chính ô đó
 * chuyển sang các mục của loại (danh mục / trang), có nút ‹ quay lại và ô tìm khi danh sách dài. Đang chọn một danh
 * mục / trang thì mở thẳng danh sách chứa nó.
 *
 * Markup: .ant-select.link-picker[data-link-picker][data-tree] (khung Ant Select dựng sẵn ở server) + ô ẩn
 * [data-link-value] (home | url | category:5 | page:3, gửi lên server với tên link). data-tree:
 *   {"items": [{value | menu, label, icon}], "menus": {"category:project": {title, icon, empty, items: [{value, label,
 *   depth, note}]}}}
 * Chọn "Tự nhập…" thì hiện ô đường dẫn ([data-link-field="url"]) và đặt con trỏ vào đó.
 * Bàn phím: Enter / ↓ mở; ↑ ↓ di chuyển, Enter / → chọn hoặc vào trong, ← / Backspace (ô tìm trống) quay lại, Esc đóng.
 */

const SEARCH_FROM = 8; // danh sách từ chừng này mục trở lên thì có ô tìm

const withIcon = (name, text) =>
  `<span class="ant-select-item-with-icon">${icon(name, 'ant-select-item-icon')}<span class="ant-select-item-text">${escapeHtml(text)}</span></span>`;

// Tên mục trong danh sách: lùi cấp bằng "— " như ô chọn danh mục, ghi chú (đang ẩn) sau tên
const itemLabel = item => `${'— '.repeat(item.depth || 0)}${item.label}${item.note ? ` (${item.note})` : ''}`;

function scrollToItem(item) {
  const holder = item?.closest('.rc-virtual-list-holder');
  if (!holder) return;
  const top = item.offsetTop;
  const bottom = top + item.offsetHeight;
  if (top < holder.scrollTop) holder.scrollTop = top;
  else if (bottom > holder.scrollTop + holder.clientHeight) holder.scrollTop = bottom - holder.clientHeight;
}

function initPicker($picker) {
  const tree = JSON.parse($picker.attr('data-tree'));
  const $value = $picker.nextAll('[data-link-value]').first();
  const $input = $picker.find('input');
  const $form = $picker.closest('form');
  const $url = $form.find('[data-link-field="url"]');
  const initial = $value.val();

  // Danh sách (menu) chứa giá trị đang chọn; null = cấp ngoài cùng
  const menuOf = value => Object.keys(tree.menus).find(key => tree.menus[key].items.some(i => i.value === value)) || null;
  const describe = value => {
    const root = tree.items.find(i => i.value === value);
    if (root) return { icon: root.icon, text: root.label };
    const key = menuOf(value);
    if (!key) return null;
    const menu = tree.menus[key];
    return { icon: menu.icon, text: `${menu.title} › ${menu.items.find(i => i.value === value).label}` };
  };

  const render = () => {
    const shown = describe($value.val());
    const $selector = $picker.find('.ant-select-selector');
    $selector.find('.ant-select-selection-item, .ant-select-selection-placeholder').remove();
    $selector.append(
      shown
        ? `<span class="ant-select-selection-item" title="${escapeHtml(shown.text)}">${withIcon(shown.icon, shown.text)}</span>`
        : '<span class="ant-select-selection-placeholder">Chọn liên kết</span>',
    );
    $url.toggleClass('hidden', $value.val() !== 'url');
  };

  const choose = value => {
    closePopup();
    if (value !== $value.val()) $value.val(value).trigger('change'); // edit-form.js tính lại "Chưa lưu thay đổi"
    render();
    if (value === 'url') setTimeout(() => $url.find('input').trigger('focus'));
    else $input.trigger('focus');
  };

  const items = () => $('.popup-root .link-picker-dropdown .ant-select-item-option:not(.hidden):not(.ant-select-item-option-disabled)');
  const activate = $item => {
    if (!$item.length) return;
    items().removeClass('ant-select-item-option-active');
    $item.addClass('ant-select-item-option-active');
    scrollToItem($item[0]);
  };

  // Mở danh sách ở cấp `level` (null = các loại link); `from` = loại vừa rời (quay lại thì sáng mục đó)
  function open(level, from = null) {
    const current = $value.val();
    const currentMenu = menuOf(current);
    const menu = level ? tree.menus[level] : null;
    const list = menu ? menu.items : tree.items;
    const rows = list
      .map(item => {
        const selected = item.value ? item.value === current : item.menu === currentMenu;
        const label = menu ? itemLabel(item) : item.label;
        return `<div class="ant-select-item ant-select-item-option${selected ? ' ant-select-item-option-selected' : ''}" aria-selected="${selected}" title="${escapeHtml(label)}"
          ${item.menu ? `data-menu="${escapeHtml(item.menu)}"` : `data-value="${escapeHtml(item.value)}"`}>
          <div class="ant-select-item-option-content">${withIcon(item.icon || menu.icon, label)}</div>
          <span class="ant-select-item-option-state" aria-hidden="true">${item.menu ? icon('right') : ''}</span>
        </div>`;
      })
      .join('');
    const head = menu
      ? `<div class="link-picker-head"><button type="button" class="link-picker-back" data-back aria-label="Quay lại">${icon('left')}<span>${escapeHtml(menu.title)}</span></button></div>${
          list.length >= SEARCH_FROM
            ? `<div class="link-picker-search"><span class="ant-input-affix-wrapper"><span class="ant-input-prefix">${icon('search')}</span><input type="text" class="ant-input" placeholder="Tìm trong ${escapeHtml(menu.title.toLowerCase())}" aria-label="Tìm" /></span></div>`
            : ''
        }`
      : '';
    const empty = menu && !list.length ? `<div class="ant-select-item-empty">${escapeHtml(menu.empty)}</div>` : '';
    const html = `<div class="ant-select-dropdown link-picker-dropdown" role="listbox">${head}<div class="rc-virtual-list-holder" style="max-height: 256px; overflow-y: auto">${rows}${empty}<div class="ant-select-item-empty hidden" data-no-match>Không tìm thấy</div></div></div>`;

    $picker.addClass('ant-select-open');
    const { $popup, position } = openPopup($picker, html, {
      matchWidth: true,
      placementClass: 'ant-select-dropdown',
      onClose: () => $picker.removeClass('ant-select-open'),
    });
    $popup.data('level', level);
    const $start = from ? items().filter(`[data-menu="${from}"]`) : items().filter('.ant-select-item-option-selected');
    activate($start.length ? $start.first() : items().first());

    const $search = $popup.find('.link-picker-search input');
    $popup
      // Giữ con trỏ ở ô (bấm vào danh sách không làm ô mất focus), trừ ô tìm
      .on('mousedown', e => {
        if (!$(e.target).closest('.link-picker-search').length) e.preventDefault();
      })
      .on('mouseenter', '.ant-select-item-option:not(.ant-select-item-option-disabled)', function () {
        activate($(this));
      })
      .on('click', '.ant-select-item-option:not(.ant-select-item-option-disabled)', function () {
        if ($(this).attr('data-menu')) open($(this).attr('data-menu'));
        else choose($(this).attr('data-value'));
      })
      .on('click', '[data-back]', () => back());
    $search.on('input', function () {
      const q = plain(this.value.trim());
      let first = null;
      $popup.find('.ant-select-item-option').each(function () {
        const match = !q || plain(this.getAttribute('title') || '').includes(q);
        this.classList.toggle('hidden', !match);
        if (match && !first) first = this;
      });
      $popup.find('[data-no-match]').toggleClass('hidden', !!first);
      if (first) activate($(first));
      position();
    });
    $search.on('keydown', keydown);
    if ($search.length) $search.trigger('focus');
    else $input.trigger('focus');
  }

  function back() {
    const level = $('.popup-root .link-picker-dropdown').data('level');
    if (level) open(null, level);
  }

  function keydown(e) {
    const isOpen = isPopupOpenFor($picker[0]);
    if (!isOpen) {
      if (['Enter', 'ArrowDown', ' '].includes(e.key)) {
        e.preventDefault();
        open(menuOf($value.val()));
      }
      return;
    }
    const $items = items();
    const $active = $items.filter('.ant-select-item-option-active');
    const searching = e.target !== $input[0] && e.target.value !== '';
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const i = $items.index($active);
      activate($items.eq(Math.max(0, Math.min($items.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))));
    } else if (e.key === 'Enter' || (e.key === 'ArrowRight' && $active.attr('data-menu'))) {
      e.preventDefault();
      $active.trigger('click');
    } else if ((e.key === 'ArrowLeft' && !searching) || (e.key === 'Backspace' && !searching)) {
      if ($('.popup-root .link-picker-dropdown').data('level')) {
        e.preventDefault();
        back();
      }
    } else if (e.key === 'Tab') {
      closePopup();
    }
  }

  $picker.on('mousedown', '.ant-select-selector', e => {
    e.preventDefault();
    $input.trigger('focus');
    if (isPopupOpenFor($picker[0])) closePopup();
    else open(menuOf($value.val()));
  });
  $input.on('keydown', keydown);
  // "Hủy thay đổi": ô ẩn không tự về giá trị cũ khi form.reset() → đặt lại rồi vẽ lại
  $form.on('reset', () =>
    setTimeout(() => {
      $value.val(initial);
      render();
    }),
  );
  render();
}

export default function initLinkPickers() {
  $('[data-link-picker]').each(function () {
    initPicker($(this));
  });
}
