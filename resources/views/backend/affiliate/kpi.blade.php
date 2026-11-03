@extends('layouts.backend')

@section('title', 'KPI - Cấu hình affiliate')
@section('inline-errors', '1')

{{-- A4 – Cấu hình KPI tháng (đặc tả 2.6 / 6.4): chỉ lưu tham số, Phase 2 mới tính thưởng. Bảng bậc thêm / bỏ dòng tự do
     (repeater.js), xếp theo "từ" khi lưu. --}}
@php
    use App\Support\Money;
    use App\Support\Percent;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $money = fn ($v) => is_numeric($v) ? Money::format($v) : (string) ($v ?? '');
    $rows = is_array(old('tiers'))
        ? collect(old('tiers'))->filter(fn ($row) => is_array($row))->map(fn ($row) => [
            'min_amount' => $money($row['min_amount'] ?? ''), 'max_amount' => $money($row['max_amount'] ?? ''),
            'rate_percent' => (string) ($row['rate_percent'] ?? ''), 'min_f1' => (string) ($row['min_f1'] ?? ''),
        ])
        : (session()->hasOldInput() ? collect() : $tiers->map(fn ($tier) => [
            'min_amount' => Money::format($tier->min_amount), 'max_amount' => $tier->max_amount === null ? '' : Money::format($tier->max_amount),
            'rate_percent' => Percent::format($tier->rate_percent), 'min_f1' => (string) $tier->min_f1,
        ]));
    // Ô trong bảng = Form.Item không lề (editable table của Ant Design), lỗi hiện ngay dưới ô
    $item = fn (string $key, $control) => new \Illuminate\Support\HtmlString(
        '<div class="ant-form-item mb-0'.($errors->has($key) ? ' ant-form-item-has-error ant-form-item-with-help' : '').'">'
        .'<div class="ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">'.$control.'</div></div>'
        .ant_form_error($errors->first($key)).'</div></div>');
    $money = fn (string $name, string $value, string $label, string $placeholder = '') => '<input type="text" inputmode="numeric" data-money name="'.e($name).'" value="'.e($value)
        .'" class="ant-input ant-input-sm tabular-nums" style="text-align: right" aria-label="'.e($label).'"'.($placeholder ? ' placeholder="'.e($placeholder).'"' : '').' />';
    $percent = fn (string $name, string $value) => '<span class="ant-input-affix-wrapper ant-input-affix-wrapper-sm"><input type="text" inputmode="decimal" name="'.e($name).'" value="'.e($value)
        .'" class="ant-input tabular-nums" style="text-align: right" aria-label="Thưởng (%)" /><span class="ant-input-suffix">%</span></span>';
    $f1 = fn (string $name, string $value) => ant_input_number($name, $value, ['min' => 0, 'max' => 10000, 'size' => 'sm', 'label' => 'Số F1 tối thiểu', 'class' => 'w-full']);
    $payDay = is_string(old('kpi_pay_day')) ? old('kpi_pay_day') : (string) $payDay;
@endphp

@section('topbar')
  @include('backend.affiliate._topbar', ['title' => 'KPI tháng', 'form' => 'kpi-form'])
@endsection

@section('content')
<form method="POST" id="kpi-form" action="{{ route('backend.affiliate.kpi.update') }}" class="ant-form ant-form-vertical edit-form mx-auto flex max-w-[1040px] flex-col gap-4"
  novalidate autocomplete="off" @if ($canEdit) data-edit-form @else data-readonly @endif @if ($errors->any()) data-dirty @endif>
  @csrf
  @method('PUT')
  <div class="ant-alert ant-alert-warning ant-alert-with-description" role="alert">
    {{ ant_icon('exclamation-circle', 'ant-alert-icon') }}
    <div class="ant-alert-content"><div class="ant-alert-message">Chưa áp dụng – Phase 2</div>
      <div class="ant-alert-description">Chỉ lưu tham số. Cách xác định "doanh số nhóm" (mặt trời + nhị phân) đang chờ khách giải thích nên hệ thống chưa tính thưởng KPI.</div></div>
  </div>
  @include('backend.affiliate._readonly')

  <div class="ant-card ant-card-bordered" data-repeater @unless ($canEdit) inert @endunless>
    <div class="ant-card-head">
      <div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">
          Bậc thưởng theo doanh số nhóm trong tháng
          <div class="text-xs font-normal {{ $muted }}">Thưởng tính trên toàn bộ doanh số. Để trống "đến" ở bậc cao nhất = không giới hạn.</div>
        </div>
        @if ($canEdit)
          <div class="ant-card-extra"><button type="button" class="ant-btn ant-btn-dashed" data-repeater-add>{{ ant_icon('plus') }}<span>Thêm bậc</span></button></div>
        @endif
      </div>
    </div>
    <div class="ant-table ant-table-small">
      <div class="ant-table-container">
        <div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto" id="kpi-table">
            <thead class="ant-table-thead">
              <tr>
                <th class="ant-table-cell">Doanh số từ (đ)</th>
                <th class="ant-table-cell">Đến (đ)</th>
                <th class="ant-table-cell" style="width: 140px">Thưởng</th>
                <th class="ant-table-cell" style="width: 140px">Số F1 tối thiểu</th>
                <th class="ant-table-cell" style="width: 48px"></th>
              </tr>
            </thead>
            <tbody class="ant-table-tbody" data-repeater-rows>
              @foreach ($rows as $i => $row)
                <tr class="ant-table-row" data-repeater-row>
                  <td class="ant-table-cell align-top">{{ $item("tiers.{$i}.min_amount", $money("tiers[{$i}][min_amount]", $row['min_amount'], 'Doanh số từ')) }}</td>
                  <td class="ant-table-cell align-top">{{ $item("tiers.{$i}.max_amount", $money("tiers[{$i}][max_amount]", $row['max_amount'], 'Doanh số đến', 'Không giới hạn')) }}</td>
                  <td class="ant-table-cell align-top">{{ $item("tiers.{$i}.rate_percent", $percent("tiers[{$i}][rate_percent]", $row['rate_percent'])) }}</td>
                  <td class="ant-table-cell align-top">{{ $item("tiers.{$i}.min_f1", $f1("tiers[{$i}][min_f1]", $row['min_f1'])) }}</td>
                  <td class="ant-table-cell align-top" style="text-align: right"><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only ant-btn-dangerous" data-repeater-remove data-tooltip="Bỏ bậc" aria-label="Bỏ bậc">{{ ant_icon('delete') }}</button></td>
                </tr>
              @endforeach
            </tbody>
          </table>
          <div data-repeater-empty @class(['ant-empty ant-empty-normal ant-empty-small py-6', 'hidden' => $rows->isNotEmpty()])><div class="ant-empty-image">{{ ant_icon('inbox', '', 'font-size: 32px; color: rgba(0, 0, 0, 0.25)') }}</div><p class="ant-empty-description">Chưa có bậc nào.</p></div>
        </div>
      </div>
    </div>
    <template data-repeater-template>
      <tr class="ant-table-row" data-repeater-row>
        <td class="ant-table-cell align-top">{{ $item('-', $money('tiers[__INDEX__][min_amount]', '', 'Doanh số từ')) }}</td>
        <td class="ant-table-cell align-top">{{ $item('-', $money('tiers[__INDEX__][max_amount]', '', 'Doanh số đến', 'Không giới hạn')) }}</td>
        <td class="ant-table-cell align-top">{{ $item('-', $percent('tiers[__INDEX__][rate_percent]', '')) }}</td>
        <td class="ant-table-cell align-top">{{ $item('-', $f1('tiers[__INDEX__][min_f1]', '5')) }}</td>
        <td class="ant-table-cell align-top" style="text-align: right"><button type="button" class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only ant-btn-dangerous" data-repeater-remove aria-label="Bỏ bậc">{{ ant_icon('delete') }}</button></td>
      </tr>
    </template>
  </div>

  <div class="ant-card ant-card-bordered" @unless ($canEdit) inert @endunless>
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Chi trả</div></div></div>
    <div class="ant-card-body">
      <div class="ant-row ant-form-item mb-0 max-w-[260px]{{ $errors->has('kpi_pay_day') ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}">
        <div class="ant-col ant-form-item-label"><label for="kpi_pay_day" class="ant-form-item-required" title="Ngày trả thưởng">Ngày trả thưởng hằng tháng</label></div>
        <div class="ant-col ant-form-item-control">
          <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
            {{ ant_input_number('kpi_pay_day', $payDay, ['id' => 'kpi_pay_day', 'min' => 1, 'max' => 28, 'class' => 'w-full']) }}
          </div></div>
          {{ ant_form_error($errors->first('kpi_pay_day')) }}
          <div class="ant-form-item-extra">Chốt theo tháng dương lịch, trả vào ngày này của tháng sau (1–28).</div>
        </div>
      </div>
    </div>
  </div>
</form>
@endsection
