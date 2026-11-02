@extends('layouts.backend')

{{-- Menu website (giao diện như trang Danh mục — categories.html của template): ô chọn vị trí menu (cố định trong code,
     Menu::POSITIONS) + cây mục bên trái (bấm để sửa, kéo tay nắm để sắp xếp / lồng cấp, Alt+↑/↓ đổi chỗ), form mục
     đang chọn bên phải: tiêu đề, mục cha, liên kết (một ô nhiều cấp: chọn loại → chọn danh mục / trang ngay trong ô,
     hoặc tự nhập đường dẫn), hiển thị. URL: ?position=…&id=… (&new: thêm mới, &parent=…: thêm mục con).
     JS: tree-page.js (cây, kéo thả, đổi vị trí / mục khi chưa lưu), link-picker.js (ô Liên kết), edit-form.js. --}}
@php
    use App\Models\Menu;
    use App\Models\MenuItem;

    $me = auth()->user();
    $editing = $item->exists;
    $canAdd = $me->can('menu/add');
    $canEdit = $me->can('menu/edit');
    $canDelete = $me->can('menu/delete');
    $canWrite = $editing ? $canEdit : $canAdd;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    // Lưu lỗi: ô theo dữ liệu vừa gửi (ô xóa trắng thành null qua ConvertEmptyStringsToNull — vẫn phải hiện trống)
    $old = fn (string $field, $default) => session()->hasOldInput() ? (is_string(old($field)) ? old($field) : '') : $default;
    $visible = session()->hasOldInput() ? (string) old('is_visible') === '1' : $item->is_visible !== false;
    // Giá trị ô Liên kết: home (trang chủ "/") | url (tự nhập, kể cả neo #… trên trang chủ) | category:5 | page:3
    $link = match ($item->linkType()) {
        'category' => 'category:'.$item->category_id,
        'page' => 'page:'.$item->page_id,
        default => $item->url === '/' ? 'home' : 'url',
    };
    $link = $old('link', $link) ?: 'url';
    // Ô Liên kết nhiều cấp (link-picker.js): các loại link; loại có danh sách (danh mục từng loại, trang) mở tiếp trong ô
    $linkTree = [
        'items' => [
            ['value' => 'home', 'label' => 'Trang chủ', 'icon' => 'home'],
            ...$categories->map(fn ($g, $k) => ['menu' => 'category:'.$k, 'label' => $g['title'], 'icon' => 'folder'])->values(),
            ['menu' => 'page', 'label' => 'Trang', 'icon' => 'file-text'],
            ['value' => 'url', 'label' => 'Tự nhập đường dẫn…', 'icon' => 'link'],
        ],
        'menus' => [
            ...$categories->mapWithKeys(fn ($g, $k) => ['category:'.$k => [
                'title' => $g['title'], 'icon' => 'folder', 'empty' => 'Chưa có danh mục nào',
                'items' => $g['list']->map(fn ($c) => ['value' => 'category:'.$c->id, 'label' => $c->name, 'depth' => $c->depth, 'note' => $c->status ? null : 'đang ẩn'])->values(),
            ]]),
            'page' => [
                'title' => 'Trang', 'icon' => 'file-text', 'empty' => 'Chưa có trang nào',
                'items' => $pages->map(fn ($p) => ['value' => 'page:'.$p->id, 'label' => $p->title, 'note' => $p->status ? null : 'đang ẩn'])->values(),
            ],
        ],
    ];
    // Chữ hiện trong ô lúc tải trang (JS vẽ lại giống hệt): "Trang chủ", "Danh mục dự án › Bán hàng"…
    $linkShown = collect($linkTree['items'])->firstWhere('value', $link)
        ?? collect($linkTree['menus'])->map(fn ($m) => ($i = $m['items']->firstWhere('value', $link)) ? ['icon' => $m['icon'], 'label' => $m['title'].' › '.$i['label']] : null)->filter()->first();
    $positionName = Menu::POSITIONS[$position][0];
    $url = fn (array $query) => route('backend.menus.index', ['position' => $position, ...$query]);
    $title = $editing ? $item->label : 'Mục menu mới';
    $list = $items->values();
    // Dòng phụ dưới tên trong cây: link của mục
    $linkText = fn (MenuItem $i) => match ($i->linkType()) {
        'category' => $i->category ? 'Danh mục: '.$i->category->name : 'Danh mục đã xóa',
        'page' => $i->page ? 'Trang: '.$i->page->title : 'Trang đã xóa',
        default => $i->url === '/' ? 'Trang chủ' : ($i->url ?: 'Không có link'),
    };
@endphp

@section('title', $title.' - '.$positionName)
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.menus.index', ['position' => $position]) }}" class="page-topbar-crumb" data-tree-nav>Menu</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $title }}</h1>
    @if ($editing && ! $item->is_visible)
      <span class="ant-tag">Ẩn</span>
    @endif
  </div>
  @if ($canWrite)
    {{-- Hiện khi form có thay đổi chưa lưu --}}
    <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
      <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
      <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
      <button type="submit" form="menu-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Thêm mục' }}</button>
    </div>
  @endif
  <div class="page-topbar-right">
    @if ($editing && $canDelete)
      <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa mục menu"
        data-delete-url="{{ route('backend.menus.destroy', $item) }}" data-delete-title="Xóa mục “{{ $item->label }}”?"
        data-delete-content="{{ $childCount ? 'Cả '.$childCount.' mục con bên trong cũng bị xóa. ' : '' }}Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
    @endif
    @if ($canAdd && $editing)
      <a href="{{ $url(['new' => 1]) }}" class="ant-btn ant-btn-primary" id="create-item" data-tree-nav aria-label="Thêm mục">{{ ant_icon('plus') }}<span class="max-sm:hidden">Thêm</span></a>
    @endif
  </div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" id="menus"
  data-tree-page="menus:{{ $position }}">
  {{-- Cây mục: bấm để sửa, kéo tay nắm để sắp xếp hoặc đưa vào trong mục khác --}}
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Các mục menu</div></div></div>
    <div class="ant-card-body">
      <div class="cat-type">
        <label for="menu_position" class="cat-type-label">Vị trí menu</label>
        <select data-antd id="menu_position" class="w-full" aria-describedby="menu-position-help" data-tree-switch>
          @foreach ($positions as $key => $p)
            <option value="{{ $key }}" data-url="{{ route('backend.menus.index', ['position' => $key]) }}" @selected($key === $position)>{{ $p[0] }} ({{ $p['count'] }})</option>
          @endforeach
        </select>
        <p class="cat-type-help" id="menu-position-help">{{ $positionName }}: {{ Menu::POSITIONS[$position][1] }}. Menu lồng tối đa {{ Menu::MAX_DEPTH }} cấp.</p>
      </div>

      <ul class="cat-tree" id="menu-tree" data-tree role="tree" aria-label="{{ $positionName }}" @if ($canEdit) data-move-url="{{ route('backend.menus.move', '__ID__') }}" @endif>
        @forelse ($list as $i => $row)
          @php
              $hasKids = ($list[$i + 1]->depth ?? -1) > $row->depth;
              $selected = $editing && $row->id === $item->id;
          @endphp
          <li class="cat-item" data-id="{{ $row->id }}" data-depth="{{ $row->depth }}" data-parent="{{ $row->parent_id }}" role="treeitem" aria-level="{{ $row->depth + 1 }}"
            aria-selected="{{ $selected ? 'true' : 'false' }}" @if ($hasKids) aria-expanded="true" @endif>
            <div @class(['cat-row', 'is-selected' => $selected]) style="margin-left: {{ $row->depth * 24 }}px" @if ($canEdit) draggable="true" @endif>
              @if ($canEdit)<span class="cat-handle" data-tooltip="Kéo để chuyển" aria-hidden="true">{{ ant_icon('holder') }}</span>@endif
              <a href="{{ $url(['id' => $row->id]) }}" class="cat-label" title="{{ $row->label }}" data-tree-nav @if ($selected) aria-current="page" @endif>
                {{ ant_icon(match (true) { $row->linkType() === 'category' => 'folder', $row->linkType() === 'page' => 'file-text', $row->url === '/' => 'home', default => 'link' }, 'cat-icon') }}
                <span class="menu-label">
                  <span class="cat-name">{{ $row->label }}</span>
                  <span class="menu-link">{{ $linkText($row) }}</span>
                </span>
                @unless ($row->is_visible)<span class="ant-tag cat-tag">Ẩn</span>@endunless
              </a>
              @if ($hasKids)
                <button type="button" class="cat-toggle" aria-label="Thu gọn {{ $row->label }}" data-label-open="Thu gọn {{ $row->label }}" data-label-closed="Mở {{ $row->label }}">{{ ant_icon('minus') }}</button>
              @endif
            </div>
          </li>
        @empty
          <li class="ant-empty ant-empty-normal"><p class="ant-empty-description">{{ $positionName }} chưa có mục nào</p></li>
        @endforelse
      </ul>
      @if ($canEdit && $list->count() > 1)
        <p class="cat-hint">Kéo {{ ant_icon('holder') }} để sắp xếp; thả vào giữa một mục để đưa vào trong mục đó.</p>
      @endif
    </div>
  </div>

  {{-- Form mục đang chọn / mục mới --}}
  @if ($editing || $canAdd)
    <form method="POST" id="menu-form" action="{{ $editing ? route('backend.menus.update', $item) : route('backend.menus.store', $position) }}"
      class="ant-form ant-form-vertical edit-form flex min-w-0 flex-col gap-4" novalidate autocomplete="off" data-tree-form
      @if ($canWrite) data-edit-form @endif @if ($errors->any()) data-dirty @endif>
      @csrf
      @if ($editing) @method('PUT') @endif
      @unless ($canWrite)
        <div class="ant-alert ant-alert-info" role="alert">
          <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
          <div class="ant-alert-content"><div class="ant-alert-message">Bạn chỉ có quyền xem menu.</div></div>
        </div>
      @endunless
      <div class="ant-card ant-card-bordered" @unless ($canWrite) inert @endunless>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('label') }}" data-required="Nhập tiêu đề menu.">
            <div class="ant-col ant-form-item-label"><label for="menu_label" class="ant-form-item-required" title="Tiêu đề menu">Tiêu đề menu</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="menu_label" name="label" value="{{ $old('label', $item->label) }}" class="ant-input" maxlength="100" placeholder="VD: Giới thiệu" @unless ($editing) autofocus @endunless />
              </div></div>
              {!! $error('label') !!}
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('parent_id') }}">
            <div class="ant-col ant-form-item-label"><label for="menu_parent" title="Menu cha">Menu cha</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                @php $parentId = (string) $old('parent_id', (string) $item->parent_id); @endphp
                {{-- Không chọn = cấp 1 (chữ gợi ý); ⓧ khi rê chuột để bỏ chọn cha --}}
                <select data-antd data-show-search data-allow-clear data-placeholder="Không có (cấp 1)" id="menu_parent" name="parent_id" class="w-full">
                  <option value=""></option>
                  @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected($parentId === (string) $parent->id)>{{ str_repeat('— ', $parent->depth).$parent->label }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('parent_id') !!}
            </div>
          </div>

          {{-- Liên kết: một ô nhiều cấp — các loại (trang chủ, danh mục dự án, danh mục tin tức, trang, tự nhập); bấm loại có "›"
               thì chọn tiếp danh mục / trang ngay trong ô đó, có nút quay lại (thay cho 3 ô [1] Trang / [2] Danh mục / [3] URL
               của hệ thống cũ). Giá trị gửi lên ở ô ẩn link. --}}
          @php $linkError = collect(['link', 'link_type', 'category_id', 'page_id'])->first(fn ($f) => $errors->has($f)); @endphp
          <div @class(['ant-row ant-form-item', 'ant-form-item-has-error ant-form-item-with-help' => $linkError])>
            <div class="ant-col ant-form-item-label"><label for="menu_link" title="Liên kết">Liên kết</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <div class="ant-select ant-select-single ant-select-show-arrow w-full link-picker" data-link-picker data-tree="{{ json_encode($linkTree, JSON_UNESCAPED_UNICODE) }}">
                  <div class="ant-select-selector">
                    <span class="ant-select-selection-search"><input type="search" id="menu_link" autocomplete="off" class="ant-select-selection-search-input" readonly unselectable="on" style="opacity: 0" role="combobox" aria-haspopup="listbox" /></span>
                    @if ($linkShown)
                      <span class="ant-select-selection-item" title="{{ $linkShown['label'] }}"><span class="ant-select-item-with-icon">{{ ant_icon($linkShown['icon'], 'ant-select-item-icon') }}<span class="ant-select-item-text">{{ $linkShown['label'] }}</span></span></span>
                    @else
                      <span class="ant-select-selection-placeholder">Chọn liên kết</span>
                    @endif
                  </div>
                  <span class="ant-select-arrow" unselectable="on" aria-hidden="true" style="user-select: none">{{ ant_icon('down', 'ant-select-suffix') }}</span>
                </div>
                <input type="hidden" name="link" value="{{ $link }}" data-link-value />
              </div></div>
              @if ($linkError)
                <div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">{{ $errors->first($linkError) }}</div></div>
              @endif
              <div class="ant-form-item-extra">Danh mục / trang: link tự theo đường dẫn của danh mục / trang; danh mục / trang đang ẩn thì mục này không hiện trên website.</div>
            </div>
          </div>
          <div @class(['ant-row ant-form-item'.$hasError('url'), 'hidden' => $link !== 'url']) data-link-field="url">
            <div class="ant-col ant-form-item-label"><label for="menu_url" title="Đường dẫn">Đường dẫn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="menu_url" name="url" value="{{ $old('url', $link === 'url' ? (string) $item->url : '') }}" class="ant-input" maxlength="500" spellcheck="false" placeholder="/gioi-thieu, #lien-he hoặc https://…" />
              </div></div>
              {!! $error('url') !!}
              <div class="ant-form-item-extra">Đường dẫn trong website (/…), neo trên trang (#…), link ngoài (https://…), mailto: hoặc tel:. Để trống: mục không có link (vd chỉ để chứa mục con).</div>
            </div>
          </div>

          <div class="ant-row ant-form-item mb-0{{ $hasError('is_visible') }}">
            <label class="flex items-start gap-3" for="menu_visible_switch">
              <button type="button" role="switch" id="menu_visible_switch" aria-checked="{{ $visible ? 'true' : 'false' }}" @class(['ant-switch mt-0.5 flex-none', 'ant-switch-checked' => $visible])><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>
              <input type="hidden" name="is_visible" value="0" />
              <input type="checkbox" name="is_visible" value="1" class="switch-input" hidden @checked($visible) />
              <span><span class="block text-[rgba(0,0,0,0.85)]">Hiển thị</span><span class="block {{ $muted }}">Tắt để ẩn mục (cùng các mục con) khỏi website mà không phải xóa.</span></span>
            </label>
            {!! $error('is_visible') !!}
          </div>
        </div>
        @if ($editing && $canAdd && $item->depth + 1 < Menu::MAX_DEPTH)
          <div class="cat-usage"><a href="{{ $url(['new' => 1, 'parent' => $item->id]) }}" data-tree-nav>{{ ant_icon('plus') }} Thêm mục con vào “{{ $item->label }}”</a></div>
        @endif
      </div>
    </form>
  @else
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty ant-empty-normal">
          <div class="ant-empty-image">{{ ant_icon('container', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
          <p class="ant-empty-description">{{ $positionName }} chưa có mục nào.</p>
        </div>
      </div>
    </div>
  @endif
</div>
@endsection
