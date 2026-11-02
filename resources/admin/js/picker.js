import $ from 'jquery';

import { closePopup, escapeHtml, icon, isPopupOpenFor, openPopup } from './core';

/*
 * ProCMS (ducnh.com): chép từ template-admin/src/js/picker.js, đổi chữ sang tiếng Việt và tuần bắt đầu từ thứ Hai
 * (như locale vi_VN của Ant Design). Giá trị trong ô vẫn là Y-m-d (gửi thẳng lên server được).
 *
 * Date / time pickers. The type comes from data-picker on the .ant-picker element:
 *   date | datetime | month | time | range | rangetime
 * The committed value is the text of the input(s); a `picker:change` event fires on commit.
 */

const MONTHS = ['Thg 1', 'Thg 2', 'Thg 3', 'Thg 4', 'Thg 5', 'Thg 6', 'Thg 7', 'Thg 8', 'Thg 9', 'Thg 10', 'Thg 11', 'Thg 12'];
const WEEK = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const hms = d => `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
const sameDay = (a, b) => a && b && ymd(a) === ymd(b);
const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate());

const FORMAT = {
  date: ymd,
  range: ymd,
  datetime: d => `${ymd(d)} ${hms(d)}`,
  rangetime: d => `${ymd(d)} ${hms(d)}`,
  month: d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`,
  time: hms,
};

function parse(type, text) {
  if (!text) return null;
  let m;
  if (type === 'time' && (m = text.match(/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/))) {
    const d = new Date();
    d.setHours(+m[1], +m[2], +m[3], 0);
    return d;
  }
  if (type === 'month' && (m = text.match(/^(\d{4})-(\d{1,2})$/))) return new Date(+m[1], +m[2] - 1, 1);
  if ((m = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?: (\d{1,2}):(\d{1,2}):(\d{1,2}))?$/))) {
    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
  }
  return null;
}

/* ---------- panel markup ---------- */

function header({ view, mode, hidePrev, hideNext }) {
  const hide = flag => (flag ? ' style="visibility: hidden"' : '');
  const btn = (cls, iconCls, flag) =>
    `<button type="button" tabindex="-1" class="ant-picker-header-${cls}-btn"${hide(flag)}><span class="ant-picker-${iconCls}-icon"></span></button>`;
  let label;
  if (mode === 'date') {
    label = `<button type="button" tabindex="-1" class="ant-picker-month-btn">${MONTHS[view.getMonth()]}</button><button type="button" tabindex="-1" class="ant-picker-year-btn">${view.getFullYear()}</button>`;
  } else if (mode === 'month') {
    label = `<button type="button" tabindex="-1" class="ant-picker-year-btn">${view.getFullYear()}</button>`;
  } else {
    const start = Math.floor(view.getFullYear() / 10) * 10;
    label = `<button type="button" tabindex="-1" class="ant-picker-decade-btn">${start}-${start + 9}</button>`;
  }
  return `<div class="ant-picker-header">${btn('super-prev', 'super-prev', hidePrev)}${
    mode === 'date' ? btn('prev', 'prev', hidePrev) : ''
  }<div class="ant-picker-header-view">${label}</div>${mode === 'date' ? btn('next', 'next', hideNext) : ''}${btn(
    'super-next',
    'super-next',
    hideNext,
  )}</div>`;
}

function dateTable(view, cellClass) {
  const first = new Date(view.getFullYear(), view.getMonth(), 1);
  const start = new Date(first);
  start.setDate(1 - ((first.getDay() + 6) % 7)); // tuần bắt đầu từ thứ Hai
  let rows = '';
  for (let r = 0; r < 6; r++) {
    rows += '<tr>';
    for (let c = 0; c < 7; c++) {
      const d = new Date(start);
      d.setDate(start.getDate() + r * 7 + c);
      const next = new Date(d);
      next.setDate(d.getDate() + 1);
      const cls = ['ant-picker-cell'];
      if (d.getDate() === 1) cls.push('ant-picker-cell-start');
      if (next.getDate() === 1) cls.push('ant-picker-cell-end');
      if (d.getMonth() === view.getMonth()) cls.push('ant-picker-cell-in-view');
      if (sameDay(d, new Date())) cls.push('ant-picker-cell-today');
      cls.push(...cellClass(d, d.getMonth() === view.getMonth()));
      rows += `<td title="${ymd(d)}" class="${cls.join(' ')}" data-date="${ymd(d)}"><div class="ant-picker-cell-inner">${d.getDate()}</div></td>`;
    }
    rows += '</tr>';
  }
  return `<div class="ant-picker-body"><table class="ant-picker-content"><thead><tr>${WEEK.map(
    w => `<th>${w}</th>`,
  ).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`;
}

function datePanel(view, cellClass, opts = {}) {
  return `<div class="ant-picker-date-panel">${header({ view, mode: 'date', ...opts })}${dateTable(view, cellClass)}</div>`;
}

function monthPanel(view, selected) {
  let rows = '';
  for (let r = 0; r < 4; r++) {
    rows += '<tr>';
    for (let c = 0; c < 3; c++) {
      const m = r * 3 + c;
      const cls = ['ant-picker-cell', 'ant-picker-cell-in-view'];
      if (selected && selected.getFullYear() === view.getFullYear() && selected.getMonth() === m)
        cls.push('ant-picker-cell-selected');
      rows += `<td title="${view.getFullYear()}-${pad(m + 1)}" class="${cls.join(' ')}" data-month="${m}"><div class="ant-picker-cell-inner">${MONTHS[m]}</div></td>`;
    }
    rows += '</tr>';
  }
  return `<div class="ant-picker-month-panel">${header({ view, mode: 'month' })}<div class="ant-picker-body"><table class="ant-picker-content"><tbody>${rows}</tbody></table></div></div>`;
}

function yearPanel(view, selected) {
  const start = Math.floor(view.getFullYear() / 10) * 10 - 1;
  let rows = '';
  for (let r = 0; r < 4; r++) {
    rows += '<tr>';
    for (let c = 0; c < 3; c++) {
      const y = start + r * 3 + c;
      const cls = ['ant-picker-cell'];
      if (y > start && y < start + 11) cls.push('ant-picker-cell-in-view');
      if (selected && selected.getFullYear() === y) cls.push('ant-picker-cell-selected');
      rows += `<td title="${y}" class="${cls.join(' ')}" data-year="${y}"><div class="ant-picker-cell-inner">${y}</div></td>`;
    }
    rows += '</tr>';
  }
  return `<div class="ant-picker-year-panel">${header({ view, mode: 'year' })}<div class="ant-picker-body"><table class="ant-picker-content"><tbody>${rows}</tbody></table></div></div>`;
}

function timePanel(value, withHeader) {
  const column = (unit, count, current) =>
    `<ul class="ant-picker-time-panel-column" style="position: relative" data-unit="${unit}">${Array.from(
      { length: count },
      (_, i) =>
        `<li class="ant-picker-time-panel-cell${current === i ? ' ant-picker-time-panel-cell-selected' : ''}" data-value="${i}"><div class="ant-picker-time-panel-cell-inner">${pad(
          i,
        )}</div></li>`,
    ).join('')}</ul>`;
  return `<div class="ant-picker-time-panel">${
    withHeader
      ? `<div class="ant-picker-header"><div class="ant-picker-header-view">${value ? hms(value) : '&nbsp;'}</div></div>`
      : ''
  }<div class="ant-picker-content">${column('h', 24, value && value.getHours())}${column('m', 60, value && value.getMinutes())}${column(
    's',
    60,
    value && value.getSeconds(),
  )}</div></div>`;
}

const footer = ({ today, now, ok, okDisabled }) => {
  if (today) return '<div class="ant-picker-footer"><a class="ant-picker-today-btn">Hôm nay</a></div>';
  if (!now && !ok) return '';
  return `<div class="ant-picker-footer"><ul class="ant-picker-ranges">${
    now ? '<li class="ant-picker-now"><a class="ant-picker-now-btn">Bây giờ</a></li>' : ''
  }${
    ok
      ? `<li class="ant-picker-ok"><button type="button" class="ant-btn ant-btn-primary ant-btn-sm"${
          okDisabled ? ' disabled' : ''
        }><span>Ok</span></button></li>`
      : ''
  }</ul></div>`;
};

/* ---------- picker behaviour ---------- */

function setClear($picker) {
  const hasValue = $picker
    .find('input')
    .toArray()
    .some(i => i.value);
  $picker.find('.ant-picker-clear').remove();
  if (hasValue) $picker.append(`<span class="ant-picker-clear" role="button">${icon('close-circle')}</span>`);
}

// Offset of the start / end input inside a range picker: 0, or start input + separator.
const rangeOffset = ($picker, index) =>
  index === 0
    ? 0
    : $picker.find('.ant-picker-input').eq(0)[0].offsetWidth +
      $picker.find('.ant-picker-range-separator')[0].offsetWidth;

function moveActiveBar($picker, index) {
  const $inputs = $picker.find('.ant-picker-input');
  $inputs.removeClass('ant-picker-input-active');
  $inputs.eq(index).addClass('ant-picker-input-active');
  $picker.find('.ant-picker-active-bar').css({ left: rangeOffset($picker, index), width: $inputs[index].offsetWidth });
}

function open($picker, activeIndex = 0) {
  const type = $picker.attr('data-picker');
  const isRange = type === 'range' || type === 'rangetime';
  const $inputs = $picker.find('input');
  const values = $inputs.toArray().map(i => parse(type, i.value));
  const state = {
    active: activeIndex,
    draft: values.slice(),
    mode: type === 'month' ? 'month' : 'date',
    view: startOfDay(values[activeIndex] || values[0] || new Date()),
  };

  const commit = () => {
    $inputs.each((i, input) => {
      input.value = state.draft[i] ? FORMAT[type](state.draft[i]) : '';
    });
    setClear($picker);
    $picker.trigger('picker:change', [isRange ? state.draft : state.draft[0]]);
  };
  const preview = () => {
    const d = state.draft[state.active];
    $inputs.eq(state.active).val(d ? FORMAT[type](d) : '');
  };

  const cellClass = (d, inView) => {
    const cls = [];
    const [a, b] = state.draft;
    if (!inView) return cls;
    if (isRange) {
      if (sameDay(d, a)) cls.push('ant-picker-cell-range-start', 'ant-picker-cell-selected');
      if (sameDay(d, b)) cls.push('ant-picker-cell-range-end', 'ant-picker-cell-selected');
      if (a && b && d > startOfDay(a) && d < startOfDay(b)) cls.push('ant-picker-cell-in-range');
    } else if (sameDay(d, state.draft[0])) {
      cls.push('ant-picker-cell-selected');
    }
    return cls;
  };

  const panelBody = () => {
    const current = state.draft[state.active];
    if (type === 'time') return timePanel(current, false) + footer({ now: true, ok: true, okDisabled: !current });
    if (state.mode === 'year') return yearPanel(state.view, current);
    if (state.mode === 'month') return monthPanel(state.view, type === 'month' ? current : null);
    if (type === 'datetime' || type === 'rangetime') {
      return `<div class="ant-picker-datetime-panel">${datePanel(state.view, cellClass)}${timePanel(current, true)}</div>${
        type === 'datetime' ? footer({ now: true, ok: true, okDisabled: !current }) : ''
      }`;
    }
    return datePanel(state.view, cellClass) + (type === 'date' ? footer({ today: true }) : '');
  };

  // ProCMS: khoảng ngày chọn nhanh dưới lịch (ranges của Ant Design RangePicker), khai báo trên .ant-picker:
  // data-presets='[{"label":"Tháng này","from":"2026-10-01","to":"2026-10-24"}]'
  const presets = type === 'range' ? JSON.parse($picker.attr('data-presets') || '[]') : [];
  const presetFooter = () =>
    presets.length
      ? `<div class="ant-picker-footer"><ul class="ant-picker-ranges">${presets
          .map(
            (p, i) =>
              `<li class="ant-picker-preset"><span class="ant-tag ant-tag-blue" data-preset="${i}">${escapeHtml(p.label)}</span></li>`,
          )
          .join('')}</ul></div>`
      : '';

  const render = () => {
    if (type === 'range' && state.mode === 'date') {
      const next = new Date(state.view.getFullYear(), state.view.getMonth() + 1, 1);
      return `<div class="ant-picker-panels"><div class="ant-picker-panel">${datePanel(state.view, cellClass, {
        hideNext: true,
      })}</div><div class="ant-picker-panel" data-offset="1">${datePanel(next, cellClass, {
        hidePrev: true,
      })}</div></div>${presetFooter()}`;
    }
    if (type === 'rangetime') {
      return `<div class="ant-picker-panels"><div class="ant-picker-panel">${panelBody()}</div></div>${footer({
        ok: true,
        okDisabled: !state.draft[state.active],
      })}`;
    }
    return `<div tabindex="-1" class="ant-picker-panel">${panelBody()}</div>`;
  };

  const shell = () => {
    if (!isRange) {
      return `<div class="ant-picker-dropdown ant-picker-dropdown-placement-bottomLeft"><div class="ant-picker-panel-container">${render()}</div></div>`;
    }
    const arrowLeft = rangeOffset($picker, state.active);
    return `<div class="ant-picker-dropdown ant-picker-dropdown-range ant-picker-dropdown-placement-bottomLeft"><div class="ant-picker-range-wrapper ant-picker-date-range-wrapper" style="min-width: ${$picker.outerWidth()}px"><div class="ant-picker-range-arrow" style="left: ${arrowLeft}px"></div><div class="ant-picker-panel-container" style="margin-left: 0px">${render()}</div></div></div>`;
  };

  if (isRange) moveActiveBar($picker, state.active);
  $inputs.eq(state.active).trigger('focus');
  const { $popup } = openPopup($picker, shell(), {
    placementClass: 'ant-picker-dropdown',
    onClose: () => {
      $picker.removeClass('ant-picker-focused');
      // Inputs show the committed values again; uncommitted drafts are dropped.
      $inputs.each((i, input) => {
        input.value = values[i] ? FORMAT[type](values[i]) : '';
      });
    },
  });

  const refresh = () => {
    const $container = $popup.find('.ant-picker-panel-container');
    $container.html(render());
    if (isRange) {
      $popup.find('.ant-picker-range-arrow').css('left', rangeOffset($picker, state.active));
    }
    $popup.find('.ant-picker-time-panel-column').each(function () {
      const $sel = $(this).find('.ant-picker-time-panel-cell-selected');
      if ($sel.length) this.scrollTop = $sel.index() * 28;
    });
  };
  refresh();

  const finish = () => {
    values.splice(0, values.length, ...state.draft);
    commit();
    closePopup();
  };

  const pick = d => {
    if (type === 'rangetime' || type === 'datetime') {
      const prev = state.draft[state.active];
      if (prev) d.setHours(prev.getHours(), prev.getMinutes(), prev.getSeconds());
      state.draft[state.active] = d;
      preview();
      return refresh();
    }
    if (type === 'range') {
      state.draft[state.active] = d;
      // ProCMS: chọn ngày bắt đầu thì luôn chuyển sang chọn ngày kết thúc như Ant Design (template chốt luôn nếu đã có
      // ngày kết thúc → đổi khoảng phải mở lại bộ chọn); ngày kết thúc cũ trước ngày bắt đầu mới thì bỏ.
      if (state.active === 0) {
        preview();
        if (state.draft[1] && state.draft[1] < d) {
          state.draft[1] = null;
          $inputs.eq(1).val('');
        }
        state.active = 1;
        moveActiveBar($picker, 1);
        $inputs.eq(1).trigger('focus');
        return refresh();
      }
      if (state.draft[0] && state.draft[1] && state.draft[0] > state.draft[1]) state.draft.reverse();
      return finish();
    }
    state.draft[0] = d;
    finish();
  };

  $popup.on('mousedown', e => e.preventDefault());
  $popup.on('click', '.ant-picker-date-panel td', function () {
    const d = parse('date', $(this).attr('data-date'));
    pick(d);
  });
  $popup.on('click', '.ant-picker-month-panel td', function () {
    const month = Number($(this).attr('data-month'));
    if (type === 'month') return pick(new Date(state.view.getFullYear(), month, 1));
    state.view = new Date(state.view.getFullYear(), month, 1);
    state.mode = 'date';
    refresh();
  });
  $popup.on('click', '.ant-picker-year-panel td', function () {
    state.view = new Date(Number($(this).attr('data-year')), state.view.getMonth(), 1);
    state.mode = 'month';
    refresh();
  });
  $popup.on('click', '.ant-picker-time-panel-cell', function () {
    const unit = $(this).parent().attr('data-unit');
    const d = new Date(state.draft[state.active] || startOfDay(type === 'time' ? new Date() : state.view));
    if (unit === 'h') d.setHours(Number($(this).attr('data-value')));
    if (unit === 'm') d.setMinutes(Number($(this).attr('data-value')));
    if (unit === 's') d.setSeconds(Number($(this).attr('data-value')));
    state.draft[state.active] = d;
    preview();
    refresh();
  });
  $popup.on('click', '.ant-picker-header-prev-btn, .ant-picker-header-next-btn', function () {
    const step = $(this).hasClass('ant-picker-header-prev-btn') ? -1 : 1;
    state.view = new Date(state.view.getFullYear(), state.view.getMonth() + step, 1);
    refresh();
  });
  $popup.on('click', '.ant-picker-header-super-prev-btn, .ant-picker-header-super-next-btn', function () {
    const step = $(this).hasClass('ant-picker-header-super-prev-btn') ? -1 : 1;
    const years = state.mode === 'year' ? 10 : 1;
    state.view = new Date(state.view.getFullYear() + step * years, state.view.getMonth(), 1);
    refresh();
  });
  $popup.on('click', '.ant-picker-month-btn', () => {
    state.mode = 'month';
    refresh();
  });
  $popup.on('click', '.ant-picker-year-btn', () => {
    state.mode = 'year';
    refresh();
  });
  $popup.on('click', '.ant-picker-today-btn', () => pick(startOfDay(new Date())));
  $popup.on('click', '[data-preset]', function () {
    const p = presets[Number($(this).attr('data-preset'))];
    state.draft = [parse('date', p.from), parse('date', p.to)];
    finish();
  });
  $popup.on('click', '.ant-picker-now-btn', () => {
    state.draft[state.active] = new Date();
    finish();
  });
  $popup.on('click', '.ant-picker-ok .ant-btn', () => {
    if (type === 'rangetime' && state.active === 0) {
      state.active = 1;
      state.view = startOfDay(state.draft[1] || state.draft[0] || new Date());
      moveActiveBar($picker, 1);
      $inputs.eq(1).trigger('focus');
      return refresh();
    }
    if (type === 'rangetime' && state.draft[0] && state.draft[1] && state.draft[0] > state.draft[1])
      state.draft.reverse();
    finish();
  });
}

export default function initPickers() {
  $(document).on('mousedown', '.ant-picker', function (e) {
    if ($(e.target).closest('.ant-picker-clear').length) return;
    const $picker = $(this);
    const $input = $(e.target).closest('.ant-picker-input');
    const index = $input.length ? $picker.find('.ant-picker-input').index($input) : 0;
    if (e.target.tagName !== 'INPUT') e.preventDefault();
    if (isPopupOpenFor(this) && $picker.attr('data-picker').startsWith('range')) return;
    if (isPopupOpenFor(this)) return closePopup();
    open($picker, index);
  });
  $(document).on('mousedown', '.ant-picker-clear', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $picker = $(this).closest('.ant-picker');
    closePopup();
    $picker.find('input').val('');
    $(this).remove();
    $picker.trigger('picker:change', [null]);
  });
  $('.ant-picker-range').each(function () {
    $(this).find('.ant-picker-input').first().addClass('ant-picker-input-active');
  });
}
