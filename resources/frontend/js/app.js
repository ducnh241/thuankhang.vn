/*
 * JS của website (không jQuery): ngăn kéo menu điện thoại (trượt từ trái), đổi ảnh chính khi bấm ảnh nhỏ ở trang sản phẩm, ô sắp
 * xếp tự gửi form.
 */
document.addEventListener('click', e => {
  if (e.target.closest('[data-nav-toggle]')) {
    openMobileNav();
    return;
  }
  if (e.target.closest('[data-nav-close]')) {
    closeMobileNav();
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
 * KYC (S10): ô ảnh CCCD [data-kyc-image] — xem trước ảnh vừa chọn. Ảnh chụp bằng điện thoại thường nặng 3–8 MB, trong khi
 * máy chủ hay giới hạn dung lượng một lần gửi (nginx mặc định 1 MB cho cả form) → thu nhỏ ngay trên máy trước khi gửi:
 * lần lượt thử các mức (cạnh dài, chất lượng JPEG) cho tới khi ảnh ≤ 480 KB — hai ảnh vẫn dưới 1 MB, chữ trên thẻ vẫn
 * đọc rõ. Không thu nhỏ được (trình duyệt cũ, ảnh lỗi) thì gửi ảnh gốc; ảnh vượt mức máy chủ cho phép thì báo ngay.
 */
const KYC_TARGET_BYTES = 480 * 1024;
const KYC_STEPS = [
  [1600, 0.82],
  [1600, 0.7],
  [1280, 0.7],
  [1280, 0.55],
  [1024, 0.55],
];
async function shrinkImage(file) {
  if (file.size <= KYC_TARGET_BYTES) return file;
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    img.src = url;
    await img.decode();
    let best = file;
    for (const [maxSide, quality] of KYC_STEPS) {
      const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.naturalWidth * scale);
      canvas.height = Math.round(img.naturalHeight * scale);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff'; // ảnh PNG / WEBP nền trong suốt
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (blob && blob.size < best.size) {
        best = new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
      }
      if (best.size <= KYC_TARGET_BYTES) break;
    }
    return best;
  } catch {
    return file;
  } finally {
    URL.revokeObjectURL(url);
  }
}
document.querySelectorAll('[data-kyc-image]').forEach(box => {
  const input = box.querySelector('input[type="file"]');
  const preview = box.querySelector('[data-kyc-preview]');
  const note = box.querySelector('[data-kyc-note]');
  const maxBytes = Number(box.dataset.maxBytes) || 0;
  const say = (text, bad = false) => {
    note.textContent = text;
    note.hidden = !text;
    note.classList.toggle('text-rose-600', bad);
    note.classList.toggle('text-slate-500', !bad);
  };
  input.addEventListener('change', async () => {
    let file = input.files[0];
    if (preview.src) URL.revokeObjectURL(preview.src);
    preview.removeAttribute('src');
    preview.hidden = true;
    say('');
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      input.value = '';
      return say('Chọn ảnh JPG, PNG hoặc WEBP.', true);
    }
    const small = await shrinkImage(file);
    if (small !== file) {
      try {
        const transfer = new DataTransfer();
        transfer.items.add(small);
        input.files = transfer.files;
        file = small;
      } catch {
        // Trình duyệt cũ không cho gán lại file: gửi ảnh gốc
      }
    }
    if (maxBytes && file.size > maxBytes) {
      input.value = '';
      return say(`Ảnh quá lớn (${(file.size / 1048576).toFixed(1)} MB). Hãy chọn ảnh nhẹ hơn.`, true);
    }
    preview.src = URL.createObjectURL(file);
    preview.hidden = false;
    say('Đã chọn ảnh. Bấm vào ảnh để đổi.'); // không hiện tên tệp: tên ảnh từ điện thoại / Zalo rất dài, tràn sang ô bên cạnh
  });
});
/*
 * Ô ngày dạng chữ [data-date-input] (ngày sinh ở form KYC): gõ số, tự chèn dấu "/" thành dd/mm/yyyy. Gõ "5" cho ngày hoặc
 * "7" cho tháng thì hiểu là 05 / 07; tự gõ dấu / - . hay dấu cách cũng được ("5/7/1985" → 05/07/1985). Rời ô: báo nếu
 * ngày không có thật, ở tương lai hoặc trước 1900 (máy chủ vẫn kiểm tra lại).
 */
function formatDateText(raw, deleting) {
  const parts = ['', '', ''];
  let i = 0;
  for (const ch of raw) {
    if (/\d/.test(ch)) {
      if (i < 2) {
        parts[i] += ch;
        // Chữ số đầu không thể là hàng chục (ngày > 3, tháng > 1): thêm 0 phía trước
        if (parts[i].length === 1 && Number(ch) > (i === 0 ? 3 : 1)) parts[i] = '0' + ch;
        if (parts[i].length === 2) i++;
      } else if (parts[2].length < 4) {
        parts[2] += ch;
      }
    } else if (i < 2 && parts[i].length === 1) {
      parts[i] = '0' + parts[i];
      i++;
    }
  }
  let text = parts[0] + (i >= 1 ? '/' : '') + parts[1] + (i >= 2 ? '/' : '') + parts[2];
  // Đang xóa lùi: bỏ dấu "/" ở cuối để xóa tiếp được chữ số trước nó
  if (deleting && text.endsWith('/')) text = text.slice(0, -1);
  return text;
}
function dateTextError(text) {
  if (text === '') return '';
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text);
  if (!m) return 'Nhập đủ ngày/tháng/năm, vd 20/05/1979.';
  const [day, month, year] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const date = new Date(year, month - 1, day);
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return 'Ngày này không có thật. Kiểm tra lại ngày/tháng/năm.';
  if (year < 1900) return 'Năm sinh không hợp lệ.';
  if (date >= new Date(new Date().setHours(0, 0, 0, 0))) return 'Ngày sinh phải trước hôm nay.';
  return '';
}
document.querySelectorAll('[data-date-input]').forEach(input => {
  const error = input.parentElement.querySelector('[data-date-error]');
  const showError = text => {
    if (!error) return;
    error.textContent = text;
    error.hidden = !text;
    input.toggleAttribute('aria-invalid', !!text);
  };
  input.addEventListener('input', e => {
    showError('');
    // Chỉ định dạng lại khi đang gõ ở cuối ô; sửa giữa chừng thì để nguyên (rời ô sẽ kiểm tra)
    if (input.selectionStart !== input.value.length) return;
    input.value = formatDateText(input.value, (e.inputType || '').startsWith('delete'));
  });
  input.addEventListener('blur', () => {
    if (/^\d{1,2}\D\d{1,2}\D\d{4}$/.test(input.value.trim())) input.value = formatDateText(input.value.trim(), false);
    showError(dateTextError(input.value.trim()));
  });
});

// Gửi hồ sơ: khóa nút để không gửi hai lần trong lúc tải ảnh lên
document.querySelector('[data-kyc-form]')?.addEventListener('submit', e => {
  const button = e.target.querySelector('[data-kyc-submit]');
  if (button.disabled) return e.preventDefault();
  button.disabled = true;
  button.textContent = 'Đang gửi hồ sơ…';
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

/*
 * Slider ngang ([data-slider] > [data-slider-track] > [data-slider-item]): nút ‹ › chuyển 1 sản phẩm (tự tắt ở hai đầu, ẩn khi không có gì
 * để cuộn); máy tính kéo chuột để gạt (kéo xong không mở link); điện thoại vuốt sẵn có (scroll-snap).
 */
document.querySelectorAll('[data-slider]').forEach(slider => {
  const track = slider.querySelector('[data-slider-track]');
  const nav = slider.querySelector('[data-slider-nav]');
  const prev = slider.querySelector('[data-slider-prev]');
  const next = slider.querySelector('[data-slider-next]');
  if (!track) return;

  const update = () => {
    const max = track.scrollWidth - track.clientWidth;
    if (nav) nav.hidden = max <= 4;
    if (prev) prev.disabled = track.scrollLeft <= 4;
    if (next) next.disabled = track.scrollLeft >= max - 4;
  };
  // Mỗi lần bấm chuyển đúng 1 sản phẩm (độ rộng thẻ + khoảng cách)
  const step = () => {
    const items = track.querySelectorAll('[data-slider-item]');
    if (items.length < 2) return track.clientWidth;
    return items[1].getBoundingClientRect().left - items[0].getBoundingClientRect().left;
  };
  const page = dir => track.scrollBy({ left: dir * step(), behavior: 'smooth' });
  prev?.addEventListener('click', () => page(-1));
  next?.addEventListener('click', () => page(1));
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();

  // Kéo chuột (chỉ chuột — cảm ứng dùng cuộn gốc của trình duyệt)
  let startX = 0;
  let startLeft = 0;
  let moved = false;
  let down = false;
  track.addEventListener('pointerdown', e => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    down = true;
    moved = false;
    startX = e.clientX;
    startLeft = track.scrollLeft;
  });
  track.addEventListener('pointermove', e => {
    if (!down) return;
    const dx = e.clientX - startX;
    if (!moved && Math.abs(dx) > 5) {
      moved = true;
      track.classList.add('is-dragging');
      track.setPointerCapture(e.pointerId);
    }
    if (moved) track.scrollLeft = startLeft - dx;
  });
  const end = () => {
    if (!down) return;
    down = false;
    track.classList.remove('is-dragging');
  };
  track.addEventListener('pointerup', end);
  track.addEventListener('pointercancel', end);
  // Vừa kéo xong: chặn click (không mở sản phẩm / không thêm vào giỏ), chặn kéo ảnh / link gốc
  track.addEventListener('click', e => {
    if (moved) {
      e.preventDefault();
      e.stopPropagation();
      moved = false;
    }
  }, true);
  track.addEventListener('dragstart', e => e.preventDefault());
});

/*
 * Ngăn kéo menu điện thoại: bỏ hidden → ép vẽ lại → thêm .is-open (để CSS trượt vào); đóng: bỏ .is-open, hết hiệu ứng
 * thì hidden lại. Khóa cuộn trang khi mở; Esc / chạm nền / nút X để đóng; trả focus về nút ☰.
 */
const mobileNav = document.querySelector('[data-mobile-nav]');
const navToggle = document.querySelector('[data-nav-toggle]');
let navOpen = false;
function openMobileNav() {
  if (!mobileNav || navOpen) return;
  navOpen = true;
  mobileNav.hidden = false;
  document.documentElement.style.overflow = 'hidden';
  navToggle?.setAttribute('aria-expanded', 'true');
  // Đọc offsetWidth buộc trình duyệt vẽ trạng thái đóng trước, rồi mới thêm .is-open → hiệu ứng trượt chạy
  void mobileNav.offsetWidth;
  mobileNav.classList.add('is-open');
  mobileNav.querySelector('[data-nav-close][aria-label]')?.focus({ preventScroll: true });
}
function closeMobileNav() {
  if (!mobileNav || !navOpen) return;
  navOpen = false;
  mobileNav.classList.remove('is-open');
  document.documentElement.style.overflow = '';
  navToggle?.setAttribute('aria-expanded', 'false');
  const panel = mobileNav.querySelector('nav');
  const done = () => {
    if (!navOpen) mobileNav.hidden = true;
  };
  panel.addEventListener('transitionend', done, { once: true });
  setTimeout(done, 350); // phòng khi không có transitionend
  navToggle?.focus({ preventScroll: true });
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeMobileNav();
});
// Màn hình rộng ra (xoay ngang / đổi cỡ): đóng ngăn kéo
window.matchMedia('(min-width: 768px)').addEventListener('change', e => e.matches && closeMobileNav());
