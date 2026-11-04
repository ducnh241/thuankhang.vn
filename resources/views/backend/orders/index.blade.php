@extends('layouts.backend')

@section('title', 'Đơn hàng')

{{-- Đơn hàng (A8), dựng như danh sách Thành viên: tab trạng thái (số theo bộ lọc; đơn mới quá hạn tính vào "Đã hủy"),
     ô tìm (mã đơn / mã giao dịch / người nhận / thành viên / SĐT) + nút lọc điểm nhận hàng, hình thức, thời gian (list.js). --}}
@php
    use App\Models\Order;
    use App\Models\PickupPoint;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.orders.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $provinces = PickupPoint::provinces();
    $point = $filter['pickup_point_id'] !== '' ? $points->firstWhere('id', (int) $filter['pickup_point_id']) : null;
    $tabs = ['' => 'Tất cả', ...Order::STATUSES];
    $hasFilter = $filter['q'] !== '' || $point || $filter['delivery'] !== '' || $filter['period'] !== '';
@endphp

@section('content')
<div id="orders" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach ($tabs as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.orders.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="order-search" placeholder="Tìm theo mã đơn, mã giao dịch, tên / SĐT người nhận hoặc thành viên" aria-label="Tìm đơn hàng" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($filter['delivery'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="delivery">Hình thức: {{ Order::DELIVERIES[$filter['delivery']] }}</button><a href="{{ $url(['delivery' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc hình thức">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="delivery">Hình thức nhận{{ ant_icon('down') }}</button>
        @endif
        @if ($point)
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="point">Điểm: {{ $point->name }}</button><a href="{{ $url(['pickup_point_id' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc điểm nhận hàng">{{ ant_icon('close') }}</a></span>
        @elseif ($points->isNotEmpty())
          <button type="button" class="filter-pill" data-filter="point">Điểm nhận hàng{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['period'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="period">Thời gian: {{ \App\Http\Controllers\Backend\OrderController::PERIODS[$filter['period']] }}</button><a href="{{ $url(['period' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc thời gian">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="period">Thời gian đặt{{ ant_icon('down') }}</button>
        @endif
        @if ($hasFilter)
          <a href="{{ route('backend.orders.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-delivery">
        <div class="ant-dropdown"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
          @foreach (Order::DELIVERIES as $key => $label)
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['delivery'] === $key]) role="menuitem"><a href="{{ $url(['delivery' => $key]) }}">{{ $label }}</a></li>
          @endforeach
        </ul></div>
      </template>
      <template id="filter-point">
        <div class="ant-dropdown"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical max-h-80 overflow-y-auto" role="menu">
          @foreach ($points as $item)
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['pickup_point_id'] === (string) $item->id]) role="menuitem"><a href="{{ $url(['pickup_point_id' => $item->id, 'delivery' => null]) }}">{{ $item->name }} <span class="{{ $muted }}">· {{ $provinces[$item->province] ?? $item->province }}{{ $item->trashed() ? ' (đã xóa)' : '' }}</span></a></li>
          @endforeach
        </ul></div>
      </template>
      <template id="filter-period">
        <div class="ant-dropdown"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
          @foreach (\App\Http\Controllers\Backend\OrderController::PERIODS as $key => $label)
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['period'] === $key]) role="menuitem"><a href="{{ $url(['period' => $key]) }}">{{ $label }}</a></li>
          @endforeach
        </ul></div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $orders->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Mã đơn</th>
                  <th class="ant-table-cell">Thành viên</th>
                  <th class="ant-table-cell max-md:hidden">Nhận hàng</th>
                  <th class="ant-table-cell" style="text-align: right">Tổng tiền</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell max-sm:hidden">Đặt lúc</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="order-rows">
                @forelse ($orders as $order)
                  @php $href = route('backend.orders.show', $order); $status = $order->displayStatus(); @endphp
                  <tr data-row-key="{{ $order->id }}" class="ant-table-row product-row" data-href="{{ $href }}">
                    <td class="ant-table-cell">
                      <a href="{{ $href }}" class="product-name font-mono">{{ $order->code }}</a>
                      <div class="text-xs {{ $muted }}">{{ $count($order->items_count) }} sản phẩm</div>
                    </td>
                    <td class="ant-table-cell">
                      {{ $order->member?->full_name }}
                      <div class="text-xs tabular-nums {{ $muted }}">{{ $order->member?->phone }}</div>
                    </td>
                    <td class="ant-table-cell max-md:hidden">
                      {{ Order::DELIVERIES[$order->delivery_type] ?? $order->delivery_type }}
                      <div class="text-xs {{ $muted }}">{{ $order->isPickup() ? $order->pickupPoint?->name : ($provinces[$order->province] ?? $order->province) }}</div>
                    </td>
                    <td class="ant-table-cell tabular-nums font-medium" style="text-align: right">{{ Money::format($order->total_amount) }} đ</td>
                    <td class="ant-table-cell"><span class="ant-tag ant-tag-{{ Order::STATUS_COLORS[$status] }} m-0">{{ $order->statusLabel() }}</span></td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-sm:hidden">{{ $order->created_at?->format('d/m/Y H:i') }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('container', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($hasFilter || $filter['tab'] !== '')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy đơn hàng nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.orders.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có đơn hàng nào</strong>Đơn được tạo khi thành viên đặt hàng trên website.</p>
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

    @if ($orders->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($orders->firstItem()) }}–{{ $count($orders->lastItem()) }} / {{ $count($orders->total()) }} đơn</span>
        {{ $orders->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
