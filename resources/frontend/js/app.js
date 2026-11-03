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
