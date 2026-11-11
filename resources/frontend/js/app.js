/*
 * JS của website (không jQuery): mở / đóng menu điện thoại, đổi ảnh chính khi bấm ảnh nhỏ ở trang sản phẩm, ô sắp
 * xếp tự gửi form.
 */
document.addEventListener('click', e => {
  const toggle = e.target.closest('[data-nav-toggle]');
  if (toggle) {
    const nav = document.getElementById(toggle.getAttribute('aria-controls'));
    const open = nav.classList.toggle('hidden') === false;
    toggle.setAttribute('aria-expanded', String(open));
    return;
  }

  const thumb = e.target.closest('[data-gallery-thumb]');
  if (thumb) {
    const main = document.querySelector('[data-gallery-main]');
    main.src = thumb.dataset.src;
    main.alt = thumb.dataset.alt || '';
    document.querySelectorAll('[data-gallery-thumb]').forEach(t => t.setAttribute('aria-current', String(t === thumb)));
  }
});

document.addEventListener('change', e => {
  if (e.target.matches('[data-autosubmit]')) e.target.form.requestSubmit();
});

/*
 * Form đăng ký: gõ mã người giới thiệu thì tra tên (GET /ma-gioi-thieu/{code}) để xác nhận đúng người.
 * Nút [data-copy]: sao chép nội dung (mã / link giới thiệu).
 */
const lookup = document.querySelector('[data-referrer-lookup]');
if (lookup) {
  const input = lookup.querySelector('[data-referrer-input]');
  const result = lookup.querySelector('[data-referrer-result]');
  let timer = null;
  let seq = 0;
  const show = (text, ok) => {
    result.textContent = '';
    if (!text) return;
    const span = document.createElement('span');
    span.className = ok ? 'text-brand-700' : 'text-rose-600';
    span.textContent = text;
    result.append(span);
  };
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const code = input.value.trim().toUpperCase();
    if (!/^[A-Z0-9]{3,20}$/.test(code)) return show(code ? 'Mã giới thiệu chỉ gồm chữ và số.' : '', false);
    timer = setTimeout(async () => {
      const mine = ++seq;
      const res = await fetch(`${lookup.dataset.url}/${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' } });
      if (mine !== seq) return;
      if (res.ok) show(`Người giới thiệu: ${(await res.json()).name}`, true);
      else show(res.status === 429 ? 'Bạn gõ quá nhanh, thử lại sau ít phút.' : 'Mã giới thiệu không tồn tại.', false);
    }, 400);
  });
}

// Sao chép: Clipboard API (chỉ có trên HTTPS / localhost); không có thì chép qua ô ẩn + execCommand — không hiện hộp thoại
async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // trình duyệt từ chối quyền: thử cách dự phòng
    }
  }
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
  document.body.append(area);
  area.select();
  area.setSelectionRange(0, text.length);
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch {
    ok = false;
  }
  area.remove();
  return ok;
}

document.addEventListener('click', async e => {
  const button = e.target.closest('[data-copy]');
  if (!button) return;
  const label = button.dataset.label || (button.dataset.label = button.textContent);
  button.textContent = (await copyText(button.dataset.copy)) ? 'Đã chép' : 'Không chép được';
  clearTimeout(button.copyTimer);
  button.copyTimer = setTimeout(() => (button.textContent = label), 1500);
});

/*
 * Thanh toán (S5): đổi hình thức nhận hàng → hiện ô tương ứng + phí ship trong tóm tắt; nhận tại VP: chọn tỉnh lọc danh
 * sách điểm, chọn điểm thì hiện địa chỉ / giờ mở cửa / điện thoại. Dữ liệu điểm lấy từ data-points.
 */
const checkout = document.querySelector('[data-checkout]');
if (checkout) {
  const money = n => new Intl.NumberFormat('vi-VN').format(n).replace(/,/g, '.') + 'đ';
  const points = JSON.parse(checkout.dataset.points || '[]');
  const subtotal = Number(checkout.dataset.subtotal);
  const fee = Number(checkout.dataset.shippingFee);
  const province = checkout.querySelector('[data-pickup-province]');
  const pointSelect = checkout.querySelector('[data-pickup-point]');
  const info = checkout.querySelector('[data-pickup-info]');

  const syncDelivery = () => {
    const type = checkout.querySelector('[data-delivery]:checked')?.value;
    checkout.querySelectorAll('[data-delivery-panel]').forEach(panel => (panel.hidden = panel.dataset.deliveryPanel !== type));
    const ship = type === 'pickup' ? 0 : fee;
    checkout.querySelector('[data-summary-shipping]').textContent = money(ship);
    checkout.querySelector('[data-summary-total]').textContent = money(subtotal + ship);
  };

  const syncInfo = () => {
    const point = points.find(p => String(p.id) === pointSelect.value);
    info.hidden = !point;
    info.textContent = '';
    if (!point) return;
    [point.address, point.opening_hours && `Giờ mở cửa: ${point.opening_hours}`, point.phone && `Điện thoại: ${point.phone}`]
      .filter(Boolean)
      .forEach(line => {
        const p = document.createElement('p');
        p.textContent = line;
        info.append(p);
      });
  };

  const filterPoints = () => {
    const key = province.value;
    [...pointSelect.options].forEach(opt => {
      if (!opt.value) return;
      const show = !key || opt.dataset.province === key;
      opt.hidden = !show;
      opt.disabled = !show;
    });
    if (pointSelect.selectedOptions[0]?.disabled) pointSelect.value = '';
    // Tỉnh chỉ có 1 điểm: chọn luôn
    const visible = [...pointSelect.options].filter(o => o.value && !o.disabled);
    if (key && visible.length === 1) pointSelect.value = visible[0].value;
    syncInfo();
  };

  checkout.addEventListener('change', e => {
    if (e.target.matches('[data-delivery]')) syncDelivery();
    else if (e.target === province) filterPoints();
    else if (e.target === pointSelect) syncInfo();
  });
  syncDelivery();
  syncInfo();
}

// Chọn số lượng (trang sản phẩm): nút −/+ trong [data-qty], giữ trong khoảng min–max của ô số
const syncQty = box => {
  const input = box.querySelector('input');
  const value = Number(input.value) || 1;
  box.querySelector('[data-qty-step="-1"]').disabled = value <= Number(input.min || 1);
  box.querySelector('[data-qty-step="1"]').disabled = value >= Number(input.max || 99);
};
document.querySelectorAll('[data-qty]').forEach(box => {
  syncQty(box);
  box.querySelector('input').addEventListener('input', () => syncQty(box));
});
document.addEventListener('click', e => {
  const step = e.target.closest('[data-qty-step]');
  if (!step) return;
  const box = step.closest('[data-qty]');
  const input = box.querySelector('input');
  const min = Number(input.min || 1);
  const max = Number(input.max || 99);
  input.value = Math.min(max, Math.max(min, (Number(input.value) || min) + Number(step.dataset.qtyStep)));
  syncQty(box);
});

// Chọn biến thể (trang sản phẩm): đổi giá, tên biến thể, dòng tồn kho và số lượng tối đa theo lựa chọn
document.addEventListener('change', e => {
  const radio = e.target.closest('[data-variants] input[name="variant_id"]');
  if (!radio) return;
  document.querySelectorAll('[data-variant-price]').forEach(el => (el.textContent = radio.dataset.price));
  // Biến thể có ảnh riêng: đổi ảnh chính, đánh dấu ảnh nhỏ tương ứng
  const main = document.querySelector('[data-gallery-main]');
  if (radio.dataset.image && main) {
    main.src = radio.dataset.image;
    document.querySelectorAll('[data-gallery-thumb]').forEach(t => t.setAttribute('aria-current', String(t.dataset.src === radio.dataset.image)));
  }
  const name = document.querySelector('[data-variant-name]');
  if (name) name.textContent = radio.dataset.name;
  const stock = document.querySelector('[data-stock]');
  if (stock) {
    stock.textContent = radio.dataset.stockText;
    stock.classList.toggle('text-rose-600', !!radio.dataset.stockLow);
    stock.classList.toggle('font-medium', !!radio.dataset.stockLow);
    stock.classList.toggle('text-slate-500', !radio.dataset.stockLow);
  }
  const qty = document.querySelector('[data-qty]');
  if (qty) {
    const input = qty.querySelector('input');
    input.max = radio.dataset.max;
    if (Number(input.value) > Number(input.max)) input.value = input.max;
    syncQty(qty);
  }
});
