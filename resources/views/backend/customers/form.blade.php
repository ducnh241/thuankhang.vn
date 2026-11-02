@extends('layouts.backend')

{{-- Thêm / sửa khách hàng. Template ProCMS không có trang này: dựng như form dự án (project-edit.html) — thanh trên cùng có
     tên đã lưu + dải "Chưa lưu thay đổi"; cột chính (Thông tin, Dự án của khách) + cột phụ (Người giới thiệu, Tổng
     quan, Đã giới thiệu). Form gửi thẳng lên server, lỗi hiện dưới từng ô. JS: resources/admin/js/edit-form.js --}}
@php
    use App\Models\Project;
    use App\Support\Money;

    $editing = $customer->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $user = auth()->user();
    $count = $editing ? ($projectCounts[$customer->id] ?? ['direct' => 0, 'total' => 0]) : null;
    $canSeeProjects = $user->can('project');
    $canAddProject = $canSeeProjects && $user->can('project/add');
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $error = fn (string $field) => $errors->has($field)
        ? '<div class="ant-form-item-explain ant-form-item-explain-error"><div role="alert">'.e($errors->first($field)).'</div></div>' : '';
    $paid = $editing ? (float) $customer->projects->sum('paid') : 0.0;
@endphp

@section('title', $editing ? $customer->name : 'Thêm khách hàng')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.customers.index') }}" class="page-topbar-crumb" title="Về danh sách khách hàng">Khách hàng</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $customer->name : 'Thêm khách hàng' }}</h1>
    @if ($editing)<span class="page-topbar-sub">#{{ $customer->id }}</span>@endif
  </div>
  {{-- Hiện khi form có thay đổi chưa lưu --}}
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="customer-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Tạo khách hàng' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('customer/delete')
        @if ($customer->projects->isNotEmpty())
          <span data-tooltip="Còn {{ $customer->projects->count() }} dự án, không xóa được (chuyển hoặc xóa dự án trước)"><button type="button" class="ant-btn ant-btn-dangerous" disabled>{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button></span>
        @else
          <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa khách hàng"
            data-delete-url="{{ route('backend.customers.destroy', $customer) }}" data-delete-title="Xóa khách hàng “{{ $customer->name }}”?">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
        @endif
      @endcan
      @if ($canAddProject)
        <a href="{{ route('backend.projects.create', ['customer_id' => $customer->id]) }}" class="ant-btn" aria-label="Thêm dự án cho khách này">{{ ant_icon('plus') }}<span class="max-sm:hidden">Dự án mới</span></a>
      @endif
    @else
      {{-- Khi đã nhập gì đó, dải "Chưa lưu thay đổi" có nút Tạo khách hàng → ẩn cặp nút này cho khỏi trùng --}}
      <a href="{{ route('backend.customers.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="customer-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Tạo khách hàng</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="customer-form" action="{{ $editing ? route('backend.customers.update', $customer) : route('backend.customers.store') }}"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cột chính --}}
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin</div></div></div>
        <div class="ant-card-body">
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('name') }}" data-required="Nhập tên khách hàng.">
              <div class="ant-col ant-form-item-label"><label for="name" class="ant-form-item-required" title="Tên khách hàng">Tên khách hàng</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="name" name="name" value="{{ old('name', $customer->name) }}" class="ant-input" placeholder="Nguyễn Văn A" maxlength="255" @unless ($editing) autofocus @endunless />
                </div></div>
                {!! $error('name') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('company') }}">
              <div class="ant-col ant-form-item-label"><label for="company" title="Công ty">Công ty</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="company" name="company" value="{{ old('company', $customer->company) }}" class="ant-input" maxlength="255" />
                </div></div>
                {!! $error('company') !!}
              </div>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            <div class="ant-row ant-form-item{{ $hasError('phone') }}">
              <div class="ant-col ant-form-item-label"><label for="phone" title="Điện thoại">Điện thoại</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="tel" id="phone" name="phone" value="{{ old('phone', $customer->phone) }}" class="ant-input" placeholder="09xx xxx xxx" maxlength="50" />
                </div></div>
                {!! $error('phone') !!}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ $hasError('email') }}" data-type="email" data-type-message="Email chưa đúng định dạng.">
              <div class="ant-col ant-form-item-label"><label for="email" title="Email">Email</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="email" id="email" name="email" value="{{ old('email', $customer->email) }}" class="ant-input" placeholder="ten@example.com" maxlength="191" />
                </div></div>
                {!! $error('email') !!}
              </div>
            </div>
          </div>
          <div class="ant-row ant-form-item{{ $hasError('address') }}">
            <div class="ant-col ant-form-item-label"><label for="address" title="Địa chỉ">Địa chỉ</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <input type="text" id="address" name="address" value="{{ old('address', $customer->address) }}" class="ant-input" maxlength="500" />
              </div></div>
              {!! $error('address') !!}
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('note') }}">
            <div class="ant-col ant-form-item-label"><label for="note" title="Ghi chú">Ghi chú</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="note" name="note" class="ant-input" rows="3">{{ old('note', $customer->note) }}</textarea>
              </div></div>
              {!! $error('note') !!}
            </div>
          </div>
        </div>
      </div>

      @if ($editing)
        {{-- Dự án của khách hàng --}}
        <div class="ant-card ant-card-bordered" data-card="projects">
          <div class="ant-card-head">
            <div class="ant-card-head-wrapper">
              <div class="ant-card-head-title">Dự án <span class="{{ $muted }} font-normal">({{ $customer->projects->count() }})</span></div>
              @if ($canAddProject)
                <div class="ant-card-extra"><a href="{{ route('backend.projects.create', ['customer_id' => $customer->id]) }}" class="ant-btn ant-btn-sm">{{ ant_icon('plus') }}<span>Dự án mới</span></a></div>
              @endif
            </div>
          </div>
          @if ($customer->projects->isEmpty())
            <div class="ant-empty ant-empty-normal my-6"><p class="ant-empty-description">Khách hàng chưa có dự án nào.</p></div>
          @else
            <div class="ant-table-wrapper">
              <div class="ant-table ant-table-small">
                <div class="ant-table-container">
                  <div class="ant-table-content overflow-x-auto">
                    <table style="table-layout: auto">
                      <thead class="ant-table-thead">
                        <tr>
                          <th class="ant-table-cell">Dự án</th>
                          <th class="ant-table-cell whitespace-nowrap max-sm:hidden">Trạng thái</th>
                          <th class="ant-table-cell whitespace-nowrap max-sm:hidden" style="text-align: right">Giá trị</th>
                          <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Đã thu</th>
                        </tr>
                      </thead>
                      <tbody class="ant-table-tbody">
                        @foreach ($customer->projects as $project)
                          <tr class="ant-table-row" data-project-row="{{ $project->id }}">
                            <td class="ant-table-cell">
                              @if ($canSeeProjects)
                                <a href="{{ route('backend.projects.show', $project) }}" class="font-medium wrap-anywhere">{{ $project->name }}</a>
                              @else
                                <span class="font-medium wrap-anywhere">{{ $project->name }}</span>
                              @endif
                              <div class="text-xs {{ $muted }}">#{{ $project->id }}{{ $project->category ? ' · '.$project->category->name : '' }}</div>
                              {{-- Điện thoại: cột trạng thái, giá trị ẩn, ghi dưới tên --}}
                              <div class="mt-1 flex flex-wrap items-center gap-x-2 sm:hidden"><span class="ant-tag m-0 {{ Project::STATUS_TAGS[$project->status] ?? '' }}">{{ $project->statusLabel() }}</span><span class="text-xs tabular-nums {{ $muted }}">Giá trị {{ Money::format($project->price) }}</span></div>
                            </td>
                            <td class="ant-table-cell whitespace-nowrap max-sm:hidden"><span class="ant-tag m-0 {{ Project::STATUS_TAGS[$project->status] ?? '' }}">{{ $project->statusLabel() }}</span></td>
                            <td class="ant-table-cell whitespace-nowrap tabular-nums max-sm:hidden" style="text-align: right">{{ Money::format($project->price) }}</td>
                            <td class="ant-table-cell whitespace-nowrap tabular-nums text-[#52c41a]" style="text-align: right">{{ Money::format($project->paid ?? 0) }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                      <tfoot class="ant-table-summary">
                        <tr>
                          <td class="ant-table-cell font-medium">Tổng</td>
                          <td class="ant-table-cell max-sm:hidden"></td>
                          <td class="ant-table-cell whitespace-nowrap font-medium tabular-nums max-sm:hidden" style="text-align: right">{{ Money::format($customer->projects->sum('price')) }}</td>
                          <td class="ant-table-cell whitespace-nowrap font-medium tabular-nums text-[#52c41a]" style="text-align: right">{{ Money::format($paid) }}</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          @endif
        </div>
      @endif
    </div>

    {{-- Cột phụ --}}
    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Người giới thiệu</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('referrer_customer_id') }}">
            <div class="ant-col ant-form-item-label sr-only"><label for="referrer_customer_id" title="Người giới thiệu">Người giới thiệu</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-show-search data-allow-clear id="referrer_customer_id" name="referrer_customer_id" class="w-full" data-placeholder="Không có — gõ để tìm">
                  <option value=""></option>
                  @foreach ($referrers as $referrer)
                    <option value="{{ $referrer->id }}" @selected((int) old('referrer_customer_id', $customer->referrer_customer_id) === $referrer->id)>{{ $referrer->displayName() }} ({{ $projectCounts[$referrer->id]['total'] ?? 0 }} dự án){{ $referrer->deleted_at ? ' — đã xóa' : '' }}</option>
                  @endforeach
                </select>
              </div></div>
              {!! $error('referrer_customer_id') !!}
              <div class="ant-form-item-extra">Ai đã giới thiệu khách hàng này. Không chọn được những người do chính khách hàng này giới thiệu.</div>
              @if ($editing && $customer->referrer?->trashed())
                <div class="ant-form-item-extra text-[#f5222d]">Người giới thiệu hiện tại ({{ $customer->referrer->name }}) đã bị xóa.</div>
              @endif
            </div>
          </div>
        </div>
      </div>

      @if ($editing)
        <div class="ant-card ant-card-bordered" data-card="overview">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tổng quan</div></div></div>
          <div class="ant-card-body">
            <div class="flex flex-col gap-2">
              <div class="flex justify-between gap-2"><span class="{{ $muted }}">Dự án trực tiếp</span><span class="tabular-nums">{{ $count['direct'] }}</span></div>
              <div class="flex justify-between gap-2"><span class="{{ $muted }}" title="Dự án của những khách hàng được người này giới thiệu (cả nhiều cấp)">Dự án qua giới thiệu</span><span class="tabular-nums">{{ $count['total'] - $count['direct'] }}</span></div>
              <div class="flex justify-between gap-2 border-t border-[#f0f0f0] pt-2"><span class="{{ $muted }}">Đã thu</span><span class="font-medium tabular-nums text-[#52c41a]">{{ Money::format($paid) }}</span></div>
              <div class="flex justify-between gap-2"><span class="{{ $muted }}">Ngày tạo</span><span class="tabular-nums" @if ($customer->created_at) title="{{ $customer->created_at->format('d/m/Y H:i') }}" @endif>{{ $customer->created_at?->format('d/m/Y') ?? '—' }}</span></div>
            </div>
          </div>
        </div>

        <div class="ant-card ant-card-bordered" data-card="referrals">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Đã giới thiệu <span class="{{ $muted }} font-normal">({{ $customer->referrals->count() }})</span></div></div></div>
          {{-- Người giới thiệu nhiều khách (vd 60+): danh sách cuộn trong khung, không kéo dài cả trang --}}
          <div class="ant-card-body max-h-[420px] overflow-y-auto">
            @forelse ($customer->referrals as $referral)
              <a href="{{ route('backend.customers.edit', $referral) }}" @class(['flex items-center gap-3 py-2', 'border-t border-[#f0f0f0]' => ! $loop->first, 'pt-0' => $loop->first, 'pb-0' => $loop->last])>
                <span class="min-w-0 flex-1 truncate text-[rgba(0,0,0,0.85)]">{{ $referral->name }}</span>
                <span class="shrink-0 text-xs tabular-nums {{ $muted }}">{{ $referral->projects_count }} dự án</span>
              </a>
            @empty
              <p class="m-0 {{ $muted }}">Chưa giới thiệu ai.</p>
            @endforelse
          </div>
        </div>
      @endif
    </div>
  </div>
</form>
@endsection
