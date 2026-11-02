@extends('layouts.backend')

{{-- Nhóm quyền theo template ProCMS user-groups.html: danh sách nhóm (số người dùng) bên trái, nhóm đang chọn bên phải.
     Phần quyền (khác template, cho dễ đọc): công tắc "Đăng nhập trang quản trị" + bảng mục × Xem / Thêm / Sửa / Xóa, chia
     theo 'section' trong config/permission.php. Chọn nhóm khác = sang trang của nhóm đó (link thật); form gửi thẳng lên
     server. $mode: create | edit | view (không có quyền sửa nhóm này) | empty.
     JS: edit-form.js (thay đổi chưa lưu), group-form.js (bảng quyền, chọn cả cột, Chọn tất cả). --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $editing = $mode === 'edit';
    $readonly = $mode === 'view';
    $hasForm = in_array($mode, ['create', 'edit'], true);
    $canAdd = $me->can('group/add');
    $canEdit = $me->can('group/edit');
    // Quay lại sau lỗi validate thì dựng lại từ dữ liệu vừa gửi, không thì lấy từ DB
    // Nhóm mới: bật sẵn đăng nhập (nhóm nào cũng cần, và tắt thì cả bảng quyền bị khóa)
    $granted = match (true) {
        // (permission gửi sai kiểu — không phải mảng — thì validate đã báo lỗi; đừng để trang lỗi 500 khi dựng lại)
        session()->hasOldInput() && $hasForm => App\Models\Group::normalizePermissionInput(is_array(old('permission')) ? old('permission') : null),
        $mode === 'create' => ['backend_access'],
        default => $group->permission_data ?? [],
    };
    $on = fn (string $key) => in_array($key, $granted, true);
    // Người không phải Root chỉ cấp được quyền chính họ đang có
    $mine = $me->permissionKeys();
    $canGrant = fn (string $key) => ! $readonly && ($me->isSuperAdmin() || in_array($key, $mine, true));
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    // Ô tích Ant Design; $label rỗng thì chỉ có ô (cột Xem / Thêm / Sửa / Xóa), tên đọc qua aria-label
    $checkbox = fn (string $class, string $name, string $label, bool $checked, bool $enabled, string $aria = '', string $attrs = '') =>
        '<label class="ant-checkbox-wrapper'.($checked ? ' ant-checkbox-wrapper-checked' : '').($enabled ? '' : ' ant-checkbox-wrapper-disabled').'">'
        .'<span class="ant-checkbox'.($checked ? ' ant-checkbox-checked' : '').($enabled ? '' : ' ant-checkbox-disabled').'">'
        .'<input type="checkbox" class="ant-checkbox-input '.$class.'" name="'.e($name).'" value="1"'.($checked ? ' checked' : '').($enabled ? '' : ' disabled')
        .($aria !== '' ? ' aria-label="'.e($aria).'"' : '').$attrs.' />'
        .'<span class="ant-checkbox-inner"></span></span>'.($label !== '' ? '<span>'.e($label).'</span>' : '').'</label>';
    // Cột chuẩn của bảng quyền; mục có việc khác (vd Nhắc gia hạn) ghi việc ngay trong dòng, gộp 3 cột
    $columns = ['add' => 'Thêm', 'edit' => 'Sửa', 'delete' => 'Xóa'];
    $sections = collect($permissions)->except('backend_access')->groupBy(fn ($config) => $config['section'] ?? 'Khác', preserveKeys: true);
    $login = $on('backend_access');
    $title = match ($mode) { 'create' => 'Nhóm mới', 'empty' => 'Chưa có nhóm', default => $group->name };
    $members = (int) ($group->users_count ?? 0);
@endphp

@section('title', $mode === 'create' ? 'Nhóm quyền mới' : ($group->exists ? $group->name.' - Nhóm quyền' : 'Nhóm quyền'))
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    @can('user')
      <a href="{{ route('backend.users.index') }}" class="page-topbar-crumb max-md:hidden" title="Về danh sách người dùng">Người dùng</a>
      <span class="page-topbar-sep max-md:hidden" aria-hidden="true">{{ ant_icon('right') }}</span>
    @endcan
    <a href="{{ route('backend.groups.index') }}" class="page-topbar-crumb">Nhóm quyền</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $title }}</h1>
    @if ($readonly)<span class="ant-tag">Chỉ xem</span>@endif
  </div>
  @if ($hasForm)
    {{-- Hiện khi form có thay đổi chưa lưu --}}
    <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
      <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
      <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
      <button type="submit" form="group-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo nhóm' }}</button>
    </div>
  @endif
  <div class="page-topbar-right">
    @if ($editing)
      @can('group/delete')
        @if ($members)
          <span data-tooltip="Còn {{ $members }} người dùng trong nhóm, không xóa được (chuyển họ sang nhóm khác trước)"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa nhóm"
            data-delete-url="{{ route('backend.groups.destroy', $group) }}" data-delete-title="Xóa nhóm quyền “{{ $group->name }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
    @endif
    @if ($mode === 'create')
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo nhóm → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.groups.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="group-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo nhóm</span></button>
    @elseif ($canAdd)
      <a href="{{ route('backend.groups.create') }}" class="ant-btn ant-btn-primary" aria-label="Nhóm mới" data-hide-when-dirty>{{ ant_icon('plus') }}<span class="max-sm:hidden">Nhóm mới</span></a>
    @endif
  </div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" id="user-groups">
  {{-- Danh sách nhóm --}}
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tất cả nhóm <span class="{{ $muted }} font-normal">({{ $groups->count() }})</span></div></div></div>
    <div class="ant-card-body">
      <ul class="group-list" id="group-list" aria-label="Nhóm quyền">
        @forelse ($groups as $g)
          @php
              $selected = $group->exists && $g->id === $group->id;
              $href = $canEdit && $me->canAssignGroup($g) ? route('backend.groups.edit', $g) : route('backend.groups.index', ['id' => $g->id]);
          @endphp
          <li><a href="{{ $href }}" @class(['group-item', 'is-selected' => $selected]) @if ($selected) aria-current="page" @endif>{{ ant_icon('team', 'group-item-icon') }}<span class="group-item-name">{{ $g->name }}</span><span class="group-item-count">{{ $g->users_count }} người dùng</span></a></li>
        @empty
          <li class="ant-empty ant-empty-normal"><p class="ant-empty-description">Chưa có nhóm nào</p></li>
        @endforelse
      </ul>
    </div>
  </div>

  @if ($mode === 'empty')
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty ant-empty-normal">
          <div class="ant-empty-image">{{ ant_icon('team', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có nhóm quyền nào</strong>Nhóm quyết định người dùng được làm gì trong trang quản trị.</p>
          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.groups.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Tạo nhóm đầu tiên</span></a></div>@endif
        </div>
      </div>
    </div>
  @else
    <form method="POST" id="group-form" action="{{ $editing ? route('backend.groups.update', $group) : route('backend.groups.store') }}"
      class="ant-form ant-form-vertical edit-form flex min-w-0 flex-col gap-4" novalidate autocomplete="off"
      @if ($hasForm) data-edit-form @endif @if ($errors->any()) data-dirty @endif>
      @if ($hasForm)
        @csrf
        @if ($editing) @method('PUT') @endif
      @endif
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('name') }}" data-required="Nhập tên nhóm.">
            <div class="ant-col ant-form-item-label"><label for="group_name" class="ant-form-item-required" title="Tên nhóm">Tên nhóm</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="group_name" name="name" value="{{ $hasForm ? old('name', $group->name) : $group->name }}" class="ant-input" maxlength="100" placeholder="VD: Kế toán, Biên tập viên" @disabled($readonly) @if ($mode === 'create') autofocus @endif />
              </div></div>
              {!! $error('name') !!}
              @if ($group->exists)
                <div class="ant-form-item-extra">
                  @if ($members && $me->can('user'))
                    <a href="{{ route('backend.users.index', ['group' => $group->id]) }}">{{ $members }} người dùng</a> đang ở nhóm này.
                  @else
                    {{ $members ? $members.' người dùng đang ở nhóm này.' : 'Chưa có ai ở nhóm này.' }}
                  @endif
                </div>
              @endif
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Quyền</div>
            @if ($hasForm)<div class="ant-card-extra"><a href="#" id="perm-all" role="button" data-select="Chọn tất cả" data-clear="Bỏ chọn tất cả">Chọn tất cả</a></div>@endif
          </div>
        </div>
        <div class="ant-card-body">
          {{-- Đăng nhập: không có thì các quyền bên dưới cũng không dùng được --}}
          <div class="perm-login">
            <label class="flex items-start gap-3" for="perm-login" @unless ($canGrant('backend_access')) @if (! $readonly) data-tooltip="Bạn không có quyền này nên không cấp được" @endif @endunless>
              <button type="button" role="switch" id="perm-login" aria-checked="{{ $login ? 'true' : 'false' }}" @class(['ant-switch mt-0.5 flex-none', 'ant-switch-checked' => $login, 'ant-switch-disabled' => ! $canGrant('backend_access')]) @disabled(! $canGrant('backend_access'))><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>
              <input type="checkbox" name="permission[backend_access][_]" value="1" class="switch-input" hidden @checked($login) @disabled(! $canGrant('backend_access')) />
              <span>
                <span class="block font-medium text-[rgba(0,0,0,0.85)]">Đăng nhập trang quản trị</span>
                <span class="block {{ $muted }}" id="perm-login-hint" data-on="Người trong nhóm đăng nhập được, làm được những việc tích ở bảng dưới." data-off="Đang tắt: người trong nhóm không đăng nhập được, mọi quyền bên dưới đều không có tác dụng.">{{ $login ? 'Người trong nhóm đăng nhập được, làm được những việc tích ở bảng dưới.' : 'Đang tắt: người trong nhóm không đăng nhập được, mọi quyền bên dưới đều không có tác dụng.' }}</span>
              </span>
            </label>
          </div>
          {{-- Tắt đăng nhập: cả phần dưới mờ đi và khóa lại (inert: không bấm / Tab vào được). Các ô vẫn giữ và vẫn gửi đi,
               bật đăng nhập lại là dùng lại được như cũ --}}
          <div @class(['perm-body', 'is-off' => ! $login]) id="perm-body" @if (! $login) inert @endif>
          <p class="settings-card-desc mt-4">
            Tích <strong class="font-medium">Xem</strong> để nhóm vào được mục đó; tích Thêm / Sửa / Xóa sẽ tự bật Xem.
            @if ($hasForm && ! $me->isSuperAdmin()) Ô mờ là quyền bạn không có nên không cấp được.@endif
          </p>
          <div class="ant-table ant-table-small perm-table">
            <div class="ant-table-container">
              <div class="ant-table-content">
                <table id="perm-table">
                  <thead class="ant-table-thead">
                    <tr>
                      <th class="ant-table-cell">Mục</th>
                      {{-- Ô dưới tên cột: chọn / bỏ cả cột --}}
                      @foreach (['_' => 'Xem', ...$columns] as $col => $label)
                        <th class="ant-table-cell perm-col">
                          <span class="block">{{ $label }}</span>
                          @if ($hasForm){!! $checkbox('perm-col-toggle', '', '', false, true, 'Chọn cả cột '.$label, ' data-perm-col="'.$col.'"') !!}@endif
                        </th>
                      @endforeach
                    </tr>
                  </thead>
                  <tbody class="ant-table-tbody">
                    @foreach ($sections as $section => $modules)
                      <tr class="perm-section"><td colspan="5" class="ant-table-cell">{{ $section }}</td></tr>
                      @foreach ($modules as $module => $config)
                        @php
                            $items = $config['items'] ?? [];
                            $standard = array_diff(array_keys($items), array_keys($columns)) === []; // chỉ có Thêm / Sửa / Xóa
                        @endphp
                        <tr @class(['ant-table-row perm-row', 'is-on' => $on($module)]) data-module="{{ $module }}">
                          <td class="ant-table-cell">
                            <span class="perm-name">{{ $config['label'] }}</span>
                            {{-- Việc riêng của mục (không phải Thêm / Sửa / Xóa) ghi ngay dưới tên --}}
                            @unless ($standard)
                              <div class="perm-extra">
                                @foreach ($items as $action => $label)
                                  {!! $checkbox('perm-action', "permission[{$module}][{$action}]", $label, $on($module.'/'.$action), $canGrant($module.'/'.$action), '', ' data-action="'.$action.'"') !!}
                                @endforeach
                              </div>
                            @endunless
                          </td>
                          <td class="ant-table-cell perm-cell">{!! $checkbox('perm-module', "permission[{$module}][_]", '', $on($module), $canGrant($module), $config['label'].': xem') !!}</td>
                          @foreach ($columns as $action => $label)
                            <td class="ant-table-cell perm-cell">
                              @if ($standard && isset($items[$action]))
                                {!! $checkbox('perm-action', "permission[{$module}][{$action}]", '', $on($module.'/'.$action), $canGrant($module.'/'.$action), $config['label'].': '.mb_strtolower($label), ' data-action="'.$action.'"') !!}
                              @else
                                <span class="perm-none" title="Không có ở mục này">–</span>
                              @endif
                            </td>
                          @endforeach
                        </tr>
                      @endforeach
                    @endforeach
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          </div>
        </div>
      </div>
    </form>
  @endif
</div>
@endsection
