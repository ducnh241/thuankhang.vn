@extends('layouts.backend')

@section('title', 'Đánh giá sản phẩm')

{{-- Đánh giá sản phẩm, dựng như danh sách Thành viên: tab trạng thái (số theo bộ lọc), ô tìm (sản phẩm / thành viên / SĐT /
     nội dung) + nút lọc số sao (list.js), bảng; nút ẩn / hiện từng dòng (review/moderate). --}}
@php
    use App\Http\Controllers\Backend\ProductReviewController;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $canModerate = auth()->user()->can('review/moderate');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.reviews.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $stars = fn (int $n) => str_repeat('★', $n).str_repeat('☆', 5 - $n);
@endphp

@section('content')
<div id="reviews" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (ProductReviewController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.reviews.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="review-search" placeholder="Tìm theo sản phẩm, thành viên, số điện thoại, nội dung" aria-label="Tìm đánh giá" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($filter['rating'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="rating">Số sao: {{ $filter['rating'] }}★</button><a href="{{ $url(['rating' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc số sao">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="rating">Số sao{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['q'] !== '' || $filter['rating'] !== '')
          <a href="{{ route('backend.reviews.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-rating">
        <div class="ant-dropdown"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
          @foreach ([5, 4, 3, 2, 1] as $star)
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['rating'] === (string) $star]) role="menuitem"><a href="{{ $url(['rating' => $star]) }}"><span class="text-[#faad14]">{{ $stars($star) }}</span></a></li>
          @endforeach
        </ul></div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $reviews->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Đánh giá</th>
                  <th class="ant-table-cell max-md:hidden">Sản phẩm</th>
                  <th class="ant-table-cell max-md:hidden">Thành viên</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  @if ($canModerate)<th class="ant-table-cell" style="text-align: right">Thao tác</th>@endif
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="review-rows">
                @forelse ($reviews as $review)
                  <tr data-row-key="{{ $review->id }}" class="ant-table-row">
                    <td class="ant-table-cell" style="max-width: 420px">
                      <div class="text-[#faad14]" aria-label="{{ $review->rating }} sao">{{ $stars($review->rating) }}</div>
                      @if ($review->comment)
                        <div class="mt-0.5 line-clamp-3 whitespace-pre-line">{{ $review->comment }}</div>
                      @else
                        <div class="{{ $muted }}">Không có nhận xét</div>
                      @endif
                      <div class="text-xs tabular-nums {{ $muted }}">{{ $review->created_at?->format('d/m/Y H:i') }}{{ $review->updated_at?->gt($review->created_at) ? ' · đã sửa' : '' }}</div>
                    </td>
                    <td class="ant-table-cell max-md:hidden">
                      @if ($review->product && ! $review->product->trashed())
                        <a href="{{ $review->product->url() }}#danh-gia" target="_blank" rel="noopener">{{ $review->product->name }}</a>
                      @else
                        {{ $review->product?->name ?? '—' }} <span class="ant-tag m-0">Đã xóa</span>
                      @endif
                    </td>
                    <td class="ant-table-cell max-md:hidden">
                      @if ($review->member)
                        @can('member')
                          <a href="{{ route('backend.members.show', $review->member) }}">{{ $review->member->full_name }}</a>
                        @else
                          {{ $review->member->full_name }}
                        @endcan
                        <div class="text-xs tabular-nums {{ $muted }}">{{ $review->member->phone }}</div>
                      @endif
                    </td>
                    <td class="ant-table-cell">{!! $review->is_visible ? '<span class="ant-tag ant-tag-green m-0">Đang hiện</span>' : '<span class="ant-tag ant-tag-red m-0">Đã ẩn</span>' !!}</td>
                    @if ($canModerate)
                      <td class="ant-table-cell" style="text-align: right">
                        <form method="POST" action="{{ route('backend.reviews.visibility', $review) }}">
                          @csrf
                          <input type="hidden" name="action" value="{{ $review->is_visible ? 'hide' : 'show' }}" />
                          @if ($review->is_visible)
                            <button type="submit" class="ant-btn ant-btn-sm ant-btn-dangerous">{{ ant_icon('eye-invisible') }}<span>Ẩn</span></button>
                          @else
                            <button type="submit" class="ant-btn ant-btn-sm">{{ ant_icon('eye') }}<span>Hiện lại</span></button>
                          @endif
                        </form>
                      </td>
                    @endif
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="{{ $canModerate ? 5 : 4 }}" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('star', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if (array_filter($filter))
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy đánh giá nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.reviews.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có đánh giá nào</strong>Thành viên đã mua sản phẩm đánh giá ở trang sản phẩm trên website.</p>
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

    @if ($reviews->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($reviews->firstItem()) }}–{{ $count($reviews->lastItem()) }} / {{ $count($reviews->total()) }} đánh giá</span>
        {{ $reviews->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
