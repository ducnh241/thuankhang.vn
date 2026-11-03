@extends('layouts.backend')

@section('title', 'Nhật ký hệ thống')

{{-- Nhật ký hệ thống (đặc tả Affiliate A14): chỉ xem, mới nhất trước. Dựng như trang Thanh toán: ô tìm + nút lọc (thao
     tác, loại đối tượng — list.js mở danh sách trong <template id="filter-…">) + khoảng ngày, bảng, phân trang.
     Thay đổi cột: bấm "Chi tiết" mở bảng trước → sau ngay dưới dòng. --}}
@php
    use App\Models\AuditLog;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $current = array_filter($filter);
    $url = fn (array $changes = []) => route('backend.audit-logs.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $filtering = $current !== [];
    $actionTag = [
        'created' => 'ant-tag-green', 'updated' => 'ant-tag-blue', 'deleted' => 'ant-tag-red', 'bulk_deleted' => 'ant-tag-red',
        'restored' => 'ant-tag-purple', 'login' => 'ant-tag-cyan', 'logout' => '', 'login_failed' => 'ant-tag-orange',
    ];
    $fmt = fn ($value) => match (true) {
        $value === null => '—',
        is_bool($value) => $value ? 'có' : 'không',
        is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        default => \Illuminate\Support\Str::limit((string) $value, 200),
    };
    $pills = [
        'action' => ['Thao tác', AuditLog::ACTIONS],
        'type' => ['Đối tượng', AuditLog::SUBJECTS],
    ];
@endphp

@section('content')
<div id="audit-logs" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-search">
      <form method="GET" action="{{ route('backend.audit-logs.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except($current, ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="audit-search" placeholder="Tìm theo người thực hiện, mô tả, IP, ID đối tượng" aria-label="Tìm nhật ký" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @foreach ($pills as $key => [$label, $options])
          @if ($filter[$key] !== '')
            <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="{{ $key }}">{{ $label }}: {{ $options[$filter[$key]] }}</button><a href="{{ $url([$key => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc {{ mb_strtolower($label) }}">{{ ant_icon('close') }}</a></span>
          @else
            <button type="button" class="filter-pill" data-filter="{{ $key }}">{{ $label }}{{ ant_icon('down') }}</button>
          @endif
        @endforeach
        <form method="GET" action="{{ route('backend.audit-logs.index') }}" class="flex flex-wrap items-center gap-2" id="audit-range">
          @foreach (\Illuminate\Support\Arr::except($current, ['from', 'to']) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
          @endforeach
          <input type="date" name="from" value="{{ $filter['from'] }}" class="ant-input ant-input-sm" style="width: 150px" aria-label="Từ ngày" data-autosubmit />
          <span class="{{ $muted }}">→</span>
          <input type="date" name="to" value="{{ $filter['to'] }}" class="ant-input ant-input-sm" style="width: 150px" aria-label="Đến ngày" data-autosubmit />
        </form>
        @if ($filtering)
          <a href="{{ route('backend.audit-logs.index') }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      @foreach ($pills as $key => [$label, $options])
        <template id="filter-{{ $key }}">
          <div class="ant-dropdown">
            <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
              @foreach ($options as $value => $text)
                <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter[$key] === $value]) role="menuitem"><a href="{{ $url([$key => $value]) }}">{{ $text }}</a></li>
              @endforeach
            </ul>
          </div>
        </template>
      @endforeach
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table class="product-table" style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell whitespace-nowrap" style="width: 150px">Thời gian</th>
                  <th class="ant-table-cell" style="width: 180px">Người thực hiện</th>
                  <th class="ant-table-cell" style="width: 130px">Thao tác</th>
                  <th class="ant-table-cell">Đối tượng</th>
                  <th class="ant-table-cell max-sm:hidden" style="width: 140px">IP</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="audit-rows">
                @forelse ($logs as $log)
                  @php
                      $old = $log->changes['old'] ?? [];
                      $new = $log->changes['new'] ?? [];
                      $fields = array_unique([...array_keys($old ?? []), ...array_keys($new ?? [])]);
                  @endphp
                  <tr class="ant-table-row" data-row-key="{{ $log->id }}">
                    <td class="ant-table-cell whitespace-nowrap tabular-nums">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td class="ant-table-cell">{{ $log->actorName() }}</td>
                    <td class="ant-table-cell"><span class="ant-tag m-0 {{ $actionTag[$log->action] ?? '' }}">{{ $log->actionLabel() }}</span></td>
                    <td class="ant-table-cell">
                      @if ($log->subjectLabel())
                        <span class="font-medium text-[rgba(0,0,0,0.85)]">{{ $log->subjectLabel() }}</span>@if ($log->auditable_id) <span class="{{ $muted }} tabular-nums">#{{ $log->auditable_id }}</span>@endif
                      @endif
                      @if ($log->description)
                        <div class="text-xs {{ $muted }} wrap-anywhere">{{ $log->description }}</div>
                      @endif
                      @if ($fields !== [])
                        <details class="mt-1 text-xs">
                          <summary class="cursor-pointer text-[#1890ff]">Chi tiết ({{ count($fields) }} trường)</summary>
                          <div class="mt-1 flex flex-col gap-0.5">
                            @foreach ($fields as $field)
                              <div class="wrap-anywhere">
                                <span class="{{ $muted }}">{{ $field }}:</span>
                                @if ($log->action === 'updated')
                                  <span class="{{ $muted }} line-through">{{ $fmt($old[$field] ?? null) }}</span> →
                                  <span class="font-medium text-[rgba(0,0,0,0.85)]">{{ $fmt($new[$field] ?? null) }}</span>
                                @else
                                  <span class="text-[rgba(0,0,0,0.85)]">{{ $fmt(($new ?: $old)[$field] ?? null) }}</span>
                                @endif
                              </div>
                            @endforeach
                          </div>
                        </details>
                      @endif
                    </td>
                    <td class="ant-table-cell font-mono text-xs {{ $muted }} max-sm:hidden">{{ $log->ip_address ?? '—' }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="5" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('history', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">{{ $filtering ? 'Không có dòng nào khớp bộ lọc' : 'Chưa có nhật ký' }}</strong>Mọi thao tác thêm / sửa / xóa trong trang quản trị và đăng nhập / đăng xuất đều được ghi lại ở đây.</p>
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

    @if ($logs->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($logs->firstItem()) }}–{{ $count($logs->lastItem()) }} / {{ $count($logs->total()) }} dòng</span>
        {{ $logs->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
