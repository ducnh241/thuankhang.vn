@extends('layouts.backend')

@section('title', 'Đồng chia')

{{-- Đồng chia: các tháng, mới nhất trước — trạng thái (đang diễn ra / chờ duyệt trong hạn / quá hạn / đã duyệt), hạn duyệt
     (ngày 1–5 tháng sau), đơn vào gói, quỹ, đã chia. Tháng chưa duyệt là số tạm tính trực tiếp. Bấm dòng mở bảng chi tiết. --}}
@php
    use App\Http\Controllers\Backend\ProfitShareController;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $tags = [
        'approved' => ['green', 'Đã duyệt'], 'open' => ['gold', 'Chờ duyệt — trong hạn'],
        'current' => ['blue', 'Đang diễn ra (tạm tính)'], 'missed' => ['red', 'Quá hạn, không duyệt'],
    ];
@endphp

@section('content')
<div class="mx-auto flex max-w-[1200px] flex-col gap-4">
  <div class="ant-alert ant-alert-info">
    <div class="ant-alert-content"><div class="ant-alert-message">
      Quỹ mỗi nhóm = tổng tiền các đơn vào gói thanh toán trong tháng × (1 − phí doanh nghiệp) × tỉ lệ đồng chia, chia đều cho
      những người vào nhóm trước (tối đa top theo cấu hình). Tiền chỉ vào ví tái tiêu dùng khi kế toán bấm <strong>Duyệt</strong>,
      và chỉ duyệt được <strong>từ ngày 1 đến hết ngày {{ \App\Services\ProfitShare::APPROVE_LAST_DAY }}</strong> của tháng sau.
    </div></div>
  </div>

  <div class="ant-card ant-card-bordered product-index">
    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table class="product-table" style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Tháng</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell max-md:hidden">Hạn duyệt</th>
                  <th class="ant-table-cell" style="text-align: right">Đơn vào gói</th>
                  <th class="ant-table-cell" style="text-align: right">Quỹ</th>
                  <th class="ant-table-cell" style="text-align: right">Đã chia</th>
                  <th class="ant-table-cell max-sm:hidden" style="text-align: right">Lượt người</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="profit-share-rows">
                @foreach ($rows as $row)
                  @php $href = route('backend.profit-share.show', $row['period']); [$color, $label] = $tags[$row['state']]; @endphp
                  <tr class="ant-table-row product-row" data-href="{{ $href }}" data-period="{{ $row['period'] }}">
                    <td class="ant-table-cell"><a href="{{ $href }}" class="product-name">Tháng {{ ProfitShareController::label($row['period']) }}</a></td>
                    <td class="ant-table-cell">
                      <span class="ant-tag ant-tag-{{ $color }} m-0">{{ $label }}</span>
                      @if ($row['record'])<div class="text-xs {{ $muted }}">{{ $row['record']->approved_at->format('d/m/Y H:i') }} · {{ $row['record']->approver?->name }}</div>@endif
                    </td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden">{{ $row['window'][0]->format('d/m') }} – {{ $row['window'][1]->format('d/m/Y') }}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">{{ Money::format($row['order_total']) }} đ</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">{{ Money::format($row['pool_total']) }} đ</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">{{ Money::format($row['distributed_total']) }} đ</td>
                    <td class="ant-table-cell tabular-nums max-sm:hidden" style="text-align: right">{{ $row['paid_count'] }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
