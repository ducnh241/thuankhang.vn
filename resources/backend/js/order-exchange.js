/*
 * Trang đổi sản phẩm trong đơn ([data-exchange]): tính trực tiếp thành tiền từng dòng, tổng mới và chênh lệch so với tổng cũ
 * (data-old-total). Nút xác nhận chỉ bật khi tổng mới bằng đúng tổng cũ và có thay đổi. Máy chủ kiểm tra lại toàn bộ.
 *
 * Bộ chọn sản phẩm ([data-picker]): ô tìm kiếm (không dấu cũng tìm được) + danh sách. "Số tiền còn thiếu" của một dòng = tổng
 * cũ − tổng các dòng khác. Sản phẩm có giá CHIA HẾT số tiền đó xếp lên đầu (giá cao trước — ít món nhất) và chọn được; còn
 * lại bị khóa (bật "Cho chọn cả sản phẩm không chia hết" để ghép nhiều sản phẩm). Chọn xong tự điền số lượng vừa khít.
 * Sản phẩm (đúng phân loại) đang bớt / bỏ khỏi đơn luôn bị khóa — không đổi sang chính nó.
 */
const money = value => `${new Intl.NumberFormat('vi-VN').format(value)} đ`;
const fold = text => String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();
const MAX_QTY = 99;
const MAX_RENDER = 200;

function initExchange(form) {
  const oldTotal = Number(form.dataset.oldTotal) || 0;
  const submit = form.querySelector('[data-exchange-submit]');
  const hint = form.querySelector('[data-exchange-hint]');
  let options = [];
  try {
    options = JSON.parse(form.querySelector('[data-exchange-options]')?.textContent || '[]');
  } catch {
    options = [];
  }
  const byValue = new Map(options.map(o => [o.value, { ...o, search: fold(o.label) }]));

  const keepLine = row => {
    const input = row.querySelector('input[data-unit]');
    const qty = Math.max(0, Math.min(Number(input.max) || 0, Math.floor(Number(input.value) || 0)));
    return { qty, max: Number(input.max), amount: qty * Number(input.dataset.unit) };
  };
  const addLine = row => {
    const option = byValue.get(row.querySelector('[data-picker-value]')?.value || '');
    const qty = Math.max(0, Math.floor(Number(row.querySelector('input[type=number]')?.value) || 0));
    return option ? qty * option.price : 0;
  };
  const total = () => [...form.querySelectorAll('[data-keep-row]')].reduce((sum, row) => sum + keepLine(row).amount, 0)
    + [...form.querySelectorAll('[data-add-row]')].reduce((sum, row) => sum + addLine(row), 0);

  const update = () => {
    let changed = false;
    form.querySelectorAll('[data-keep-row]').forEach(row => {
      const line = keepLine(row);
      if (line.qty !== line.max) changed = true;
      row.querySelector('[data-line-total]').textContent = money(line.amount);
    });
    form.querySelectorAll('[data-add-row]').forEach(row => {
      const line = addLine(row);
      if (line > 0) changed = true;
      row.querySelector('[data-line-total]').textContent = money(line);
    });
    const diff = total() - oldTotal;
    form.querySelector('[data-new-total]').textContent = money(total());
    const diffEl = form.querySelector('[data-diff]');
    diffEl.textContent = diff === 0 ? '0 đ — khớp' : `${diff > 0 ? 'thừa' : 'thiếu'} ${money(Math.abs(diff))}`;
    diffEl.style.color = diff === 0 ? '#389e0d' : '#cf1322';
    const ok = diff === 0 && changed;
    submit.disabled = !ok;
    if (hint) hint.textContent = ok ? 'Tổng khớp — có thể xác nhận.' : changed ? 'Tổng mới phải bằng đúng tổng cũ mới đổi được.' : 'Chưa có thay đổi nào.';
  };

  // ---- Bộ chọn sản phẩm ----
  let open = null; // { picker, row, remaining, items, active }

  const close = () => {
    if (!open) return;
    open.picker.querySelector('[data-picker-panel]').classList.add('hidden');
    open.picker.querySelector('[data-picker-toggle]').setAttribute('aria-expanded', 'false');
    open = null;
  };

  const render = () => {
    const { picker, remaining } = open;
    const query = fold(picker.querySelector('[data-picker-search]').value.trim());
    const all = picker.querySelector('[data-picker-all]').checked;
    const list = picker.querySelector('[data-picker-list]');
    // Sản phẩm (đúng phân loại) đang bớt / bỏ khỏi đơn: không đổi sang chính nó
    const reduced = new Set([...form.querySelectorAll('[data-keep-row]')]
      .filter(row => keepLine(row).qty < keepLine(row).max).map(row => row.dataset.itemValue));
    const items = [...byValue.values()]
      .filter(o => !query || query.split(/\s+/).every(word => o.search.includes(word)))
      .map(o => {
        const fits = remaining > 0 && o.price > 0 && remaining % o.price === 0;
        const outOfStock = o.stock !== null && o.stock <= 0;
        const same = reduced.has(o.value);
        const enabled = !outOfStock && !same && (fits || (all && o.price > 0 && o.price <= remaining));
        return { ...o, fits: fits && !same, enabled, outOfStock, same };
      })
      .sort((a, b) => (b.fits - a.fits) || (b.enabled - a.enabled) || (a.fits ? b.price - a.price : a.label.localeCompare(b.label, 'vi')));
    const fitCount = items.filter(o => o.fits && o.enabled).length;

    picker.querySelector('[data-picker-hint]').textContent = remaining > 0
      ? `Còn thiếu ${money(remaining)} — ${fitCount} sản phẩm có giá chia hết số tiền này.`
      : 'Tổng mới đã đủ hoặc thừa — giảm số lượng giữ lại ở bảng trên trước.';

    list.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('li');
      empty.className = 'px-3 py-4 text-center text-sm text-[rgba(0,0,0,0.45)]';
      empty.textContent = 'Không tìm thấy sản phẩm.';
      list.append(empty);
    }
    items.slice(0, MAX_RENDER).forEach((o, index) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.dataset.index = String(index);
      li.setAttribute('aria-disabled', o.enabled ? 'false' : 'true');
      li.className = `flex items-center gap-3 rounded-md px-2 py-2 ${o.enabled ? 'cursor-pointer hover:bg-[#e6f4ff]' : 'cursor-not-allowed opacity-45'}`;

      const thumb = document.createElement('span');
      thumb.className = 'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded border border-[#f0f0f0] bg-white';
      if (o.thumb) {
        const img = document.createElement('img');
        img.src = o.thumb;
        img.alt = '';
        img.loading = 'lazy';
        img.className = 'size-full object-contain';
        thumb.append(img);
      }

      const text = document.createElement('span');
      text.className = 'min-w-0 flex-1';
      const name = document.createElement('span');
      name.className = 'line-clamp-2 text-sm';
      name.textContent = o.label;
      const meta = document.createElement('span');
      meta.className = 'block text-xs text-[rgba(0,0,0,0.45)]';
      meta.textContent = `${money(o.price)}${o.stock !== null ? ` · còn ${Math.max(0, o.stock)}` : ''}${o.outOfStock ? ' · hết hàng' : ''}${o.same ? ' · đang bớt khỏi đơn, không đổi sang chính nó' : ''}`;
      text.append(name, meta);

      li.append(thumb, text);
      if (o.fits && !o.outOfStock) {
        const badge = document.createElement('span');
        badge.className = 'shrink-0 rounded bg-[#f6ffed] px-2 py-0.5 text-xs font-medium text-[#389e0d] tabular-nums';
        badge.textContent = `× ${remaining / o.price} = vừa đủ`;
        li.append(badge);
      }
      list.append(li);
    });
    open.items = items;
    open.active = items.findIndex(o => o.enabled);
    highlight();
  };

  const highlight = () => {
    open.picker.querySelectorAll('[data-picker-list] [role=option]').forEach(li => {
      li.classList.toggle('bg-[#e6f4ff]', Number(li.dataset.index) === open.active);
    });
    open.picker.querySelector(`[data-picker-list] [data-index="${open.active}"]`)?.scrollIntoView({ block: 'nearest' });
  };

  const choose = item => {
    if (!item?.enabled) return;
    const { picker, row, remaining } = open;
    picker.querySelector('[data-picker-value]').value = item.value;
    const label = picker.querySelector('[data-picker-label]');
    label.textContent = `${item.label} — ${money(item.price)}`;
    label.classList.remove('text-[rgba(0,0,0,0.35)]');
    const qty = row.querySelector('input[type=number]');
    if (qty) qty.value = String(Math.max(1, Math.min(MAX_QTY, Math.floor(remaining / item.price))));
    close();
    picker.querySelector('[data-picker-toggle]').focus();
    update();
  };

  const openPicker = picker => {
    close();
    const row = picker.closest('[data-add-row]');
    open = { picker, row, remaining: oldTotal - (total() - addLine(row)), items: [], active: -1 };
    picker.querySelector('[data-picker-panel]').classList.remove('hidden');
    picker.querySelector('[data-picker-toggle]').setAttribute('aria-expanded', 'true');
    const search = picker.querySelector('[data-picker-search]');
    search.value = '';
    render();
    search.focus();
  };

  form.addEventListener('click', event => {
    const toggle = event.target.closest('[data-picker-toggle]');
    if (toggle) {
      const picker = toggle.closest('[data-picker]');
      if (open?.picker === picker) close(); else openPicker(picker);
      return;
    }
    const option = event.target.closest('[data-picker-list] [role=option]');
    if (option && open) choose(open.items[Number(option.dataset.index)]);
    // Thêm / bỏ dòng (repeater.js) thay đổi DOM — tính lại sau khi xử lý xong
    if (event.target.closest('[data-repeater-add], [data-repeater-remove]')) {
      const added = event.target.closest('[data-repeater-add]');
      close();
      setTimeout(() => {
        update();
        // Dòng mới: mở luôn bộ chọn để tìm sản phẩm
        const pickers = form.querySelectorAll('[data-add-row] [data-picker]');
        if (added && pickers.length) openPicker(pickers[pickers.length - 1]);
      }, 0);
    }
  });
  document.addEventListener('mousedown', event => {
    if (open && !open.picker.contains(event.target)) close();
  });

  form.addEventListener('input', event => {
    if (event.target.matches('[data-picker-search]')) {
      render();
      return;
    }
    update();
  });
  form.addEventListener('change', event => {
    if (event.target.matches('[data-picker-all]')) {
      render();
      return;
    }
    update();
  });
  form.addEventListener('keydown', event => {
    if (!open || !event.target.closest('[data-picker-panel]')) return;
    const enabled = open.items.map((o, i) => (o.enabled && i < MAX_RENDER ? i : -1)).filter(i => i >= 0);
    if (event.key === 'Escape') {
      event.preventDefault();
      const toggle = open.picker.querySelector('[data-picker-toggle]');
      close();
      toggle.focus();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!enabled.length) return;
      const at = enabled.indexOf(open.active);
      open.active = enabled[event.key === 'ArrowDown' ? Math.min(enabled.length - 1, at + 1) : Math.max(0, at - 1)];
      highlight();
    } else if (event.key === 'Enter') {
      event.preventDefault(); // không gửi form khi đang chọn
      choose(open.items[open.active]);
    }
  });

  update();
}

export default function initOrderExchanges() {
  document.querySelectorAll('[data-exchange]').forEach(initExchange);
}
