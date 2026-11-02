import { barChart } from './charts';

/*
 * Dashboard (backend/dashboard.blade.php): biểu đồ doanh thu 6 tháng từ khối JSON #dashboard-data (số liệu tính ở
 * DashboardController). Tháng hiện tại tô đậm, kẻ sọc vì chưa hết tháng.
 */

const vnd = n => Math.round(n).toLocaleString('vi-VN');
const compact = n => {
  const abs = Math.abs(n);
  const one = v => v.toFixed(1).replace(/\.0$/, '').replace('.', ',');
  if (abs >= 1e9) return `${one(n / 1e9)} tỷ`;
  if (abs >= 1e6) return `${one(n / 1e6)}tr`;
  if (abs >= 1e3) return `${Math.round(n / 1e3)}k`;
  return String(Math.round(n));
};

export default function initDashboard() {
  const source = document.getElementById('dashboard-data');
  const el = document.getElementById('dashboard-months');
  if (!source || !el) return;
  const { months } = JSON.parse(source.textContent);

  barChart(el, {
    height: 180,
    bars: months.map(m => ({
      label: m.label,
      value: m.value,
      valueLabel: m.value > 0 ? compact(m.value) : '',
      color: '#007bff',
      active: m.current,
      pattern: m.current,
      tooltip: `<div class="mb-1 font-semibold">${m.title}${m.current ? ' (chưa hết tháng)' : ''}</div><div>Doanh thu <b>${vnd(m.value)}</b></div>`,
    })),
  });
}
