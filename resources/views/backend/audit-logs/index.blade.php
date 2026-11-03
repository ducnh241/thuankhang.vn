@extends('layouts.backend')

@section('title', 'Nhật ký hệ thống')

{{-- Nhật ký hệ thống (đặc tả Affiliate A14): chỉ xem, mới nhất trước. Dựng như trang Thanh toán: ô tìm + nút lọc (thao
     tác, loại đối tượng — list.js mở danh sách trong <template id="filter-…">) + khoảng ngày, bảng, phân trang.
     Thay đổi cột: nút mở rộng đầu dòng mở bảng trước → sau ngay dưới (expandable row, table-expand.js). --}}
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
    $today = now();
    $presets = [
        ['label' => 'Hôm nay', 'from' => $today->toDateString(), 'to' => $today->toDateString()],
        ['label' => '7 ngày qua', 'from' => $today->copy()->subDays(6)->toDateString(), 'to' => $today->toDateString()],
        ['label' => 'Tháng này', 'from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
        ['label' => 'Tháng trước', 'from' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
    ];
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
        {{-- RangePicker (picker.js + list.js): chọn đủ 2 ngày → tải lại với from / to; ⓧ bỏ lọc ngày --}}
        <div @class(['ant-picker ant-picker-range ant-picker-small payment-range', 'is-applied' => $filter['from'] !== '' || $filter['to'] !== '']) id="audit-range" data-picker="range"
          data-presets="{{ json_encode($presets, JSON_UNESCAPED_UNICODE) }}" data-url="{{ $url(['from' => null, 'to' => null]) }}">
          <div class="ant-picker-input ant-picker-input-active"><input placeholder="Từ ngày" readonly aria-label="Từ ngày" value="{{ $filter['from'] }}" /></div>
          <div class="ant-picker-range-separator"><span class="ant-picker-separator">{{ ant_icon('swap-right') }}</span></div>
          <div class="ant-picker-input"><input placeholder="Đến ngày" readonly aria-label="Đến ngày" value="{{ $filter['to'] }}" /></div>
          <div class="ant-picker-active-bar" style="left: 0px; width: 96px; position: absolute"></div>
          <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
          @if ($filter['from'] !== '' || $filter['to'] !== '')<span class="ant-picker-clear" role="button" aria-label="Bỏ lọc ngày">{{ ant_icon('close-circle') }}</span>@endif
        </div>
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
                  <th class="ant-table-cell ant-table-row-expand-icon-cell" style="width: 48px"></th>
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
                    <td class="ant-table-cell ant-table-row-expand-icon-cell">
                      @if ($fields !== [])
                        <button type="button" class="ant-table-row-expand-icon ant-table-row-expand-icon-collapsed" data-expand="log-{{ $log->id }}" aria-label="Mở rộng" aria-expanded="false"></button>
                      @endif
                    </td>
                    <td class="ant-table-cell whitespace-nowrap tabular-nums">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td class="ant-table-cell">{{ $log->actorName() }}</td>
                    <td class="ant-table-cell"><span class="ant-tag m-0 {{ $actionTag[$log->action] ?? '' }}">{{ $log->actionLabel() }}</span></td>
                    <td class="ant-table-cell">
                      @if ($log->subjectLabel())
                        <span class="font-medium text-[rgba(0,0,0,0.85)]">{{ $log->subjectLabel() }}</span>@if ($log->auditable_id) <span class="{{ $muted }} tabular-nums">#{{ $log->auditable_id }}</span>@endif
                      @endif
                      @if ($log->description)
                        <div class="ant-typography ant-typography-secondary text-xs wrap-anywhere">{{ $log->description }}</div>
                      @endif
                    </td>
                    <td class="ant-table-cell font-mono text-xs {{ $muted }} max-sm:hidden">{{ $log->ip_address ?? '—' }}</td>
                  </tr>
                  @if ($fields !== [])
                    {{-- Dòng mở rộng (table-expand.js): bảng trường thay đổi trước → sau --}}
                    <tr class="ant-table-expanded-row ant-table-expanded-row-level-1 hidden" data-expanded-for="log-{{ $log->id }}">
                      <td class="ant-table-cell" colspan="6">
                        <div class="ant-table ant-table-small ant-table-bordered">
                          <div class="ant-table-container"><div class="ant-table-content">
                            <table style="table-layout: auto">
                              <thead class="ant-table-thead"><tr>
                                <th class="ant-table-cell" style="width: 200px">Trường</th>
                                @if ($log->action === 'updated')<th class="ant-table-cell">Trước</th><th class="ant-table-cell">Sau</th>@else<th class="ant-table-cell">Giá trị</th>@endif
                              </tr></thead>
                              <tbody class="ant-table-tbody">
                                @foreach ($fields as $field)
                                  <tr class="ant-table-row">
                                    <td class="ant-table-cell font-mono text-xs">{{ $field }}</td>
                                    @if ($log->action === 'updated')
                                      <td class="ant-table-cell wrap-anywhere {{ $muted }}">{{ $fmt($old[$field] ?? null) }}</td>
                                      <td class="ant-table-cell wrap-anywhere">{{ $fmt($new[$field] ?? null) }}</td>
                                    @else
                                      <td class="ant-table-cell wrap-anywhere">{{ $fmt(($new ?: $old)[$field] ?? null) }}</td>
                                    @endif
                                  </tr>
                                @endforeach
                              </tbody>
                            </table>
                          </div></div>
                        </div>
                      </td>
                    </tr>
                  @endif
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
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
