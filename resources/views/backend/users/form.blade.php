@extends('layouts.backend')

{{-- Thêm / sửa người dùng theo template ProCMS user-edit.html: thanh trên cùng có tên đã lưu + trạng thái + dải "Chưa lưu
     thay đổi"; cột chính (Thông tin + ảnh đại diện, Mật khẩu) + cột phụ (Trạng thái, Quyền truy cập). Form gửi thẳng lên
     server (có ảnh nên multipart), lỗi hiện dưới từng ô. JS: edit-form.js (thay đổi chưa lưu), user-form.js (ảnh đại
     diện, công tắc Root admin làm mờ ô nhóm). --}}
@php
    $editing = $user->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $self = $editing && $user->is($me);
    $isRootActor = $me->isSuperAdmin();
    // Không tự khóa / tự bỏ Root của mình, không bỏ Root admin cuối cùng (server cũng chặn)
    $lockedStatus = $self ? 'Không thể tự khóa tài khoản đang đăng nhập.' : null;
    $lockedRoot = ! $editing ? null : ($self ? 'Không thể tự bỏ quyền Root admin của mình.' : ($user->isLastSuperAdmin() ? 'Root admin cuối cùng: cần có Root admin khác trước.' : null));
    // Gửi lỗi quay lại: công tắc tắt thì không có trong dữ liệu gửi đi (checkbox) → không lấy giá trị đã lưu làm mặc định
    $old = session()->hasOldInput();
    $active = $old ? (bool) old('status') : ($editing ? $user->isActive() : true);
    $root = $old && $isRootActor ? (bool) old('is_super_admin') : $user->isSuperAdmin();
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    $switch = fn (string $id, bool $on, ?string $locked) => '<button type="button" role="switch" aria-checked="'.($on ? 'true' : 'false').'" id="'.$id.'"'
        .' class="ant-switch mt-0.5 flex-none'.($on ? ' ant-switch-checked' : '').($locked ? ' ant-switch-disabled' : '').'"'.($locked ? ' disabled' : '').'>'
        .'<div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>';
    $deleteBlocked = ! $editing ? null : ($self ? 'Không thể xóa tài khoản đang đăng nhập' : ($user->isLastSuperAdmin() ? 'Root admin cuối cùng, không xóa được (cần có Root admin khác trước)' : null));
@endphp

@section('title', $editing ? $user->name : 'Thêm người dùng')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.users.index') }}" class="page-topbar-crumb" title="Về danh sách người dùng">Người dùng</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $user->name : 'Thêm người dùng' }}</h1>
    @if ($editing)
      @if ($user->isSuperAdmin())<span class="ant-tag ant-tag-gold">Root admin</span>@endif
      <span @class(['ant-tag', 'ant-tag-green' => $user->isActive()])>{{ $user->isActive() ? 'Kích hoạt' : 'Đã khóa' }}</span>
    @endif
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="user-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo người dùng' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('user/delete')
        @if ($deleteBlocked)
          <span data-tooltip="{{ $deleteBlocked }}"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa người dùng"
            data-delete-url="{{ route('backend.users.destroy', $user) }}" data-delete-title="Xóa người dùng “{{ $user->name }}”?"
            data-delete-content="Tài khoản sẽ không đăng nhập được nữa. Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
    @else
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo người dùng → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.users.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="user-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo người dùng</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="user-form" action="{{ $editing ? route('backend.users.update', $user) : route('backend.users.store') }}" enctype="multipart/form-data"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1040px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin</div></div></div>
        <div class="ant-card-body">
          <div class="flex flex-col gap-x-6 sm:flex-row">
            @include('backend.partials.avatar-upload', ['user' => $user])
            <div class="min-w-0 flex-1">
              <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập họ tên.">
                <div class="ant-col ant-form-item-label"><label for="name" class="ant-form-item-required" title="Họ tên">Họ tên</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" class="ant-input" placeholder="Nguyễn Văn A" maxlength="100" @unless ($editing) autofocus @endunless />
                  </div></div>
                  {!! $error('name') !!}
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $hasError('email') }}" data-required="Nhập email." data-type="email" data-type-message="Email chưa đúng định dạng.">
                <div class="ant-col ant-form-item-label"><label for="email" class="ant-form-item-required" title="Email">Email</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" class="ant-input" placeholder="ten@example.com" maxlength="255" autocomplete="off" />
                  </div></div>
                  {!! $error('email') !!}
                  <div class="ant-form-item-extra">Dùng để đăng nhập.</div>
                </div>
              </div>
              <div class="ant-row ant-form-item mb-0{{ $hasError('phone') }}">
                <div class="ant-col ant-form-item-label"><label for="phone" title="Điện thoại">Điện thoại</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="ant-input" placeholder="09xx xxx xxx" maxlength="30" />
                  </div></div>
                  {!! $error('phone') !!}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Mật khẩu</div></div></div>
        <div class="ant-card-body">
          <p class="settings-card-desc">{{ $editing ? 'Để trống cả hai ô nếu không đổi mật khẩu.' : 'Mật khẩu dùng để đăng nhập.' }}</p>
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('password') }}" @unless ($editing) data-required="Đặt mật khẩu." @endunless data-min="8" data-min-message="Mật khẩu ít nhất 8 ký tự.">
              <div class="ant-col ant-form-item-label"><label for="password" @class(['ant-form-item-required' => ! $editing]) title="Mật khẩu mới">{{ $editing ? 'Mật khẩu mới' : 'Mật khẩu' }}</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-affix-wrapper ant-input-password"><input type="password" id="password" name="password" class="ant-input" autocomplete="new-password" placeholder="Ít nhất 8 ký tự" /><span class="ant-input-suffix">{{ ant_icon('eye-invisible', 'ant-input-password-icon') }}</span></span>
                </div></div>
                {!! $error('password') !!}
                <div class="ant-form-item-extra">Ít nhất 8 ký tự.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item" data-match="#password" data-match-message="Hai mật khẩu chưa khớp nhau.">
              <div class="ant-col ant-form-item-label"><label for="password_confirmation" title="Nhập lại mật khẩu">Nhập lại mật khẩu</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-affix-wrapper ant-input-password"><input type="password" id="password_confirmation" name="password_confirmation" class="ant-input" autocomplete="new-password" placeholder="Gõ lại mật khẩu" /><span class="ant-input-suffix">{{ ant_icon('eye-invisible', 'ant-input-password-icon') }}</span></span>
                </div></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0">
            <label class="flex items-start gap-3" for="user_active" @if ($lockedStatus) data-tooltip="{{ $lockedStatus }}" @endif>
              {!! $switch('user_active', $active, $lockedStatus) !!}
              <input type="checkbox" name="status" value="1" class="switch-input" hidden @checked($active) />
              <span><span class="block text-[rgba(0,0,0,0.85)]">Kích hoạt</span><span class="block {{ $muted }}">Tài khoản bị khóa không đăng nhập được.</span></span>
            </label>
          </div>
          <div class="mt-4 {{ $muted }}" id="user-meta">
            @if ($editing)
              Tạo ngày {{ $user->created_at?->format('d/m/Y') ?? '—' }}<br />Cập nhật {{ $user->updated_at?->format('d/m/Y H:i') ?? '—' }}
            @else
              Chưa lưu
            @endif
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Quyền truy cập</div></div></div>
        <div class="ant-card-body">
          {{-- Chỉ Root admin mới cấp / bỏ được quyền Root admin --}}
          @if ($isRootActor)
            <div class="ant-row ant-form-item mb-0">
              <label class="flex items-start gap-3" for="user_root" @if ($lockedRoot) data-tooltip="{{ $lockedRoot }}" @endif>
                {!! $switch('user_root', $root, $lockedRoot) !!}
                <input type="checkbox" name="is_super_admin" value="1" class="switch-input" hidden @checked($root) />
                <span><span class="block text-[rgba(0,0,0,0.85)]">Root admin</span><span class="block {{ $muted }}">Có mọi quyền, không phụ thuộc nhóm.</span></span>
              </label>
            </div>
          @endif
          <div @class(['ant-row ant-form-item mb-0'.$hasError('group_id'), 'mt-5' => $isRootActor])>
            <div class="ant-col ant-form-item-label"><label for="user_group" title="Nhóm quyền">Nhóm quyền</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-allow-clear id="user_group" name="group_id" class="w-full" data-placeholder="Chưa có nhóm">
                  <option value=""></option>
                  @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected((string) old('group_id', $user->group_id) === (string) $group->id)>{{ $group->name }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('group_id') !!}
              <div class="ant-form-item-extra">
                <span id="group-hint" data-root-hint="Không dùng: Root admin có mọi quyền." data-hint="Quyền trong trang quản trị lấy theo nhóm.">{{ $root ? 'Không dùng: Root admin có mọi quyền.' : 'Quyền trong trang quản trị lấy theo nhóm.' }}</span>
                @can('group') <a href="{{ route('backend.groups.index') }}">Quản lý nhóm quyền</a>@endcan
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
