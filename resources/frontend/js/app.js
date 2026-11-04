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
