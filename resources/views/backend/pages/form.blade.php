@extends('layouts.backend')

{{-- Thêm / sửa trang, theo template ProCMS news-edit.html: thanh trên cùng có tên đã lưu + dải "Chưa lưu thay đổi"; cột
     chính (Tiêu đề, Đường dẫn, Mô tả ngắn, Nội dung soạn bằng Tiptap; kết quả tìm kiếm Google) + cột phụ (Trạng thái, Ảnh
     đại diện, Menu đang trỏ tới). Form gửi thẳng lên server (kèm ảnh), lỗi hiện dưới từng ô.
     JS: edit-form.js (thay đổi chưa lưu), page-form.js (đường dẫn tự theo tiêu đề, trình soạn thảo, SEO), image-field.js. --}}
@php
    use App\Models\Menu;
    use App\Models\Page;
    use App\Support\SiteSettings;

    $editing = $page->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    // Lưu lỗi: ô theo dữ liệu vừa gửi (ô xóa trắng thành null qua ConvertEmptyStringsToNull — vẫn phải hiện trống)
    $old = fn (string $field) => session()->hasOldInput() ? (is_string(old($field)) ? old($field) : '') : (string) $page->{$field};
    // Công tắc tắt = checkbox không gửi đi (ô ẩn status=0 đứng trước)
    $visible = session()->hasOldInput() ? (string) old('status') === '1' : $page->status !== false;
    $image = $page->imageUrl();
    $site = SiteSettings::all();
    $favicon = SiteSettings::imageUrl($site['site_favicon'] ?? null);
    $base = url('/'.Page::PATH).'/';
    $seoOpen = $errors->hasAny(['meta_title', 'meta_description']);
@endphp

@section('title', $editing ? $page->title : 'Thêm trang')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.pages.index') }}" class="page-topbar-crumb" title="Về danh sách trang">Trang</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $page->title : 'Thêm trang' }}</h1>
    @if ($editing && ! $page->status)<span class="ant-tag">Đang ẩn</span>@endif
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="page-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo trang' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('page/delete')
        @if ($page->menuItems->isNotEmpty())
          <span data-tooltip="Đang dùng trong {{ $page->menuItems->count() }} mục menu, không xóa được (đổi liên kết mục menu trước)"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa trang"
            data-delete-url="{{ route('backend.pages.destroy', $page) }}" data-delete-title="Xóa trang “{{ $page->title }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
    @else
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo trang → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.pages.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="page-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo trang</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="page-form" action="{{ $editing ? route('backend.pages.update', $page) : route('backend.pages.store') }}" enctype="multipart/form-data"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('title') }}" data-required="Nhập tiêu đề trang.">
            <div class="ant-col ant-form-item-label"><label for="page_title" class="ant-form-item-required" title="Tiêu đề">Tiêu đề</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="page_title" name="title" value="{{ $old('title') }}" class="ant-input" maxlength="255" placeholder="VD: Giới thiệu" />
              </div></div>
              {!! $error('title') !!}
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('slug') }}">
            <div class="ant-col ant-form-item-label"><label for="page_slug" title="Đường dẫn">Đường dẫn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group"
                  ><span class="ant-input-group-addon"><span class="page-slug-base" title="{{ $base }}"><bdi>{{ $base }}</bdi></span></span
                  ><input type="text" id="page_slug" name="slug" value="{{ $old('slug') }}" class="ant-input" maxlength="255" placeholder="tu-dong-theo-tieu-de" @if ($old('slug') !== '') data-touched @endif /></span
                ></span>
              </div></div>
              {!! $error('slug') !!}
              <div class="ant-form-item-extra">Để trống thì tự tạo theo tiêu đề. Chỉ chữ thường không dấu, số và dấu “-”.</div>
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('excerpt') }}">
            <div class="ant-col ant-form-item-label"><label for="page_excerpt" title="Mô tả ngắn">Mô tả ngắn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="page_excerpt" name="excerpt" class="ant-input" rows="3" maxlength="500" placeholder="Một vài câu tóm tắt, dùng khi chia sẻ trang và khi chưa nhập mô tả SEO">{{ $old('excerpt') }}</textarea>
              </div></div>
              {!! $error('excerpt') !!}
              <div class="ant-form-item-extra"><span data-counter-for="page_excerpt" data-max="500"></span></div>
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('content') }}">
            <div class="ant-col ant-form-item-label"><label for="page_content_editor" title="Nội dung">Nội dung</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                {{-- Trình soạn thảo Tiptap (editor.js); HTML ghi vào ô ẩn content để gửi cùng form --}}
                <div class="rich-editor page-editor" data-placeholder="Viết nội dung trang…" data-label="Nội dung">
                  <div class="rich-editor-toolbar"></div>
                  <div class="rich-editor-content" id="page_content_editor"></div>
                  <textarea class="rich-editor-source hidden" aria-label="HTML nội dung"></textarea>
                  <div class="rich-editor-footer"></div>
                </div>
                <textarea name="content" class="hidden" aria-hidden="true" tabindex="-1">{{ $old('content') }}</textarea>
              </div></div>
              {!! $error('content') !!}
            </div>
          </div>
        </div>
      </div>

      {{-- Kết quả tìm kiếm: xem trước như Google; bấm bút để sửa tiêu đề / mô tả SEO --}}
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Hiển thị trên Google</div>
            <div class="ant-card-extra">
              <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" id="seo-edit" data-tooltip="Sửa tiêu đề, mô tả SEO" aria-label="Sửa tiêu đề, mô tả SEO" aria-expanded="{{ $seoOpen ? 'true' : 'false' }}" aria-controls="seo-fields">{{ ant_icon('edit') }}</button>
            </div>
          </div>
        </div>
        <div class="ant-card-body">
          <div class="seo-preview" aria-label="Xem trước kết quả tìm kiếm">
            <div class="seo-preview-site">
              <span class="seo-preview-favicon">@if ($favicon)<img src="{{ $favicon }}" alt="" />@else{{ ant_icon('shop') }}@endif</span>
              <div class="min-w-0">
                <div class="seo-preview-name">{{ ($site['site_name'] ?? null) ?: config('app.name') }}</div>
                <div class="seo-preview-url" id="seo-preview-url" data-base="{{ url('/') }} › {{ Page::PATH }} › "></div>
              </div>
            </div>
            <div class="seo-preview-title" id="seo-preview-title"></div>
            <div class="seo-preview-description" id="seo-preview-description"></div>
          </div>
          <div @class(['mt-6', 'hidden' => ! $seoOpen]) id="seo-fields">
            <div class="ant-row ant-form-item{{ $hasError('meta_title') }}">
              <div class="ant-col ant-form-item-label"><label for="page_meta_title" title="Tiêu đề SEO">Tiêu đề SEO</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="page_meta_title" name="meta_title" value="{{ $old('meta_title') }}" class="ant-input" maxlength="70" placeholder="Để trống thì dùng tiêu đề trang" />
                </div></div>
                {!! $error('meta_title') !!}
                <div class="ant-form-item-extra"><span data-counter-for="page_meta_title" data-max="70"></span></div>
              </div>
            </div>
            <div class="ant-row ant-form-item mb-0{{ $hasError('meta_description') }}">
              <div class="ant-col ant-form-item-label"><label for="page_meta_description" title="Mô tả SEO">Mô tả SEO</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="page_meta_description" name="meta_description" class="ant-input" rows="3" maxlength="160" placeholder="Để trống thì dùng mô tả ngắn">{{ $old('meta_description') }}</textarea>
                </div></div>
                {!! $error('meta_description') !!}
                <div class="ant-form-item-extra"><span data-counter-for="page_meta_description" data-max="160"></span></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('status') }}">
            <label class="flex items-start gap-3" for="page_status_switch">
              <button type="button" role="switch" id="page_status_switch" aria-checked="{{ $visible ? 'true' : 'false' }}" @class(['ant-switch mt-0.5 flex-none', 'ant-switch-checked' => $visible])><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>
              <input type="hidden" name="status" value="0" />
              <input type="checkbox" name="status" value="1" class="switch-input" hidden @checked($visible) />
              <span><span class="block text-[rgba(0,0,0,0.85)]">Hiển thị trên website</span><span class="block {{ $muted }}">Tắt khi trang còn đang viết; mục menu trỏ tới trang đang ẩn cũng ẩn theo.</span></span>
            </label>
            {!! $error('status') !!}
          </div>
          @if ($editing)
            <dl class="profile-facts mt-4 mb-0">
              <dt>Tạo lúc</dt><dd class="tabular-nums">{{ $page->created_at?->format('d/m/Y H:i') }}</dd>
              <dt>Cập nhật</dt><dd class="tabular-nums">{{ $page->updated_at?->format('d/m/Y H:i') }}</dd>
            </dl>
          @endif
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Ảnh đại diện</div></div></div>
        <div class="ant-card-body">
          {{-- Chọn ảnh thì xem trước ngay, lưu form mới tải lên; ⓧ trên ảnh = bỏ ảnh --}}
          <div class="ant-row ant-form-item mb-0{{ $hasError('image') }}" data-image-field="image" data-max-size="{{ 2 * 1024 * 1024 }}" data-type-message="Chọn ảnh PNG, JPG, WEBP hoặc GIF." data-size-message="Ảnh tối đa 2 MB.">
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <div class="ant-upload-picture-card-wrapper">
                  <div class="ant-upload-list ant-upload-list-picture-card page-image" data-current="{{ $image }}">
                    @if ($image)
                      <div class="ant-upload-list-picture-card-container">
                        <div class="ant-upload-list-item ant-upload-list-item-done ant-upload-list-item-list-type-picture-card">
                          <div class="ant-upload-list-item-info">
                            <span><a class="ant-upload-list-item-thumbnail" href="{{ $image }}" target="_blank" rel="noopener noreferrer"><img src="{{ $image }}" alt="" class="ant-upload-list-item-image" /></a></span>
                          </div>
                          <span class="ant-upload-list-item-actions"><a href="{{ $image }}" target="_blank" rel="noopener noreferrer" title="Xem ảnh">{{ ant_icon('eye') }}</a><button title="Bỏ ảnh" type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file">{{ ant_icon('delete') }}</button></span>
                        </div>
                      </div>
                    @endif
                    <div @class(['ant-upload ant-upload-select ant-upload-select-picture-card', 'hidden' => $image])>
                      <span tabindex="0" class="ant-upload" role="button" data-image-pick aria-label="Chọn ảnh đại diện">
                        <div>{{ ant_icon('plus') }}<div class="mt-2">Tải lên</div></div>
                      </span>
                    </div>
                  </div>
                </div>
                {{-- Ô chọn tệp thật nằm ngoài khung .ant-upload (controls.js xóa tệp của ô nằm trong khung sau khi đọc) --}}
                <input type="file" name="image" id="page_image" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" aria-label="Ảnh đại diện" />
                <input type="checkbox" name="remove_image" value="1" class="hidden" data-image-remove />
              </div></div>
              {!! $error('image') !!}
              <div class="ant-form-item-extra">Hiện khi chia sẻ trang lên Facebook, Zalo… Ảnh ngang 1200 × 630 là đẹp nhất; PNG, JPG, WEBP hoặc GIF, tối đa 2 MB.</div>
            </div>
          </div>
        </div>
      </div>

      @if ($editing)
        {{-- Mục menu đang trỏ tới trang (gắn trang vào menu ở trang Menu, ô Liên kết) --}}
        <div class="ant-card ant-card-bordered" data-card="menus">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Menu</div></div></div>
          <div class="ant-card-body">
            @forelse ($page->menuItems as $item)
              <div @class(['flex items-center gap-2', 'mt-2' => ! $loop->first])>
                {{ ant_icon('container', $muted) }}
                @can('menu')
                  <a href="{{ route('backend.menus.index', ['position' => $item->menu?->handle, 'id' => $item->id]) }}" class="min-w-0 wrap-anywhere">{{ Menu::POSITIONS[$item->menu?->handle][0] ?? 'Menu' }} › {{ $item->label }}</a>
                @else
                  <span class="min-w-0 wrap-anywhere">{{ Menu::POSITIONS[$item->menu?->handle][0] ?? 'Menu' }} › {{ $item->label }}</span>
                @endcan
                @unless ($item->is_visible)<span class="ant-tag m-0">Ẩn</span>@endunless
              </div>
            @empty
              <p class="m-0 {{ $muted }}">Chưa có mục menu nào trỏ tới trang này.@can('menu/add') <a href="{{ route('backend.menus.index', ['position' => 'main', 'new' => 1]) }}">Thêm vào menu</a>@endcan</p>
            @endforelse
          </div>
        </div>
      @endif
    </div>
  </div>
</form>
@endsection
