import $ from 'jquery';

import { closePopup, escapeHtml, icon, isPopupOpenFor, openPopup, plain } from './core';

/*
 * Select / AutoComplete / Cascader.
 * Options are declared on the element as JSON: data-options='[{"value":"china","label":"China"}]'.
 * The current value is kept in data-value (a JSON array for multiple selects) and a `select:change` event fires.
 */

const options = $el => JSON.parse($el.attr('data-options') || '[]');
const isSearch = $select => $select.hasClass('ant-select-show-search') && $select.hasClass('ant-select-single');
// Cuộn danh sách (không cuộn trang — trang cuộn thì core.js đóng popup) để thấy mục đang sáng
function scrollToItem(item) {
  const holder = item?.closest('.rc-virtual-list-holder');
  if (!holder) return;
  const top = item.offsetTop;
  const bottom = top + item.offsetHeight;
  if (top < holder.scrollTop) holder.scrollTop = top;
  else if (bottom > holder.scrollTop + holder.clientHeight) holder.scrollTop = bottom - holder.clientHeight;
}

export const getSelectValue = $select => {
  const raw = $select.attr('data-value');
  if ($select.hasClass('ant-select-multiple')) return raw ? JSON.parse(raw) : [];
  return raw || undefined;
};

function renderSingle($select, value) {
  const opt = options($select).find(o => o.value === value);
  const $selector = $select.find('.ant-select-selector');
  $selector.find('.ant-select-selection-item, .ant-select-selection-placeholder').remove();
  if (opt) {
    $selector.append(
      `<span class="ant-select-selection-item" title="${escapeHtml(opt.label)}">${withIcon(opt)}</span>`,
    );
  } else if ($select.attr('data-placeholder')) {
    $selector.append(
      `<span class="ant-select-selection-placeholder">${escapeHtml($select.attr('data-placeholder'))}</span>`,
    );
  }
  // allowClear: nút xóa chỉ có khi đang có giá trị
  $select.find('.ant-select-clear').toggleClass('hidden', !opt);
}

function renderMultiple($select, values) {
  const $selector = $select.find('.ant-select-selector');
  $selector.find('.ant-select-selection-item, .ant-select-selection-placeholder').remove();
  const $search = $selector.find('.ant-select-selection-search');
  for (const value of values) {
    // Tags mode accepts free values that are not in the option list.
    const opt = options($select).find(o => o.value === value) || { value, label: value };
    $(`<span class="ant-select-selection-item" title="${escapeHtml(opt.label)}">
        <span class="ant-select-selection-item-content">${escapeHtml(opt.label)}</span
        ><span class="ant-select-selection-item-remove" unselectable="on" aria-hidden="true">${icon('close')}</span>
      </span>`)
      .attr('data-value', value)
      .insertBefore($search);
  }
  if (!values.length && $select.attr('data-placeholder')) {
    $selector.append(
      `<span class="ant-select-selection-placeholder">${escapeHtml($select.attr('data-placeholder'))}</span>`,
    );
  }
}

export function setSelectValue($select, value) {
  if ($select.hasClass('ant-select-multiple')) {
    $select.attr('data-value', JSON.stringify(value || []));
    renderMultiple($select, value || []);
  } else {
    if (value === undefined) $select.removeAttr('data-value');
    else $select.attr('data-value', value);
    renderSingle($select, value);
  }
  $select.trigger('select:change', [value]);
}

function dropdown(items) {
  return `<div class="ant-select-dropdown ant-select-dropdown-placement-bottomLeft">
    <div>
      <div class="rc-virtual-list" style="position: relative">
        <div class="rc-virtual-list-holder" style="max-height: 256px; overflow-y: auto; overflow-anchor: none">
          <div>
            <div class="rc-virtual-list-holder-inner" style="display: flex; flex-direction: column">${items}</div>
          </div>
        </div>
      </div>
    </div>
  </div>`;
}

// ProCMS: <option data-icon="folder"> → icon trước tên (cả ở ô đã chọn), icon và chữ căn giữa theo chiều dọc trong
// một khung flex (chữ dài vẫn cắt "…"); <option disabled> → mục mờ, không chọn được
function withIcon(opt) {
  if (!opt.icon) return escapeHtml(opt.label);
  return `<span class="ant-select-item-with-icon">${icon(opt.icon, 'ant-select-item-icon')}<span class="ant-select-item-text">${escapeHtml(
    opt.label,
  )}</span></span>`;
}

function optionItem(opt, selected, multiple) {
  return `<div aria-selected="${selected}" class="ant-select-item ant-select-item-option${
    selected ? ' ant-select-item-option-selected' : ''
  }${opt.disabled ? ' ant-select-item-option-disabled" aria-disabled="true' : ''}" title="${escapeHtml(opt.label)}" data-value="${escapeHtml(opt.value)}">
    <div class="ant-select-item-option-content">${withIcon(opt)}</div>
    <span class="ant-select-item-option-state" unselectable="on" aria-hidden="true" style="user-select: none">${
      multiple && selected ? icon('check') : ''
    }</span>
  </div>`;
}

function openSelect($select) {
  const multiple = $select.hasClass('ant-select-multiple');
  const search = isSearch($select);
  const value = getSelectValue($select);
  const isSelected = v => (multiple ? value.includes(v) : value === v);
  // ProCMS: <optgroup> → dòng tiêu đề nhóm (ant-select-item-group) trước các lựa chọn của nhóm
  let group = null;
  const html = dropdown(
    options($select)
      .map(o => {
        const header =
          o.group && o.group !== group ? `<div class="ant-select-item ant-select-item-group" data-group>${escapeHtml(o.group)}</div>` : '';
        group = o.group || null;
        return header + optionItem(o, isSelected(o.value), multiple);
      })
      .join('') + (search ? '<div class="ant-select-item-empty hidden">Không tìm thấy</div>' : ''),
  );

  $select.addClass('ant-select-open');
  // showSearch: khi mở, mũi tên thành kính lúp (như Ant Design)
  if (search) $select.find('.ant-select-arrow').html(icon('search', 'ant-select-suffix'));
  const { $popup, position } = openPopup($select, html, {
    matchWidth: true,
    placementClass: 'ant-select-dropdown',
    onClose: () => {
      $select.removeClass('ant-select-open');
      if (!search) return;
      $select.find('.ant-select-arrow').html(icon('down', 'ant-select-suffix'));
      $select.find('.ant-select-selection-search-input').val('');
      $select.find('.ant-select-selection-item, .ant-select-selection-placeholder').css({ visibility: '', transition: '' });
    },
  });
  $select.data('reposition', position);
  // Mục đang chọn sáng lên và cuộn tới (danh sách dài như khách hàng); chưa chọn gì thì mục đầu
  const $current = $popup.find('.ant-select-item-option-selected').first();
  const enabled = '.ant-select-item-option:not(.ant-select-item-option-disabled)';
  ($current.length ? $current : $popup.find(enabled).first()).addClass('ant-select-item-option-active');
  if ($current.length) scrollToItem($current[0]);
  $popup
    .on('mouseenter', enabled, function () {
      $popup.find('.ant-select-item-option-active').removeClass('ant-select-item-option-active');
      $(this).addClass('ant-select-item-option-active');
    })
    .on('mousedown', e => e.preventDefault())
    .on('click', enabled, function () {
      const v = $(this).attr('data-value');
      if (multiple) {
        const next = getSelectValue($select);
        const i = next.indexOf(v);
        if (i >= 0) next.splice(i, 1);
        else next.push(v);
        setSelectValue($select, next);
        $(this)
          .toggleClass('ant-select-item-option-selected', i < 0)
          .attr('aria-selected', i < 0)
          .find('.ant-select-item-option-state')
          .html(i < 0 ? icon('check') : '');
      } else {
        setSelectValue($select, v);
        closePopup();
      }
    });
}

/*
 * showSearch (ProCMS): <select data-antd data-show-search> → gõ vào ô để lọc danh sách (không phân biệt dấu, khớp bất
 * kỳ đoạn nào của tên), mục khớp đầu tiên sáng lên để Enter chọn; đóng danh sách thì xóa chữ đã gõ.
 */
function filterSelect($select, query) {
  const q = plain(query.trim());
  const $popup = $('.popup-root .ant-select-dropdown');
  let first = null;
  $popup.find('.ant-select-item-option').each(function () {
    const match = !q || plain(this.getAttribute('title') || '').includes(q);
    this.classList.toggle('hidden', !match);
    if (match && !first && !this.classList.contains('ant-select-item-option-disabled')) first = this;
  });
  // Tiêu đề nhóm chỉ hiện khi nhóm còn lựa chọn khớp
  $popup.find('[data-group]').each(function () {
    let next = this.nextElementSibling;
    let any = false;
    while (next && !next.hasAttribute('data-group') && next.classList.contains('ant-select-item-option')) {
      if (!next.classList.contains('hidden')) any = true;
      next = next.nextElementSibling;
    }
    this.classList.toggle('hidden', !any);
  });
  $popup.find('.ant-select-item-option-active').removeClass('ant-select-item-option-active');
  if (first) {
    first.classList.add('ant-select-item-option-active');
    scrollToItem(first);
  }
  $popup.find('.ant-select-item-empty').toggleClass('hidden', !!first);
  // Đang gõ: ẩn ngay chữ của mục đã chọn / placeholder (bỏ hiệu ứng 0,3s của Ant, kẻo chữ cũ đè lên chữ đang gõ)
  $select
    .find('.ant-select-selection-item, .ant-select-selection-placeholder')
    .css({ visibility: query ? 'hidden' : '', transition: query ? 'none' : '' });
  // Danh sách ngắn lại: đặt lại vị trí (khi đang mở lên phía trên ô)
  $select.data('reposition')?.();
}

function initSearch() {
  $(document).on('input', '.ant-select-show-search.ant-select-single .ant-select-selection-search-input', function () {
    const $select = $(this).closest('.ant-select');
    if (!isPopupOpenFor($select[0])) openSelect($select);
    filterSelect($select, this.value);
  });
  // Rời ô (Tab đi chỗ khác): đóng danh sách
  $(document).on('blur', '.ant-select-show-search.ant-select-single .ant-select-selection-search-input', function () {
    if (isPopupOpenFor($(this).closest('.ant-select')[0])) closePopup();
  });
}

/*
 * Tags mode (data-mode="tags" on a multiple select): type a value and press Enter or comma to add it,
 * Backspace on an empty input removes the last tag.
 */
/*
 * Keyboard: Enter / Space / ArrowDown opens the list; with it open, typing jumps to the first option starting with
 * the typed letters (accents ignored), arrows move, Enter picks.
 */
function initKeyboard() {
  let buffer = '';
  let timer;

  // ProCMS: .link-picker (ô chọn liên kết nhiều cấp của trang Menu) tự xử lý — link-picker.js
  $(document).on('keydown', '.ant-select:not([data-mode="tags"]):not(.ant-select-auto-complete):not(.link-picker) input', function (e) {
    const $select = $(this).closest('.ant-select');
    const open = isPopupOpenFor($select[0]);
    const search = isSearch($select);
    if (!open) {
      // showSearch: dấu cách là chữ gõ vào ô tìm, không mở danh sách
      if (['Enter', 'ArrowDown'].includes(e.key) || (e.key === ' ' && !search)) {
        e.preventDefault();
        openSelect($select);
      }
      return;
    }
    const $items = $('.popup-root .ant-select-item-option:not(.hidden):not(.ant-select-item-option-disabled)');
    const $active = $items.filter('.ant-select-item-option-active');
    const activate = $item => {
      if (!$item.length) return;
      $items.removeClass('ant-select-item-option-active');
      $item.addClass('ant-select-item-option-active');
      scrollToItem($item[0]);
    };
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const i = $items.index($active);
      activate($items.eq(Math.max(0, Math.min($items.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      $active.trigger('click');
    } else if (!search && e.key.length === 1 && !e.ctrlKey && !e.metaKey) {
      clearTimeout(timer);
      buffer += plain(e.key);
      timer = setTimeout(() => (buffer = ''), 700);
      activate($items.filter((_, el) => plain($(el).attr('title') || '').startsWith(buffer)).first());
    }
  });
}

function initTags() {
  const fit = input =>
    $(input)
      .parent()
      .css('width', `${Math.max(4, input.value.length * 8 + 8)}px`);

  $(document).on('keydown', '.ant-select[data-mode="tags"] input', function (e) {
    const $select = $(this).closest('.ant-select');
    const values = getSelectValue($select);
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      const v = this.value.trim();
      if (v && !values.includes(v)) setSelectValue($select, [...values, v]);
      this.value = '';
    } else if (e.key === 'Backspace' && !this.value && values.length) {
      setSelectValue($select, values.slice(0, -1));
    }
    $select.find('.ant-select-selection-placeholder').toggle(!getSelectValue($select).length && !this.value);
    setTimeout(() => fit(this));
  });
  $(document).on('input', '.ant-select[data-mode="tags"] input', function () {
    $(this).closest('.ant-select').find('.ant-select-selection-placeholder').toggle(!this.value);
    fit(this);
  });
  // Leaving the field keeps what was typed as a tag.
  $(document).on('blur', '.ant-select[data-mode="tags"] input', function () {
    const $select = $(this).closest('.ant-select');
    const v = this.value.trim();
    if (v && !getSelectValue($select).includes(v)) setSelectValue($select, [...getSelectValue($select), v]);
    this.value = '';
    fit(this);
  });
}

/*
 * <select data-antd> is replaced by an Ant Design select built from its <option>s. The native element stays
 * (hidden) right after it, keeps its value in sync and fires `change`, so forms can still read it.
 * Supports `multiple`, data-placeholder, data-mode="tags", data-show-search, data-allow-clear, <optgroup> (ProCMS: tiêu
 * đề nhóm trong danh sách), <option data-icon> / <option disabled> (ProCMS), id (moved to the search input for <label for>),
 * class and style (copied to the wrapper).
 */
export function enhanceSelects(root = document) {
  $(root)
    .find('select[data-antd]')
    .each(function () {
      const $native = $(this);
      const multiple = this.multiple;
      const tags = $native.attr('data-mode') === 'tags';
      const search = !multiple && $native.is('[data-show-search]');
      const opts = $native
        .find('option')
        .toArray()
        .filter(o => o.value !== '')
        .map(o => ({
          value: o.value,
          label: o.textContent.trim(),
          ...(o.parentElement.tagName === 'OPTGROUP' ? { group: o.parentElement.label } : {}),
          ...(o.dataset.icon ? { icon: o.dataset.icon } : {}),
          ...(o.disabled ? { disabled: true } : {}),
        }));
      const id = $native.attr('id');
      const $select = $(`<div class="ant-select ${
        multiple ? 'ant-select-multiple ant-select-show-search' : 'ant-select-single ant-select-show-arrow'
      }">
        <div class="ant-select-selector">
          <span class="ant-select-selection-search"${multiple ? ' style="width: 4px"' : ''}
            ><input type="search" autocomplete="off" class="ant-select-selection-search-input"${
              tags || search ? '' : ' readonly unselectable="on" style="opacity: 0"'
            } /></span>
        </div>
        ${
          multiple
            ? ''
            : `<span class="ant-select-arrow" unselectable="on" aria-hidden="true" style="user-select: none">${icon(
                'down',
                'ant-select-suffix',
              )}</span>`
        }
      </div>`);

      $select.addClass($native.attr('class') || '').attr('style', $native.attr('style') || null);
      if (search) $select.addClass('ant-select-show-search');
      // ProCMS: data-allow-clear trên <select> đơn → nút ⓧ khi rê chuột để bỏ chọn (allowClear của Ant Design)
      if (!multiple && $native.is('[data-allow-clear]')) {
        $select
          .addClass('ant-select-allow-clear')
          .append(`<span class="ant-select-clear" unselectable="on" aria-hidden="true">${icon('close-circle')}</span>`);
      }
      $select.attr('data-options', JSON.stringify(opts));
      if ($native.attr('data-placeholder')) $select.attr('data-placeholder', $native.attr('data-placeholder'));
      if (tags) $select.attr('data-mode', 'tags');
      if (id) {
        $native.removeAttr('id');
        $select.find('input').attr('id', id);
      }
      $native.removeAttr('data-antd').removeAttr('style').attr('class', 'hidden').attr('tabindex', '-1');
      $select.insertBefore($native);

      const selected = $native
        .find('option:selected')
        .toArray()
        .filter(o => o.value !== '')
        .map(o => o.value);
      setSelectValue($select, multiple ? selected : selected[0]);

      $select.on('select:change', (e, value) => {
        if (multiple) {
          // Tags may add values that have no <option> yet.
          for (const v of value) {
            if (!$native.find('option').filter((_, o) => o.value === v).length) $native.append(new Option(v, v));
          }
          $native.find('option').each((_, o) => (o.selected = value.includes(o.value)));
        } else {
          $native.val(value === undefined ? '' : value);
        }
        $native.trigger('change');
      });
    });
}

function initSelect() {
  $(document).on('mousedown', '.ant-select:not(.ant-select-auto-complete):not(.link-picker) .ant-select-selector', function (e) {
    const $select = $(this).closest('.ant-select');
    if ($(e.target).closest('.ant-select-selection-item-remove').length) return;
    if ($select.attr('data-mode') === 'tags') {
      if (e.target.tagName !== 'INPUT') {
        e.preventDefault();
        $select.find('input').trigger('focus');
      }
      return;
    }
    e.preventDefault();
    $select.find('input').trigger('focus');
    if (isPopupOpenFor($select[0])) closePopup();
    else openSelect($select);
  });
  $(document).on('mousedown', '.ant-select-arrow', function (e) {
    e.preventDefault();
    $(this)
      .closest('.ant-select')
      .find('.ant-select-selector')
      .trigger($.Event('mousedown', { target: this }));
  });
  $(document).on('mousedown', '.ant-select-clear', function (e) {
    e.preventDefault();
    e.stopPropagation();
    closePopup();
    setSelectValue($(this).closest('.ant-select'), undefined);
  });
  $(document).on('mousedown', '.ant-select-selection-item-remove', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $select = $(this).closest('.ant-select');
    const v = $(this).closest('.ant-select-selection-item').attr('data-value');
    setSelectValue(
      $select,
      getSelectValue($select).filter(x => x !== v),
    );
  });
}

/* AutoComplete: suggestions computed from the typed text (data-suffixes='[".com",".org"]'). */
function initAutoComplete() {
  $(document).on('input', '.ant-select-auto-complete input', function () {
    const $select = $(this).closest('.ant-select');
    const text = this.value;
    $select.find('.ant-select-selection-placeholder').toggle(!text);
    if (!text) return closePopup();
    const suffixes = JSON.parse($select.attr('data-suffixes') || '[]');
    const items = suffixes.map(s => optionItem({ value: text + s, label: text + s }, false, false)).join('');
    const { $popup } = openPopup($select, dropdown(items), {
      matchWidth: true,
      placementClass: 'ant-select-dropdown',
      onClose: () => $select.removeClass('ant-select-open'),
    });
    $select.addClass('ant-select-open');
    $popup.find('.ant-select-item').first().addClass('ant-select-item-option-active');
    $popup
      .on('mousedown', e => e.preventDefault())
      .on('mouseenter', '.ant-select-item', function () {
        $popup.find('.ant-select-item-option-active').removeClass('ant-select-item-option-active');
        $(this).addClass('ant-select-item-option-active');
      })
      .on('click', '.ant-select-item', function () {
        const $input = $select.find('input');
        $input.val($(this).attr('data-value')).trigger('change');
        closePopup();
      });
  });
}

/* Cascader: one column per level; picking a leaf writes "A / B / C" into the label. */
function initCascader() {
  const open = $picker => {
    const tree = options($picker);
    const path = JSON.parse($picker.attr('data-value') || '[]');

    const columns = () => {
      const cols = [];
      let level = tree;
      for (let i = 0; level; i++) {
        cols.push(
          `<ul class="ant-cascader-menu">${level
            .map(o => {
              const active = path[i] === o.value;
              return `<li class="ant-cascader-menu-item${o.children ? ' ant-cascader-menu-item-expand' : ''}${
                active ? ' ant-cascader-menu-item-active' : ''
              }" title="${escapeHtml(o.label)}" role="menuitem" data-level="${i}" data-value="${escapeHtml(o.value)}">${escapeHtml(
                o.label,
              )}${o.children ? `<span class="ant-cascader-menu-item-expand-icon">${icon('right')}</span>` : ''}</li>`;
            })
            .join('')}</ul>`,
        );
        const next = level.find(o => o.value === path[i]);
        level = next && next.children;
      }
      return cols.join('');
    };

    $picker.addClass('ant-cascader-picker-focused');
    const { $popup, position } = openPopup(
      $picker,
      `<div class="ant-cascader-menus ant-cascader-menus-placement-bottomLeft"><div>${columns()}</div></div>`,
      {
        placementClass: 'ant-cascader-menus',
        onClose: () => $picker.removeClass('ant-cascader-picker-focused'),
      },
    );
    $popup
      .on('mousedown', e => e.preventDefault())
      .on('click', '.ant-cascader-menu-item', function () {
        const level = Number($(this).attr('data-level'));
        path.splice(level, path.length, $(this).attr('data-value'));
        if ($(this).hasClass('ant-cascader-menu-item-expand')) {
          $popup.children().html(columns());
          position();
          return;
        }
        const labels = [];
        let nodes = tree;
        for (const v of path) {
          const node = nodes.find(o => o.value === v);
          labels.push(node.label);
          nodes = node.children;
        }
        $picker.attr('data-value', JSON.stringify(path));
        $picker.find('.ant-cascader-picker-label').text(labels.join(' / '));
        $picker.trigger('select:change', [path]);
        closePopup();
      });
  };

  $(document).on('click', '.ant-cascader-picker', function (e) {
    if ($(e.target).closest('.ant-cascader-picker-clear').length) return;
    if (isPopupOpenFor(this)) closePopup();
    else open($(this));
  });
  $(document).on('click', '.ant-cascader-picker-clear', function (e) {
    e.stopPropagation();
    const $picker = $(this).closest('.ant-cascader-picker');
    $picker.removeAttr('data-value').find('.ant-cascader-picker-label').text('');
    $picker.trigger('select:change', [[]]);
  });
}

export default function initSelects() {
  enhanceSelects();
  initSelect();
  initKeyboard();
  initSearch();
  initTags();
  initAutoComplete();
  initCascader();
}
