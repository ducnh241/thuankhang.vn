@extends('layouts.backend')

@section('title', 'Quản lý file')

{{-- Quản lý file tải lên, dựng như danh sách Sản phẩm: tab loại file (số theo từ khóa), ô tìm theo tên, bảng, phân trang.
     Danh sách (App\Support\FileLibrary) gồm file của thư viện + ảnh sản phẩm / trang / cài đặt; lọc theo nguồn (list.js).
     Nút "Tải file lên" / kéo thả vào trang gửi form #file-upload-form (file-manager.js); mỗi dòng: sao chép link
     (copy-text.js), mở / tải về, xóa có hỏi lại (delete.js); chọn nhiều dòng để xóa hàng loạt (bulk.js). --}}
@php
    use App\Http\Controllers\Backend\FileController;
    use App\Models\FileUpload;
    use App\Support\FileLibrary;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canUpload = $me->can('file/upload');
    $canDelete = $me->can('file/delete');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.files.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $maxBytes = FileUpload::maxKilobytes() * 1024;
    $limitText = 'Ảnh JPG, PNG, WEBP, GIF và tài liệu PDF, Word, Excel, PowerPoint, TXT, CSV, ZIP. Mỗi file tối đa '.FileUpload::formatSize($maxBytes).', mỗi lần '.FileUpload::MAX_FILES.' file.';
    $deleteNote = 'File bị xóa hẳn khỏi máy chủ; nơi nào đang dùng link của file sẽ không mở được nữa.';
@endphp

@section('topbar-sub', $count($totalFiles).' file · '.FileUpload::formatSize($usedBytes))

@section('actions')
  @if ($canUpload)
    <button type="button" class="ant-btn ant-btn-primary" data-upload-trigger aria-label="Tải file lên">{{ ant_icon('upload') }}<span class="max-sm:hidden">Tải file lên</span></button>
  @endif
@endsection

@section('content')
<div id="files" class="file-manager mx-auto max-w-[1200px]" data-list>
  @if ($tooLarge)
    <div class="ant-alert ant-alert-error mb-4" role="alert">
      {{ ant_icon('close-circle', 'ant-alert-icon') }}
      <div class="ant-alert-content"><div class="ant-alert-message">Chưa tải được: tổng dung lượng các file vượt quá mức máy chủ nhận trong một lần{{ FileUpload::maxPostBytes() ? ' ('.FileUpload::formatSize(FileUpload::maxPostBytes()).')' : '' }}. Hãy tải ít file hơn mỗi lần.</div></div>
    </div>
  @endif

  @if ($canUpload)
    <form method="POST" action="{{ route('backend.files.store') }}" enctype="multipart/form-data" id="file-upload-form" class="hidden"
      data-max-files="{{ FileUpload::MAX_FILES }}" data-max-bytes="{{ $maxBytes }}" data-max-total="{{ FileUpload::maxPostBytes() }}">
      @csrf
      <input type="file" name="files[]" multiple accept="{{ collect(FileUpload::extensions())->map(fn ($e) => '.'.$e)->implode(',') }}" aria-label="Chọn file tải lên" />
    </form>
  @endif

  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Loại file">
        @foreach (FileController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.files.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="file-search" placeholder="Tìm theo tên file, tên sản phẩm / trang đang dùng" aria-label="Tìm file" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($filter['source'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="source">Nguồn: {{ FileLibrary::SOURCES[$filter['source']][0] }}</button><a href="{{ $url(['source' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc nguồn">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="source">Nguồn{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['q'] !== '' || $filter['source'] !== '')
          <a href="{{ route('backend.files.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-source">
        <div class="ant-dropdown"><ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
          @foreach (FileLibrary::SOURCES as $key => [$label])
            <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['source'] === $key]) role="menuitem"><a href="{{ $url(['source' => $key]) }}">{{ $label }}</a></li>
          @endforeach
        </ul></div>
      </template>
      @if ($canUpload)<div class="mt-2 text-xs {{ $muted }}">{{ $limitText }} Có thể kéo thả file vào trang này.</div>@endif
    </div>

    <form method="POST" action="{{ route('backend.files.bulk-destroy') }}" id="file-bulk-form" @if ($canDelete) data-bulk-form @endif>
    @csrf
    <div class="relative">
    @if ($canDelete)
      <div class="product-bulk hidden" data-bulk-bar>
        <label class="ant-checkbox-wrapper"><span class="ant-checkbox ant-checkbox-indeterminate"><input type="checkbox" class="ant-checkbox-input" data-bulk-clear aria-label="Bỏ chọn tất cả" /><span class="ant-checkbox-inner"></span></span></label>
        <span class="whitespace-nowrap font-medium"><span data-bulk-count>0</span> đã chọn</span>
        <div class="ml-auto flex gap-2">
          <button type="button" class="ant-btn ant-btn-sm ant-btn-dangerous" data-bulk-delete data-title="Xóa %d file đã chọn?" data-content="{{ $deleteNote }}">{{ ant_icon('delete') }}<span>Xóa</span></button>
        </div>
      </div>
    @endif
    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $files->isEmpty()]) style="table-layout: auto">
              @if ($canDelete)<colgroup><col class="ant-table-selection-col" /></colgroup>@endif
              <thead class="ant-table-thead">
                <tr>
                  @if ($canDelete)
                    <th class="ant-table-cell ant-table-selection-column">
                      <div class="ant-table-selection">
                        <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" data-select-page aria-label="Chọn cả trang" @disabled($files->isEmpty()) /><span class="ant-checkbox-inner"></span></span></label>
                      </div>
                    </th>
                  @endif
                  <th class="ant-table-cell">File</th>
                  <th class="ant-table-cell max-md:hidden">Nguồn</th>
                  <th class="ant-table-cell max-md:hidden" style="text-align: right">Dung lượng</th>
                  <th class="ant-table-cell max-md:hidden">Cập nhật</th>
                  <th class="ant-table-cell product-actions-col" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="file-rows">
                @forelse ($files as $file)
                  @php $time = \Illuminate\Support\Carbon::createFromTimestamp($file->time, config('app.timezone'))->format('d/m/Y H:i'); @endphp
                  <tr data-row-key="{{ $file->path }}" class="ant-table-row">
                    @if ($canDelete)
                      <td class="ant-table-cell ant-table-selection-column">
                        {{-- Chỉ file của thư viện mới chọn để xóa được; ảnh sản phẩm / trang / cài đặt gỡ ở màn hình của nó --}}
                        <label @class(['ant-checkbox-wrapper', 'ant-checkbox-wrapper-disabled' => ! $file->upload])><span @class(['ant-checkbox', 'ant-checkbox-disabled' => ! $file->upload])><input type="checkbox" class="ant-checkbox-input" name="ids[]" value="{{ $file->upload?->id }}" data-row-check aria-label="Chọn {{ $file->name }}" @disabled(! $file->upload) /><span class="ant-checkbox-inner"></span></span></label>
                      </td>
                    @endif
                    <td class="ant-table-cell">
                      <div class="flex items-center gap-3">
                        <a href="{{ $file->url }}" target="_blank" rel="noopener" class="product-thumb" aria-label="Mở {{ $file->name }}">@if ($file->is_image)<img src="{{ $file->url }}" alt="" loading="lazy" />@else<span class="file-ext">{{ $file->extension }}</span>@endif</a>
                        <div class="min-w-0 max-w-[480px] whitespace-normal">
                          <a href="{{ $file->url }}" target="_blank" rel="noopener" class="product-name wrap-anywhere">{{ $file->name }}</a>
                          <div class="text-xs {{ $muted }} wrap-anywhere max-md:hidden">{{ $file->url }}</div>
                          <div class="text-xs tabular-nums {{ $muted }} md:hidden">{{ FileLibrary::SOURCES[$file->source][0] }} · {{ FileUpload::formatSize($file->size) }} · {{ $time }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell max-md:hidden" style="max-width: 240px; white-space: normal">
                      <span class="ant-tag m-0">{{ FileLibrary::SOURCES[$file->source][0] }}</span>
                      @if ($file->usage)
                        <div class="mt-0.5 text-xs wrap-anywhere">
                          @if ($file->usage['route'] && $me->can($file->usage['can']))
                            <a href="{{ $file->usage['route'] }}">{{ $file->usage['label'] }}</a>
                          @else
                            <span class="{{ $muted }}">{{ $file->usage['label'] }}</span>
                          @endif
                        </div>
                      @elseif ($file->source !== 'library')
                        <div class="mt-0.5 text-xs {{ $muted }}">Không còn dùng</div>
                      @elseif ($file->uploader)
                        <div class="mt-0.5 text-xs {{ $muted }}">{{ $file->uploader }} tải lên</div>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden" style="text-align: right">{{ FileUpload::formatSize($file->size) }}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden">{{ $time }}</td>
                    <td class="ant-table-cell product-actions-col" style="text-align: right">
                      <div class="product-row-actions">
                        <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sao chép link" aria-label="Sao chép link {{ $file->name }}" data-copy-text="{{ $file->url }}">{{ ant_icon('copy') }}</button>
                        <a href="{{ $file->url }}" download="{{ $file->name }}" class="ant-btn ant-btn-text ant-btn-icon-only max-sm:hidden" data-tooltip="Tải về" aria-label="Tải về {{ $file->name }}">{{ ant_icon('download') }}</a>
                        @if ($canDelete && $file->upload)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $file->name }}"
                            data-delete-url="{{ route('backend.files.destroy', $file->upload) }}" data-delete-title="Xóa file “{{ $file->name }}”?" data-delete-content="{{ $deleteNote }}">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="{{ $canDelete ? 6 : 5 }}" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('inbox', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if (array_filter($filter))
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy file nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.files.index') }}" class="ant-btn"><span>Xem tất cả</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có file nào</strong>Tải ảnh / tài liệu lên để lấy link dùng trong trang, mô tả sản phẩm hoặc gửi cho khách.</p>
                          @if ($canUpload)<div class="ant-empty-footer"><button type="button" class="ant-btn ant-btn-primary" data-upload-trigger>{{ ant_icon('upload') }}<span>Tải file lên</span></button></div>@endif
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

    @if ($files->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($files->firstItem()) }}–{{ $count($files->lastItem()) }} / {{ $count($files->total()) }} file</span>
        {{ $files->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
