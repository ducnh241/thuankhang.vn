import $ from 'jquery';

import { pictureCard } from './controls';
import { setItemError } from './forms';

/*
 * Trang thêm / sửa người dùng (backend/users/form.blade.php, theo template user-edit.js) và trang Tài khoản
 * (backend/profile/edit.blade.php — chỉ phần ảnh đại diện):
 *   - ảnh đại diện: ô "+" mở ô chọn tệp thật (#avatar-file, gửi cùng form); chọn xong xem trước ngay; nút xóa trên ảnh
 *     bỏ ảnh mới chọn, hoặc đánh dấu bỏ ảnh đang lưu (#remove-avatar); "Hủy thay đổi" (form reset) đưa về ảnh đang lưu;
 *   - bật Root admin: ô nhóm quyền mờ đi (không dùng tới), như template.
 * Phần thay đổi chưa lưu / chống bấm đúp: edit-form.js.
 */

const MAX_SIZE = 2 * 1024 * 1024; // khớp rule avatar max:2048 (KB) ở server

export default function initUserForm() {
  // Trang Tài khoản (backend/profile/edit) dùng chung phần ảnh đại diện
  const $form = $('#user-form, #profile-form').first();
  if (!$form.length) return;
  const $list = $('#user-avatar');
  const file = $('#avatar-file')[0];
  const remove = $('#remove-avatar')[0];
  const current = $list.attr('data-current') || '';
  let preview = null; // object URL của ảnh vừa chọn
  let accepted = null; // tệp hợp lệ đang chọn: chọn tiếp tệp sai thì trả lại tệp này (ô vẫn đang xem trước nó)

  const show = src => {
    $list.find('.ant-upload-list-picture-card-container').remove();
    if (src) $list.find('.ant-upload-select').before(pictureCard(src));
    $list.find('.ant-upload-select').toggleClass('hidden', !!src);
  };
  const changed = () => $form.trigger('change'); // edit-form.js tính lại "Chưa lưu thay đổi"

  $list.on('click keydown', '[data-avatar-pick]', e => {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    file.click();
  });
  $(file).on('change', () => {
    const picked = file.files[0];
    if (!picked) return;
    const $item = $('#avatar-item');
    // Chỉ các loại trong accept của ô chọn tệp (SVG có thể chứa mã → server cũng từ chối)
    const allowed = file.accept.split(',').includes(picked.type);
    if (!allowed || picked.size > MAX_SIZE) {
      setItemError($item, allowed ? 'Ảnh tối đa 2 MB.' : 'Chọn ảnh PNG, JPG, WEBP hoặc GIF.');
      if (accepted) file.files = accepted;
      else file.value = '';
      return;
    }
    setItemError($item, null);
    accepted = file.files;
    if (preview) URL.revokeObjectURL(preview);
    preview = URL.createObjectURL(picked);
    remove.checked = false;
    show(preview);
    changed();
  });
  // Nút xóa trên ảnh (controls.js đã gỡ ảnh khỏi danh sách)
  $list.on('upload:change', () => {
    if ($list.find('.ant-upload-list-picture-card-container').length) return;
    file.value = '';
    accepted = null;
    remove.checked = !!current;
    show('');
    changed();
  });
  // "Hủy thay đổi": form.reset() bắn sự kiện trước khi đặt lại các ô → đợi một nhịp rồi vẽ lại theo ảnh đang lưu
  $form.on('reset', () =>
    setTimeout(() => {
      accepted = null;
      show(current);
      setItemError($('#avatar-item'), null);
    }),
  );

  // Root admin có mọi quyền: ô nhóm không dùng tới → mờ đi, không mở được (giá trị vẫn giữ)
  const $group = $('#user_group').closest('.ant-select');
  const $hint = $('#group-hint');
  const rootOn = () => $form.find('[name=is_super_admin]').prop('checked') === true;
  const sync = () => {
    const root = rootOn();
    $group.toggleClass('ant-select-disabled', root).find('input').prop('disabled', root);
    $hint.text($hint.attr(root ? 'data-root-hint' : 'data-hint'));
  };
  $group.on('mousedown', e => {
    if (rootOn()) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  });
  $form.on('edit-form:refresh', sync);
  sync();
}
