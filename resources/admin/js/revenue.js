import $ from 'jquery';

import { barChart, donutChart, lineChart, sparkline, stackedBarChart } from './charts';

/*
 * Báo cáo doanh thu (backend/reports/revenue.blade.php), theo src/js/revenue.js của template. Số liệu đã tính ở server
 * (ReportController) và nằm trong <script type="application/json" id="revenue-data">; ở đây chỉ vẽ biểu đồ, đổi kỳ
 * tùy chọn bằng bộ chọn ngày và đổi tab xếp hạng khách hàng.
 */

const NEW = '#007bff';
const RENEWAL = '#13c2c2';
const PROJECT_VALUE = '#faad14';
const PREVIOUS_YEAR = '#8c8c8c';
// 3 kỳ doanh thu cao nhất: thu mới / gia hạn tô 2 sắc cam, không bị làm mờ
const TOP_NEW = '#fa8c16';
const TOP_RENEWAL = '#ffc069';
const MONTHS = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'];
// Màn hẹp (cột không đủ chỗ cho "T10"): trục chỉ ghi số tháng; tooltip vẫn ghi "T10"
const MONTH_NUMBERS = MONTHS.map((m, i) => String(i + 1));

const vnd = n => Math.round(n).toLocaleString('vi-VN');
// Số gọn kiểu Việt: 12,5tr / 1,2 tỷ / 500k (giống $short ở view)
const compact = n => {
  const abs = Math.abs(n);
  const one = v => v.toFixed(1).replace(/\.0$/, '').replace('.', ',');
  if (abs >= 1e9) return `${one(n / 1e9)} tỷ`;
  if (abs >= 1e6) return `${one(n / 1e6)}tr`;
  if (abs >= 1e3) return `${Math.round(n / 1e3)}k`;
  return String(Math.round(n));
};
const pct = (cur, prev) => (prev ? ((cur - prev) / prev) * 100 : null);
const fmtPct = p => `${p > 0 ? '+' : ''}${p.toFixed(1).replace('.', ',')}%`;
const swatch = color => `<span class="chart-swatch" style="background:${color}"></span>`;

function trendChart(data) {
  const last = data.trend.length - 1;
  barChart(document.getElementById('revenue-bars'), {
    height: 170,
    reference: data.average ? { value: data.average, label: `TB ${compact(data.average)}` } : null,
    bars: data.trend.map((t, i) => ({
      label: t.label,
      value: t.total,
      valueLabel: t.total > 0 ? compact(t.total) : '',
      segments: [
        { value: t.new, color: t.top ? TOP_NEW : NEW },
        { value: t.renewal, color: t.top ? TOP_RENEWAL : RENEWAL },
      ],
      pattern: i === last && t.running,
      active: i === last,
      highlight: !!t.top,
      valueColor: t.top ? '#d46b08' : null,
      tooltip: `<div class="mb-1 font-semibold">${t.label} · ${t.range}</div>
        <div class="flex items-center gap-2 whitespace-nowrap">${swatch(t.top ? TOP_NEW : NEW)}Thu mới: <b>${vnd(t.new)}</b></div>
        <div class="flex items-center gap-2 whitespace-nowrap">${swatch(t.top ? TOP_RENEWAL : RENEWAL)}Gia hạn: <b>${vnd(t.renewal)}</b></div>
        <div class="mt-1 border-t border-white/20 pt-1">Tổng <b>${vnd(t.total)}</b>${
          data.average ? ` · ${fmtPct(pct(t.total, data.average))} so với trung bình` : ''
        }</div>${t.top ? `<div class="text-[#ffc069]">Top ${t.top} doanh thu</div>` : ''}${
          i === last && t.running ? '<div class="opacity-75">Kỳ chưa kết thúc</div>' : ''
        }`,
    })),
  });
}

function mixDonut(data) {
  const total = data.mix.new + data.mix.renewal;
  const share = total ? Math.round((data.mix.renewal / total) * 100) : 0;
  donutChart(document.getElementById('mix-donut'), {
    size: 120,
    thickness: 16,
    center: { value: compact(total), label: 'tổng' },
    segments: [
      { label: 'Thu mới', value: data.mix.new, color: NEW, tooltip: `Thu mới: <b>${vnd(data.mix.new)}</b> (${total ? 100 - share : 0}%)` },
      { label: 'Gia hạn', value: data.mix.renewal, color: RENEWAL, tooltip: `Gia hạn: <b>${vnd(data.mix.renewal)}</b> (${share}%)` },
    ],
  });
}

function yearChart(data) {
  const { year, months } = data;
  const row = (color, label, value) =>
    `<div class="flex items-center justify-between gap-4 whitespace-nowrap"><span class="flex items-center gap-2"><span class="inline-block size-2 rounded-full" style="background:${color}"></span>${label}</span><b>${value}</b></div>`;
  // Đường liền: năm đang xem (tô nền, chấm từng tháng); đường nét đứt: năm trước
  lineChart(document.getElementById('year-chart'), {
    labels: MONTHS,
    shortLabels: MONTH_NUMBERS,
    height: 280,
    format: vnd,
    axisFormat: compact,
    activeIndex: months.active,
    minMax: 1e6, // chưa có số: trục 0 – 1tr thay vì các mức lẻ
    tooltipBeside: true, // tooltip cạnh đường dọc, không che tiêu đề / chú thích
    series: [
      { name: `Năm ${year}`, values: months.current, color: NEW, area: true },
      { name: `Năm ${year - 1}`, values: months.previous, color: PREVIOUS_YEAR, dashed: true },
    ],
    tooltipHtml: m => {
      const cur = months.current[m];
      const prev = months.previous[m];
      const p = cur !== null && prev ? pct(cur, prev) : null;
      return `<div class="mb-1 font-semibold">Tháng ${m + 1}</div>
        ${row(NEW, `Năm ${year}`, cur === null ? 'chưa đến' : vnd(cur))}
        ${cur === null ? '' : `<div class="pl-4 text-xs opacity-75">Thu mới ${vnd(months.new[m])} · gia hạn ${vnd(months.renewal[m])}</div>`}
        ${row(PREVIOUS_YEAR, `Năm ${year - 1}`, vnd(prev))}
        ${
          p === null
            ? ''
            : `<div class="mt-1 border-t border-white/20 pt-1"><b class="${p >= 0 ? 'text-[#95de64]' : 'text-[#ff7875]'}">${fmtPct(p)}</b> <span class="opacity-75">so với cùng tháng năm trước</span></div>`
        }`;
    },
  });
}

function renewalChart(data) {
  const { renewals } = data;
  stackedBarChart(document.getElementById('renewal-chart'), {
    labels: MONTHS,
    shortLabels: MONTH_NUMBERS,
    height: 170,
    format: vnd,
    totalFormat: compact,
    activeIndex: renewals.today,
    marker: { index: renewals.today, label: 'Hôm nay' },
    stacks: [
      { name: 'Đã thu', values: renewals.collected, color: '#52c41a' },
      { name: 'Dự kiến', values: renewals.expected, color: '#73d13d', pattern: true },
    ],
  });
}

export default function initRevenue() {
  const source = document.getElementById('revenue-data');
  if (!source) return;
  const data = JSON.parse(source.textContent);

  trendChart(data);
  mixDonut(data);
  sparkline(document.getElementById('project-value-spark'), data.projectValues, PROJECT_VALUE);
  yearChart(data);
  renewalChart(data);

  // Kỳ tùy chọn: chọn xong khoảng ngày thì tải lại báo cáo
  $('#custom-range').on('picker:change', function (e, dates) {
    if (!dates || !dates[0] || !dates[1]) return;
    const inputs = $(this).find('input');
    const url = new URL(this.dataset.url, window.location.origin);
    url.searchParams.set('period', 'custom');
    url.searchParams.set('from', inputs.eq(0).val());
    url.searchParams.set('to', inputs.eq(1).val());
    window.location.href = url.toString();
  });

  // Tab xếp hạng khách hàng: Tổng / Trực tiếp / Giới thiệu
  $('#ltv-tabs').on('radio:change', (e, value) => {
    $('[data-ranking-panel]').each(function () {
      $(this).toggleClass('hidden', this.dataset.rankingPanel !== value);
    });
  });
}
