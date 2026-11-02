@extends('layouts.backend')

{{-- Thêm / sửa hosting. Template ProCMS không có trang này: dựng như form dự án / khách hàng (project-edit.html) — thanh
     trên cùng có tên đã lưu + dải "Chưa lưu thay đổi"; cột chính (Thông tin, Dự án trên hosting) + cột phụ (Loại, Thời
     hạn, Trạng thái). Form gửi thẳng lên server, lỗi hiện dưới từng ô.
     JS: edit-form.js (thay đổi chưa lưu), hosting-form.js (lọc / đếm dự án đã chọn). --}}
@php
    use App\Http\Controllers\Backend\HostingController;
    use App\Models\Hosting;
    use App\Models\Project;

    $editing = $hosting->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $hasError = fn (string ...$fields) => collect($fields)->contains(fn ($f) => $errors->has($f)) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string ...$fields) => ($field = collect($fields)->first(fn ($f) => $errors->has($f)))
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    $specs = (array) $hosting->specs;
    // (string) (float): 200 → "200", 1.50 → "1.5"; 0 / chưa nhập → để trống
    $spec = fn (string $key) => old($key, isset($specs[$key]) && (float) $specs[$key] > 0 ? (string) (float) $specs[$key] : '');
    // Bỏ chọn hết rồi lưu lỗi → old() không có key project_ids, vẫn phải giữ "không chọn gì"
    $checked = array_map('intval', array_filter((array) (session()->hasOldInput() ? old('project_ids', []) : $selected), 'is_numeric'));
    $ownership = old('ownership', $hosting->ownership ?? 'mine');
    // Công tắc tắt = checkbox không gửi đi (ô ẩn is_active=0 đứng trước)
    $active = session()->hasOldInput() ? (string) old('is_active') === '1' : $hosting->is_active !== false;
    $date = fn (string $field) => old($field, $hosting->{$field}?->toDateString());
    $days = $editing ? $hosting->daysLeft() : null;
    $projectsCount = $editing ? $hosting->projects()->count() : 0;
@endphp

@section('title', $editing ? $hosting->name : 'Thêm hosting')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.hostings.index') }}" class="page-topbar-crumb" title="Về danh sách hosting">Hosting</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $hosting->name : 'Thêm hosting' }}</h1>
    @if ($editing)
      <span class="page-topbar-sub">#{{ $hosting->id }}</span>
      @unless ($hosting->is_active)<span class="ant-tag">Ngừng dùng</span>@endunless
    @endif
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="hosting-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo hosting' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('hosting/delete')
        @if ($projectsCount)
          <span data-tooltip="Đang gắn {{ $projectsCount }} dự án, không xóa được (bỏ gắn dự án trước)"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa hosting"
            data-delete-url="{{ route('backend.hostings.destroy', $hosting) }}" data-delete-title="Xóa hosting “{{ $hosting->name }}”?" data-delete-content="Không thể hoàn tác.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
    @else
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo hosting → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.hostings.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="hosting-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo hosting</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="hosting-form" action="{{ $editing ? route('backend.hostings.update', $hosting) : route('backend.hostings.store') }}"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên hosting.">
            <div class="ant-col ant-form-item-label"><label for="name" class="ant-form-item-required" title="Tên">Tên</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="name" name="name" value="{{ old('name', $hosting->name) }}" class="ant-input" maxlength="255" placeholder="VD: Contabo VPS 1" @unless ($editing) autofocus @endunless />
              </div></div>
              {!! $error('name') !!}
            </div>
          </div>
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
            @foreach (['provider' => ['Nhà cung cấp', 'Contabo, Inet…', 255], 'plan_name' => ['Gói', 'Cloud VPS 20', 255], 'ip' => ['IP', '103.56.160.36', 64]] as $field => [$text, $placeholder, $max])
              <div class="ant-row ant-form-item{{ $hasError($field) }}">
                <div class="ant-col ant-form-item-label"><label for="{{ $field }}" title="{{ $text }}">{{ $text }}</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $hosting->{$field}) }}" @class(['ant-input', 'font-mono' => $field === 'ip']) maxlength="{{ $max }}" placeholder="{{ $placeholder }}" />
                  </div></div>
                  {!! $error($field) !!}
                </div>
              </div>
            @endforeach
          </div>
          {{-- Cấu hình: ô số có đơn vị ở cuối (Ant Input addonAfter) --}}
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
            @foreach (['cpu' => ['CPU', 'core', 'numeric'], 'ram_gb' => ['RAM', 'GB', 'decimal'], 'disk_gb' => ['Ổ cứng', 'GB', 'decimal']] as $field => [$text, $unit, $mode])
              <div class="ant-row ant-form-item{{ $hasError($field) }}">
                <div class="ant-col ant-form-item-label"><label for="{{ $field }}" title="{{ $text }}">{{ $text }}</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group">
                      <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ $spec($field) }}" class="ant-input tabular-nums" inputmode="{{ $mode }}" maxlength="12" placeholder="0" />
                      <span class="ant-input-group-addon">{{ $unit }}</span>
                    </span></span>
                  </div></div>
                  {!! $error($field) !!}
                </div>
              </div>
            @endforeach
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('note') }}">
            <div class="ant-col ant-form-item-label"><label for="note" title="Ghi chú">Ghi chú</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="note" name="note" class="ant-input" rows="3" maxlength="5000" placeholder="Vị trí đặt máy, mục đích sử dụng…">{{ old('note', $hosting->note) }}</textarea>
              </div></div>
              {!! $error('note') !!}
            </div>
          </div>
        </div>
      </div>

      {{-- Dự án chạy trên hosting: tích chọn, lọc ngay trên trang (không dấu) --}}
      <div class="ant-card ant-card-bordered" id="hosting-projects">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Dự án trên hosting <span class="{{ $muted }} font-normal">(<span data-picked-count>{{ count($checked) }}</span> đã chọn)</span></div>
          </div>
        </div>
        <div class="ant-card-body">
          @if ($errors->has('project_ids') || $errors->has('project_ids.*'))
            <div class="ant-alert ant-alert-error mb-3" role="alert"><span class="ant-alert-message">{{ $errors->first('project_ids') ?: $errors->first('project_ids.*') }}</span></div>
          @endif
          <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="ant-input-affix-wrapper min-w-[200px] flex-1">
              <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
              <input type="text" class="ant-input" data-project-search placeholder="Lọc theo tên dự án, domain, khách hàng" aria-label="Lọc dự án" />
            </span>
            <label class="ant-checkbox-wrapper ml-0 whitespace-nowrap">
              <span @class(['ant-checkbox', 'ant-checkbox-checked' => $editing && count($checked)])><input type="checkbox" class="ant-checkbox-input" data-only-picked @checked($editing && count($checked)) /><span class="ant-checkbox-inner"></span></span>
              <span>Chỉ hiện đã chọn</span>
            </label>
          </div>
          <div class="hosting-project-list">
            @forelse ($projects as $project)
              @php $tagClass = Project::STATUS_TAGS[$project->status] ?? ''; @endphp
              <label class="hosting-project" data-project-row data-search="{{ $project->name.' '.$project->primary_domain.' '.$project->domains->pluck('domain')->implode(' ').' '.$project->customer?->name.' #'.$project->id }}">
                <span class="ant-checkbox-wrapper ml-0"><span @class(['ant-checkbox', 'ant-checkbox-checked' => in_array($project->id, $checked, true)])><input type="checkbox" class="ant-checkbox-input" name="project_ids[]" value="{{ $project->id }}" @checked(in_array($project->id, $checked, true)) /><span class="ant-checkbox-inner"></span></span></span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium text-[rgba(0,0,0,0.85)]">{{ $project->name }}</span>
                  <span class="block truncate text-xs {{ $muted }}">#{{ $project->id }}{{ $project->customer ? ' · '.$project->customer->name : '' }}</span>
                </span>
                <span class="ant-tag m-0 {{ $tagClass }}">{{ $project->statusLabel() }}</span>
              </label>
            @empty
              <div class="ant-empty ant-empty-normal my-4"><p class="ant-empty-description">Chưa có dự án nào.</p></div>
            @endforelse
            <div class="ant-empty ant-empty-normal my-4 hidden" data-project-empty><p class="ant-empty-description">Không có dự án phù hợp.</p></div>
          </div>
        </div>
      </div>
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Loại</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('ownership') }}">
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <div class="ant-radio-group ant-radio-group-outline flex flex-col gap-3" role="radiogroup" aria-label="Loại hosting" data-value="{{ $ownership }}">
                  @foreach (['mine' => 'VPS / hosting của mình — có nhắc gia hạn và soạn thông báo cho khách', 'customer' => 'Hosting của khách — khách tự quản lý'] as $key => $hint)
                    <label @class(['ant-radio-wrapper mr-0 flex items-start whitespace-normal', 'ant-radio-wrapper-checked' => $ownership === $key])>
                      <span @class(['ant-radio mt-0.5', 'ant-radio-checked' => $ownership === $key])><input type="radio" class="ant-radio-input" name="ownership" value="{{ $key }}" @checked($ownership === $key) /><span class="ant-radio-inner"></span></span>
                      <span><span class="block text-[rgba(0,0,0,0.85)]">{{ Hosting::OWNERSHIPS[$key] }}</span><span class="block text-xs {{ $muted }}">{{ $hint }}</span></span>
                    </label>
                  @endforeach
                </div>
              </div></div>
              {!! $error('ownership') !!}
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thời hạn</div></div></div>
        <div class="ant-card-body">
          @foreach (['register_date' => 'Ngày đăng ký', 'expire_date' => 'Ngày hết hạn'] as $field => $text)
            <div class="ant-row ant-form-item{{ $hasError($field) }}">
              <div class="ant-col ant-form-item-label"><label for="{{ $field }}" title="{{ $text }}">{{ $text }}</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <div class="ant-picker w-full" data-picker="date">
                    <div class="ant-picker-input">
                      <input id="{{ $field }}" name="{{ $field }}" value="{{ is_string($date($field)) ? $date($field) : '' }}" placeholder="Chọn ngày" readonly autocomplete="off" />
                      <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
                    </div>
                    @if (is_string($date($field)) && $date($field) !== '')<span class="ant-picker-clear" role="button">{{ ant_icon('close-circle') }}</span>@endif
                  </div>
                </div></div>
                {!! $error($field) !!}
                @if ($field === 'expire_date' && $days !== null)
                  <div class="ant-form-item-extra" @if ($days <= 30) style="color: {{ $days <= 0 ? '#ff4d4f' : '#d48806' }}" @endif>{{ $days < 0 ? 'Đã quá hạn '.abs($days).' ngày' : ($days === 0 ? 'Hết hạn hôm nay' : 'Còn '.$days.' ngày') }}</div>
                @endif
              </div>
            </div>
          @endforeach
          <div class="ant-row ant-form-item mb-0{{ $hasError('alert_days') }}">
            <div class="ant-col ant-form-item-label"><label for="alert_days" title="Mốc nhắc gia hạn">Mốc nhắc gia hạn</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <span class="ant-input-group-wrapper"><span class="ant-input-wrapper ant-input-group">
                  <input type="text" id="alert_days" name="alert_days" value="{{ old('alert_days', $hosting->alert_days) }}" class="ant-input tabular-nums" maxlength="100" placeholder="30,15,7,1" />
                  <span class="ant-input-group-addon">ngày</span>
                </span></span>
              </div></div>
              {!! $error('alert_days') !!}
              <div class="ant-form-item-extra">Số ngày trước hạn, cách nhau dấu phẩy.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('is_active') }}">
            <label class="flex items-start gap-3" for="is_active_switch">
              <button type="button" role="switch" id="is_active_switch" aria-checked="{{ $active ? 'true' : 'false' }}" @class(['ant-switch mt-0.5 flex-none', 'ant-switch-checked' => $active])><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>
              <input type="hidden" name="is_active" value="0" />
              <input type="checkbox" name="is_active" value="1" class="switch-input" hidden @checked($active) />
              <span><span class="block text-[rgba(0,0,0,0.85)]">Đang dùng</span><span class="block {{ $muted }}">Tắt khi đã trả / ngừng thuê hosting.</span></span>
            </label>
            {!! $error('is_active') !!}
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
