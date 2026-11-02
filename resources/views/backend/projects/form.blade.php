@extends('layouts.backend')

{{-- Thêm / sửa dự án, theo template ProCMS project-edit.html: thanh trên cùng có tên đã lưu và dải "Chưa lưu thay đổi"
     (Hủy thay đổi / Lưu) khi form khác lúc mở; cột chính (Thông tin chung, Tài chính) + cột phụ (Trạng thái, Mốc thời
     gian, Tags, Hosting). Form gửi thẳng lên server; lỗi validate hiện ngay dưới từng ô.
     JS: resources/admin/js/edit-form.js (dải chưa lưu…) + project-form.js (thanh toán, tạo nhanh khách hàng) --}}
@php
    use App\Models\Hosting;
    use App\Models\Project;
    use App\Support\Money;

    $editing = $project->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $money = fn ($field) => old($field, (float) $project->{$field} > 0 ? Money::format($project->{$field}) : '');
    $date = fn ($field) => old($field, $project->{$field}?->format('Y-m-d'));
    // Có old input (vừa bị lỗi validate) thì lấy đúng những gì đã tick — kể cả khi bỏ tick hết
    $selectedTags = array_map('intval', (array) (session()->hasOldInput() ? old('tag_ids', []) : $project->tags->pluck('id')->all()));
    $selectedHostings = array_map('intval', (array) (session()->hasOldInput() ? old('hosting_ids', []) : $project->hostings->pluck('id')->all()));
    $currency = old('currency', $project->currency ?: 'VND');
    $currencies = array_unique(['VND', 'USD', $currency]);
    // Như Project::recalculatePaymentStatus: thu mới đã thu so với giá; còn phát sinh chưa thu thì là "một phần"
    $paid = $editing ? $project->paidNewAmount() : 0.0;
    $unpaidExtra = $editing ? $project->unpaidAmount() : 0.0;

    // Ô có lỗi: viền đỏ + dòng báo lỗi dưới ô (như forms.js làm ở trình duyệt)
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    $tagDot = fn ($color) => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $color) ? $color : '#d9d9d9';
@endphp

@section('title', $editing ? $project->name : 'Thêm dự án')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.projects.index') }}" class="page-topbar-crumb" title="Về danh sách dự án">Dự án</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $project->name : 'Thêm dự án' }}</h1>
    @if ($editing)
      <span class="page-topbar-sub">#{{ $project->id }}</span>
      <span class="ant-tag {{ Project::STATUS_TAGS[$project->status] ?? '' }} max-sm:hidden">{{ $project->statusLabel() }}</span>
    @endif
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="project-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo dự án' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('project/delete')
        @if ($project->payments()->exists())
          <span data-tooltip="Đã có khoản thu, không xóa được"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa dự án"
            data-delete-url="{{ route('backend.projects.destroy', $project) }}" data-delete-title="Xóa dự án “{{ $project->name }}”?">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
      <a href="{{ route('backend.projects.show', $project) }}" class="ant-btn" aria-label="Xem dự án">{{ ant_icon('eye') }}<span class="max-sm:hidden">Xem dự án</span></a>
    @else
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo dự án → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.projects.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="project-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo dự án</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="project-form" action="{{ $editing ? route('backend.projects.update', $project) : route('backend.projects.store') }}"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin chung</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên dự án.">
            <div class="ant-col ant-form-item-label"><label for="name" class="ant-form-item-required" title="Tên dự án">Tên dự án</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" class="ant-input" placeholder="VD: Website bán hàng ABC" maxlength="255" @unless ($editing) autofocus @endunless />
              </div></div>
              {!! $error('name') !!}
            </div>
          </div>

          <div class="ant-row ant-form-item{{ $hasError('customer_id') }}" data-required="Chọn khách hàng.">
            <div class="ant-col ant-form-item-label flex! items-center justify-between">
              <label for="customer_id" class="ant-form-item-required" title="Khách hàng">Khách hàng</label>
              @can('customer/add')
                <a class="text-xs" role="button" tabindex="0" id="new-customer">+ Khách hàng mới</a>
              @endcan
            </div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-show-search id="customer_id" name="customer_id" class="w-full" data-placeholder="Chọn hoặc gõ để tìm khách hàng">
                  <option value=""></option>
                  @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((int) old('customer_id', $project->customer_id) === $customer->id)>{{ $customer->displayName() }} ({{ $customerCounts[$customer->id]['direct'] ?? 0 }} dự án){{ $customer->deleted_at ? ' — đã xóa' : '' }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('customer_id') !!}
              <div class="ant-form-item-extra">Gõ tên khách hoặc công ty để tìm, không cần gõ dấu.</div>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('primary_domain') }}">
              <div class="ant-col ant-form-item-label"><label for="primary_domain" title="Domain chính">Domain chính</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="primary_domain" name="primary_domain" value="{{ old('primary_domain', $project->primary_domain) }}" class="ant-input" placeholder="example.com" maxlength="255" />
                </div></div>
                {!! $error('primary_domain') !!}
                <div class="ant-form-item-extra">Mỗi domain chỉ gắn với một dự án.</div>
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('tech_stack') }}">
              <div class="ant-col ant-form-item-label"><label for="tech_stack" title="Công nghệ">Công nghệ</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="tech_stack" name="tech_stack" value="{{ old('tech_stack', $project->tech_stack) }}" class="ant-input" placeholder="WordPress, Laravel…" list="tech-list" maxlength="255" />
                  <datalist id="tech-list">
                    @foreach ($techStacks as $tech)<option value="{{ $tech }}"></option>@endforeach
                  </datalist>
                </div></div>
                {!! $error('tech_stack') !!}
              </div>
            </div>
          </div>

          {{-- Domain alias: cùng website (chung source code, chung database) chạy trên nhiều domain --}}
          @php
              $aliasRows = session()->hasOldInput()
                  ? array_values(array_map(fn ($r) => is_array($r) ? ['domain' => is_string($r['domain'] ?? null) ? $r['domain'] : '', 'note' => is_string($r['note'] ?? null) ? $r['note'] : ''] : ['domain' => '', 'note' => ''], (array) old('domains', [])))
                  : $project->domains->map(fn ($d) => ['domain' => $d->domain, 'note' => $d->note])->all();
          @endphp
          <div class="ant-row ant-form-item{{ $hasError('domains') }}" id="domain-aliases">
            <div class="ant-col ant-form-item-label"><label title="Domain alias">Domain alias</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="domain-alias-list" data-domain-list data-next="{{ count($aliasRows) }}">
                @foreach ($aliasRows as $i => $row)
                  @include('backend.projects.domain-row', ['i' => $i, 'row' => $row])
                @endforeach
              </div>
              <template id="domain-alias-template">@include('backend.projects.domain-row', ['i' => '__I__', 'row' => [], 'errors' => new \Illuminate\Support\ViewErrorBag])</template>
              <button type="button" class="ant-btn ant-btn-dashed w-full" data-domain-add>{{ ant_icon('plus') }}<span>Thêm domain alias</span></button>
              {!! $error('domains') !!}
              <div class="ant-form-item-extra">Domain khác của cùng website (chung source code và database), vd bản tiếng Anh, domain cũ đã chuyển hướng. Mỗi domain chỉ gắn với một dự án.</div>
            </div>
          </div>

          <div class="ant-row ant-form-item{{ $hasError('domain_note') }}">
            <div class="ant-col ant-form-item-label"><label for="domain_note" title="Ghi chú domain / đăng nhập">Ghi chú domain / đăng nhập</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="domain_note" name="domain_note" class="ant-input" rows="3" placeholder="Nơi đăng ký domain, DNS, tài khoản quản trị…">{{ old('domain_note', $project->domain_note) }}</textarea>
              </div></div>
              {!! $error('domain_note') !!}
              <div class="ant-form-item-extra">Nội dung không được chép vào lịch sử thay đổi.</div>
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('description') }}">
            <div class="ant-col ant-form-item-label"><label for="description" title="Mô tả">Mô tả</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="description" name="description" class="ant-input" rows="3">{{ old('description', $project->description) }}</textarea>
              </div></div>
              {!! $error('description') !!}
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('note') }}">
            <div class="ant-col ant-form-item-label"><label for="note" title="Ghi chú nội bộ">Ghi chú nội bộ</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="note" name="note" class="ant-input" rows="3" placeholder="Chỉ người trong nhóm xem được">{{ old('note', $project->note) }}</textarea>
              </div></div>
              {!! $error('note') !!}
            </div>
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">
              Tài chính
              <div class="text-xs font-normal {{ $muted }}">Trạng thái thanh toán tự tính từ các khoản đã thu so với giá hợp đồng.</div>
            </div>
          </div>
        </div>
        <div class="ant-card-body">
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-[1fr_180px]">
            <div class="ant-row ant-form-item{{ $hasError('price') }}">
              <div class="ant-col ant-form-item-label"><label for="price" title="Giá hợp đồng">Giá hợp đồng</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="price" name="price" value="{{ $money('price') }}" class="ant-input tabular-nums" inputmode="decimal" data-money placeholder="0" />
                </div></div>
                {!! $error('price') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('currency') }}">
              <div class="ant-col ant-form-item-label"><label for="currency" title="Tiền tệ">Tiền tệ</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <select data-antd id="currency" name="currency" class="w-full">
                    @foreach ($currencies as $code)
                      <option value="{{ $code }}" @selected($currency === $code)>{{ $code }}</option>
                    @endforeach
                  </select>
                </div></div>
                {!! $error('currency') !!}
              </div>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div class="ant-row ant-form-item mb-0{{ $hasError('renewal_hosting_amount') }}">
              <div class="ant-col ant-form-item-label"><label for="renewal_hosting_amount" title="Gia hạn hosting / năm">Gia hạn hosting / năm</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="renewal_hosting_amount" name="renewal_hosting_amount" value="{{ $money('renewal_hosting_amount') }}" class="ant-input tabular-nums" inputmode="decimal" data-money placeholder="0" />
                </div></div>
                {!! $error('renewal_hosting_amount') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item mb-0{{ $hasError('renewal_domain_amount') }}">
              <div class="ant-col ant-form-item-label"><label for="renewal_domain_amount" title="Gia hạn domain / năm">Gia hạn domain / năm</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="renewal_domain_amount" name="renewal_domain_amount" value="{{ $money('renewal_domain_amount') }}" class="ant-input tabular-nums" inputmode="decimal" data-money placeholder="0" />
                </div></div>
                {!! $error('renewal_domain_amount') !!}
                <div class="ant-form-item-extra">Chỉ điền nếu mình thu hộ tiền domain.</div>
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
          <div class="ant-row ant-form-item{{ $hasError('cat_id') }}">
            <div class="ant-col ant-form-item-label"><label for="cat_id" title="Danh mục">Danh mục</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-allow-clear id="cat_id" name="cat_id" class="w-full" data-placeholder="— Chưa phân loại —">
                  <option value=""></option>
                  @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) old('cat_id', $project->cat_id) === $category->id)>{{ $category->indentedName() }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('cat_id') !!}
              @can('category', 'project')
                <div class="ant-form-item-extra"><a href="{{ route('backend.categories.index', ['type' => 'project']) }}">Quản lý danh mục</a></div>
              @endcan
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('status') }}">
            <div class="ant-col ant-form-item-label"><label for="status" title="Tình trạng dự án">Tình trạng dự án</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd id="status" name="status" class="w-full">
                  @foreach (Project::STATUSES as $key => $text)
                    <option value="{{ $key }}" @selected(old('status', $project->status) === $key)>{{ $text }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('status') !!}
            </div>
          </div>
          {{-- Tính lại ngay khi sửa giá (project-form.js), cùng quy tắc với Project::recalculatePaymentStatus --}}
          <div class="flex items-center justify-between gap-2" id="payment-status" data-paid="{{ $paid }}" data-unpaid="{{ $unpaidExtra }}">
            <span class="{{ $muted }}">Thanh toán</span>
            <span class="ant-tag m-0 {{ ['paid' => 'ant-tag-green', 'partial' => 'ant-tag-gold', 'unpaid' => (float) $project->price > 0 ? 'ant-tag-red' : ''][$project->payment_status] ?? '' }}" data-payment-tag>{{ $project->paymentStatusLabel() }}</span>
          </div>
          <div class="mt-1 text-right text-xs tabular-nums {{ $muted }}" data-payment-detail>
            {{ (float) $project->price > 0 ? 'Đã thu '.Money::format($paid).' / '.Money::format($project->price).' '.$currency : ($paid > 0 ? 'Đã thu '.Money::format($paid).' '.$currency : 'Chưa có giá hợp đồng') }}
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Mốc thời gian</div></div></div>
        <div class="ant-card-body">
          @foreach (['start_date' => 'Ngày bắt đầu', 'deliver_date' => 'Ngày bàn giao', 'next_renewal_date' => 'Gia hạn kế tiếp'] as $field => $text)
            <div @class(['ant-row ant-form-item'.$hasError($field), 'mb-0' => $loop->last])>
              <div class="ant-col ant-form-item-label"><label for="{{ $field }}" title="{{ $text }}">{{ $text }}</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <div class="ant-picker w-full" data-picker="date">
                    <div class="ant-picker-input">
                      <input id="{{ $field }}" name="{{ $field }}" value="{{ $date($field) }}" placeholder="Chọn ngày" readonly autocomplete="off" />
                      <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
                    </div>
                    @if ($date($field))<span class="ant-picker-clear" role="button">{{ ant_icon('close-circle') }}</span>@endif
                  </div>
                </div></div>
                {!! $error($field) !!}
                @if ($field === 'next_renewal_date')
                  <div class="ant-form-item-extra">{{ $editing ? 'Tự chuyển sang năm sau khi ghi nhận đã gia hạn ở trang Nhắc gia hạn.' : 'Để trống sẽ lấy theo ngày bắt đầu.' }}</div>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tags</div></div></div>
        <div class="ant-card-body">
          <div class="check-list flex flex-col gap-2">
            @forelse ($tags as $tag)
              <label class="ant-checkbox-wrapper ml-0 flex items-start">
                <span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="tag_ids[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selectedTags, true)) /><span class="ant-checkbox-inner"></span></span>
                <span><span class="mr-1 inline-block size-2 rounded-full align-middle" style="background: {{ $tagDot($tag->color) }}"></span>{{ $tag->name }}</span>
              </label>
            @empty
              <p class="m-0 {{ $muted }}">Chưa có tag nào.</p>
            @endforelse
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Hosting</div></div></div>
        <div class="ant-card-body">
          <div class="check-list flex flex-col gap-3">
            @forelse ($hostings as $hosting)
              <label class="ant-checkbox-wrapper ml-0 flex items-start">
                <span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="hosting_ids[]" value="{{ $hosting->id }}" @checked(in_array($hosting->id, $selectedHostings, true)) /><span class="ant-checkbox-inner"></span></span>
                <span class="min-w-0">
                  <span class="block font-medium text-[rgba(0,0,0,0.85)]">{{ $hosting->name }}</span>
                  <span class="block text-xs {{ $muted }}">{{ collect([$hosting->provider, $hosting->ip, Hosting::OWNERSHIPS[$hosting->ownership] ?? null])->filter()->implode(' · ') }}</span>
                </span>
              </label>
            @empty
              <p class="m-0 {{ $muted }}">Chưa có hosting nào.</p>
            @endforelse
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

@can('customer/add')
  {{-- Tạo nhanh khách hàng (template: #customer-modal), gửi bằng fetch tới customers.quick-store rồi chọn luôn --}}
  <template id="customer-modal">
    <div class="ant-modal-root">
      <div class="ant-modal-mask"></div>
      <div tabindex="-1" class="ant-modal-wrap" role="dialog" aria-labelledby="customer-modal-title">
        <div role="document" class="ant-modal" style="width: 460px">
          <div class="ant-modal-content">
            <button type="button" aria-label="Đóng" class="ant-modal-close"><span class="ant-modal-close-x">{{ ant_icon('close', 'ant-modal-close-icon') }}</span></button>
            <div class="ant-modal-header"><div class="ant-modal-title" id="customer-modal-title">Khách hàng mới</div></div>
            <div class="ant-modal-body">
              <form id="customer-form" class="ant-form ant-form-vertical" action="{{ route('backend.customers.quick-store') }}" novalidate>
                <div class="ant-row ant-form-item" data-required="Nhập tên khách hàng.">
                  <div class="ant-col ant-form-item-label"><label for="qc_name" class="ant-form-item-required" title="Tên khách hàng">Tên khách hàng</label></div>
                  <div class="ant-col ant-form-item-control">
                    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content"><input type="text" id="qc_name" name="name" class="ant-input" placeholder="Nguyễn Văn A" maxlength="255" /></div></div>
                  </div>
                </div>
                <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                  <div class="ant-row ant-form-item">
                    <div class="ant-col ant-form-item-label"><label for="qc_phone" title="Điện thoại">Điện thoại</label></div>
                    <div class="ant-col ant-form-item-control">
                      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content"><input type="tel" id="qc_phone" name="phone" class="ant-input" maxlength="50" /></div></div>
                    </div>
                  </div>
                  <div class="ant-row ant-form-item" data-type="email" data-type-message="Email chưa đúng định dạng.">
                    <div class="ant-col ant-form-item-label"><label for="qc_email" title="Email">Email</label></div>
                    <div class="ant-col ant-form-item-control">
                      <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content"><input type="email" id="qc_email" name="email" class="ant-input" maxlength="191" /></div></div>
                    </div>
                  </div>
                </div>
                <div class="ant-row ant-form-item mb-0">
                  <div class="ant-col ant-form-item-label"><label for="qc_company" title="Công ty">Công ty</label></div>
                  <div class="ant-col ant-form-item-control">
                    <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content"><input type="text" id="qc_company" name="company" class="ant-input" maxlength="255" /></div></div>
                  </div>
                </div>
              </form>
            </div>
            <div class="ant-modal-footer">
              <button type="button" class="ant-btn" data-modal-close><span>Hủy</span></button>
              <button type="button" class="ant-btn ant-btn-primary modal-ok"><span>Tạo và chọn</span></button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>
@endcan
@endsection
