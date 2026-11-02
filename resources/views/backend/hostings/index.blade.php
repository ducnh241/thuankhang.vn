@extends('layouts.backend')

@section('title', 'Hosting')

{{-- Template ProCMS không có trang hosting: dựng như các trang danh sách khác (products.html) — chỉ vài hosting nên không
     tìm / lọc / sắp xếp: một bảng theo tên A–Z, thanh thời hạn (Ant Progress), số dự án bấm được. Bấm dòng mở trang sửa
     (list.js); xóa hỏi lại (delete.js) — hosting còn dự án thì không xóa được. --}}
@php
    use App\Http\Controllers\Backend\HostingController;
    use App\Models\Hosting;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('hosting/add');
    $canEdit = $me->can('hosting/edit');
    $canDelete = $me->can('hosting/delete');
    $canSeeProjects = $me->can('project');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    // Loại hosting: màu riêng, khác xanh / vàng / đỏ của thời hạn
    $ownershipTag = ['mine' => 'ant-tag-purple', 'customer' => 'ant-tag-blue'];
@endphp

@section('actions')
  @if ($canAdd)
    <a href="{{ route('backend.hostings.create') }}" class="ant-btn ant-btn-primary" aria-label="Thêm hosting">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm hosting</span></a>
  @endif
@endsection

@section('content')
<div id="hostings" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table class="product-table hosting-table" style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Hosting</th>
                  <th class="ant-table-cell" data-col="type">Loại</th>
                  <th class="ant-table-cell max-lg:hidden" data-col="ip">IP</th>
                  <th class="ant-table-cell max-xl:hidden" data-col="specs">Cấu hình</th>
                  <th class="ant-table-cell max-md:hidden" data-col="expiry">Hết hạn</th>
                  <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Dự án</th>
                  {{-- Điện thoại: không cột thao tác — chạm dòng để mở trang sửa --}}
                  <th class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="hosting-rows">
                @forelse ($hostings as $hosting)
                  @php
                      $href = $canEdit ? route('backend.hostings.edit', $hosting) : null;
                      $days = $hosting->daysLeft();
                      // Thời hạn: số ngày + thanh (365 ngày trở lên = 100%, hết hạn = 0%); đỏ khi hết hạn, vàng khi ≤ 30 ngày
                      [$expLabel, $expStatus, $expColor] = match (true) {
                          $days === null => [null, null, null],
                          $days < 0 => ['Quá hạn '.abs($days).' ngày', 'exception', '#ff4d4f'],
                          $days === 0 => ['Hết hạn hôm nay', 'exception', '#ff4d4f'],
                          $days <= 30 => ['Còn '.$days.' ngày', 'normal', '#faad14'],
                          default => ['Còn '.$days.' ngày', 'success', '#52c41a'],
                      };
                      $expPercent = $days === null ? 0 : round(max(0, min($days, HostingController::EXPIRY_FULL_DAYS)) / HostingController::EXPIRY_FULL_DAYS * 100, 1);
                      $specs = $hosting->specLabel();
                      $sub = collect([$hosting->provider, $hosting->plan_name])->filter()->implode(' · ');
                      $typeTags = '<span class="ant-tag m-0 '.($ownershipTag[$hosting->ownership] ?? '').'">'.e(Hosting::OWNERSHIPS[$hosting->ownership] ?? $hosting->ownership).'</span>'
                          .($hosting->is_active ? '' : ' <span class="ant-tag m-0">Ngừng dùng</span>');
                  @endphp
                  <tr data-row-key="{{ $hosting->id }}" @class(['ant-table-row', 'product-row' => $href]) @if ($href) data-href="{{ $href }}" @endif>
                    <td class="ant-table-cell">
                      <div class="min-w-[140px] max-w-[280px] whitespace-normal">
                        @if ($href)
                          <a href="{{ $href }}" class="product-name wrap-anywhere">{{ $hosting->name }}</a>
                        @else
                          <span class="product-name wrap-anywhere">{{ $hosting->name }}</span>
                        @endif
                        <div class="text-xs {{ $muted }} wrap-anywhere">{{ $sub ?: '—' }}</div>
                        {{-- Điện thoại / tablet: loại, IP, hạn ghi dưới tên --}}
                        <div class="product-row-meta">{!! $typeTags !!}</div>
                        <div class="hosting-meta text-xs {{ $muted }} wrap-anywhere">
                          <span class="lg:hidden font-mono">{{ $hosting->ip }}</span>
                          @if ($expLabel)<span class="md:hidden" style="color: {{ $expColor }}">{{ $hosting->ip ? ' · ' : '' }}{{ $expLabel }}</span>@endif
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell" data-col="type"><div class="flex flex-wrap gap-1">{!! $typeTags !!}</div></td>
                    <td class="ant-table-cell font-mono text-xs max-lg:hidden" data-col="ip"><div class="max-w-[150px] whitespace-normal wrap-anywhere">{{ $hosting->ip ?: '—' }}</div></td>
                    <td class="ant-table-cell {{ $muted }} max-xl:hidden" data-col="specs"><div class="max-w-[220px] whitespace-normal">{{ $specs ?: '—' }}</div></td>
                    <td class="ant-table-cell max-md:hidden" data-col="expiry">
                      @if ($hosting->expire_date)
                        <div class="tabular-nums">{{ $hosting->expire_date->format('d/m/Y') }}</div>
                        <div class="hosting-expiry" data-expiry="{{ $expPercent }}">
                          <div class="ant-progress ant-progress-line ant-progress-small ant-progress-status-{{ $expStatus }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $expPercent }}" aria-label="Thời hạn còn lại (365 ngày = 100%)"><div class="ant-progress-outer"><div class="ant-progress-inner"><div class="ant-progress-bg" style="width: {{ $expPercent }}%; height: 6px; background-color: {{ $expColor }}"></div></div></div></div>
                          <span class="text-xs font-medium tabular-nums whitespace-nowrap" style="color: {{ $expColor }}">{{ $expLabel }}</span>
                        </div>
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums" style="text-align: right">
                      @if ($hosting->projects_count && $canSeeProjects)
                        <a href="{{ route('backend.projects.index', ['hosting_id' => $hosting->id]) }}" data-tooltip="Xem các dự án trên hosting này">{{ $count($hosting->projects_count) }}</a>
                      @else
                        <span @class([$muted => ! $hosting->projects_count])>{{ $count($hosting->projects_count) }}</span>
                      @endif
                    </td>
                    <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                      <div class="product-row-actions">
                        @if ($href)
                          <a href="{{ $href }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $hosting->name }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete && $hosting->projects_count)
                          <span data-tooltip="Đang gắn {{ $count($hosting->projects_count) }} dự án, không xóa được (bỏ gắn dự án trước)"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled aria-label="Xóa {{ $hosting->name }} (không xóa được)">{{ ant_icon('delete') }}</button></span>
                        @elseif ($canDelete)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $hosting->name }}"
                            data-delete-url="{{ route('backend.hostings.destroy', $hosting) }}" data-delete-title="Xóa hosting “{{ $hosting->name }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="7" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('cloud-server', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có hosting nào</strong>VPS / hosting của mình và của khách, hạn gia hạn và các dự án đang chạy trên đó.</p>
                        @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.hostings.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm hosting</span></a></div>@endif
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

    @if ($hostings->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($hostings->firstItem()) }}–{{ $count($hostings->lastItem()) }} / {{ $count($hostings->total()) }} hosting</span>
        {{ $hostings->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
