import $ from 'jquery';

import { icon, message } from './core';

/*
 * Màn Quản lý file (backend/files/index): nút [data-upload-trigger] mở hộp chọn file của form #file-upload-form; chọn
 * xong (hoặc kéo thả file vào trang) thì kiểm tra số file / dung lượng ở trình duyệt rồi gửi form thật lên server.
 * Giới hạn lấy từ data-max-files, data-max-bytes (mỗi file), data-max-total (cả lần gửi; 0 = không giới hạn) của form.
 */
const mb = bytes => `${(bytes / 1024 / 1024).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} MB`;

export default function initFileManager() {
  const form = document.getElementById('file-upload-form');
  if (!form) return;
  const input = form.querySelector('input[type="file"]');
  const maxFiles = Number(form.dataset.maxFiles);
  const maxBytes = Number(form.dataset.maxBytes);
  const maxTotal = Number(form.dataset.maxTotal);
  const $triggers = $('[data-upload-trigger]');
  const $zone = $('#files');
  let sending = false;

  const send = () => {
    const files = Array.from(input.files);
    if (!files.length || sending) return;
    const tooBig = files.find(file => file.size > maxBytes);
    const total = files.reduce((sum, file) => sum + file.size, 0);
    let error = '';
    if (files.length > maxFiles) error = `Mỗi lần tải tối đa ${maxFiles} file.`;
    else if (tooBig) error = `“${tooBig.name}” nặng ${mb(tooBig.size)} — mỗi file tối đa ${mb(maxBytes)}.`;
    else if (maxTotal && total >= maxTotal) error = `Các file đã chọn nặng ${mb(total)} — mỗi lần tải tối đa ${mb(maxTotal)}, hãy chia nhỏ.`;
    if (error) {
      input.value = '';
      message(error, 'error');
      return;
    }
    sending = true;
    $triggers
      .addClass('ant-btn-loading')
      .prop('disabled', true)
      .each((_, el) => $(el).find('.anticon').first().replaceWith(`<span class="ant-btn-loading-icon">${icon('loading', 'anticon-spin')}</span>`));
    form.submit();
  };

  $triggers.on('click', () => input.click());
  $(input).on('change', send);

  // Kéo thả file từ máy vào trang
  let depth = 0;
  const hasFiles = e => Array.from(e.originalEvent.dataTransfer?.types || []).includes('Files');
  $(document)
    .on('dragenter', e => {
      if (!hasFiles(e)) return;
      depth += 1;
      $zone.addClass('is-dragover');
    })
    .on('dragleave', e => {
      if (!hasFiles(e)) return;
      depth = Math.max(0, depth - 1);
      if (!depth) $zone.removeClass('is-dragover');
    })
    .on('dragover', e => hasFiles(e) && e.preventDefault())
    .on('drop', e => {
      if (!hasFiles(e)) return;
      e.preventDefault();
      depth = 0;
      $zone.removeClass('is-dragover');
      input.files = e.originalEvent.dataTransfer.files;
      send();
    });
}
