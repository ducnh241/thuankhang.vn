import $ from 'jquery';

import { icon, message } from './core';
import { setItemError, validateItem } from './forms';
import initImage from './image-field';

/*
 * Trang Cài đặt (backend/settings/edit.blade.php, theo template settings.js; ?tab=general|social|seo|tracking): một form
 * cho cả 4 tab, chuyển tab không tải lại trang. Dải "Chưa lưu thay đổi" / chống bấm đúp / hỏi khi rời trang: edit-form.js.
 * Ở đây:
 *   - tab: đổi tab thì cập nhật tiêu đề, ?tab= trên URL và ô _tab (lưu xong quay về đúng tab);
 *   - chân trang: trình soạn thảo Tiptap (editor.js, tải khi cần) ghi HTML vào ô ẩn site_footer;
 *   - logo / favicon: chọn ảnh xem trước ngay, ⓧ bỏ ảnh (như ảnh đại diện ở user-form.js);
 *   - xem trước bản đồ Google và kết quả tìm kiếm, đếm ký tự tiêu đề / mô tả;
 *   - bấm Lưu: kiểm tra cả các tab đang ẩn, có lỗi thì mở tab có ô lỗi đầu tiên.
 */

const MAP_TLD = '(com(\\.[a-z]{2})?|co\\.[a-z]{2}|[a-z]{2})';
const MAP_HOSTS = new RegExp(
  `^https://((www\\.)?google\\.${MAP_TLD}/maps|maps\\.google\\.${MAP_TLD}/|goo\\.gl/maps/|maps\\.app\\.goo\\.gl/)`,
  'i',
);

// Link nhúng của bản đồ: từ mã <iframe>, link nhúng hay link Google Maps thường. Khớp SiteSettings::mapEmbedUrl().
export function mapUrl(value) {
  const textarea = document.createElement('textarea');
  const raw = (value.match(/src=["']([^"']+)["']/i) || [])[1];
  if (raw) textarea.innerHTML = raw; // giải mã &amp; như html_entity_decode
  const src = raw ? textarea.value : value.trim();
  if (!MAP_HOSTS.test(src)) return '';
  if (/\/maps\/embed|output=embed/.test(src)) return src;
  return `https://www.google.com/maps?q=${encodeURIComponent(src)}&output=embed`;
}

function initTabs($form) {
  const titles = JSON.parse($form.attr('data-tabs'));
  const suffix = $form.attr('data-title-suffix');
  const show = tab => {
    const name = titles[tab] ? tab : 'general';
    $('.settings-tab').each(function () {
      const on = $(this).attr('data-tab') === name;
      $(this).toggleClass('is-active', on).attr('aria-selected', on);
    });
    $form.find('[data-panel]').each(function () {
      $(this).toggleClass('hidden', $(this).attr('data-panel') !== name);
    });
    $('#page-title').text(titles[name]);
    document.title = `${titles[name]} - ${suffix}`;
    $form.find('[name=_tab]').val(name);
    const url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    history.replaceState(history.state, '', url);
  };
  $('.settings-nav').on('click', '.settings-tab', function () {
    show($(this).attr('data-tab'));
  });
  return show;
}

export default async function initSettings() {
  const $form = $('#settings-form');
  if (!$form.length) return;
  const showTab = initTabs($form);

  const imageOf = {};
  $form.find('[data-image-field]').each(function () {
    imageOf[$(this).attr('data-image-field')] = initImage($(this));
  });

  const $map = $('#site_map');
  const $mapItem = $map.closest('.ant-form-item');
  const checkMap = () => {
    const bad = $map.val().trim() !== '' && !mapUrl($map.val());
    setItemError($mapItem, bad ? $mapItem.attr('data-map-message') : null);
    return !bad;
  };
  $map.on('change', checkMap);

  const refresh = () => {
    // Bản đồ
    const map = mapUrl($map.val());
    const $preview = $('#site-map-preview');
    if ($preview.attr('data-src') !== map) {
      $preview.attr('data-src', map).toggleClass('hidden', !map).empty();
      if (map)
        $('<iframe title="Xem trước bản đồ" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>')
          .attr('src', map)
          .appendTo($preview);
    }
    // Kết quả tìm kiếm
    const name = $('#site_name').val().trim();
    $('#seo-preview-name').text(name || 'Website của bạn');
    $('#seo-preview-title').text($('#seo_title').val().trim() || name || 'Tiêu đề website');
    $('#seo-preview-description').text(
      $('#seo_description').val().trim() || 'Thêm mô tả để xem website hiện thế nào trong kết quả tìm kiếm.',
    );
    const favicon = imageOf.favicon ? imageOf.favicon() : '';
    const $icon = $('#seo-preview-favicon');
    if ($icon.attr('data-src') !== favicon) {
      $icon.attr('data-src', favicon).html(favicon ? '' : icon('shop'));
      if (favicon) $('<img alt="" />').attr('src', favicon).appendTo($icon);
    }
    $('[data-counter-for]').each(function () {
      const len = $(`#${$(this).attr('data-counter-for')}`).val().length;
      $(this).text(`${len} / ${$(this).attr('data-max')} ký tự`);
    });
    // Chấm đỏ trên tab có ô lỗi (lỗi ở tab đang ẩn vẫn thấy). forms.js báo lỗi sau khi sự kiện tới document → đợi một nhịp
    setTimeout(() =>
      $('.settings-tab').each(function () {
        const tab = $(this).attr('data-tab');
        $(this).toggleClass('has-error', $form.find(`[data-panel="${tab}"] .ant-form-item-has-error`).length > 0);
      }),
    );
  };
  $form.on('input change edit-form:refresh', refresh);
  $form.find('[data-image-field]').on('image:change', refresh);
  $form.on('reset', () => setTimeout(refresh));
  refresh();

  // Chân trang: Tiptap tải sau cùng (trang vẫn dùng được trong lúc tải), ghi HTML vào ô ẩn site_footer
  const $editor = $form.find('.rich-editor');
  const $footer = $form.find('[name=site_footer]');
  let editorApi = null;
  const syncFooter = () => {
    const html = editorApi.getEditorHtml($editor);
    if ($footer.val() !== html) $footer.val(html).trigger('change');
  };

  // Gắn trên chính form nên chạy trước forms.js / edit-form.js (gắn ở document): chặn được trước khi gửi
  $form.on('submit', e => {
    if ($editor.length && editorApi) {
      editorApi.leaveSource($editor);
      syncFooter();
    }
    let ok = checkMap();
    // (ảnh chọn sai đã bị trả lại ngay lúc chọn nên không cần kiểm tra lại; lỗi ảnh server báo không chặn gửi)
    $form.find('.ant-form-item').each(function () {
      if (!validateItem($(this))) ok = false;
    });
    refresh();
    if (ok) return;
    e.preventDefault();
    const $error = $form.find('.ant-form-item-has-error').first();
    showTab($error.closest('[data-panel]').attr('data-panel'));
    $error[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    $error.find('input, textarea').filter(':visible').first().trigger('focus');
    message('Kiểm tra lại các ô báo lỗi.', 'error');
  });

  if (!$editor.length) return;
  editorApi = await import('./editor');
  editorApi.default($form);
  editorApi.setEditorHtml($editor, $footer.val());
  if ($form.is('[data-readonly]')) editorApi.editorOf($editor).setEditable(false);
  // Chỉ ghi khi người dùng sửa (HTML Tiptap chuẩn hóa có thể khác HTML đang lưu dù nội dung như nhau)
  $editor.find('.rich-editor-content').on('input', syncFooter);
  $form.on('reset', () => setTimeout(() => editorApi.setEditorHtml($editor, $footer.val())));
}
