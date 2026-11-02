import $ from 'jquery';

import { escapeHtml } from './core';

/*
 * ProCMS (ducnh.com): chép từ template-admin/src/js/charts.js, thêm `highlight` / `valueColor` cho cột (3 kỳ doanh thu
 * cao nhất tô cam, không bị làm mờ) và `minMax` cho groupedBarChart (năm chưa có số: trục 0 – minMax thay vì 0 – 1);
 * `shortLabels` (vd "1".."12") dùng thay nhãn khi cột quá hẹp (màn điện thoại); số trên đầu cột không tràn ra ngoài.
 *
 * Lightweight SVG charts (no dependency). Each chart renders into a container element, redraws when the
 * container is resized, and shows an Ant Design style tooltip on hover.
 *
 *   barChart(el, { bars: [{ label, value, valueLabel, segments, color, active, pattern, tooltip, highlight, valueColor }], height, reference })
 *   groupedBarChart(el, { labels, shortLabels, series: [{ name, values, color }], height, format, axisFormat, activeIndex, minMax })
 *   lineChart(el, { labels, shortLabels, series: [{ name, values, color, dashed, area }], height, format, activeIndex, minMax, tooltipHtml, tooltipBeside })
 *   stackedBarChart(el, { labels, shortLabels, stacks: [{ name, values, color, pattern }], height, format, activeIndex, marker })
 *   donutChart(el, { segments: [{ label, value, color, tooltip }], size, center })
 *   sparkline(el, values, color)
 */

const NS = 'http://www.w3.org/2000/svg';
const AXIS = 'rgba(0, 0, 0, 0.45)';
const GRID = 'rgba(0, 0, 0, 0.06)';

const observers = new WeakMap();

// Re-render on container resize (charts are drawn in pixels for crisp text).
function mount(el, draw) {
  draw();
  if (observers.has(el)) observers.get(el).disconnect();
  let width = el.clientWidth;
  const ro = new ResizeObserver(() => {
    if (el.clientWidth !== width) {
      width = el.clientWidth;
      draw();
    }
  });
  ro.observe(el);
  observers.set(el, ro);
}

function svg(width, height) {
  const s = document.createElementNS(NS, 'svg');
  s.setAttribute('width', width);
  s.setAttribute('height', height);
  s.setAttribute('viewBox', `0 0 ${width} ${height}`);
  s.style.display = 'block';
  s.style.overflow = 'visible';
  return s;
}

function node(parent, tag, attrs = {}, text) {
  const n = document.createElementNS(NS, tag);
  Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v));
  if (text !== undefined) n.textContent = text;
  parent.appendChild(n);
  return n;
}

// Tooltip inside the chart container, positioned above a point.
function tooltip($el) {
  let $tip = $el.children('.chart-tooltip');
  if (!$tip.length) {
    $el.css('position', 'relative');
    $tip =
      $(`<div class="chart-tooltip ant-tooltip ant-tooltip-placement-top" style="display: none; pointer-events: none">
        <div class="ant-tooltip-content">
          <div class="ant-tooltip-arrow"><span class="ant-tooltip-arrow-content"></span></div>
          <div class="ant-tooltip-inner" role="tooltip"></div>
        </div>
      </div>`).appendTo($el);
  }
  return {
    place(html, x, y) {
      $tip.find('.ant-tooltip-inner').html(html);
      $tip.css({ display: 'block', left: 0, top: 0 });
      const w = $tip.outerWidth();
      const h = $tip.outerHeight();
      const max = $el.innerWidth() - w;
      $tip.css({ left: Math.max(0, Math.min(max, x - w / 2)), top: y - h });
      $tip
        .find('.ant-tooltip-arrow')
        .css('left', Math.max(8, Math.min(w - 8, x - Math.max(0, Math.min(max, x - w / 2)))));
    },
    // ProCMS: đặt cạnh đường dọc x (bên phải, sát mép thì sang trái), đỉnh ở top; hai bên đều không đủ chỗ thì
    // trả về false để gọi show() (đặt phía trên điểm).
    beside(html, x, top, gap = 12) {
      $tip.find('.ant-tooltip-inner').html(html);
      $tip.css({ display: 'block', left: 0, top: 0 });
      const w = $tip.outerWidth();
      const room = $el.innerWidth();
      let left = x + gap;
      if (left + w > room) left = x - gap - w;
      if (left < 0) return false;
      $tip.find('.ant-tooltip-arrow').hide();
      $tip.css({ left, top });
      return true;
    },
    show(html, x, y) {
      $tip.find('.ant-tooltip-arrow').show();
      this.place(html, x, y);
    },
    hide() {
      $tip.hide();
    },
  };
}

// Đo độ rộng thật của chữ cỡ 12px bằng một <text> ẩn đặt ngay trong khung biểu đồ (cùng font, chữ đậm, số
// tabular-nums của thẻ Ant Design). Dùng xong gọi done() để bỏ phần tử đo.
function measurer(el) {
  const probe = document.createElementNS(NS, 'svg');
  probe.setAttribute('width', 0);
  probe.setAttribute('height', 0);
  probe.style.cssText = 'position: absolute; visibility: hidden';
  const t = node(probe, 'text', { 'font-size': 12 });
  el.appendChild(probe);
  const width = (text, weight = 400) => {
    t.setAttribute('font-weight', weight);
    t.textContent = String(text);
    return t.getComputedTextLength();
  };
  return {
    width,
    // x của chữ căn giữa sao cho cả chữ nằm trong [0, max]
    center: (cx, text, max, weight = 400) => {
      const half = width(text, weight) / 2 + 1;
      return Math.max(half, Math.min(max - half, cx));
    },
    done: () => probe.remove(),
  };
}
// Nhãn trục: nhãn ngắn khi nhãn đầy đủ dài nhất không vừa ô
const axisLabels = (m, labels, shortLabels, slot) =>
  shortLabels && slot < Math.max(...labels.map(l => m.width(l, 600))) + 4 ? shortLabels : labels;

const niceMax = max => {
  if (max <= 0) return 1;
  const pow = 10 ** Math.floor(Math.log10(max));
  const step = [1, 2, 2.5, 5, 10].find(s => s * pow * 4 >= max) * pow;
  return step * 4;
};

// Diagonal white stripes laid over a bar: "not final yet" (period still running, forecast).
function hatch(defs, id, stroke = 'rgba(255,255,255,0.55)', bg = 'transparent') {
  const pattern = node(defs, 'pattern', {
    id,
    width: 6,
    height: 6,
    patternUnits: 'userSpaceOnUse',
    patternTransform: 'rotate(45)',
  });
  node(pattern, 'rect', { width: 6, height: 6, fill: bg });
  node(pattern, 'line', { x1: 0, y1: 0, x2: 0, y2: 6, stroke, 'stroke-width': 2.5 });
  return `url(#${id})`;
}

// Dashed horizontal reference line with a small label pill at the left end (the latest bars sit on the right).
// ProCMS: `avoid` = các ô [x, y, w, h] (số trên đầu cột) mà nhãn không được che: thử trái-trên (như template),
// phải-trên, trái-dưới, phải-dưới đường kẻ, lấy chỗ đầu tiên không chồng.
function referenceLine(s, { y, x1, x2, label, color = '#fa8c16', avoid = [] }) {
  node(s, 'line', { x1, x2, y1: y, y2: y, stroke: color, 'stroke-width': 1.5, 'stroke-dasharray': '4 3' });
  if (!label) return;
  const w = label.length * 6.4 + 10;
  const hits = ([px, py]) => avoid.some(([ax, ay, aw, ah]) => px < ax + aw + 2 && ax < px + w + 2 && py < ay + ah + 2 && ay < py + 16 + 2);
  const spots = [[x1, y - 18], [x2 - w, y - 18], [x1, y + 2], [x2 - w, y + 2]];
  const [x, top] = spots.find(spot => !hits(spot)) || spots[0];
  node(s, 'rect', { x, y: top, width: w, height: 16, rx: 3, fill: color });
  node(
    s,
    'text',
    { x: x + w / 2, y: top + 12, 'text-anchor': 'middle', 'font-size': 11, fill: '#fff', 'font-weight': 500 },
    label,
  );
}

/* ---------- bar chart (vertical; each bar may be split into stacked segments) ---------- */

// bars: [{ label, value, valueLabel, segments: [{ value, color }], color, active, pattern, tooltip }]
// The active bar is drawn in full colour, the others faded, so the selected period stands out.
// reference: { value, label } draws a dashed line (e.g. the average of the bars).
export function barChart(el, { bars, height = 150, gap = 0.35, reference, fade = true }) {
  const $el = $(el);
  const tip = tooltip($el);
  mount(el, () => {
    $el.children('svg').remove();
    const width = el.clientWidth;
    const top = 18;
    const bottom = 20;
    const plot = height - top - bottom;
    const max = Math.max(...bars.map(b => b.value), reference?.value || 0, 1);
    const slot = width / bars.length;
    const barW = Math.max(6, Math.min(48, slot * (1 - gap)));
    const s = svg(width, height);
    const stripes = hatch(node(s, 'defs'), `hatch-${el.id || 'bar'}`);
    const anyActive = bars.some(b => b.active);
    const m = measurer(el);

    // Narrow bars: value labels only on hover (tooltip), axis labels every other bar.
    const showValues = slot >= 46; // "131.7M" needs ~40px
    // ProCMS: nhãn dài (vd "13/09/25") cần cột rộng hơn → ước độ rộng theo nhãn dài nhất (~6.6px / ký tự ở cỡ 12px)
    const labelW = Math.max(28, ...bars.map(b => m.width(b.label, 600) + 6));
    const labelEvery = slot >= labelW ? 1 : slot * 2 >= labelW ? 2 : 3;
    bars.forEach((b, i) => {
      const h = Math.max(b.value > 0 ? 3 : 0, (b.value / max) * plot);
      const x = slot * i + (slot - barW) / 2;
      const y = top + plot - h;
      const faded = fade && anyActive && !b.active && !b.highlight;
      const g = node(s, 'g', { opacity: faded ? 0.4 : 1, class: 'chart-bar' });
      // Segments bottom-up; the top one gets the rounded corners via a clip.
      const clip = node(node(s, 'defs'), 'clipPath', { id: `clip-${el.id || 'bar'}-${i}` });
      node(clip, 'rect', { x, y, width: barW, height: h + 3, rx: 3 });
      let base = top + plot;
      (b.segments || [{ value: b.value, color: b.color }]).forEach(seg => {
        const sh = b.value ? (seg.value / b.value) * h : 0;
        if (sh <= 0) return;
        node(g, 'rect', {
          x,
          y: base - sh,
          width: barW,
          height: sh,
          fill: seg.color,
          'clip-path': `url(#clip-${el.id || 'bar'}-${i})`,
        });
        base -= sh;
      });
      if (b.pattern)
        node(g, 'rect', {
          x,
          y,
          width: barW,
          height: h,
          fill: stripes,
          'clip-path': `url(#clip-${el.id || 'bar'}-${i})`,
        });
      if (showValues || b.active || b.highlight) {
        node(
          s,
          'text',
          {
            x: m.center(x + barW / 2, b.valueLabel, width, b.active || b.valueColor ? 600 : 400),
            y: y - 6,
            'text-anchor': 'middle',
            'font-size': 12,
            fill: b.valueColor || (b.active ? 'rgba(0,0,0,0.85)' : AXIS),
            'font-weight': b.active || b.valueColor ? 600 : 400,
          },
          b.valueLabel,
        );
      }
      if ((bars.length - 1 - i) % labelEvery === 0 || b.active)
        node(
          s,
          'text',
          {
            x: m.center(x + barW / 2, b.label, width, b.active ? 600 : 400),
            y: height - 5,
            'text-anchor': 'middle',
            'font-size': 12,
            fill: b.active ? 'rgba(0,0,0,0.85)' : AXIS,
            'font-weight': b.active ? 600 : 400,
          },
          b.label,
        );
      // A full-height transparent hit area makes small bars easy to hover.
      const hit = node(s, 'rect', { x: slot * i, y: 0, width: slot, height, fill: 'transparent' });
      hit.addEventListener('mouseenter', () => {
        g.setAttribute('opacity', 1);
        tip.show(b.tooltip, x + barW / 2, y - 4);
      });
      hit.addEventListener('mouseleave', () => {
        g.setAttribute('opacity', faded ? 0.4 : 1);
        tip.hide();
      });
    });
    if (reference?.value) {
      const y = top + plot - (reference.value / max) * plot;
      // Ô chữ số trên đầu từng cột đang hiện để nhãn trung bình né
      const avoid = [...s.querySelectorAll('text')]
        .filter(t => t.getAttribute('y') < top + plot) // bỏ nhãn trục dưới
        .map(t => {
          const tw = m.width(t.textContent, t.getAttribute('font-weight') || 400);
          return [Number(t.getAttribute('x')) - tw / 2, Number(t.getAttribute('y')) - 11, tw, 13];
        });
      referenceLine(s, { y, x1: 0, x2: width, label: reference.label, avoid });
      // Keep the hover areas on top of the line.
      [...s.querySelectorAll('rect[fill="transparent"]')].forEach(r => s.appendChild(r));
    }
    m.done();
    el.prepend(s);
  });
}

/* ---------- grouped bars (e.g. each month of this year next to the same month last year) ---------- */

// series: [{ name, values, color }] (null = no data, e.g. months still ahead); the first series is the main one.
export function groupedBarChart(
  el,
  { labels, shortLabels, series, height = 260, format = v => v, axisFormat = format, activeIndex = -1, tooltipExtra, minMax = 0 },
) {
  const $el = $(el);
  const tip = tooltip($el);
  mount(el, () => {
    $el.children('svg').remove();
    const width = el.clientWidth;
    const left = 44;
    const right = 4;
    const top = 10;
    const bottom = 26;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const max = niceMax(Math.max(...series.flatMap(ser => ser.values.filter(v => v !== null && v !== undefined)), minMax));
    const y = v => top + plotH - (v / max) * plotH;
    const slot = plotW / labels.length;
    const groupW = Math.min(slot * 0.7, 56);
    const barW = groupW / series.length;
    const s = svg(width, height);
    const m = measurer(el);
    const shown = axisLabels(m, labels, shortLabels, slot);
    m.done();

    for (let t = 0; t <= 4; t++) {
      const v = (max / 4) * t;
      node(s, 'line', { x1: left, x2: width - right, y1: y(v), y2: y(v), stroke: GRID });
      node(s, 'text', { x: left - 8, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 12, fill: AXIS }, axisFormat(v));
    }
    labels.forEach((l, i) => {
      const x0 = left + slot * i + (slot - groupW) / 2;
      if (i === activeIndex)
        node(s, 'rect', { x: left + slot * i, y: top, width: slot, height: plotH, fill: 'rgba(0,123,255,0.05)' });
      series.forEach((ser, k) => {
        const v = ser.values[i];
        if (v === null || v === undefined) return;
        const h = Math.max(v > 0 ? 2 : 0, y(0) - y(v));
        node(s, 'rect', {
          x: x0 + barW * k + 1,
          y: y(0) - h,
          width: Math.max(2, barW - 2),
          height: h,
          rx: 2,
          fill: ser.color,
        });
      });
      node(
        s,
        'text',
        {
          x: left + slot * i + slot / 2,
          y: height - 6,
          'text-anchor': 'middle',
          'font-size': 12,
          fill: i === activeIndex ? 'rgba(0,0,0,0.85)' : AXIS,
          'font-weight': i === activeIndex ? 600 : 400,
        },
        shown[i],
      );
      const hit = node(s, 'rect', { x: left + slot * i, y: top, width: slot, height: plotH, fill: 'transparent' });
      hit.addEventListener('mouseenter', () => {
        const values = series.map(ser => ser.values[i]).filter(v => v !== null && v !== undefined);
        if (!values.length) return;
        const rows = series
          .map(ser =>
            ser.values[i] === null || ser.values[i] === undefined
              ? ''
              : `<div class="flex items-center gap-2 whitespace-nowrap"><span class="inline-block size-2 rounded-sm" style="background:${ser.color}"></span>${escapeHtml(
                  ser.name,
                )}: <b>${format(ser.values[i])}</b></div>`,
          )
          .join('');
        tip.show(
          `<div class="mb-1 font-semibold">${escapeHtml(l)}</div>${rows}${tooltipExtra ? tooltipExtra(i) : ''}`,
          left + slot * i + slot / 2,
          y(Math.max(...values, 0)) - 6,
        );
      });
      hit.addEventListener('mouseleave', () => tip.hide());
    });
    el.prepend(s);
  });
}

/* ---------- donut (share of a whole) ---------- */

// segments: [{ label, value, color, tooltip }]; center: { value, label } written in the hole.
export function donutChart(el, { segments, size = 136, thickness = 18, center }) {
  const $el = $(el);
  const tip = tooltip($el);
  mount(el, () => {
    $el.children('svg').remove();
    const r = (size - thickness) / 2;
    const c = size / 2;
    const circumference = 2 * Math.PI * r;
    const total = segments.reduce((sum, seg) => sum + seg.value, 0);
    const s = svg(size, size);
    s.style.margin = '0 auto';
    node(s, 'circle', { cx: c, cy: c, r, fill: 'none', stroke: '#f0f0f0', 'stroke-width': thickness });
    let offset = 0;
    segments.forEach(seg => {
      if (!total || !seg.value) return;
      const len = (seg.value / total) * circumference;
      // A 1.5px gap between segments; start at 12 o'clock.
      const arc = node(s, 'circle', {
        cx: c,
        cy: c,
        r,
        fill: 'none',
        stroke: seg.color,
        'stroke-width': thickness,
        'stroke-dasharray': `${Math.max(0, len - 1.5)} ${circumference}`,
        'stroke-dashoffset': -offset,
        transform: `rotate(-90 ${c} ${c})`,
        class: 'chart-arc',
      });
      const mid = ((offset + len / 2) / circumference) * 2 * Math.PI - Math.PI / 2;
      arc.addEventListener('mouseenter', () => {
        arc.setAttribute('stroke-width', thickness + 4);
        tip.show(seg.tooltip || escapeHtml(seg.label), c + Math.cos(mid) * r, c + Math.sin(mid) * r - thickness / 2);
      });
      arc.addEventListener('mouseleave', () => {
        arc.setAttribute('stroke-width', thickness);
        tip.hide();
      });
      offset += len;
    });
    if (center) {
      node(
        s,
        'text',
        {
          x: c,
          y: c + 2,
          'text-anchor': 'middle',
          'font-size': 18,
          'pointer-events': 'none',
          'font-weight': 600,
          fill: 'rgba(0,0,0,0.85)',
        },
        center.value,
      );
      node(
        s,
        'text',
        { 'pointer-events': 'none', x: c, y: c + 20, 'text-anchor': 'middle', 'font-size': 12, fill: AXIS },
        center.label,
      );
    }
    el.prepend(s);
  });
}

/* ---------- line chart with optional area and dashed comparison series ---------- */

export function lineChart(
  el,
  { labels, shortLabels, series, height = 260, format = v => v, axisFormat = format, activeIndex = -1, minMax = 0, tooltipHtml, tooltipBeside = false },
) {
  // ProCMS: thêm shortLabels / minMax (như groupedBarChart), tooltipHtml(i) để tự dựng nội dung tooltip, chấm của
  // mọi đường tại tháng đang rê chuột (đường nét đứt chỉ hiện chấm khi rê), nhãn trục không tràn ra ngoài.
  const $el = $(el);
  const tip = tooltip($el);
  mount(el, () => {
    $el.children('svg').remove();
    const width = el.clientWidth;
    const left = 44;
    const right = 8;
    const top = 10;
    const bottom = 26;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const all = series.flatMap(s => s.values.filter(v => v !== null && v !== undefined));
    const max = niceMax(Math.max(...all, minMax));
    const x = i => left + (labels.length === 1 ? plotW / 2 : (plotW * i) / (labels.length - 1));
    const y = v => top + plotH - (v / max) * plotH;
    const s = svg(width, height);
    const m = measurer(el);
    const shown = axisLabels(m, labels, shortLabels, plotW / Math.max(1, labels.length - 1));

    for (let t = 0; t <= 4; t++) {
      const v = (max / 4) * t;
      node(s, 'line', { x1: left, x2: width - right, y1: y(v), y2: y(v), stroke: GRID });
      node(s, 'text', { x: left - 8, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 12, fill: AXIS }, axisFormat(v));
    }
    labels.forEach((l, i) => {
      const weight = i === activeIndex ? 600 : 400;
      node(
        s,
        'text',
        {
          x: m.center(x(i), shown[i], width, weight),
          y: height - 6,
          'text-anchor': 'middle',
          'font-size': 12,
          fill: i === activeIndex ? 'rgba(0,0,0,0.85)' : AXIS,
          'font-weight': weight,
        },
        shown[i],
      );
    });
    m.done();

    // Hover: vertical guide (behind the lines).
    const guide = node(s, 'line', {
      y1: top,
      y2: top + plotH,
      stroke: 'rgba(0,0,0,0.25)',
      'stroke-dasharray': '3 3',
      visibility: 'hidden',
    });

    // Draw comparison series first so the main one sits on top.
    const dots = series.map(() => []);
    [...series].reverse().forEach(ser => {
      const k = series.indexOf(ser);
      const pts = ser.values.map((v, i) => (v === null || v === undefined ? null : [x(i), y(v), i])).filter(Boolean);
      if (!pts.length) return;
      const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0]},${p[1]}`).join('');
      if (ser.area) {
        node(s, 'path', {
          d: `${d}L${pts[pts.length - 1][0]},${y(0)}L${pts[0][0]},${y(0)}Z`,
          fill: ser.color,
          opacity: 0.1,
        });
      }
      node(s, 'path', {
        d,
        fill: 'none',
        stroke: ser.color,
        'stroke-width': ser.dashed ? 1.5 : 2,
        'stroke-dasharray': ser.dashed ? '5 4' : null,
        'stroke-linejoin': 'round',
      });
      // Main line: hollow dots at every point; dashed line: a dot only at the hovered point.
      pts.forEach(([px, py, i]) => {
        dots[k][i] = node(s, 'circle', {
          cx: px,
          cy: py,
          r: 3,
          fill: ser.dashed ? ser.color : '#fff',
          stroke: ser.dashed ? '#fff' : ser.color,
          'stroke-width': ser.dashed ? 1.5 : 2,
          visibility: ser.dashed ? 'hidden' : 'visible',
        });
      });
    });

    const highlight = (i, on) =>
      series.forEach((ser, k) => {
        const dot = dots[k][i];
        if (!dot) return;
        dot.setAttribute('r', on ? (ser.dashed ? 3.5 : 5) : 3);
        if (ser.dashed) dot.setAttribute('visibility', on ? 'visible' : 'hidden');
        else dot.setAttribute('fill', on ? ser.color : '#fff');
      });

    labels.forEach((l, i) => {
      const slotW = plotW / Math.max(1, labels.length - 1);
      const hit = node(s, 'rect', { x: x(i) - slotW / 2, y: top, width: slotW, height: plotH, fill: 'transparent' });
      hit.addEventListener('mouseenter', () => {
        guide.setAttribute('x1', x(i));
        guide.setAttribute('x2', x(i));
        guide.setAttribute('visibility', 'visible');
        highlight(i, true);
        const values = series.map(ser => ser.values[i]).filter(v => v !== null && v !== undefined);
        const html = tooltipHtml
          ? tooltipHtml(i)
          : `<div class="mb-1 font-semibold">${escapeHtml(l)}</div>${series
              .map(ser =>
                ser.values[i] === null || ser.values[i] === undefined
                  ? ''
                  : `<div class="flex items-center gap-2 whitespace-nowrap"><span class="inline-block size-2 rounded-full" style="background:${ser.color}"></span>${escapeHtml(
                      ser.name,
                    )}: <b>${format(ser.values[i])}</b></div>`,
              )
              .join('')}`;
        if (!tooltipBeside || !tip.beside(html, x(i), top)) tip.show(html, x(i), y(Math.max(...values, 0)) - 8);
      });
      hit.addEventListener('mouseleave', () => {
        guide.setAttribute('visibility', 'hidden');
        highlight(i, false);
        tip.hide();
      });
    });
    el.prepend(s);
  });
}

/* ---------- stacked bars (e.g. collected + expected) ---------- */

// stacks: [{ name, values, color, pattern }] (pattern = striped, e.g. a forecast); marker: { index, label } draws a
// dashed vertical line through that slot (e.g. today).
export function stackedBarChart(
  el,
  { labels, shortLabels, stacks, height = 140, format = v => v, totalFormat = format, activeIndex = -1, marker },
) {
  const $el = $(el);
  const tip = tooltip($el);
  mount(el, () => {
    $el.children('svg').remove();
    const width = el.clientWidth;
    const top = marker ? 34 : 18; // room for the marker label above the value labels
    const bottom = 20;
    const plotH = height - top - bottom;
    const totals = labels.map((_, i) => stacks.reduce((sum, st) => sum + (st.values[i] || 0), 0));
    const max = Math.max(...totals, 1);
    const slot = width / labels.length;
    const barW = Math.max(6, Math.min(36, slot * 0.45));
    const s = svg(width, height);
    const defs = node(s, 'defs');
    const m = measurer(el);
    const shown = axisLabels(m, labels, shortLabels, slot);
    m.done();
    const fills = stacks.map((st, k) =>
      st.pattern ? hatch(defs, `hatch-${el.id || 'stack'}-${k}`, st.color, `${st.color}33`) : st.color,
    );

    labels.forEach((l, i) => {
      const cx = slot * i + slot / 2;
      let base = top + plotH;
      stacks.forEach((st, k) => {
        const v = st.values[i] || 0;
        if (!v) return;
        const h = Math.max(2, (v / max) * plotH);
        node(s, 'rect', {
          x: cx - barW / 2,
          y: base - h,
          width: barW,
          height: h,
          fill: fills[k],
          stroke: st.pattern ? st.color : 'none',
          'stroke-width': st.pattern ? 1 : 0,
        });
        base -= h;
      });
      if (totals[i] && slot >= 46)
        node(
          s,
          'text',
          { x: cx, y: base - 6, 'text-anchor': 'middle', 'font-size': 12, fill: AXIS },
          totalFormat(totals[i]),
        );
      node(
        s,
        'text',
        {
          x: cx,
          y: height - 5,
          'text-anchor': 'middle',
          'font-size': 12,
          fill: i === activeIndex ? 'rgba(0,0,0,0.85)' : AXIS,
          'font-weight': i === activeIndex ? 600 : 400,
        },
        shown[i],
      );
      const hit = node(s, 'rect', { x: slot * i, y: 0, width: slot, height, fill: 'transparent' });
      hit.addEventListener('mouseenter', () => {
        const rows = stacks
          .map(
            st =>
              `<div class="flex items-center gap-2 whitespace-nowrap"><span class="inline-block size-2 rounded-full" style="background:${st.color}"></span>${escapeHtml(
                st.name,
              )}: <b>${format(st.values[i] || 0)}</b></div>`,
          )
          .join('');
        tip.show(`<div class="mb-1 font-semibold">${escapeHtml(l)}</div>${rows}`, cx, base - 4);
      });
      hit.addEventListener('mouseleave', () => tip.hide());
    });
    if (marker && marker.index >= 0) {
      // Behind the bars, with its label in the strip reserved above them.
      const mx = slot * marker.index + slot / 2;
      const line = node(s, 'line', {
        x1: mx,
        x2: mx,
        y1: 16,
        y2: top + plotH,
        stroke: '#fa8c16',
        'stroke-dasharray': '3 3',
      });
      s.insertBefore(line, s.querySelector('defs').nextSibling);
      if (marker.label) {
        const w = marker.label.length * 6.4 + 10;
        node(s, 'rect', { x: mx - w / 2, y: 0, width: w, height: 16, rx: 3, fill: '#fa8c16' });
        node(
          s,
          'text',
          { x: mx, y: 12, 'text-anchor': 'middle', 'font-size': 11, fill: '#fff', 'font-weight': 500 },
          marker.label,
        );
      }
    }
    el.prepend(s);
  });
}

/* ---------- sparkline ---------- */

export function sparkline(el, values, color) {
  mount(el, () => {
    $(el).empty();
    const width = el.clientWidth || 80;
    const height = el.clientHeight || 32;
    const max = Math.max(...values, 1);
    const pts = values.map((v, i) => [
      (width * i) / Math.max(1, values.length - 1),
      height - 2 - (v / max) * (height - 4),
    ]);
    const d = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0]},${p[1]}`).join('');
    const s = svg(width, height);
    node(s, 'path', { d: `${d}L${width},${height}L0,${height}Z`, fill: color, opacity: 0.12 });
    node(s, 'path', { d, fill: 'none', stroke: color, 'stroke-width': 1.5, 'stroke-linejoin': 'round' });
    el.appendChild(s);
  });
}
