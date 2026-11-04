@extends('layouts.backend')

@section('title', 'Điểm nhận hàng')

{{-- VP tỉnh / điểm nhận hàng (A7): tab bật / tắt, tìm (tên / địa chỉ / SĐT), lọc tỉnh (list.js), bảng theo tỉnh. Chỉ điểm
     đang bật hiện ở checkout. Tỉnh đã đủ số điểm tối đa (Tham số chung) có nhãn "Đủ". --}}
@php
    use App\Http\Controllers\Backend\PickupPointController;
    use App\Models\PickupPoint;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('pickup_point/add');
    $canEdit = $me->can('pickup_point/edit');
    $canDelete = $me->can('pickup_point/delete');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.pickup-points.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $provinces = PickupPoint::provinces();
@endphp

@section('actions')
  @if ($canAdd)
    <a href="{{ route('backend.pickup-points.create') }}" class="ant-btn ant-btn-primary" aria-label="Thêm điểm nhận hàng">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm điểm nhận hàng</span></a>
  @endif
@endsection

@section('content')
<div id="pickup-points" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (PickupPointController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
      <div class="ml-auto text-xs {{ $muted }} max-md:hidden">Tối đa mỗi tỉnh: <strong>{{ $max ? $max.' điểm đang bật' : 'không giới hạn' }}</strong>@can('affiliate_setting') · <a href="{{ route('backend.affiliate.settings.edit') }}">Tham số chung</a>@endcan</div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.pickup-points.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="pickup-search" placeholder="Tìm theo tên, địa chỉ, số điện thoại" aria-label="Tìm điểm nhận hàng" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($filter['province'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="province">Tỉnh: {{ $provinces[$filter['province']] }}</button><a href="{{ $url(['province' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc tỉnh">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="province">Tỉnh / thành{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['q'] !== '' || $filter['province'] !== '')
          <a href="{{ route('backend.pickup-points.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-province">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($provinces as $key => $name)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['province'] === $key]) role="menuitem"><a href="{{ $url(['province' => $key]) }}">{{ $name }}@if ($activeByProvince[$key] ?? 0) ({{ $activeByProvince[$key] }})@endif</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $points->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Điểm nhận hàng</th>
                  <th class="ant-table-cell">Tỉnh / thành</th>
                  <th class="ant-table-cell max-md:hidden">Liên hệ</th>
                  <th class="ant-table-cell max-md:hidden">Tài khoản VP</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="pickup-rows">
                @forelse ($points as $point)
                  @php $href = $canEdit ? route('backend.pickup-points.edit', $point) : null; $full = $max && ($activeByProvince[$point->province] ?? 0) >= $max; @endphp
                  <tr data-row-key="{{ $point->id }}" @class(['ant-table-row', 'product-row' => $href]) @if ($href) data-href="{{ $href }}" @endif>
                    <td class="ant-table-cell">
                      @if ($href)<a href="{{ $href }}" class="product-name">{{ $point->name }}</a>@else<span class="product-name">{{ $point->name }}</span>@endif
                      <div class="max-w-[420px] whitespace-normal text-xs {{ $muted }}">{{ $point->address }}</div>
                    </td>
                    <td class="ant-table-cell whitespace-nowrap">
                      {{ $point->provinceName() }}
                      @if ($full)<span class="ant-tag ant-tag-orange ml-1" title="Đã đủ {{ $max }} điểm đang bật">Đủ</span>@endif
                    </td>
                    <td class="ant-table-cell max-md:hidden">
                      @if ($point->phone)<div class="tabular-nums">{{ $point->phone }}</div>@endif
                      @if ($point->opening_hours)<div class="text-xs {{ $muted }}">{{ $point->opening_hours }}</div>@endif
                      @if (! $point->phone && ! $point->opening_hours)<span class="{{ $muted }}">—</span>@endif
                    </td>
                    <td class="ant-table-cell max-md:hidden">
                      @if ($point->owner)
                        @can('member')<a href="{{ route('backend.members.show', $point->owner) }}">{{ $point->owner->full_name }}</a>@else{{ $point->owner->full_name }}@endcan
                        <div class="text-xs font-mono {{ $muted }}">{{ $point->owner->referral_code }}</div>
                      @else
                        <span class="{{ $muted }}">Chưa gán</span>
                      @endif
                    </td>
                    <td class="ant-table-cell">{!! $point->active ? '<span class="ant-tag ant-tag-green m-0">Đang bật</span>' : '<span class="ant-tag m-0">Đang tắt</span>' !!}</td>
                    <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                      <div class="product-row-actions">
                        @if ($href)
                          <a href="{{ $href }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $point->name }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $point->name }}"
                            data-delete-url="{{ route('backend.pickup-points.destroy', $point) }}" data-delete-title="Xóa điểm nhận hàng “{{ $point->name }}”?" data-delete-content="Điểm không còn hiện ở checkout; đơn hàng cũ vẫn giữ điểm đã chọn.">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('environment', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if (array_filter($filter))
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy điểm nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có điểm nhận hàng</strong>Khách chọn "Nhận tại VP tỉnh" ở bước đặt hàng sẽ thấy các điểm đang bật.</p>
                          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.pickup-points.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm điểm nhận hàng</span></a></div>@endif
                        @endif
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
    @if ($points->isNotEmpty())
      <div class="product-index-footer"><span class="{{ $muted }}">{{ $count($points->count()) }} điểm nhận hàng</span></div>
    @endif
  </div>
</div>
@endsection
