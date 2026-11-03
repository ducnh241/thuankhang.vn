@extends('layouts.backend')

@section('title', 'Ma trận hoa hồng - Cấu hình affiliate')
@section('inline-errors', '1')

{{-- A3 – Ma trận hoa hồng (đặc tả 6.3): tỉ lệ theo cấp của NGƯỜI HƯỞNG × tầng F1 / F2. Lưu = thêm phiên bản mới có
     giờ hiệu lực; đơn thanh toán từ giờ đó mới dùng tỉ lệ mới. Lịch sử bên dưới chỉ xem. --}}
@php
    use App\Models\CommissionRate;
    use App\Support\Percent;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $tiers = array_keys(CommissionRate::TIERS);
    $value = function (int $levelId, string $tier) use ($latest) {
        $old = old("rates.{$levelId}.{$tier}");
        if (session()->hasOldInput()) {
            return is_string($old) ? $old : '';
        }

        return isset($latest['rates'][$levelId][$tier]) ? Percent::format($latest['rates'][$levelId][$tier]) : '0';
    };
    $effective = is_string(old('effective_from')) ? old('effective_from') : now()->format('Y-m-d H:i:00');
    $sum = fn (array $rates) => array_sum(array_map('floatval', $rates));
@endphp

@section('topbar')
  @include('backend.affiliate._topbar', ['title' => 'Ma trận hoa hồng', 'form' => 'commission-form'])
@endsection

@section('content')
<div class="mx-auto flex max-w-[1040px] flex-col gap-4">
  <form method="POST" id="commission-form" action="{{ route('backend.affiliate.commission.store') }}" class="ant-form ant-form-vertical edit-form"
    novalidate autocomplete="off" @if ($canEdit) data-edit-form @else data-readonly @endif @if ($errors->any()) data-dirty @endif>
    @csrf
    @include('backend.affiliate._readonly')

    <div class="ant-card ant-card-bordered" @unless ($canEdit) inert @endunless>
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">
            Phiên bản mới
            <div class="text-xs font-normal {{ $muted }}">
              Hoa hồng = floor(cơ sở × tỉ lệ theo cấp người hưởng). F1: người giới thiệu trực tiếp của người mua; F2: người giới thiệu của F1.
              @if ($latest) Đang điền sẵn tỉ lệ của phiên bản {{ $latest['effective_from']->format('d/m/Y H:i') }}.@endif
            </div>
          </div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto" id="commission-table">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Cấp người hưởng</th>
                  @foreach (CommissionRate::TIERS as $label)
                    <th class="ant-table-cell" style="width: 180px">{{ $label }} (%)</th>
                  @endforeach
                </tr>
              </thead>
              <tbody class="ant-table-tbody">
                @forelse ($levels as $level)
                  <tr class="ant-table-row" data-level="{{ $level->id }}">
                    <td class="ant-table-cell">
                      <span class="font-medium">{{ $level->name }}</span>
                      @unless ($level->active)<span class="ant-tag ml-1">Đang tắt</span>@endunless
                    </td>
                    @foreach ($tiers as $tier)
                      @php $key = "rates.{$level->id}.{$tier}"; @endphp
                      <td class="ant-table-cell align-top">
                        <div @class(['ant-form-item mb-0', 'ant-form-item-has-error ant-form-item-with-help' => $errors->has($key)])>
                          <div class="ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                            <span class="ant-input-affix-wrapper ant-input-affix-wrapper-sm">
                              <input type="text" inputmode="decimal" name="rates[{{ $level->id }}][{{ $tier }}]" value="{{ $value($level->id, $tier) }}" class="ant-input tabular-nums" style="text-align: right" aria-label="{{ $tier }} – {{ $level->name }}" />
                              <span class="ant-input-suffix">%</span>
                            </span>
                          </div></div>{{ ant_form_error($errors->first($key)) }}</div>
                        </div>
                      </td>
                    @endforeach
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="3" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có cấp hưởng hoa hồng — thêm cấp ở trang Cấp bậc.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="ant-card-body">
        <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
          <div class="ant-row ant-form-item mb-0{{ $errors->has('effective_from') ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}">
            <div class="ant-col ant-form-item-label"><label for="effective_from" class="ant-form-item-required" title="Hiệu lực từ">Hiệu lực từ</label></div>
            <div class="ant-col ant-form-item-control">
              <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                {{-- DatePicker có giờ (picker.js, data-picker="datetime"): giá trị Y-m-d H:i:s --}}
                <div class="ant-picker w-full" data-picker="datetime">
                  <div class="ant-picker-input">
                    <input id="effective_from" name="effective_from" value="{{ $effective }}" placeholder="Chọn ngày giờ" readonly autocomplete="off" />
                    <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
                  </div>
                </div>
              </div></div>
              {{ ant_form_error($errors->first('effective_from')) }}
              <div class="ant-form-item-extra">Mặc định là bây giờ. Đơn đang chờ thanh toán sẽ dùng tỉ lệ có hiệu lực lúc được thanh toán.</div>
            </div>
          </div>
          <div class="ant-row ant-form-item mb-0">
            <div class="ant-col ant-form-item-label"><label title="Ngưỡng cảnh báo">Ngưỡng cảnh báo F1 + F2</label></div>
            <div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
              <span class="ant-typography"><strong>{{ Percent::format($warning) }}%</strong></span>
              <span class="ant-typography ant-typography-secondary"> — sửa ở <a href="{{ route('backend.affiliate.settings.edit') }}">Tham số chung</a></span>
            </div></div></div>
          </div>
        </div>
        @if ($errors->has('rates'))
          <div class="ant-alert ant-alert-error mt-4" role="alert">{{ ant_icon('close-circle', 'ant-alert-icon') }}<div class="ant-alert-content"><div class="ant-alert-message">{{ $errors->first('rates') }}</div></div></div>
        @endif
        @if ($errors->has('confirm_over'))
          <div class="ant-alert ant-alert-warning ant-alert-with-description mt-4" role="alert">
            {{ ant_icon('exclamation-circle', 'ant-alert-icon') }}
            <div class="ant-alert-content">
              <div class="ant-alert-message">Tỉ lệ vượt ngưỡng an toàn</div>
              <div class="ant-alert-description">
                <div>{{ $errors->first('confirm_over') }}</div>
                <label class="ant-checkbox-wrapper mt-2"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="confirm_over" value="1" /><span class="ant-checkbox-inner"></span></span><span>Tôi xác nhận tỉ lệ vượt ngưỡng là đúng</span></label>
              </div>
            </div>
          </div>
        @endif
      </div>
    </div>
  </form>

  <div class="ant-card ant-card-bordered" data-card="history">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử phiên bản <span class="{{ $muted }} font-normal">({{ $versions->count() }})</span></div></div></div>
    <div class="ant-table ant-table-small ant-table-bordered">
      <div class="ant-table-container">
        <div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto" id="commission-history">
            <thead class="ant-table-thead">
              <tr>
                <th class="ant-table-cell" rowspan="2" style="width: 190px">Hiệu lực từ</th>
                <th class="ant-table-cell" rowspan="2" style="width: 120px">Trạng thái</th>
                @foreach ($levels as $level)
                  <th class="ant-table-cell whitespace-nowrap" colspan="2" style="text-align: center">{{ $level->name }}</th>
                @endforeach
              </tr>
              <tr>
                @foreach ($levels as $level)
                  <th class="ant-table-cell" style="text-align: right">F1</th>
                  <th class="ant-table-cell" style="text-align: right">F2</th>
                @endforeach
              </tr>
            </thead>
            <tbody class="ant-table-tbody">
              @forelse ($versions as $i => $version)
                @php
                    $status = $currentKey === $i ? ['Đang áp dụng', 'ant-tag-green'] : ($version['effective_from']->isFuture() ? ['Sắp áp dụng', 'ant-tag-blue'] : ['Đã thay thế', '']);
                @endphp
                <tr class="ant-table-row" data-version="{{ $version['effective_from']->format('Y-m-d H:i') }}">
                  <td class="ant-table-cell">
                    <div class="tabular-nums">{{ $version['effective_from']->format('d/m/Y H:i') }}</div>
                    @if ($version['creator'])<div class="ant-typography ant-typography-secondary text-xs">{{ $version['creator'] }}</div>@endif
                  </td>
                  <td class="ant-table-cell"><span class="ant-tag m-0 {{ $status[1] }}">{{ $status[0] }}</span></td>
                  @foreach ($levels as $level)
                    @php $rates = $version['rates'][$level->id] ?? []; $over = $rates && $sum($rates) > (float) $warning; @endphp
                    @foreach (['F1', 'F2'] as $tier)
                      <td @class(['ant-table-cell tabular-nums whitespace-nowrap', 'text-[#fa8c16]' => $over]) style="text-align: right" @if ($over) title="F1 + F2 vượt ngưỡng cảnh báo" @endif>
                        @if (isset($rates[$tier])){{ Percent::format($rates[$tier]) }}%@else<span class="{{ $muted }}">—</span>@endif
                      </td>
                    @endforeach
                  @endforeach
                </tr>
              @empty
                <tr class="ant-table-placeholder"><td colspan="{{ 2 + 2 * $levels->count() }}" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có phiên bản nào.</p></div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
