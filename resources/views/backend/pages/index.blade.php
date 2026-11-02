@extends('layouts.backend')

@section('title', 'Trang')

{{-- Theo template ProCMS news.html: tab trạng thái (số trang theo từ khóa đang tìm), ô tìm (Enter để tìm), bảng, phân
     trang. Bấm dòng mở trang sửa (list.js); xóa hỏi lại (delete.js) — trang đang dùng trong menu thì không xóa được. --}}
@php
    use App\Http\Controllers\Backend\PageController;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('page/add');
    $canEdit = $me->can('page/edit');
    $canDelete = $me->can('page/delete');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.pages.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $statusTag = fn ($page) => $page->status
        ? '<span class="ant-tag ant-tag-green m-0">Đang hiện</span>'
        : '<span class="ant-tag m-0">Đang ẩn</span>';
@endphp

@section('actions')
  @if ($canAdd)
    <a href="{{ route('backend.pages.create') }}" class="ant-btn ant-btn-primary" aria-label="Thêm trang">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm trang</span></a>
  @endif
@endsection

@section('content')
<div id="pages" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (PageController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.pages.index') }}" role="search">
        @if ($filter['tab'] !== '')<input type="hidden" name="tab" value="{{ $filter['tab'] }}" />@endif
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="page-search" placeholder="Tìm theo tiêu đề, đường dẫn" aria-label="Tìm trang" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table page-table', 'min-w-0' => $pages->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Trang</th>
                  <th class="ant-table-cell" data-col="status">Trạng thái</th>
                  <th class="ant-table-cell max-md:hidden" data-col="menu">Menu</th>
                  <th class="ant-table-cell" data-col="updated">Cập nhật</th>
                  {{-- Điện thoại: không cột thao tác — chạm dòng để mở trang sửa --}}
                  <th class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="page-rows">
                @forelse ($pages as $page)
                  @php $href = $canEdit ? route('backend.pages.edit', $page) : null; @endphp
                  <tr data-row-key="{{ $page->id }}" @class(['ant-table-row', 'product-row' => $href]) @if ($href) data-href="{{ $href }}" @endif>
                    <td class="ant-table-cell">
                      <div class="flex items-center gap-3">
                        <span class="product-thumb page-thumb">@if ($page->imageUrl())<img src="{{ $page->imageUrl() }}" alt="" loading="lazy" />@else{{ ant_icon('file-text') }}@endif</span>
                        <div class="min-w-0 max-w-[420px] whitespace-normal">
                          @if ($href)
                            <a href="{{ $href }}" class="product-name wrap-anywhere">{{ $page->title }}</a>
                          @else
                            <span class="product-name wrap-anywhere">{{ $page->title }}</span>
                          @endif
                          <div class="text-xs {{ $muted }} wrap-anywhere">{{ $page->url() }}</div>
                          {{-- Điện thoại: trạng thái + ngày cập nhật ghi dưới tên --}}
                          <div class="product-row-meta">{!! $statusTag($page) !!} <span class="tabular-nums {{ $muted }}">{{ $page->updated_at?->format('d/m/Y') }}</span></div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell" data-col="status">{!! $statusTag($page) !!}</td>
                    <td class="ant-table-cell max-md:hidden" data-col="menu">
                      @if ($page->menu_items_count)
                        <span title="Số mục menu trỏ tới trang này">{{ $count($page->menu_items_count) }} mục</span>
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" data-col="updated" title="Tạo lúc {{ $page->created_at?->format('d/m/Y H:i') }}">{{ $page->updated_at?->format('d/m/Y H:i') }}</td>
                    <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                      <div class="product-row-actions">
                        @if ($href)
                          <a href="{{ $href }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $page->title }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete && $page->menu_items_count)
                          <span data-tooltip="Đang dùng trong {{ $count($page->menu_items_count) }} mục menu, không xóa được (đổi liên kết mục menu trước)"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled aria-label="Xóa {{ $page->title }} (không xóa được)">{{ ant_icon('delete') }}</button></span>
                        @elseif ($canDelete)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $page->title }}"
                            data-delete-url="{{ route('backend.pages.destroy', $page) }}" data-delete-title="Xóa trang “{{ $page->title }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="5" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('file-text', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($filter['q'] !== '' || $filter['tab'] !== '')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy trang nào</strong>Thử từ khóa khác hoặc xem tab khác.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.pages.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có trang nào</strong>Trang nội dung của website: Giới thiệu, Liên hệ, Chính sách… — gắn vào menu ở trang Menu.</p>
                          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.pages.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm trang</span></a></div>@endif
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

    @if ($pages->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($pages->firstItem()) }}–{{ $count($pages->lastItem()) }} / {{ $count($pages->total()) }} trang</span>
        {{ $pages->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
