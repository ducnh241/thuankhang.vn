@extends('layouts.backend')

@section('title', 'Cấp bậc - Cấu hình affiliate')
@section('inline-errors', '1')

{{-- A2 – Cấp bậc (đặc tả 6.2): một bảng sửa tại chỗ, lưu cả bảng. Cấp gốc (mốc 0) cố định mốc, luôn bật. Cấp đã có
     không xóa — chỉ tắt; dòng mới thêm (chưa lưu) bỏ được. JS: repeater.js (thêm / bỏ dòng), edit-form.js, forms.js (ô tiền). --}}
@php
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    // Lưu lỗi: dựng lại bảng từ dữ liệu vừa gửi
    $rows = is_array(old('levels'))
        ? collect(old('levels'))->filter(fn ($row) => is_array($row))->map(fn ($row) => [
            'id' => $row['id'] ?? null, 'name' => (string) ($row['name'] ?? ''), 'sort_order' => (string) ($row['sort_order'] ?? ''),
            'threshold_amount' => is_numeric($row['threshold_amount'] ?? null) ? Money::format($row['threshold_amount']) : (string) ($row['threshold_amount'] ?? ''),
            'is_vp' => ! empty($row['is_vp']), 'active' => ! empty($row['active']),
        ])
        : $levels->mapWithKeys(fn ($level) => [$level->id => [
            'id' => $level->id, 'name' => $level->name, 'sort_order' => (string) $level->sort_order,
            'threshold_amount' => Money::format($level->threshold_amount), 'is_vp' => $level->is_vp, 'active' => $level->active,
        ]]);
    // Ô trong bảng sửa tại chỗ = Form.Item không lề (như editable table của Ant Design), lỗi hiện ngay dưới ô
    $item = fn (string $key, $control, string $class = '') => new \Illuminate\Support\HtmlString(
        '<div class="ant-form-item mb-0'.($errors->has($key) ? ' ant-form-item-has-error ant-form-item-with-help' : '').($class ? ' '.$class : '').'">'
        .'<div class="ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">'.$control.'</div></div>'
        .ant_form_error($errors->first($key)).'</div></div>');
    $check = fn (string $name, bool $checked, bool $disabled = false, string $label = '') =>
        '<label class="ant-checkbox-wrapper'.($checked ? ' ant-checkbox-wrapper-checked' : '').($disabled ? ' ant-checkbox-wrapper-disabled' : '').'">'
        .'<span class="ant-checkbox'.($checked ? ' ant-checkbox-checked' : '').($disabled ? ' ant-checkbox-disabled' : '').'">'
        .'<input type="checkbox" class="ant-checkbox-input" name="'.e($name).'" value="1"'.($checked ? ' checked' : '').($disabled ? ' disabled' : '').' aria-label="'.e($label).'" />'
        .'<span class="ant-checkbox-inner"></span></span></label>';
    $nextOrder = ($levels->max('sort_order') ?? 0) + 1;
@endphp

@section('topbar')
  @include('backend.affiliate._topbar', ['title' => 'Cấp bậc', 'form' => 'level-form'])
@endsection

@section('content')
<form method="POST" id="level-form" action="{{ route('backend.affiliate.levels.update') }}" class="ant-form edit-form mx-auto max-w-[1040px]"
  novalidate autocomplete="off" @if ($canEdit) data-edit-form @else data-readonly @endif @if ($errors->any()) data-dirty @endif>
  @csrf
  @method('PUT')
  @include('backend.affiliate._readonly')

  <div class="ant-card ant-card-bordered" data-repeater @unless ($canEdit) inert @endunless>
    <div class="ant-card-head">
      <div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">
          Cấp bậc thành viên
          <div class="text-xs font-normal {{ $muted }}">Lên cấp tự động khi doanh số cá nhân cộng dồn (tiền hàng tự mua) đạt mốc; không bao giờ hạ cấp. Mốc cấp sau phải lớn hơn cấp trước.</div>
        </div>
        @if ($canEdit)
          <div class="ant-card-extra"><button type="button" class="ant-btn ant-btn-dashed" data-repeater-add>{{ ant_icon('plus') }}<span>Thêm cấp</span></button></div>
        @endif
      </div>
    </div>
    <div class="ant-table ant-table-small">
      <div class="ant-table-container">
        <div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto" id="level-table">
            <thead class="ant-table-thead">
              <tr>
                <th class="ant-table-cell" style="width: 90px">Thứ tự</th>
                <th class="ant-table-cell">Tên cấp</th>
                <th class="ant-table-cell" style="width: 210px">Mốc doanh số cộng dồn (đ)</th>
                <th class="ant-table-cell" style="width: 90px; text-align: center">VP tỉnh</th>
                <th class="ant-table-cell" style="width: 70px; text-align: center">Bật</th>
                <th class="ant-table-cell" style="width: 48px"></th>
              </tr>
            </thead>
            <tbody class="ant-table-tbody" data-repeater-rows>
              @foreach ($rows as $i => $row)
                @php $base = $row['id'] && $levels->firstWhere('id', (int) $row['id'])?->isBase(); $p = "levels[{$i}]"; $k = "levels.{$i}"; @endphp
                <tr class="ant-table-row" data-repeater-row data-level="{{ $row['id'] ?? 'new' }}">
                  <td class="ant-table-cell align-top">
                    @if ($row['id'])<input type="hidden" name="{{ $p }}[id]" value="{{ $row['id'] }}" />@endif
                    {{ $item("{$k}.sort_order", ant_input_number("{$p}[sort_order]", $row['sort_order'], ['min' => 0, 'max' => 999, 'size' => 'sm', 'label' => 'Thứ tự', 'class' => 'w-full'])) }}
                  </td>
                  <td class="ant-table-cell align-top">
                    {{ $item("{$k}.name", '<input type="text" name="'.e("{$p}[name]").'" value="'.e($row['name']).'" maxlength="100" class="ant-input ant-input-sm" aria-label="Tên cấp" />') }}
                    @if ($base)<div class="mt-1 flex items-center gap-1 text-xs {{ $muted }}"><span class="ant-tag ant-tag-blue m-0">Cấp gốc</span>Đã đăng ký, chưa có đơn thanh toán — không nhận hoa hồng.</div>@endif
                  </td>
                  <td class="ant-table-cell align-top">
                    {{ $item("{$k}.threshold_amount", '<input type="text" inputmode="numeric" name="'.e("{$p}[threshold_amount]").'" value="'.e($row['threshold_amount']).'" class="ant-input ant-input-sm tabular-nums" style="text-align: right" data-money aria-label="Mốc doanh số"'.($base ? ' readonly' : '').' />') }}
                  </td>
                  <td class="ant-table-cell align-top" style="text-align: center">
                    {{ $item("{$k}.is_vp", $check("{$p}[is_vp]", $row['is_vp'], (bool) $base, 'Là cấp VP tỉnh')) }}
                  </td>
                  <td class="ant-table-cell align-top" style="text-align: center">
                    @if ($base)<input type="hidden" name="{{ $p }}[active]" value="1" />@endif
                    {{ $item("{$k}.active", $check("{$p}[active]", $row['active'], (bool) $base, 'Bật cấp')) }}
                  </td>
                  <td class="ant-table-cell align-top" style="text-align: right">
                    @unless ($row['id'])
                      <button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only ant-btn-dangerous" data-repeater-remove data-tooltip="Bỏ dòng" aria-label="Bỏ dòng">{{ ant_icon('delete') }}</button>
                    @endunless
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="ant-card-body">
      @if ($errors->has('levels'))
        <div class="ant-alert ant-alert-error mb-3" role="alert">{{ ant_icon('close-circle', 'ant-alert-icon') }}<div class="ant-alert-content"><div class="ant-alert-message">{{ $errors->first('levels') }}</div></div></div>
      @endif
      <div class="ant-typography ant-typography-secondary text-xs">Cấp đã lưu không xóa được (thành viên và lịch sử còn tham chiếu) — tắt cấp để ngừng dùng. Đổi mốc không làm hạ cấp ai; hạ mốc thì thành viên đủ điều kiện được nâng ở đơn tiếp theo.</div>
    </div>
    <template data-repeater-template>
      <tr class="ant-table-row" data-repeater-row data-level="new">
        <td class="ant-table-cell align-top">{{ $item('-', ant_input_number('levels[__INDEX__][sort_order]', $nextOrder, ['min' => 0, 'max' => 999, 'size' => 'sm', 'label' => 'Thứ tự', 'class' => 'w-full'])) }}</td>
        <td class="ant-table-cell align-top">{{ $item('-', '<input type="text" name="levels[__INDEX__][name]" value="" maxlength="100" class="ant-input ant-input-sm" placeholder="VD: Tổng đại lý" aria-label="Tên cấp" />') }}</td>
        <td class="ant-table-cell align-top">{{ $item('-', '<input type="text" inputmode="numeric" name="levels[__INDEX__][threshold_amount]" value="" class="ant-input ant-input-sm tabular-nums" style="text-align: right" data-money placeholder="0" aria-label="Mốc doanh số" />') }}</td>
        <td class="ant-table-cell align-top" style="text-align: center">{{ $item('-', $check('levels[__INDEX__][is_vp]', false, false, 'Là cấp VP tỉnh')) }}</td>
        <td class="ant-table-cell align-top" style="text-align: center">{{ $item('-', $check('levels[__INDEX__][active]', true, false, 'Bật cấp')) }}</td>
        <td class="ant-table-cell align-top" style="text-align: right"><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only ant-btn-dangerous" data-repeater-remove aria-label="Bỏ dòng">{{ ant_icon('delete') }}</button></td>
      </tr>
    </template>
  </div>
</form>
@endsection
