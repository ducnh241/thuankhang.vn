{{-- Chỉ số mở rộng của Dashboard (DashboardController + App\Services\DashboardMetrics), mỗi khối theo quyền. Khối nào ghi
     "kỳ" thì theo kỳ đang chọn ở bảng So sánh (tháng / quý / năm, tới hiện tại). Biến từ view cha: $money, $count, $muted, $strong. --}}
@php
    $pct = fn ($v) => str_replace('.', ',', (string) $v).'%';
    $extra = fn () => '<div class="ant-card-extra text-xs '.$muted.'">'.e($periodLabel).' (tới nay)</div>';
    $right = 'style="text-align: right"';
@endphp

{{-- Doanh thu 12 tháng --}}
@if ($monthly)
  @php $peak = max(1, max(array_column($monthly, 'total'))); @endphp
  <div class="ant-card ant-card-bordered" data-section="monthly">
    <div class="ant-card-head"><div class="ant-card-head-wrapper">
      <div class="ant-card-head-title">Doanh thu 12 tháng gần nhất</div>
      <div class="ant-card-extra text-xs {{ $muted }}">Cao nhất: {{ $money($peak > 1 ? $peak : 0) }}</div>
    </div></div>
    <div class="ant-card-body">
      <div class="overflow-x-auto">
        <div class="flex h-48 min-w-[560px] items-end gap-2" role="img" aria-label="Biểu đồ doanh thu 12 tháng">
          @foreach ($monthly as $m)
            <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" data-month="{{ $m['month'] }}" data-total="{{ $m['total'] }}"
                 title="Tháng {{ $m['label'] }}: {{ $money($m['total']) }} · {{ $count($m['count']) }} đơn">
              <span class="text-[10px] tabular-nums {{ $muted }} whitespace-nowrap">{{ $m['total'] >= 1000000 ? rtrim(rtrim(number_format($m['total'] / 1000000, 1, ',', '.'), '0'), ',').'tr' : ($m['total'] > 0 ? $count(intdiv($m['total'], 1000)).'k' : '') }}</span>
              <div @class(['w-full rounded-t', 'bg-[#1677ff]' => $loop->last, 'bg-[#91caff]' => ! $loop->last]) style="height: {{ $m['total'] > 0 ? max(2, round($m['total'] / $peak * 100)) : 0 }}%"></div>
              <span @class(['text-[11px] tabular-nums whitespace-nowrap', 'font-medium '.$strong => $loop->last, $muted => ! $loop->last])>{{ $m['label'] }}</span>
            </div>
          @endforeach
        </div>
      </div>
      <div class="mt-2 text-xs {{ $muted }}">Tháng hiện tại (màu đậm) tính tới hôm nay. Rê chuột / chạm cột để xem số đơn.</div>
    </div>
  </div>
@endif

{{-- Top sản phẩm + doanh thu theo nơi nhận --}}
@if ($topProducts !== null)
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1.4fr_1fr]">
    <div class="ant-card ant-card-bordered" data-section="top-products">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Top 10 sản phẩm bán chạy</div>{!! $extra() !!}</div></div>
      @if ($topProducts->isEmpty())
        <div class="ant-card-body {{ $muted }}">Chưa có đơn đã thanh toán trong kỳ.</div>
      @else
        <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto">
            <thead class="ant-table-thead"><tr>
              <th class="ant-table-cell" style="width: 36px">#</th><th class="ant-table-cell min-w-[160px]">Sản phẩm</th>
              <th class="ant-table-cell" {!! $right !!}>SL</th><th class="ant-table-cell" {!! $right !!}>Đơn</th><th class="ant-table-cell" {!! $right !!}>Doanh thu</th>
            </tr></thead>
            <tbody class="ant-table-tbody">
              @foreach ($topProducts as $p)
                <tr class="ant-table-row" data-product="{{ $p['product_id'] }}">
                  <td class="ant-table-cell tabular-nums {{ $muted }}">{{ $loop->iteration }}</td>
                  <td class="ant-table-cell">@can('product/edit')<a href="{{ route('backend.products.edit', $p['product_id']) }}">{{ $p['name'] }}</a>@else{{ $p['name'] }}@endcan</td>
                  <td class="ant-table-cell tabular-nums" {!! $right !!}>{{ $count($p['qty']) }}</td>
                  <td class="ant-table-cell tabular-nums" {!! $right !!}>{{ $count($p['orders']) }}</td>
                  <td class="ant-table-cell tabular-nums whitespace-nowrap font-medium" {!! $right !!}>{{ $money($p['amount']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div></div></div>
      @endif
    </div>

    @php $pickupMax = max(1, max(array_column($byPickup ?: [['total' => 1]], 'total'))); @endphp
    <div class="ant-card ant-card-bordered" data-section="by-pickup">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Doanh thu theo nơi nhận</div>{!! $extra() !!}</div></div>
      <div class="ant-card-body flex flex-col gap-3">
        @forelse ($byPickup as $row)
          <div data-pickup="{{ $row['name'] }}" data-total="{{ $row['total'] }}">
            <div class="flex justify-between gap-3 text-sm tabular-nums">
              <span class="min-w-0">{{ $row['name'] }}@if ($row['province']) <span class="text-xs {{ $muted }}">· {{ $row['province'] }}</span>@endif <span class="text-xs {{ $muted }}">· {{ $count($row['count']) }} đơn</span></span>
              <span class="shrink-0 font-medium">{{ $money($row['total']) }}</span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded bg-[#f0f0f0]"><div class="h-full rounded bg-[#13c2c2]" style="width: {{ round($row['total'] / $pickupMax * 100) }}%"></div></div>
          </div>
        @empty
          <p class="m-0 {{ $muted }}">Chưa có đơn đã thanh toán trong kỳ.</p>
        @endforelse
      </div>
    </div>
  </div>
@endif

{{-- Chi trả affiliate + số dư ví --}}
@if ($payout || $wallets)
  <div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-2' => $payout && $wallets])>
    @if ($payout)
      <div class="ant-card ant-card-bordered" data-section="payout">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Chi trả affiliate so với doanh thu</div>{!! $extra() !!}</div></div>
        <div class="ant-card-body">
          <div class="flex items-baseline gap-2">
            <span class="text-3xl font-semibold tabular-nums {{ $strong }}" data-payout-ratio>{{ $payout['ratio'] === null ? '—' : $pct($payout['ratio']) }}</span>
            <span class="text-sm {{ $muted }}">doanh thu được chi trả lại cho thành viên</span>
          </div>
          <dl class="m-0 mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm tabular-nums">
            <dt class="{{ $muted }}">Doanh thu</dt><dd class="m-0 text-right">{{ $money($payout['revenue']) }}</dd>
            <dt class="{{ $muted }}">Hoa hồng phát sinh</dt><dd class="m-0 text-right">{{ $money($payout['commission']) }}</dd>
            <dd class="col-span-2 m-0 -mt-1 text-xs {{ $muted }}">→ ví rút {{ $money($payout['commission_cash']) }} · ví tái tiêu dùng {{ $money($payout['commission_reconsume']) }} · thuế TNCN {{ $money($payout['commission_tax']) }}</dd>
            <dt class="{{ $muted }}">Đồng chia đã duyệt trong kỳ</dt><dd class="m-0 text-right">{{ $money($payout['profit_share']) }}</dd>
            <dt class="font-medium">Tổng chi trả</dt><dd class="m-0 text-right font-semibold" data-payout-total>{{ $money($payout['total']) }}</dd>
          </dl>
          <div class="mt-2 text-xs {{ $muted }}">Hoa hồng tính lúc đơn được thanh toán; đồng chia tính theo ngày kế toán duyệt (tiền vào ví).</div>
        </div>
      </div>
    @endif
    @if ($wallets)
      <div class="ant-card ant-card-bordered" data-section="wallets">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Số dư ví toàn hệ thống</div><div class="ant-card-extra text-xs {{ $muted }}">Tại thời điểm hiện tại</div></div></div>
        <div class="ant-card-body">
          <div class="grid grid-cols-2 gap-4">
            <div><div class="ant-statistic-title mb-0">Ví rút tiền</div><div class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl {{ $strong }}" data-wallet-cash>{{ $money($wallets['cash']) }}</div><div class="text-xs {{ $muted }}">{{ $count($wallets['cash_members']) }} thành viên có số dư</div></div>
            <div><div class="ant-statistic-title mb-0">Ví tái tiêu dùng</div><div class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl {{ $strong }}" data-wallet-reconsume>{{ $money($wallets['reconsume']) }}</div><div class="text-xs {{ $muted }}">{{ $count($wallets['reconsume_members']) }} thành viên có số dư</div></div>
          </div>
          <div class="mt-4 rounded-lg bg-[#fafafa] p-3 text-sm" data-withdrawable>
            <strong class="tabular-nums">{{ $count($wallets['withdrawable_members']) }}</strong> thành viên có ví rút tiền từ {{ $money($wallets['min_withdraw']) }} (mức rút tối thiểu),
            tổng <strong class="tabular-nums">{{ $money($wallets['withdrawable']) }}</strong> — số tiền có thể bị yêu cầu rút khi mở chức năng rút tiền.
          </div>
          <div class="mt-2 text-xs {{ $muted }}">Đây là tiền công ty đang giữ hộ thành viên (nợ phải trả). Ví tái tiêu dùng chỉ dùng mua hàng, không rút ra.</div>
        </div>
      </div>
    @endif
  </div>
@endif

{{-- Tỉ lệ hủy + top thành viên --}}
@if ($cancellations || $recruiters !== null)
  <div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-[1fr_1.6fr]' => $cancellations && $recruiters !== null])>
    @if ($cancellations)
      <div class="ant-card ant-card-bordered" data-section="cancellations">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Đơn bị hủy / quá hạn</div>{!! $extra() !!}</div></div>
        <div class="ant-card-body">
          <div class="flex items-baseline gap-2">
            <span @class(['text-3xl font-semibold tabular-nums', 'text-[#cf1322]' => ($cancellations['rate'] ?? 0) >= 30, $strong => ($cancellations['rate'] ?? 0) < 30]) data-cancel-rate>{{ $cancellations['rate'] === null ? '—' : $pct($cancellations['rate']) }}</span>
            <span class="text-sm {{ $muted }}">đơn đặt trong kỳ bị hủy</span>
          </div>
          <dl class="m-0 mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm tabular-nums">
            <dt class="{{ $muted }}">Đơn đặt trong kỳ</dt><dd class="m-0 text-right">{{ $count($cancellations['total']) }}</dd>
            <dt class="{{ $muted }}">Đã thanh toán</dt><dd class="m-0 text-right">{{ $count($cancellations['paid']) }}</dd>
            <dt class="{{ $muted }}">Đang chờ thanh toán</dt><dd class="m-0 text-right">{{ $count($cancellations['open']) }}</dd>
            <dt class="font-medium">Đã hủy</dt><dd class="m-0 text-right font-semibold">{{ $count($cancellations['cancelled']) }}</dd>
            @foreach ($cancellations['by_source'] as $key => $row)
              <dt class="pl-3 text-xs {{ $muted }}" data-cancel-source="{{ $key }}">{{ $row['label'] }}</dt><dd class="m-0 text-right text-xs">{{ $count($row['count']) }}</dd>
            @endforeach
          </dl>
        </div>
      </div>
    @endif
    @if ($recruiters !== null)
      <div class="ant-card ant-card-bordered" data-section="top-members">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thành viên nổi bật</div>{!! $extra() !!}</div></div>
        <div class="ant-card-body grid grid-cols-1 gap-6 sm:grid-cols-2">
          @foreach ([['Giới thiệu nhiều người đăng ký nhất', $recruiters, 'recruiters'], ['Doanh số nhóm nhỏ cao nhất', $teams, 'teams']] as [$title, $list, $key])
            <div data-list="{{ $key }}">
              <div class="mb-2 font-medium">{{ $title }}</div>
              @forelse ($list as $row)
                <div class="flex items-baseline gap-2 border-b border-[#f0f0f0] py-1.5 text-sm last:border-0" data-member="{{ $row['member']->id }}">
                  <span class="w-5 shrink-0 tabular-nums {{ $muted }}">{{ $loop->iteration }}</span>
                  <span class="min-w-0 flex-1 truncate"><a href="{{ route('backend.members.show', $row['member']) }}">{{ $row['member']->full_name }}</a> <span class="text-xs {{ $muted }}">{{ $row['member']->level?->name }}</span></span>
                  @if ($key === 'recruiters')
                    <span class="shrink-0 tabular-nums font-medium">{{ $count($row['count']) }} người</span>
                  @else
                    <span class="shrink-0 text-right tabular-nums"><span class="font-medium">{{ $money($row['weak']) }}</span><span class="block text-[11px] {{ $muted }}">A {{ $money($row['left']) }} · B {{ $money($row['right']) }}</span></span>
                  @endif
                </div>
              @empty
                <p class="m-0 text-sm {{ $muted }}">Chưa có ai trong kỳ.</p>
              @endforelse
            </div>
          @endforeach
        </div>
        <div class="ant-card-body pt-0! text-xs {{ $muted }}">Doanh số nhóm nhỏ = nhánh có doanh số thấp hơn (Nhánh A / Nhánh B trong cây nhị phân) trong kỳ, chưa tính phần nhánh lớn chuyển sang từ kỳ trước.</div>
      </div>
    @endif
  </div>
@endif
