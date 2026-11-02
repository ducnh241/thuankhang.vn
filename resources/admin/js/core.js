import $ from 'jquery';

// Markup of an Ant Design icon that references the inline sprite in index.html.
export const icon = (name, className = '', style = '') =>
  `<span role="img" aria-label="${name.replace(/-tt$/, '')}" class="anticon anticon-${name.replace(/-tt$/, '')}${
    className ? ` ${className}` : ''
  }"${style ? ` style="${style}"` : ''}><svg width="1em" height="1em" fill="currentColor" aria-hidden="true"><use href="#i-${name}"></use></svg></span>`;

// ProCMS: bỏ dấu tiếng Việt, chữ thường — để tìm "nguyen" khớp "Nguyễn" (ô tìm trong danh sách chọn, nút lọc…)
export const plain = s =>
  String(s)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[đĐ]/g, 'd')
    .toLowerCase();

export const escapeHtml = s =>
  String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/* Toast message at the top of the page (Ant Design `message`). type: success | error | warning | info */
export function message(text, type = 'success') {
  let $holder = $('.ant-message');
  if (!$holder.length) $holder = $('<div class="ant-message"><div></div></div>').appendTo('body');
  const icons = {
    success: 'check-circle',
    error: 'close-circle',
    warning: 'exclamation-circle',
    info: 'exclamation-circle',
  };
  const $notice = $(`<div class="ant-message-notice move-up-appear move-up-appear-active">
      <div class="ant-message-notice-content">
        <div class="ant-message-custom-content ant-message-${type}">${icon(icons[type])}<span>${escapeHtml(text)}</span></div>
      </div>
    </div>`).appendTo($holder.children());
  setTimeout(() => $notice.removeClass('move-up-appear move-up-appear-active'), 200);
  setTimeout(() => {
    $notice.addClass('move-up-leave move-up-leave-active');
    setTimeout(() => $notice.remove(), 200);
  }, 3000);
}

/* Ant Design dropdown menu markup. rows: [key, icon, label, { selected, disabled }] or ['-'] for a divider. */
export const dropdownMenu = rows =>
  `<div class="ant-dropdown ant-dropdown-placement-bottomLeft"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical ant-dropdown-menu-light" role="menu">${rows
    .map(([key, ic, label, state = {}]) =>
      key === '-'
        ? '<li class="ant-dropdown-menu-item-divider"></li>'
        : `<li class="ant-dropdown-menu-item${state.selected ? ' ant-dropdown-menu-item-selected' : ''}${
            state.disabled ? ' ant-dropdown-menu-item-disabled' : ''
          }" role="menuitem" data-key="${key}">${ic ? `${icon(ic)}<span class="ml-2">` : '<span>'}${escapeHtml(label)}</span></li>`,
    )
    .join('')}</ul></div>`;

/*
 * Floating layers (dropdowns, pickers, tooltips). A popup is attached to an anchor element and positioned below it
 * (or above when there is no room), like Ant Design's `bottomLeft` / `topLeft` placements.
 * Only one popup is open at a time; it closes on an outside mousedown or Escape.
 */
let current = null;

export function closePopup() {
  if (!current) return;
  const { $root, onClose } = current;
  current = null;
  $root.remove();
  if (onClose) onClose();
}

export function openPopup($anchor, html, { onClose, placementClass, gap = 4, matchWidth = false, keepOpenOn } = {}) {
  closePopup();
  const $root = $('<div class="popup-root"><div></div></div>').appendTo('body');
  const $popup = $(html).appendTo($root.children());
  current = { $root, $anchor, onClose, keepOpenOn };
  position();

  function position() {
    const a = $anchor[0].getBoundingClientRect();
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    // Positions and widths are whole pixels, as Ant Design's aligner produces.
    if (matchWidth) $popup.css({ minWidth: Math.round(a.width), width: Math.round(a.width) });
    const h = $popup.outerHeight();
    const below = a.bottom + gap + h <= window.innerHeight || a.top - gap - h < 0;
    const top = below ? a.bottom + gap : a.top - gap - h;

    // Horizontally: start at the anchor's left edge; if that overflows the viewport, align to its right edge
    // instead, and always keep MARGIN px inside the screen (very wide popups get a max-width).
    const MARGIN = 8;
    const vw = document.documentElement.clientWidth;
    $popup.css('max-width', vw - MARGIN * 2);
    const w = $popup.outerWidth();
    let left = a.left;
    let side = 'Left';
    if (left + w > vw - MARGIN) {
      left = a.right - w;
      side = 'Right';
    }
    left = Math.max(MARGIN, Math.min(left, vw - MARGIN - w));
    $popup.css({ left: Math.round(left + scrollX), top: Math.round(top + scrollY) });
    if (placementClass) {
      $popup.removeClass(
        ['bottomLeft', 'topLeft', 'bottomRight', 'topRight'].map(p => `${placementClass}-placement-${p}`).join(' '),
      );
      $popup.addClass(`${placementClass}-placement-${below ? 'bottom' : 'top'}${side}`);
    }
  }

  return { $popup, position };
}

export const isPopupOpenFor = el => current && current.$anchor[0] === el;

$(document).on('mousedown', e => {
  if (!current) return;
  const inside = $(e.target).closest(current.$root).length || $(e.target).closest(current.$anchor).length;
  const keep = current.keepOpenOn && $(e.target).closest(current.keepOpenOn).length;
  if (!inside && !keep) closePopup();
});
$(document).on('keydown', e => {
  if (e.key === 'Escape') closePopup();
});
// Content scrolls inside the workspace, so floating layers would drift: close them instead.
$(document).on('scroll', closePopup);
$(() => $('.workspace > .ant-layout').on('scroll', closePopup));
$(window).on('resize', closePopup);
