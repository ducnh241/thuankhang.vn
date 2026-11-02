{{-- Ảnh đại diện (Ant Upload picture card, trang sửa người dùng + trang Tài khoản): chọn ảnh thì xem trước ngay, lưu
     form mới tải lên; ⓧ trên ảnh = bỏ ảnh. Gửi cùng form: avatar (tệp), remove_avatar. JS: user-form.js.
     Tham số: $user --}}
<div class="flex-none">
  <div class="ant-row ant-form-item{{ $errors->has('avatar') ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}" id="avatar-item">
    <div class="ant-col ant-form-item-label"><label for="avatar-file" title="Ảnh đại diện">Ảnh đại diện</label></div>
    <div class="ant-col ant-form-item-control">
      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
        <div class="ant-upload-picture-card-wrapper">
          <div class="ant-upload-list ant-upload-list-picture-card user-avatar-upload" id="user-avatar" data-current="{{ $user->avatarUrl() }}">
            @if ($user->avatarUrl())
              <div class="ant-upload-list-picture-card-container">
                <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
                  <div class="ant-upload-list-item-info">
                    <span><a class="ant-upload-list-item-thumbnail" href="{{ $user->avatarUrl() }}" target="_blank" rel="noopener noreferrer"><img src="{{ $user->avatarUrl() }}" alt="" class="ant-upload-list-item-image" /></a></span>
                  </div>
                  <span class="ant-upload-list-item-actions"><a href="{{ $user->avatarUrl() }}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">{{ ant_icon('eye') }}</a><button title="Bỏ ảnh" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file">{{ ant_icon('delete') }}</button></span>
                </div>
              </div>
            @endif
            <div @class(['ant-upload ant-upload-select ant-upload-select-picture-card', 'hidden' => $user->avatarUrl()])>
              <span tabindex="0" class="ant-upload" role="button" data-avatar-pick aria-label="Chọn ảnh đại diện">
                <div>{{ ant_icon('plus') }}<div class="mt-2">Ảnh</div></div>
              </span>
            </div>
          </div>
        </div>
        {{-- Ô chọn tệp thật nằm ngoài khung .ant-upload (controls.js xóa tệp của ô nằm trong khung sau khi đọc) --}}
        <input type="file" name="avatar" id="avatar-file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" />
        <input type="checkbox" name="remove_avatar" value="1" id="remove-avatar" class="hidden" />
      </div></div>
      @error('avatar')<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">{{ $message }}</div></div>@enderror
      <div class="ant-form-item-extra">Ảnh vuông, tối đa 2 MB.</div>
    </div>
  </div>
</div>
