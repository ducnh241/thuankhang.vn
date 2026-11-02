@extends('layouts.backend')

@section('title', 'Người dùng')

{{-- Theo template ProCMS users.html: tab trạng thái, ô tìm + nút lọc nhóm quyền + sắp xếp, bảng ant-table (avatar, email,
     nhóm, trạng thái), phân trang. Lọc / tìm / phân trang ở server (link thật); resources/admin/js/list.js mở danh sách
     chọn của các nút lọc và cho bấm cả dòng để mở trang sửa; delete.js hỏi lại trước khi xóa. --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('user/add');
    $canEdit = $me->can('user/edit');
    $canDelete = $me->can('user/delete');
    $tabs = ['all' => 'Tất cả', 'active' => 'Kích hoạt', 'locked' => 'Đã khóa', 'root' => 'Root admin'];
    $sorts = ['date:desc' => 'Mới nhất', 'date:asc' => 'Cũ nhất', 'name:asc' => 'Tên A–Z', 'name:desc' => 'Tên Z–A'];

    // Link danh sách giữ ô tìm / tab / nhóm / sắp xếp đang chọn, đổi vài tham số (null = bỏ), luôn về trang 1
    $current = array_filter(['status' => $status !== 'all' ? $status : null, 'q' => $q, 'group' => $group, 'sort' => $sort !== 'date:desc' ? $sort : null]);
    $url = fn (array $changes = []) => route('backend.users.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $filtering = $q !== '' || $group !== '';
    $groupName = $group === 'none' ? 'Chưa có nhóm' : $groups->firstWhere('id', (int) $group)?->name;
    $statusTag = fn ($u) => $u->isActive() ? '<span class="ant-tag ant-tag-green m-0">Kích hoạt</span>' : '<span class="ant-tag m-0">Đã khóa</span>';
@endphp

@section('actions')
  @can('group')
    <a href="{{ route('backend.groups.index') }}" class="ant-btn" aria-label="Nhóm quyền">{{ ant_icon('team') }}<span class="max-sm:hidden">Nhóm quyền</span></a>
  @endcan
  @if ($canAdd)
    <a href="{{ route('backend.users.create') }}" class="ant-btn ant-btn-primary" aria-label="Thêm người dùng">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm người dùng</span></a>
  @endif
@endsection

@section('content')
<div id="users" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    {{-- Tab trạng thái (số đếm theo ô tìm + nhóm đang lọc) --}}
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Lọc theo trạng thái">
        @foreach ($tabs as $key => $label)
          <a href="{{ $url(['status' => $key === 'all' ? null : $key]) }}" role="tab" aria-selected="{{ $status === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $status === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    {{-- Tìm kiếm (Enter để tìm) + lọc nhóm quyền + sắp xếp --}}
    <div class="product-search">
      <form method="GET" action="{{ route('backend.users.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except($current, ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $q }}" class="ant-input" id="user-search" placeholder="Tìm theo tên, email, điện thoại" aria-label="Tìm người dùng" />
          @if ($q !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($group !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="group">Nhóm: {{ $groupName }}</button><a href="{{ $url(['group' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc nhóm">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="group">Nhóm quyền{{ ant_icon('down') }}</button>
        @endif
        @if ($filtering)
          <a href="{{ $url(['q' => null, 'group' => null]) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
        <button type="button" class="filter-pill ml-auto" data-filter="sort">Sắp xếp: {{ $sorts[$sort] }}{{ ant_icon('down') }}</button>
      </div>
      <template id="filter-group">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($groups as $g)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $group === (string) $g->id]) role="menuitem"><a href="{{ $url(['group' => $g->id]) }}">{{ $g->name }}</a></li>
            @endforeach
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $group === 'none']) role="menuitem"><a href="{{ $url(['group' => 'none']) }}">Chưa có nhóm</a></li>
          </ul>
        </div>
      </template>
      <template id="filter-sort">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($sorts as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $sort === $key]) role="menuitem"><a href="{{ $url(['sort' => $key === 'date:desc' ? null : $key]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table class="product-table user-table min-w-0" style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Người dùng</th>
                  <th class="ant-table-cell" data-col="group">Nhóm quyền</th>
                  <th class="ant-table-cell" data-col="status">Trạng thái</th>
                  <th class="ant-table-cell max-md:hidden" data-col="created">Ngày tạo</th>
                  <th class="ant-table-cell product-actions-col" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="user-rows">
                @forelse ($users as $user)
                  @php
                      $self = $user->is($me);
                      $manageable = $me->canManage($user);
                      $editable = $manageable && $canEdit;
                      $href = $editable ? route('backend.users.edit', $user) : null;
                      // Không xóa được: chính mình / Root admin cuối cùng → nút mờ kèm lý do
                      $blocked = $self ? 'Không thể xóa tài khoản đang đăng nhập' : ($user->isLastSuperAdmin() ? 'Root admin cuối cùng, không xóa được (cần có Root admin khác trước)' : null);
                  @endphp
                  <tr data-row-key="{{ $user->id }}" @class(['ant-table-row', 'product-row' => $href]) @if ($href) data-href="{{ $href }}" @endif>
                    <td class="ant-table-cell">
                      <div class="flex min-w-0 items-center gap-3">
                        @include('backend.partials.avatar', ['user' => $user])
                        <div class="min-w-[120px] whitespace-normal">
                          @if ($href)
                            <a href="{{ $href }}" class="product-name wrap-anywhere">{{ $user->name }}</a>
                          @else
                            <span class="product-name wrap-anywhere">{{ $user->name }}</span>
                          @endif
                          @if ($self)<span class="ant-tag ant-tag-blue ml-1.5 align-middle">Bạn</span>@endif
                          <div class="text-xs {{ $muted }} wrap-anywhere">{{ $user->email }}</div>
                          {{-- Điện thoại: nhóm + trạng thái ghi dưới email --}}
                          <div class="product-row-meta">@if ($user->isSuperAdmin())<span class="ant-tag ant-tag-gold m-0">Root admin</span>@endif {!! $statusTag($user) !!} <span class="{{ $muted }} wrap-anywhere">{{ $user->group?->name }}</span></div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell" data-col="group">
                      <div class="max-w-[220px] min-w-[100px] whitespace-normal">
                        @if ($user->isSuperAdmin())<span class="ant-tag ant-tag-gold">Root admin</span>@endif
                        @if ($user->group)
                          <span class="wrap-anywhere">{{ $user->group->name }}</span>
                        @elseif (! $user->isSuperAdmin())
                          <span class="{{ $muted }}">Chưa có nhóm</span>
                        @endif
                      </div>
                    </td>
                    <td class="ant-table-cell" data-col="status">{!! $statusTag($user) !!}</td>
                    <td class="ant-table-cell tabular-nums max-md:hidden" data-col="created">{{ $user->created_at?->format('d/m/Y') }}</td>
                    <td class="ant-table-cell product-actions-col" style="text-align: right">
                      <div class="product-row-actions">
                        @if ($editable)
                          <a href="{{ $href }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $user->name }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete && $manageable)
                          @if ($blocked)
                            <span data-tooltip="{{ $blocked }}"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled aria-label="Xóa {{ $user->name }}">{{ ant_icon('delete') }}</button></span>
                          @else
                            <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $user->name }}"
                              data-delete-url="{{ route('backend.users.destroy', $user) }}" data-delete-title="Xóa người dùng “{{ $user->name }}”?"
                              data-delete-content="Tài khoản sẽ không đăng nhập được nữa. Không thể hoàn tác.">{{ ant_icon('delete') }}</button>
                          @endif
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="5" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('search', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có người dùng nào</strong>Thử từ khóa khác hoặc bỏ bớt bộ lọc.</p>
                        <div class="ant-empty-footer"><a href="{{ route('backend.users.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                      </div>
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    @if ($users->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($users->firstItem()) }}–{{ $count($users->lastItem()) }} / {{ $count($users->total()) }} người dùng</span>
        {{ $users->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
