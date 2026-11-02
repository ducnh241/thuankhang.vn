import $ from 'jquery';

import { pictureCard } from './controls';
import { setItemError } from './forms';

/*
 * Ô một ảnh gửi cùng form (Ant Upload picture card): logo / favicon ở trang Cài đặt, ảnh đại diện của Trang. Markup:
 * .ant-form-item[data-image-field][data-max-size][data-type-message][data-size-message] chứa .ant-upload-list
 * (data-current = ảnh đang lưu), nút [data-image-pick], <input type=file> và checkbox [data-image-remove].
 * Chọn ảnh mới thay ảnh đang xem (xem trước ngay); ⓧ bỏ ảnh (ảnh đang lưu → đánh dấu xóa khi lưu); "Hủy thay đổi" về
 * ảnh đang lưu. Đổi ảnh bắn `image:change` trên ô. Trả về hàm lấy src ảnh đang xem.
 */
export default function initImageField($field) {
  const $list = $field.find('.ant-upload-list');
  const file = $field.find('input[type=file]')[0];
  const remove = $field.find('[data-image-remove]')[0];
  const current = $list.attr('data-current') || '';
  const maxSize = Number($field.attr('data-max-size'));
  let preview = null;
  let accepted = null; // tệp hợp lệ đang chọn: chọn tiếp tệp sai thì trả lại tệp này

  const show = src => {
    $list.find('.ant-upload-list-picture-card-container').remove();
    if (src) $list.find('.ant-upload-select').before(pictureCard(src));
    $list.find('.ant-upload-select').toggleClass('hidden', !!src);
    $field.trigger('image:change', [src]);
  };

  $list.on('click keydown', '[data-image-pick]', e => {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    file.click();
  });
  $(file).on('change', () => {
    const picked = file.files[0];
    if (!picked) return;
    const allowed = file.accept.split(',').includes(picked.type);
    if (!allowed || picked.size > maxSize) {
      setItemError($field, allowed ? $field.attr('data-size-message') : $field.attr('data-type-message'));
      if (accepted) file.files = accepted;
      else file.value = '';
      return;
    }
    setItemError($field, null);
    accepted = file.files;
    if (preview) URL.revokeObjectURL(preview);
    preview = URL.createObjectURL(picked);
    remove.checked = false;
    show(preview);
    $field.closest('form').trigger('change'); // edit-form.js tính lại "Chưa lưu thay đổi"
  });
  // Nút xóa trên ảnh (controls.js đã gỡ ảnh khỏi danh sách)
  $list.on('upload:change', () => {
    if ($list.find('.ant-upload-list-picture-card-container').length) return;
    file.value = '';
    accepted = null;
    remove.checked = !!current;
    show('');
    $field.closest('form').trigger('change');
  });
  // "Hủy thay đổi": form.reset() chạy trước khi các ô về giá trị cũ → đợi một nhịp rồi vẽ lại theo ảnh đang lưu
  $field.closest('form').on('reset', () =>
    setTimeout(() => {
      accepted = null;
      show(current);
      setItemError($field, null);
    }),
  );
  return () => $list.find('.ant-upload-list-item-image').attr('src') || '';
}
