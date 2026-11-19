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
 * lần lượt thử các mức (cạnh dài, chất lượng JPEG) cho tới khi ảnh ≤ mức đích của ô (data-target-bytes; mặc định 360 KB
 * cho ảnh CCCD, 200 KB cho ảnh mã QR) — cả form KYC (2 ảnh CCCD + 1 ảnh QR) vẫn dưới 1 MB, chữ trên thẻ vẫn đọc rõ. Không thu nhỏ được (trình duyệt cũ, ảnh lỗi) thì gửi ảnh gốc; ảnh vượt mức máy chủ cho phép thì báo ngay.
 */
const KYC_TARGET_BYTES = 360 * 1024;
const KYC_STEPS = [
  [1600, 0.82],
  [1600, 0.7],
  [1280, 0.7],
  [1280, 0.55],
  [1024, 0.55],
  [800, 0.5],
];
async function shrinkImage(file, target = KYC_TARGET_BYTES) {
  if (file.size <= target) return file;
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
      if (best.size <= target) break;
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
  const targetBytes = Number(box.dataset.targetBytes) || KYC_TARGET_BYTES;
  const current = box.querySelector('[data-kyc-current]'); // ảnh đang lưu (mã QR): ẩn khi đã chọn ảnh mới
  const say = (text, bad = false) => {
    note.textContent = text;
    note.hidden = !text;
    note.classList.toggle('text-rose-600', bad);
    note.classList.toggle('text-slate-500', !bad);
  };
  const process = async () => {
    let file = input.files[0];
    if (preview.src) URL.revokeObjectURL(preview.src);
    preview.removeAttribute('src');
    preview.hidden = true;
    if (current) current.hidden = false;
    say('');
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      input.value = '';
      return say('Chọn ảnh JPG, PNG hoặc WEBP.', true);
    }
    const small = await shrinkImage(file, targetBytes);
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
    if (current) current.hidden = true;
    say('Đã chọn ảnh. Bấm vào ảnh để đổi.'); // không hiện tên tệp: tên ảnh từ điện thoại / Zalo rất dài, tràn sang ô bên cạnh
  };
  input.addEventListener('change', async () => {
    box.dispatchEvent(new CustomEvent('kyc-image:busy', { bubbles: true }));
    try {
      await process();
    } finally {
      // Báo cho form bao ngoài biết ô ảnh đã xử lý xong (còn ảnh hợp lệ hay đã bị bỏ)
      box.dispatchEvent(new CustomEvent('kyc-image:done', { bubbles: true }));
    }
  });
});
/*
 * Form "Tôi đã chuyển khoản" [data-bill-form] ở trang đơn hàng: bắt buộc có ảnh bill — nút gửi chỉ bật khi đã chọn ảnh hợp
 * lệ (đang thu nhỏ ảnh thì tạm tắt). Không có JS thì nút vẫn bấm được và máy chủ báo lỗi thiếu ảnh.
 */
document.querySelectorAll('[data-bill-form]').forEach(form => {
  const input = form.querySelector('input[type="file"]');
  const button = form.querySelector('[data-bill-submit]');
  const hint = form.querySelector('[data-bill-hint]');
  const sync = (busy = false) => {
    const ready = !busy && input.files.length > 0;
    button.disabled = !ready;
    if (hint) hint.hidden = ready || busy;
  };
  form.addEventListener('kyc-image:busy', () => sync(true));
  form.addEventListener('kyc-image:done', () => sync());
  form.addEventListener('submit', event => {
    if (!input.files.length) event.preventDefault();
  });
  sync();
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

/*
 * Gợi ý "Cài ứng dụng" [data-install-app] (thêm website vào màn hình chính, mở như ứng dụng riêng). Chỉ hiện trên điện thoại
 * / máy tính bảng, khi trang CHƯA chạy ở chế độ ứng dụng và khách chưa bấm đóng (nhớ 30 ngày).
 *   - Android: trình duyệt phát sự kiện beforeinstallprompt → hiện nút "Cài ứng dụng" (bấm là ra hộp thoại cài của trình
 *     duyệt); trình duyệt không phát sự kiện (Zalo, Facebook, Firefox…) → hiện các bước làm tay.
 *   - iPhone / iPad: iOS không có cài bằng nút → chỉ hiện các bước (Safari → Chia sẻ → Thêm vào Màn hình chính).
 */
const installBoxes = document.querySelectorAll('[data-install-app]');
if (installBoxes.length) {
  const DISMISS_KEY = 'tk_install_dismissed';
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const ua = navigator.userAgent;
  const android = /android/i.test(ua);
  // iPad đời mới tự nhận là máy Mac có cảm ứng
  const ios = !android && (/iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));
  let dismissed = false;
  try {
    dismissed = Number(localStorage.getItem(DISMISS_KEY) || 0) > Date.now() - 30 * 864e5;
  } catch {
    // Không dùng được localStorage (chế độ riêng tư): coi như chưa đóng
  }
  let installEvent = null;
  const render = () => {
    installBoxes.forEach(box => {
      box.hidden = standalone || dismissed || !(ios || android || installEvent);
      box.querySelector('[data-install-button]').hidden = !installEvent;
      box.querySelector('[data-install-ios]').hidden = !ios;
      box.querySelector('[data-install-android]').hidden = !android || !!installEvent;
    });
  };
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault(); // giữ lại để tự hiện khi khách bấm nút
    installEvent = event;
    render();
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    dismissed = true;
    render();
  });
  installBoxes.forEach(box => {
    box.querySelector('[data-install-button]').addEventListener('click', async () => {
      if (!installEvent) return;
      installEvent.prompt();
      const choice = await installEvent.userChoice;
      installEvent = null; // mỗi sự kiện chỉ hỏi được một lần
      if (choice && choice.outcome === 'accepted') dismissed = true;
      render();
    });
    box.querySelector('[data-install-close]').addEventListener('click', () => {
      dismissed = true;
      try {
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
      } catch {
        // bỏ qua
      }
      render();
    });
  });
  render();
}

/*
 * Popup (<dialog>): nút [data-dialog-open="id"] mở, [data-dialog-close] hoặc bấm nền tối thì đóng. Dùng ở trang Ví hoa hồng
 * (chi tiết cách tính từng dòng).
 */
document.addEventListener('click', e => {
  const opener = e.target.closest('[data-dialog-open]');
  if (opener) {
    document.getElementById(opener.dataset.dialogOpen)?.showModal();
    return;
  }
  if (e.target.closest('[data-dialog-close]')) {
    e.target.closest('dialog')?.close();
    return;
  }
  if (e.target instanceof HTMLDialogElement && e.target.open) {
    const box = e.target.getBoundingClientRect();
    if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) e.target.close();
  }
});

/*
 * Biểu đồ hoa hồng lũy kế (trang Ví): rê chuột / chạm vào vùng vẽ → đường dọc, chấm trên hai đường, tooltip giá trị kỳ này
 * và kỳ trước tại điểm gần nhất. Dữ liệu: data-points = [[nhãn trục, nhãn tooltip, kỳ này | null, kỳ trước | null], ...].
 */
document.querySelectorAll('[data-wallet-chart]').forEach(chart => {
  const points = JSON.parse(chart.dataset.points);
  const plot = chart.querySelector('[data-chart-plot]');
  const crosshair = plot.querySelector('[data-chart-crosshair]');
  const tip = plot.querySelector('[data-chart-tip]');
  const dots = { current: plot.querySelector('[data-chart-dot="current"]'), previous: plot.querySelector('[data-chart-dot="previous"]') };
  const max = Math.max(...points.flatMap(p => [p[2] ?? 0, p[3] ?? 0]));
  const money = v => new Intl.NumberFormat('vi-VN').format(v) + 'đ';
  // Thang y: cùng mốc tròn như server (1 / 2 / 2,5 / 5 × 10^n)
  const niceMax = (() => {
    if (!max) return 1;
    const m = 10 ** Math.floor(Math.log10(max));
    return [1, 2, 2.5, 5, 10].map(s => s * m).find(v => v >= max);
  })();
  const hide = () => [crosshair, tip, dots.current, dots.previous].forEach(el => el.classList.add('hidden'));
  const show = clientX => {
    const box = plot.getBoundingClientRect();
    const n = points.length;
    const i = Math.max(0, Math.min(n - 1, Math.round(((clientX - box.left) / box.width) * (n - 1))));
    const [, label, cur, prev] = points[i];
    const left = n > 1 ? (i / (n - 1)) * 100 : 0;
    crosshair.style.left = left + '%';
    crosshair.classList.remove('hidden');
    for (const [key, value] of [['current', cur], ['previous', prev]]) {
      dots[key].classList.toggle('hidden', value === null);
      if (value !== null) {
        dots[key].style.left = left + '%';
        dots[key].style.top = 100 - (value / niceMax) * 100 + '%';
      }
    }
    tip.innerHTML = '';
    const head = document.createElement('div');
    head.className = 'mb-1 font-semibold text-slate-900';
    head.textContent = label;
    tip.append(head);
    for (const [name, value, swatch] of [[chart.dataset.currentLabel, cur, 'bg-brand-600'], [chart.dataset.previousLabel, prev, 'bg-slate-400']]) {
      if (value === null) continue;
      const row = document.createElement('div');
      row.className = 'flex items-center gap-2';
      row.innerHTML = `<span class="inline-block size-2 rounded-full ${swatch}"></span><span class="text-slate-500"></span><span class="ml-auto font-medium text-slate-900"></span>`;
      row.children[1].textContent = name;
      row.children[2].textContent = money(value);
      tip.append(row);
    }
    tip.classList.remove('hidden');
    const x = (left / 100) * box.width;
    tip.style.left = Math.min(Math.max(0, x - tip.offsetWidth / 2), box.width - tip.offsetWidth) + 'px';
  };
  plot.addEventListener('pointermove', e => show(e.clientX));
  plot.addEventListener('pointerdown', e => show(e.clientX));
  plot.addEventListener('pointerleave', e => e.pointerType === 'mouse' && hide());
});
