@extends('layouts.backend')

{{-- Danh mục mọi loại trên một trang (theo categories.html của template): ô chọn loại (dự án / tin tức) + cây danh mục
     bên trái (bấm để sửa, kéo tay nắm để sắp xếp / lồng cấp, Alt+↑/↓ đổi chỗ), form danh mục đang chọn bên phải; thanh
     trên cùng có tên + trạng thái đã lưu, dải "Chưa lưu thay đổi", nút Xóa / Thêm. URL: ?type=…&id=… (&new: thêm mới).
     Template có thẻ SEO riêng cho danh mục — bảng categories chưa có cột nên chưa làm.
     JS: tree-page.js (cây, kéo thả, đổi loại / danh mục khi chưa lưu — dùng chung với trang Menu), edit-form.js (thay đổi chưa lưu). --}}
@php
    $me = auth()->user();
    $editing = $category->exists;
    $canAdd = $me->can('category/add', $kind);
    $canEdit = $me->can('category/edit', $kind);
    $canDelete = $me->can('category/delete', $kind);
    $canWrite = $editing ? $canEdit : $canAdd;
    $usage = $config['usage'];
    $canSeeUsage = $usage && $me->can($usage['can']);
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    // Lưu lỗi: ô theo dữ liệu vừa gửi (ô xóa trắng thành null qua ConvertEmptyStringsToNull — vẫn phải hiện trống,
    // không hiện lại giá trị đã lưu); mảng (gửi sai kiểu) → trống
    $old = fn (string $field, $default) => session()->hasOldInput() ? (is_string(old($field)) ? old($field) : '') : $default;
    $status = session()->hasOldInput() ? (string) old('status') !== '0' : $category->status !== false;
    $list = $categories->values();
    $url = fn (array $query) => route('backend.categories.index', ['type' => $kind, ...$query]);
    $title = $editing ? $category->name : 'Danh mục mới';
@endphp

@section('title', $title.' - '.$config['title'])
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.categories.index', ['type' => $kind]) }}" class="page-topbar-crumb" data-tree-nav>Danh mục</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $title }}</h1>
    @if ($editing)
      <span @class(['ant-tag', 'ant-tag-green' => $category->status])>{{ $category->status ? 'Hiển thị' : 'Ẩn' }}</span>
    @endif
  </div>
  @if ($canWrite)
    {{-- Hiện khi form có thay đổi chưa lưu --}}
    <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
      <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
      <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
      <button type="submit" form="category-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo danh mục' }}</button>
    </div>
  @endif
  <div class="page-topbar-right">
    @if ($editing && $canDelete)
      @if ($blocked)
        <span data-tooltip="Không xóa được: {{ $blocked }}"><button type="button" class="ant-btn ant-btn-dangerous" disabled aria-label="Xóa (không xóa được)">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
      @else
        <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa danh mục"
          data-delete-url="{{ route('backend.categories.destroy', [$kind, $category]) }}" data-delete-title="Xóa danh mục “{{ $category->name }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
      @endif
    @endif
    @if ($canAdd && $editing)
      <a href="{{ $url(['new' => 1]) }}" class="ant-btn ant-btn-primary" id="create-category" data-tree-nav aria-label="Thêm danh mục">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm</span></a>
    @endif
  </div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" id="categories"
  data-tree-page="categories:{{ $kind }}">
  {{-- Cây danh mục: bấm để sửa, kéo tay nắm để sắp xếp hoặc đưa vào trong danh mục khác --}}
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tất cả danh mục</div></div></div>
    <div class="ant-card-body">
      <div class="cat-type">
        @if ($kinds->count() > 1)
          <label for="category_type" class="cat-type-label">Loại danh mục</label>
          <select data-antd id="category_type" class="w-full" aria-describedby="category-type-help" data-tree-switch>
            @foreach ($kinds as $key => $k)
              <option value="{{ $key }}" data-url="{{ route('backend.categories.index', ['type' => $key]) }}" @selected($key === $kind)>{{ $k['title'] }}</option>
            @endforeach
          </select>
        @else
          <div class="cat-type-label font-medium">{{ $config['title'] }}</div>
        @endif
        <p class="cat-type-help" id="category-type-help">Đang quản lý danh mục dùng cho <strong>{{ $config['used_by'] }}</strong>.@if ($kinds->count() > 1) Muốn quản lý loại khác, chọn ở đây.@endif</p>
      </div>

      <ul class="cat-tree" id="category-tree" data-tree role="tree" aria-label="{{ $config['title'] }}" @if ($canEdit) data-move-url="{{ route('backend.categories.move', [$kind, '__ID__']) }}" @endif>
        @forelse ($list as $i => $item)
          @php
              $hasKids = ($list[$i + 1]->depth ?? -1) > $item->depth;
              $selected = $editing && $item->id === $category->id;
              $count = $usageCounts[$item->id] ?? 0;
          @endphp
          <li class="cat-item" data-id="{{ $item->id }}" data-depth="{{ $item->depth }}" data-parent="{{ $item->parent_id }}" role="treeitem" aria-level="{{ $item->depth + 1 }}"
            aria-selected="{{ $selected ? 'true' : 'false' }}" @if ($hasKids) aria-expanded="true" @endif>
            <div @class(['cat-row', 'is-selected' => $selected]) style="margin-left: {{ $item->depth * 24 }}px" @if ($canEdit) draggable="true" @endif>
              @if ($canEdit)<span class="cat-handle" data-tooltip="Kéo để chuyển" aria-hidden="true">{{ ant_icon('holder') }}</span>@endif
              <a href="{{ $url(['id' => $item->id]) }}" class="cat-label" title="{{ $item->name }}" data-tree-nav @if ($selected) aria-current="page" @endif>
                {{ ant_icon($hasKids ? 'folder' : 'file', 'cat-icon') }}
                <span class="cat-name">{{ $item->name }}</span>
                @if ($usage)<span @class(['cat-count', $muted => ! $count]) title="{{ $count }} {{ $usage['unit'] }}">({{ $count }})</span>@endif
                @unless ($item->status)<span class="ant-tag cat-tag">Ẩn</span>@endunless
              </a>
              @if ($hasKids)
                <button type="button" class="cat-toggle" aria-label="Thu gọn {{ $item->name }}" data-label-open="Thu gọn {{ $item->name }}" data-label-closed="Mở {{ $item->name }}">{{ ant_icon('minus') }}</button>
              @endif
            </div>
          </li>
        @empty
          <li class="ant-empty ant-empty-normal"><p class="ant-empty-description">Chưa có danh mục nào</p></li>
        @endforelse
      </ul>
      @if ($canEdit && $list->isNotEmpty())
        <p class="cat-hint">Kéo {{ ant_icon('holder') }} để sắp xếp; thả vào giữa một danh mục để đưa vào trong danh mục đó.</p>
      @endif
    </div>
  </div>

  {{-- Form danh mục đang chọn / danh mục mới --}}
  @if ($editing || $canAdd)
    <form method="POST" id="category-form" action="{{ $editing ? route('backend.categories.update', [$kind, $category]) : route('backend.categories.store', $kind) }}"
      class="ant-form ant-form-vertical edit-form flex min-w-0 flex-col gap-4" novalidate autocomplete="off" data-tree-form
      @if ($canWrite) data-edit-form @endif @if ($errors->any()) data-dirty @endif>
      @csrf
      @if ($editing) @method('PUT') @endif
      @unless ($canWrite)
        <div class="ant-alert ant-alert-info" role="alert">
          <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
          <div class="ant-alert-content"><div class="ant-alert-message">Bạn chỉ có quyền xem danh mục.</div></div>
        </div>
      @endunless
      <div class="ant-card ant-card-bordered" @unless ($canWrite) inert @endunless>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên danh mục.">
            <div class="ant-col ant-form-item-label"><label for="category_name" class="ant-form-item-required" title="Tên danh mục">Tên danh mục</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="category_name" name="name" value="{{ $old('name', $category->name) }}" class="ant-input" maxlength="255" placeholder="{{ $config['example'] }}" data-slug-source @unless ($editing) autofocus @endunless />
              </div></div>
              {!! $error('name') !!}
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('slug') }}">
            <div class="ant-col ant-form-item-label"><label for="category_slug" title="Đường dẫn (slug)">Đường dẫn (slug)</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="category_slug" name="slug" value="{{ $old('slug', $category->slug) }}" class="ant-input font-mono" maxlength="255" spellcheck="false" data-slug-target @if ($old('slug', $category->slug) !== '') data-touched @endif />
              </div></div>
              {!! $error('slug') !!}
              <div class="ant-form-item-extra">Chữ không dấu, số và dấu gạch ngang; để trống sẽ tạo từ tên. Không trùng với danh mục cùng loại.</div>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('parent_id') }}">
              <div class="ant-col ant-form-item-label"><label for="category_parent" title="Danh mục cha">Danh mục cha</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  @php $parentId = (string) $old('parent_id', (string) $category->parent_id); @endphp
                  {{-- Không chọn = cấp gốc (chữ gợi ý); ⓧ khi rê chuột để bỏ chọn cha --}}
                  <select data-antd data-show-search data-allow-clear data-placeholder="Không có (cấp gốc)" id="category_parent" name="parent_id" class="w-full">
                    <option value=""></option>
                    @foreach ($parents as $parent)
                      <option value="{{ $parent->id }}" @selected($parentId === (string) $parent->id)>{{ $parent->indentedName() }}</option>
                    @endforeach
                  </select>
                </div></div>
                {!! $error('parent_id') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('status') }}">
              <div class="ant-col ant-form-item-label"><label for="category_status" title="Trạng thái">Trạng thái</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <select data-antd id="category_status" name="status" class="w-full">
                    <option value="1" @selected($status)>Hiển thị</option>
                    <option value="0" @selected(! $status)>Ẩn</option>
                  </select>
                </div></div>
                {!! $error('status') !!}
              </div>
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('description') }}">
            <div class="ant-col ant-form-item-label"><label for="category_description" title="Mô tả">Mô tả</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="category_description" name="description" class="ant-input" rows="4" maxlength="2000" placeholder="Mô tả ngắn">{{ $old('description', $category->description) }}</textarea>
              </div></div>
              {!! $error('description') !!}
            </div>
          </div>
        </div>
        @if ($editing && $usage)
          @php $used = $usageCounts[$category->id] ?? 0; @endphp
          <div class="cat-usage">
            @if ($used && $canSeeUsage)
              <a href="{{ route($usage['route'], [$usage['column'] => $category->id]) }}">{{ $used }} {{ $usage['unit'] }}</a> thuộc danh mục này
            @else
              {{ $used ? $used.' '.$usage['unit'].' thuộc danh mục này' : 'Chưa có '.$usage['unit'].' nào thuộc danh mục này' }}
            @endif
          </div>
        @endif
      </div>
    </form>
  @else
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty ant-empty-normal">
          <div class="ant-empty-image">{{ ant_icon('folder', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
          <p class="ant-empty-description">Chưa có danh mục nào.</p>
        </div>
      </div>
    </div>
  @endif
</div>
@endsection
