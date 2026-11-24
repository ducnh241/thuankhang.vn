/*
 * Sơ đồ cây nhị phân (backend/members/tree) — [data-tree-zoom]:
 *   - Thu phóng 10%–200%: nút − / + (bước 10%), bấm số % để về 100%, "Vừa khung" (thu nhỏ cho cả cây lọt chiều ngang khung,
 *     không phóng to quá 100%); Ctrl / ⌘ + lăn chuột (kể cả chụm hai ngón trên touchpad) quanh vị trí con trỏ; phím + − 0 khi
 *     đang chọn sơ đồ. Dùng thuộc tính CSS zoom trên <ul> gốc nên vùng cuộn co giãn theo. Mức đang dùng nhớ trong localStorage.
 *   - Toàn màn hình: Fullscreen API trên cả khung (gồm thanh công cụ); không hỗ trợ (Safari iPhone) thì phủ kín cửa sổ bằng
 *     lớp .is-full, Esc để thoát.
 *   - Kéo chuột trên vùng trống của sơ đồ để di chuyển (không áp dụng khi bắt đầu kéo trên link / nút).
 */
const MIN = 0.1;
const MAX = 2;
const STEP = 0.1;
const STORAGE_KEY = 'btree-zoom';

const clamp = value => Math.min(MAX, Math.max(MIN, Math.round(value * 100) / 100));

const stored = () => {
  try {
    const value = parseFloat(window.localStorage.getItem(STORAGE_KEY));
    return Number.isFinite(value) ? clamp(value) : 1;
  } catch {
    return 1;
  }
};

const remember = value => {
  try {
    window.localStorage.setItem(STORAGE_KEY, String(value));
  } catch {
    // Trình duyệt chặn lưu trữ: chỉ không nhớ được mức thu phóng, vẫn dùng bình thường
  }
};

function initTreeZoom(card) {
  const viewport = card.querySelector('.btree');
  const content = viewport?.querySelector(':scope > ul');
  if (!content) {
    return;
  }
  const label = card.querySelector('[data-zoom="reset"]');
  const fullButton = card.querySelector('[data-fullscreen]');
  let zoom = 1;

  /** Đặt mức thu phóng, giữ nguyên điểm (x, y) của khung (mặc định: tâm khung) ở cùng chỗ trên sơ đồ. */
  const apply = (value, anchorX = viewport.clientWidth / 2, anchorY = viewport.clientHeight / 2) => {
    const next = clamp(value);
    const ratio = next / zoom;
    const x = viewport.scrollLeft + anchorX;
    const y = viewport.scrollTop + anchorY;
    zoom = next;
    content.style.zoom = String(next);
    viewport.scrollLeft = x * ratio - anchorX;
    viewport.scrollTop = y * ratio - anchorY;
    if (label) {
      label.textContent = `${Math.round(next * 100)}%`;
    }
    card.querySelector('[data-zoom="out"]')?.toggleAttribute('disabled', next <= MIN);
    card.querySelector('[data-zoom="in"]')?.toggleAttribute('disabled', next >= MAX);
    remember(next);
  };

  /** Mức để cả cây lọt chiều ngang khung (tối đa 100%), rồi cuộn về giữa. */
  const fit = () => {
    const style = window.getComputedStyle(viewport);
    const padding = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
    const room = viewport.clientWidth - padding;
    // Đo chiều rộng thật của cây (node gốc + mọi nhánh, đã tính thu phóng — vùng cuộn thì không bao giờ nhỏ hơn khung nên
    // không dùng được cho cây hẹp); viền / đường nối không co đúng tỉ lệ nên đo lại một lần cho khớp
    const measure = () => {
      viewport.classList.add('btree-measure'); // <ul> gốc co về đúng chiều rộng tự nhiên của cây (app.css)
      const width = content.getBoundingClientRect().width;
      viewport.classList.remove('btree-measure');
      return width;
    };
    for (let pass = 0; pass < 2; pass++) {
      const width = measure();
      const target = width > 0 ? Math.floor(Math.min(1, (zoom * room) / width) * 100) / 100 : 1; // làm tròn xuống: không lố khung
      if (pass > 0 && Math.abs(target - zoom) < 0.01) {
        break;
      }
      apply(target);
    }
    viewport.scrollLeft = (viewport.scrollWidth - viewport.clientWidth) / 2;
    viewport.scrollTop = 0;
  };

  card.addEventListener('click', event => {
    const button = event.target.closest('[data-zoom]');
    if (!button) {
      return;
    }
    const action = button.dataset.zoom;
    if (action === 'fit') {
      fit();
    } else {
      apply(action === 'in' ? zoom + STEP : action === 'out' ? zoom - STEP : 1);
    }
  });

  viewport.addEventListener(
    'wheel',
    event => {
      if (!event.ctrlKey && !event.metaKey) {
        return;
      }
      event.preventDefault();
      const box = viewport.getBoundingClientRect();
      apply(zoom * Math.exp(-event.deltaY * 0.0025), event.clientX - box.left, event.clientY - box.top);
    },
    { passive: false }
  );

  viewport.addEventListener('keydown', event => {
    if (event.ctrlKey || event.metaKey || event.altKey) {
      return;
    }
    if (event.key === '+' || event.key === '=') {
      apply(zoom + STEP);
    } else if (event.key === '-' || event.key === '_') {
      apply(zoom - STEP);
    } else if (event.key === '0') {
      apply(1);
    } else {
      return;
    }
    event.preventDefault();
  });

  // Kéo để di chuyển (chuột); trên màn hình cảm ứng đã có vuốt cuộn sẵn
  let pan = null;
  viewport.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('a, button, input, select, textarea')) {
      return;
    }
    pan = { x: event.clientX, y: event.clientY, left: viewport.scrollLeft, top: viewport.scrollTop, moved: false };
    viewport.setPointerCapture(event.pointerId);
  });
  viewport.addEventListener('pointermove', event => {
    if (!pan) {
      return;
    }
    const dx = event.clientX - pan.x;
    const dy = event.clientY - pan.y;
    if (!pan.moved && Math.abs(dx) + Math.abs(dy) < 4) {
      return;
    }
    pan.moved = true;
    viewport.classList.add('is-panning');
    viewport.scrollLeft = pan.left - dx;
    viewport.scrollTop = pan.top - dy;
  });
  const endPan = () => {
    pan = null;
    viewport.classList.remove('is-panning');
  };
  viewport.addEventListener('pointerup', endPan);
  viewport.addEventListener('pointercancel', endPan);

  // Toàn màn hình
  let openedNative = false;
  const nativeFull = () => document.fullscreenElement === card;
  const isFull = () => nativeFull() || card.classList.contains('is-full');
  const syncFull = () => {
    const full = isFull();
    fullButton?.querySelector('[data-fullscreen-enter]')?.toggleAttribute('hidden', full);
    fullButton?.querySelector('[data-fullscreen-exit]')?.toggleAttribute('hidden', !full);
    fullButton?.setAttribute('aria-pressed', full ? 'true' : 'false');
    document.body.classList.toggle('btree-full-open', card.classList.contains('is-full'));
    if (full) {
      viewport.focus({ preventScroll: true });
    }
  };
  fullButton?.addEventListener('click', () => {
    if (nativeFull()) {
      document.exitFullscreen();
    } else if (card.classList.contains('is-full')) {
      card.classList.remove('is-full');
      syncFull();
    } else if (card.requestFullscreen && document.fullscreenEnabled) {
      // Có trình duyệt / khung nhúng nhận lệnh nhưng không mở (lời hứa không trả về hoặc mở rồi đóng ngay): sau một nhịp
      // mà vẫn chưa toàn màn hình thì phủ kín cửa sổ thay thế
      openedNative = false;
      const fallback = () => {
        if (!openedNative && !nativeFull() && !card.classList.contains('is-full')) {
          card.classList.add('is-full');
          syncFull();
        }
      };
      card.requestFullscreen().then(() => window.setTimeout(fallback, 150), fallback);
      window.setTimeout(fallback, 600);
    } else {
      card.classList.add('is-full');
      syncFull();
    }
  });
  document.addEventListener('fullscreenchange', () => {
    openedNative ||= nativeFull(); // đã mở thật thì người dùng tự thoát nhanh cũng không bật phủ cửa sổ
    syncFull();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && card.classList.contains('is-full')) {
      card.classList.remove('is-full');
      syncFull();
    }
  });

  apply(stored(), 0, 0);
  // Cây rộng hơn khung: cuộn sao cho node đang xem (giữa hàng trên cùng) nằm giữa
  viewport.scrollLeft = (viewport.scrollWidth - viewport.clientWidth) / 2;
}

export default function initTreeZooms() {
  document.querySelectorAll('[data-tree-zoom]').forEach(initTreeZoom);
}
