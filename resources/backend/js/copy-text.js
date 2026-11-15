import $ from 'jquery';

import { message } from './core';

/*
 * Nút [data-copy-text="…"]: chép chuỗi vào clipboard. Clipboard API chỉ có trên HTTPS / localhost; không có thì chép
 * qua ô ẩn + execCommand (không hiện hộp thoại).
 */
async function copy(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // bị từ chối quyền: thử cách dự phòng
    }
  }
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
  document.body.append(area);
  area.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch {
    ok = false;
  }
  area.remove();
  return ok;
}

export default function initCopyText() {
  $(document).on('click', '[data-copy-text]', async function () {
    const ok = await copy($(this).attr('data-copy-text'));
    message(ok ? 'Đã sao chép.' : 'Không sao chép được — hãy chọn và sao chép thủ công.', ok ? 'success' : 'error');
  });
}
