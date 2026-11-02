@extends('layouts.backend')

@section('title', 'Tag dự án')

{{-- Template ProCMS không có trang tag: dựng như trang Thanh toán (orders.html) nhưng gọn hơn — tag ít nên không có tìm /
     lọc / sắp xếp: một bảng theo tên A–Z, chọn nhiều dòng để xóa hàng loạt (tag đang gắn với dự án không xóa / chọn được),
     thêm / sửa trong hộp thoại Ant Design (Modal) ngay trên trang. JS: list.js (bấm dòng mở hộp sửa), bulk.js (chọn nhiều), tag-modal.js (hộp thêm / sửa), delete.js. --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canAdd = $me->can('tag/add');
    $canEdit = $me->can('tag/edit');
    $canDelete = $me->can('tag/delete');
    $canSeeProjects = $me->can('project');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $dot = fn ($color) => preg_match('/^#[0-9a-f]{6}$/i', (string) $color) ? $color : '#6b7280';
    $colspan = 4 + ($canDelete ? 1 : 0);
@endphp

@section('actions')
  @if ($canAdd)
    <button type="button" class="ant-btn ant-btn-primary" data-tag-open aria-label="Thêm tag">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm tag</span></button>
  @endif
@endsection

@section('content')
<div id="tags" class="mx-auto max-w-[880px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <form method="POST" action="{{ route('backend.tags.bulk-destroy') }}" id="tag-bulk-form" @if ($canDelete) data-bulk-form @endif>
      @csrf
      <div class="relative">
        @if ($canDelete)
          {{-- Thanh thao tác hàng loạt: phủ lên tiêu đề bảng khi có dòng được chọn --}}
          <div class="product-bulk hidden" data-bulk-bar>
            <label class="ant-checkbox-wrapper"><span class="ant-checkbox ant-checkbox-indeterminate"><input type="checkbox" class="ant-checkbox-input" data-bulk-clear aria-label="Bỏ chọn tất cả" /><span class="ant-checkbox-inner"></span></span></label>
            <span class="whitespace-nowrap font-medium"><span data-bulk-count>0</span> đã chọn</span>
            <div class="ml-auto flex gap-2">
              <button type="button" class="ant-btn ant-btn-sm ant-btn-dangerous" data-bulk-delete data-title="Xóa %d tag đã chọn?" data-content="Không thể hoàn tác.">{{ ant_icon('delete') }}<span>Xóa</span></button>
            </div>
          </div>
        @endif

        <div class="ant-table-wrapper">
          <div class="ant-table ant-table-small">
            <div class="ant-table-container">
              <div class="ant-table-content" style="overflow: auto hidden">
                <table class="product-table tag-table" style="table-layout: auto">
                  @if ($canDelete)<colgroup><col class="ant-table-selection-col" /></colgroup>@endif
                  <thead class="ant-table-thead">
                    <tr>
                      @if ($canDelete)
                        <th class="ant-table-cell ant-table-selection-column">
                          <div class="ant-table-selection">
                            <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" data-select-page aria-label="Chọn cả trang" @disabled($tags->every(fn ($tag) => $tag->projects_count > 0)) /><span class="ant-checkbox-inner"></span></span></label>
                          </div>
                        </th>
                      @endif
                      {{-- Cột tên giãn theo chỗ trống, các cột còn lại cố định bề rộng cho bảng cân --}}
                      <th class="ant-table-cell">Tag</th>
                      <th class="ant-table-cell" data-col="slug" style="width: 34%">Slug</th>
                      <th class="ant-table-cell whitespace-nowrap" style="width: 96px; text-align: right">Dự án</th>
                      {{-- Điện thoại: không cột thao tác — chạm dòng để sửa, chọn dòng + "Xóa" ở thanh trên để xóa --}}
                      <th class="ant-table-cell product-actions-col max-sm:hidden" style="width: 104px; text-align: right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody class="ant-table-tbody" id="tag-rows">
                    @forelse ($tags as $tag)
                      @php
                          $data = ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'color' => $dot($tag->color)];
                          $used = $tag->projects_count;
                          $blocked = 'Đang gắn với '.$count($used).' dự án, không xóa được (gỡ tag khỏi các dự án trước)';
                      @endphp
                      <tr data-row-key="{{ $tag->id }}" @class(['ant-table-row', 'product-row' => $canEdit]) @if ($canEdit) data-row-click="[data-tag-open]" @endif>
                        @if ($canDelete)
                          <td class="ant-table-cell ant-table-selection-column">
                            {{-- Tag đang gắn với dự án không xóa được → không cho chọn để xóa hàng loạt --}}
                            @if ($used)
                              <label class="ant-checkbox-wrapper ant-checkbox-wrapper-disabled" data-tooltip="{{ $blocked }}"><span class="ant-checkbox ant-checkbox-disabled"><input type="checkbox" class="ant-checkbox-input" value="{{ $tag->id }}" data-row-check disabled aria-label="Chọn {{ $tag->name }} (không xóa được)" /><span class="ant-checkbox-inner"></span></span></label>
                            @else
                              <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="ids[]" value="{{ $tag->id }}" data-row-check aria-label="Chọn {{ $tag->name }}" /><span class="ant-checkbox-inner"></span></span></label>
                            @endif
                          </td>
                        @endif
                        <td class="ant-table-cell">
                          <span class="ant-tag project-tag m-0" title="{{ $tag->name }}"><span class="project-tag-dot" style="background: {{ $dot($tag->color) }}"></span><span class="truncate">{{ $tag->name }}</span></span>
                          <div class="product-row-meta font-mono text-xs {{ $muted }} whitespace-normal wrap-anywhere">{{ $tag->slug }}</div>
                        </td>
                        <td class="ant-table-cell font-mono text-xs {{ $muted }}" data-col="slug"><div class="max-w-[280px] whitespace-normal wrap-anywhere">{{ $tag->slug }}</div></td>
                        <td class="ant-table-cell tabular-nums" style="text-align: right">
                          @if ($used && $canSeeProjects)
                            <a href="{{ route('backend.projects.index', ['tag_id' => $tag->id]) }}" data-tooltip="Xem các dự án có tag này">{{ $count($used) }}</a>
                          @else
                            <span @class([$muted => ! $used])>{{ $count($used) }}</span>
                          @endif
                        </td>
                        <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                          <div class="product-row-actions">
                            @if ($canEdit)
                              <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa tag {{ $tag->name }}"
                                data-tag-open data-tag="{{ json_encode($data) }}">{{ ant_icon('edit') }}</button>
                            @endif
                            @if ($canDelete && $used)
                              <span data-tooltip="{{ $blocked }}"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled aria-label="Xóa tag {{ $tag->name }} (không xóa được)">{{ ant_icon('delete') }}</button></span>
                            @elseif ($canDelete)
                              <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa tag {{ $tag->name }}"
                                data-delete-url="{{ route('backend.tags.destroy', $tag) }}" data-delete-title="Xóa tag “{{ $tag->name }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}</button>
                            @endif
                          </div>
                        </td>
                      </tr>
                    @empty
                      <tr class="ant-table-placeholder">
                        <td colspan="{{ $colspan }}" class="ant-table-cell">
                          <div class="ant-empty ant-empty-normal">
                            <div class="ant-empty-image">{{ ant_icon('tags', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                            <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có tag nào</strong>Tag là nhãn gắn vào dự án để lọc nhanh (người giới thiệu, nền tảng, dịch vụ…).</p>
                            @if ($canAdd)<div class="ant-empty-footer"><button type="button" class="ant-btn ant-btn-primary" data-tag-open>{{ ant_icon('plus') }}<span>Thêm tag</span></button></div>@endif
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

    @if ($tags->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($tags->firstItem()) }}–{{ $count($tags->lastItem()) }} / {{ $count($tags->total()) }} tag</span>
        {{ $tags->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>

@if ($canAdd || $canEdit)
  @include('backend.tags.dialog')
@endif
@endsection
