import $ from 'jquery';

import initImageField from './image-field';
import { toSlug } from './tree-page';

/*
 * Trang thêm / sửa trang (backend/pages/form.blade.php, theo template news-edit.js):
 *   - đường dẫn tự theo tiêu đề cho tới khi sửa tay (xóa trắng thì lại tự theo); trang đã lưu giữ nguyên đường dẫn;
 *   - nội dung: trình soạn thảo Tiptap (editor.js, tải khi cần) ghi HTML vào ô ẩn content;
 *   - ảnh đại diện: image-field.js;
 *   - xem trước kết quả tìm kiếm Google, đếm ký tự; nút bút mở / đóng ô tiêu đề, mô tả SEO.
 * Dải "Chưa lưu thay đổi" / chống bấm đúp / hỏi khi rời trang: edit-form.js.
 */

// Chữ thuần của HTML nội dung (DOMParser: không chạy script, không tải ảnh)
const textOf = html => new DOMParser().parseFromString(html || '', 'text/html').body.textContent.replace(/\s+/g, ' ').trim();

export default async function initPageForm() {
  const $form = $('#page-form');
  if (!$form.length) return;
  $form.find('[data-image-field]').each(function () {
    initImageField($(this));
  });

  // Đường dẫn
  const $title = $('#page_title');
  const $slug = $('#page_slug');
  $slug.on('input', function () {
    if (this.value.trim() === '') this.removeAttribute('data-touched');
    else this.setAttribute('data-touched', '');
  });
  // Rời ô: chuẩn hóa như server (Str::slug) để thấy đúng đường dẫn sẽ lưu
  $slug.on('blur', () => {
    const slug = toSlug($slug.val());
    if (slug !== $slug.val()) $slug.val(slug).trigger('change');
  });
  $title.on('input', () => {
    if (!$slug[0].hasAttribute('data-touched')) $slug.val(toSlug($title.val())).trigger('change');
  });

  // Xem trước kết quả tìm kiếm + đếm ký tự
  const $content = $form.find('[name=content]');
  const refresh = () => {
    const slug = toSlug($slug.val()) || toSlug($title.val());
    $('#seo-preview-url').text($('#seo-preview-url').attr('data-base') + (slug || 'duong-dan'));
    $('#seo-preview-title').text($('#page_meta_title').val().trim() || $title.val().trim() || 'Tiêu đề trang');
    const description =
      $('#page_meta_description').val().trim() || $('#page_excerpt').val().trim() || textOf($content.val()).slice(0, 160);
    $('#seo-preview-description').text(
      description || 'Thêm mô tả ngắn hoặc mô tả SEO để xem trang hiện thế nào trong kết quả tìm kiếm.',
    );
    $form.find('[data-counter-for]').each(function () {
      const len = $(`#${$(this).attr('data-counter-for')}`).val().length;
      $(this).text(`${len} / ${$(this).attr('data-max')} ký tự`);
    });
  };
  $form.on('input change', refresh);
  refresh();

  $('#seo-edit').on('click', function () {
    const $fields = $('#seo-fields').toggleClass('hidden');
    const open = !$fields.hasClass('hidden');
    $(this).attr('aria-expanded', open);
    if (open) $('#page_meta_title').trigger('focus');
  });

  // "Hủy thay đổi": đường dẫn về giá trị đã lưu → có giá trị thì coi như đã sửa tay; vẽ lại phần xem trước
  $form.on('reset', () =>
    setTimeout(() => {
      $slug.attr('data-touched', $slug.val() !== '' ? '' : null);
      refresh();
    }),
  );

  // Nội dung: Tiptap tải sau cùng (trang vẫn dùng được trong lúc tải), ghi HTML vào ô ẩn content
  const $editor = $form.find('.rich-editor');
  let editorApi = null;
  const sync = () => {
    const html = editorApi.getEditorHtml($editor);
    if ($content.val() !== html) $content.val(html).trigger('change');
  };
  // Gắn trên chính form nên chạy trước forms.js / edit-form.js (gắn ở document): đang sửa HTML thì đưa về trình soạn
  // thảo rồi ghi vào ô ẩn trước khi gửi
  $form.on('submit', () => {
    if (!editorApi) return;
    editorApi.leaveSource($editor);
    sync();
  });

  editorApi = await import('./editor');
  editorApi.default($form);
  editorApi.setEditorHtml($editor, $content.val());
  // Chỉ ghi khi người dùng sửa (HTML Tiptap chuẩn hóa có thể khác HTML đang lưu dù nội dung như nhau)
  $editor.find('.rich-editor-content').on('input', sync);
  $form.on('reset', () => setTimeout(() => editorApi.setEditorHtml($editor, $content.val())));
}
