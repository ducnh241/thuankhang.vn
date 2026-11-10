@extends('layouts.backend')

@section('title', 'Sản phẩm')

{{-- Danh sách sản phẩm (A6), dựng như trang Trang: tab trạng thái (số theo bộ lọc), ô tìm + nút lọc danh mục (list.js),
     bảng, phân trang. Bấm dòng mở trang sửa; xóa hỏi lại (delete.js); chọn nhiều dòng để xóa hàng loạt (bulk.js) —
     đều là xóa mềm. --}}
@php
    use App\Http\Controllers\Backend\ProductController;
    use App\Support\Money;
    use App\Support\Percent;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('product/add');
    $canEdit = $me->can('product/edit');
    $canDelete = $me->can('product/delete');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.products.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $statusTag = fn ($product) => $product->status
        ? '<span class="ant-tag ant-tag-green m-0">Đang bán</span>'
        : '<span class="ant-tag m-0">Đang ẩn</span>';
    $category = $filter['category_id'] !== '' ? $categories->firstWhere('id', (int) $filter['category_id']) : null;
@endphp

@section('actions')
  @can('product')
    <a href="{{ route('backend.categories.index', ['type' => 'product']) }}" class="ant-btn">{{ ant_icon('folder') }}<span class="max-sm:hidden">Danh mục</span></a>
  @endcan
  @if ($canAdd)
    <a href="{{ route('backend.products.create') }}" class="ant-btn ant-btn-primary" aria-label="Thêm sản phẩm">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm sản phẩm</span></a>
  @endif
@endsection

@section('content')
<div id="products" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (ProductController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.products.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="product-search" placeholder="Tìm theo tên, mã sản phẩm" aria-label="Tìm sản phẩm" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($category)
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="category">Danh mục: {{ $category->name }}</button><a href="{{ $url(['category_id' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc danh mục">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="category">Danh mục{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['q'] !== '' || $category)
          <a href="{{ route('backend.products.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-category">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @forelse ($categories as $item)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['category_id'] === (string) $item->id]) role="menuitem"><a href="{{ $url(['category_id' => $item->id]) }}">{{ $item->indentedName() }}</a></li>
            @empty
              <li class="ant-dropdown-menu-item ant-dropdown-menu-item-disabled" role="menuitem">Chưa có danh mục</li>
            @endforelse
          </ul>
        </div>
      </template>
    </div>

    {{-- Chọn nhiều dòng để xóa hàng loạt (bulk.js): thanh "N đã chọn" phủ lên tiêu đề bảng --}}
    <form method="POST" action="{{ route('backend.products.bulk-destroy') }}" id="product-bulk-form" @if ($canDelete) data-bulk-form @endif>
    @csrf
    <div class="relative">
    @if ($canDelete)
      <div class="product-bulk hidden" data-bulk-bar>
        <label class="ant-checkbox-wrapper"><span class="ant-checkbox ant-checkbox-indeterminate"><input type="checkbox" class="ant-checkbox-input" data-bulk-clear aria-label="Bỏ chọn tất cả" /><span class="ant-checkbox-inner"></span></span></label>
        <span class="whitespace-nowrap font-medium"><span data-bulk-count>0</span> đã chọn</span>
        <div class="ml-auto flex gap-2">
          <button type="button" class="ant-btn ant-btn-sm ant-btn-dangerous" data-bulk-delete data-title="Xóa %d sản phẩm đã chọn?" data-content="Sản phẩm ẩn khỏi cửa hàng và danh sách; đơn hàng cũ vẫn giữ thông tin.">{{ ant_icon('delete') }}<span>Xóa</span></button>
        </div>
      </div>
    @endif
    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $products->isEmpty()]) style="table-layout: auto">
              @if ($canDelete)<colgroup><col class="ant-table-selection-col" /></colgroup>@endif
              <thead class="ant-table-thead">
                <tr>
                  @if ($canDelete)
                    <th class="ant-table-cell ant-table-selection-column">
                      <div class="ant-table-selection">
                        <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" data-select-page aria-label="Chọn cả trang" @disabled($products->isEmpty()) /><span class="ant-checkbox-inner"></span></span></label>
                      </div>
                    </th>
                  @endif
                  <th class="ant-table-cell">Sản phẩm</th>
                  <th class="ant-table-cell max-md:hidden" data-col="category">Danh mục</th>
                  <th class="ant-table-cell whitespace-nowrap" data-col="price" style="text-align: right">Giá (gồm VAT)</th>
                  <th class="ant-table-cell max-md:hidden" data-col="vat" style="text-align: right">VAT</th>
                  <th class="ant-table-cell max-md:hidden" data-col="stock" style="text-align: right">Tồn kho</th>
                  <th class="ant-table-cell" data-col="status">Trạng thái</th>
                  <th class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="product-rows">
                @forelse ($products as $product)
                  @php $href = $canEdit ? route('backend.products.edit', $product) : null; @endphp
                  <tr data-row-key="{{ $product->id }}" @class(['ant-table-row', 'product-row' => $href]) @if ($href) data-href="{{ $href }}" @endif>
                    @if ($canDelete)
                      <td class="ant-table-cell ant-table-selection-column">
                        <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="ids[]" value="{{ $product->id }}" data-row-check aria-label="Chọn {{ $product->name }}" /><span class="ant-checkbox-inner"></span></span></label>
                      </td>
                    @endif
                    <td class="ant-table-cell">
                      <div class="flex items-center gap-3">
                        <span class="product-thumb">@if ($product->cover)<img src="{{ $product->cover->url() }}" alt="" loading="lazy" />@else{{ ant_icon('picture') }}@endif</span>
                        <div class="min-w-0 max-w-[420px] whitespace-normal">
                          @if ($href)
                            <a href="{{ $href }}" class="product-name wrap-anywhere">{{ $product->name }}</a>
                          @else
                            <span class="product-name wrap-anywhere">{{ $product->name }}</span>
                          @endif
                          @if ($product->is_featured)<span class="ant-tag ant-tag-gold ml-1">Nổi bật</span>@endif
                          <div class="text-xs {{ $muted }} wrap-anywhere">{{ $product->sku ?: $product->url() }}</div>
                          <div class="product-row-meta">{!! $statusTag($product) !!} <span class="tabular-nums">{{ Money::format($product->price) }} đ</span></div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell max-md:hidden" data-col="category">{{ $product->category?->name ?? '—' }}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" data-col="price" style="text-align: right">{{ Money::format($product->price) }} đ</td>
                    <td class="ant-table-cell tabular-nums max-md:hidden" data-col="vat" style="text-align: right">{{ Percent::format($product->vat_rate) }}%</td>
                    <td class="ant-table-cell tabular-nums max-md:hidden" data-col="stock" style="text-align: right">
                      @if (! $product->tracksStock())
                        <span class="text-[rgba(0,0,0,0.45)]">—</span>
                      @elseif ($product->isOutOfStock())
                        <span class="ant-tag ant-tag-red m-0">Hết hàng</span>
                      @else
                        {{ Money::format($product->stock) }}
                      @endif
                    </td>
                    <td class="ant-table-cell" data-col="status">{!! $statusTag($product) !!}</td>
                    <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                      <div class="product-row-actions">
                        {{-- Xem trước: link thật của sản phẩm (tab mới); sản phẩm đang ẩn vẫn mở được vì người xem đang đăng nhập quản trị --}}
                        <a href="{{ $product->url() }}" target="_blank" rel="noopener" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Xem trước" aria-label="Xem trước {{ $product->name }}">{{ ant_icon('eye') }}</a>
                        @if ($href)
                          <a href="{{ $href }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $product->name }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $product->name }}"
                            data-delete-url="{{ route('backend.products.destroy', $product) }}" data-delete-title="Xóa sản phẩm “{{ $product->name }}”?" data-delete-content="Sản phẩm ẩn khỏi cửa hàng và danh sách; đơn hàng cũ vẫn giữ thông tin.">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="{{ $canDelete ? 8 : 7 }}" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('shop', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if (array_filter($filter))
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy sản phẩm nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.products.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có sản phẩm nào</strong>Thêm sản phẩm để bán trên cửa hàng; giá nhập đã gồm VAT.</p>
                          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.products.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm sản phẩm</span></a></div>@endif
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

    </div>
    </form>

    @if ($products->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($products->firstItem()) }}–{{ $count($products->lastItem()) }} / {{ $count($products->total()) }} sản phẩm</span>
        {{ $products->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
