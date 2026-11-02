@extends('layouts.backend')

{{-- Tài khoản của người đang đăng nhập, dựng như trang sửa người dùng (template user-edit.html): thanh trên cùng có tên +
     quyền + dải "Chưa lưu thay đổi" (form thông tin); cột chính: Thông tin (ảnh đại diện, họ tên, email, điện thoại) và
     Đổi mật khẩu (form riêng, cần mật khẩu hiện tại, lỗi ở túi lỗi "password"); cột phụ: quyền, trạng thái, ngày tạo.
     Không tự đổi nhóm / Root admin / trạng thái ở đây. JS: edit-form.js, user-form.js (ảnh đại diện). --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    // Lưu lỗi: ô theo dữ liệu vừa gửi (ô xóa trắng thành null qua ConvertEmptyStringsToNull — vẫn phải hiện trống)
    $old = fn (string $field, $default) => session()->hasOldInput() && $errors->any() ? (is_string(old($field)) ? old($field) : '') : $default;
    $pw = $errors->getBag('password');
    $pwHasError = fn (string $field) => $pw->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $pwError = fn (string $field) => $pw->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($pw->first($field)).'</div></div>' : '';
    $eye = ant_icon('eye-invisible', 'ant-input-password-icon');
@endphp

@section('title', 'Tài khoản')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <span class="page-topbar-crumb">Tài khoản</span>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $user->name }}</h1>
    @if ($user->isSuperAdmin())
      <span class="ant-tag ant-tag-gold">Root admin</span>
    @elseif ($user->group)
      {{-- Tên nhóm dài: cắt "…" (đủ tên ở cột Quyền truy cập); điện thoại không hiện --}}
      <span class="ant-tag max-w-[240px] min-w-0 shrink truncate max-sm:hidden" title="{{ $user->group->name }}">{{ $user->group->name }}</span>
    @endif
  </div>
  {{-- Hiện khi form thông tin có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="profile-form" class="save-bar-save">Lưu</button>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1040px] grid-cols-1 items-start gap-4 lg:grid-cols-3">
  {{-- Cột chính --}}
  <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
    <form method="POST" id="profile-form" action="{{ route('backend.profile.update') }}" enctype="multipart/form-data"
      class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
      @csrf
      @method('PUT')
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
                    <input type="text" id="name" name="name" value="{{ $old('name', $user->name) }}" class="ant-input" placeholder="Nguyễn Văn A" maxlength="100" autocomplete="name" />
                  </div></div>
                  {!! $error('name') !!}
                </div>
              </div>
              <div class="ant-row ant-form-item{{ $hasError('email') }}" data-required="Nhập email." data-type="email" data-type-message="Email chưa đúng định dạng.">
                <div class="ant-col ant-form-item-label"><label for="email" class="ant-form-item-required" title="Email">Email</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="email" id="email" name="email" value="{{ $old('email', $user->email) }}" class="ant-input" placeholder="ten@example.com" maxlength="255" autocomplete="email" />
                  </div></div>
                  {!! $error('email') !!}
                  <div class="ant-form-item-extra">Dùng để đăng nhập.</div>
                </div>
              </div>
              <div class="ant-row ant-form-item mb-0{{ $hasError('phone') }}">
                <div class="ant-col ant-form-item-label"><label for="phone" title="Điện thoại">Điện thoại</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="tel" id="phone" name="phone" value="{{ $old('phone', $user->phone) }}" class="ant-input" placeholder="09xx xxx xxx" maxlength="30" autocomplete="tel" />
                  </div></div>
                  {!! $error('phone') !!}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>

    {{-- Đổi mật khẩu: form riêng, nút riêng (không theo dải "Chưa lưu thay đổi") --}}
    <form method="POST" id="password-form" action="{{ route('backend.profile.password') }}" class="ant-form ant-form-vertical" novalidate>
      @csrf
      @method('PUT')
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Đổi mật khẩu</div></div></div>
        <div class="ant-card-body">
          <p class="settings-card-desc">Nhập mật khẩu hiện tại để xác nhận. Đổi xong, các thiết bị khác đang đăng nhập tài khoản này sẽ bị đăng xuất.</p>
          {{-- Tên đăng nhập ẩn cho trình quản lý mật khẩu biết mật khẩu mới thuộc tài khoản nào --}}
          <input type="text" name="username" value="{{ $user->email }}" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" readonly />
          <div class="ant-row ant-form-item{{ $pwHasError('current_password') }}" data-required="Nhập mật khẩu hiện tại.">
            <div class="ant-col ant-form-item-label"><label for="current_password" class="ant-form-item-required" title="Mật khẩu hiện tại">Mật khẩu hiện tại</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <span class="ant-input-affix-wrapper ant-input-password"><input type="password" id="current_password" name="current_password" class="ant-input" autocomplete="current-password" /><span class="ant-input-suffix">{{ $eye }}</span></span>
              </div></div>
              {!! $pwError('current_password') !!}
            </div>
          </div>
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $pwHasError('password') }}" data-required="Nhập mật khẩu mới." data-min="8" data-min-message="Mật khẩu ít nhất 8 ký tự.">
              <div class="ant-col ant-form-item-label"><label for="password" class="ant-form-item-required" title="Mật khẩu mới">Mật khẩu mới</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-affix-wrapper ant-input-password"><input type="password" id="password" name="password" class="ant-input" autocomplete="new-password" placeholder="Ít nhất 8 ký tự" /><span class="ant-input-suffix">{{ $eye }}</span></span>
                </div></div>
                {!! $pwError('password') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item" data-required="Nhập lại mật khẩu mới." data-match="#password" data-match-message="Hai mật khẩu chưa khớp nhau.">
              <div class="ant-col ant-form-item-label"><label for="password_confirmation" class="ant-form-item-required" title="Nhập lại mật khẩu mới">Nhập lại mật khẩu mới</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <span class="ant-input-affix-wrapper ant-input-password"><input type="password" id="password_confirmation" name="password_confirmation" class="ant-input" autocomplete="new-password" placeholder="Gõ lại mật khẩu mới" /><span class="ant-input-suffix">{{ $eye }}</span></span>
                </div></div>
              </div>
            </div>
          </div>
          <div class="flex justify-end">
            <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('lock') }}<span>Đổi mật khẩu</span></button>
          </div>
        </div>
      </div>
    </form>
  </div>

  {{-- Cột phụ: chỉ xem (đổi quyền / trạng thái ở trang Người dùng, do người có quyền làm) --}}
  <div class="flex min-w-0 flex-col gap-4">
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Quyền truy cập</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts">
          <dt>Quyền</dt>
          <dd>
            @if ($user->isSuperAdmin())
              <span class="ant-tag ant-tag-gold m-0">Root admin</span> <span class="{{ $muted }}">— có mọi quyền</span>
            @elseif ($user->group)
              {{ $user->group->name }}
            @else
              <span class="{{ $muted }}">Chưa có nhóm</span>
            @endif
          </dd>
          <dt>Trạng thái</dt>
          <dd><span @class(['ant-badge-status-dot', 'ant-badge-status-success' => $user->isActive(), 'ant-badge-status-default' => ! $user->isActive()])></span> {{ $user->isActive() ? 'Đang hoạt động' : 'Đã khóa' }}</dd>
          <dt>Tham gia</dt>
          <dd class="tabular-nums">{{ $user->created_at?->format('d/m/Y') ?? '—' }}</dd>
          <dt>Cập nhật</dt>
          <dd class="tabular-nums">{{ $user->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </dl>
        <p class="mt-4 mb-0 {{ $muted }}">Quyền và trạng thái do người quản trị đổi ở trang Người dùng.</p>
      </div>
    </div>
  </div>
</div>
@endsection
