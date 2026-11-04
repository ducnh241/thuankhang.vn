@extends('layouts.backend')

{{-- Thêm / sửa điểm nhận hàng (A7): thông tin (tên, tỉnh, địa chỉ, SĐT, giờ mở cửa) + cột phụ (bật / tắt, tài khoản VP
     sở hữu, thứ tự). JS: edit-form.js (thay đổi chưa lưu), select.js (ô chọn có tìm). --}}
@php
    use App\Models\PickupPoint;

    $editing = $point->exists;
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $old = fn (string $field) => session()->hasOldInput() ? (is_string(old($field)) ? old($field) : '') : (string) $point->{$field};
    $active = session()->hasOldInput() ? (string) old('active') === '1' : $point->active !== false;
    $province = $old('province');
    $ownerId = $old('owner_member_id');
    $field = function (string $name, string $label, string $placeholder = '', bool $required = false, int $max = 150) use ($hasError, $old) {
        return new \Illuminate\Support\HtmlString(
            '<div class="ant-row ant-form-item'.$hasError($name).'"'.($required ? ' data-required="Nhập '.e(mb_strtolower($label)).'."' : '').'>'
            .'<div class="ant-col ant-form-item-label"><label for="pp_'.$name.'"'.($required ? ' class="ant-form-item-required"' : '').' title="'.e($label).'">'.e($label).'</label></div>'
            .'<div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">'
            .'<input type="text" id="pp_'.$name.'" name="'.$name.'" value="'.e($old($name)).'" class="ant-input" maxlength="'.$max.'" placeholder="'.e($placeholder).'" />'
            .'</div></div>'.ant_form_error(session('errors')?->first($name)).'</div></div>');
    };
@endphp

@section('title', $editing ? $point->name : 'Thêm điểm nhận hàng')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.pickup-points.index') }}" class="page-topbar-crumb" title="Về danh sách điểm nhận hàng">Điểm nhận hàng</a>
    <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
    <h1 class="page-topbar-title">{{ $editing ? $point->name : 'Thêm điểm nhận hàng' }}</h1>
    @if ($editing && ! $point->active)<span class="ant-tag">Đang tắt</span>@endif
  </div>
  <div class="save-bar hidden" id="save-bar" role="region" aria-label="Thay đổi chưa lưu">
    <span class="save-bar-text">{{ ant_icon('exclamation-circle') }}Chưa lưu thay đổi</span>
    <button type="button" class="save-bar-discard" id="discard-changes">Hủy thay đổi</button>
    <button type="submit" form="pickup-form" class="save-bar-save">{{ $editing ? 'Lưu' : 'Thêm điểm' }}</button>
  </div>
  <div class="page-topbar-right">
    @if ($editing)
      @can('pickup_point/delete')
        <button type="button" class="ant-btn ant-btn-dangerous" aria-label="Xóa điểm nhận hàng"
          data-delete-url="{{ route('backend.pickup-points.destroy', $point) }}" data-delete-title="Xóa điểm nhận hàng “{{ $point->name }}”?" data-delete-content="Điểm không còn hiện ở checkout; đơn hàng cũ vẫn giữ điểm đã chọn.">{{ ant_icon('delete') }}<span class="max-sm:hidden">Xóa</span></button>
      @endcan
    @else
      <a href="{{ route('backend.pickup-points.index') }}" class="ant-btn" data-hide-when-dirty>Hủy</a>
      <button type="submit" form="pickup-form" class="ant-btn ant-btn-primary" data-hide-when-dirty>{{ ant_icon('plus') }}<span>Thêm điểm</span></button>
    @endif
  </div>
@endsection

@section('content')
<form method="POST" id="pickup-form" action="{{ $editing ? route('backend.pickup-points.update', $point) : route('backend.pickup-points.store') }}"
  class="ant-form ant-form-vertical edit-form mx-auto max-w-[1100px]" novalidate autocomplete="off" data-edit-form @if ($errors->any()) data-dirty @endif>
  @csrf
  @if ($editing) @method('PUT') @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin điểm nhận hàng</div></div></div>
        <div class="ant-card-body">
          {{ $field('name', 'Tên điểm', 'VD: VP Thuận Khang – Hà Nội', true) }}
          <div class="ant-row ant-form-item{{ $hasError('province') }}" data-required="Chọn tỉnh / thành.">
            <div class="ant-col ant-form-item-label"><label for="pp_province" class="ant-form-item-required" title="Tỉnh / thành">Tỉnh / thành</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-show-search data-placeholder="Chọn tỉnh / thành" id="pp_province" name="province" class="w-full">
                  <option value=""></option>
                  @foreach (PickupPoint::provinces() as $key => $name)
                    <option value="{{ $key }}" @selected($province === $key)>{{ $name }}</option>
                  @endforeach
                </select>
              </div></div>
              {{ ant_form_error($errors->first('province')) }}
              <div class="ant-form-item-extra">Khách chọn tỉnh trước, rồi chọn điểm. {{ $max ? "Mỗi tỉnh tối đa {$max} điểm đang bật." : '' }}</div>
            </div>
          </div>
          {{ $field('address', 'Địa chỉ', 'Số nhà, đường, phường / xã', true, 300) }}
          <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            {{ $field('phone', 'Số điện thoại', 'VD: 024 3xxx xxxx', false, 30) }}
            {{ $field('opening_hours', 'Giờ mở cửa', 'VD: 8:00 – 17:30, Thứ 2 – Thứ 7') }}
          </div>
        </div>
      </div>
    </div>

    <div class="flex min-w-0 flex-col gap-4">
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item mb-0{{ $hasError('active') }}">
            <label class="flex items-start gap-3" for="pp_active_switch">
              <button type="button" role="switch" id="pp_active_switch" aria-checked="{{ $active ? 'true' : 'false' }}" @class(['ant-switch mt-0.5 flex-none', 'ant-switch-checked' => $active])><div class="ant-switch-handle"></div><span class="ant-switch-inner"></span></button>
              <input type="hidden" name="active" value="0" />
              <input type="checkbox" name="active" value="1" class="switch-input" hidden @checked($active) />
              <span><span class="block text-[rgba(0,0,0,0.85)]">Hiện ở checkout</span><span class="block {{ $muted }}">Tắt khi điểm tạm ngưng nhận hàng. Đơn đã đặt vẫn giữ điểm đã chọn.</span></span>
            </label>
            {{ ant_form_error($errors->first('active')) }}
          </div>
        </div>
      </div>

      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tài khoản VP</div></div></div>
        <div class="ant-card-body">
          <div class="ant-row ant-form-item{{ $hasError('owner_member_id') }}">
            <div class="ant-col ant-form-item-label"><label for="pp_owner" title="Thành viên sở hữu">Thành viên sở hữu</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <select data-antd data-show-search data-allow-clear data-placeholder="Chưa gán" id="pp_owner" name="owner_member_id" class="w-full">
                  <option value=""></option>
                  @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}" @selected($ownerId === (string) $owner->id)>{{ $owner->full_name }} · {{ $owner->phone }} · {{ $owner->level?->name }}</option>
                  @endforeach
                </select>
              </div></div>
              {{ ant_form_error($errors->first('owner_member_id')) }}
              <div class="ant-form-item-extra">Chỉ thành viên đang ở cấp VP tỉnh, mỗi người làm chủ tối đa một điểm.@if ($owners->isEmpty()) Hiện chưa có thành viên nào ở cấp VP.@endif</div>
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0{{ $hasError('sort_order') }}">
            <div class="ant-col ant-form-item-label"><label for="pp_sort" title="Thứ tự">Thứ tự hiển thị</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                {{ ant_input_number('sort_order', $old('sort_order') ?: '0', ['id' => 'pp_sort', 'min' => -9999, 'max' => 9999, 'class' => 'w-full']) }}
              </div></div>
              {{ ant_form_error($errors->first('sort_order')) }}
              <div class="ant-form-item-extra">Số nhỏ đứng trước trong cùng tỉnh.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
