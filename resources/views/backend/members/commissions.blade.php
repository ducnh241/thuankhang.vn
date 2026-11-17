@extends('layouts.backend')

{{-- Sổ hoa hồng của một thành viên (mở từ nút "Xem sổ hoa hồng" ở trang chi tiết) — để quản trị đối chiếu với ví thành viên
     thấy trên website. Mỗi dòng = hoa hồng F1 hoặc F2 của một đơn đã thanh toán: đơn, người mua, cấp + tỉ lệ của người hưởng
     lúc tính, tiền hàng → cơ sở (trừ phí doanh nghiệp) → hoa hồng, số dư ví ngay sau bút toán. Dòng không được
     nhận (chưa kích hoạt / bị khóa / tỉ lệ 0%) vẫn hiện kèm lý do. Lọc ?tier, ?status. --}}
@php
    use App\Models\Commission;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $change) => route('backend.members.commissions', [$member, ...array_filter([...$filter, ...$change], fn ($v) => $v !== '' && $v !== null)]);
@endphp

@section('title', 'Sổ hoa hồng - '.$member->full_name)

@section('topbar')
  <div class="page-topbar-left">
    @can('member')
      <a href="{{ route('backend.members.index') }}" class="page-topbar-crumb max-md:hidden" title="Về danh sách thành viên">Thành viên</a>
      <span class="page-topbar-sep max-md:hidden" aria-hidden="true">/</span>
      <a href="{{ route('backend.members.show', $member) }}" class="page-topbar-crumb" title="Về trang chi tiết">{{ $member->full_name }}</a>
    @else
      <span class="page-topbar-crumb">{{ $member->full_name }}</span>
    @endcan
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">Sổ hoa hồng</h1>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto flex w-full max-w-[1400px] flex-col gap-4">
  <div class="ant-card ant-card-bordered" data-card="wallet-summary">
    {{-- Lưới nằm trong một div riêng: .ant-card-body có ::before / ::after (clearfix) sẽ chiếm ô nếu đặt grid thẳng lên nó --}}
    <div class="ant-card-body"><div class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-5">
      <div>
        <div class="text-xs {{ $muted }}">Số dư ví</div>
        <div class="text-xl font-semibold tabular-nums" data-wallet-balance>{{ Money::format($wallet['balance']) }} đ</div>
      </div>
      <div>
        <div class="text-xs {{ $muted }}">Tổng hoa hồng đã nhận</div>
        <div class="text-xl font-semibold tabular-nums" data-wallet-total>{{ Money::format($wallet['total']) }} đ</div>
      </div>
      <div>
        <div class="text-xs {{ $muted }}">Tháng {{ now()->format('m/Y') }}</div>
        <div class="text-xl font-semibold tabular-nums">{{ Money::format($wallet['month']) }} đ</div>
      </div>
      <div>
        <div class="text-xs {{ $muted }}">F1 (trực tiếp)</div>
        <div class="text-xl font-semibold tabular-nums" data-wallet-f1>{{ Money::format($wallet['f1']) }} đ</div>
      </div>
      <div>
        <div class="text-xs {{ $muted }}">F2 (gián tiếp)</div>
        <div class="text-xl font-semibold tabular-nums" data-wallet-f2>{{ Money::format($wallet['f2']) }} đ</div>
      </div>
    </div></div>
    <div class="border-t border-[#f0f0f0] px-6 py-3 text-xs {{ $muted }}">
      Cấp hiện tại: <strong class="text-[rgba(0,0,0,0.85)]">{{ $member->level?->name ?? '—' }}</strong> ·
      Hoa hồng = floor(cơ sở × tỉ lệ theo cấp của người hưởng lúc đơn được thanh toán). Cơ sở = tiền hàng của đơn (giá bán đã gồm thuế) × (1 − phí doanh nghiệp, hiện {{ Commission::percentLabel($feePercent) }}), không tính phí giao hàng.
      F1: đơn của người do thành viên này trực tiếp giới thiệu; F2: đơn của người do F1 giới thiệu.
    </div>
  </div>

  <div class="ant-card ant-card-bordered" data-card="commission-lines">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-6 py-3">
      <div class="flex items-center gap-2">
        <span class="{{ $muted }}">Tầng:</span>
        <div class="ant-btn-group" role="group" aria-label="Lọc theo tầng">
          <a href="{{ $url(['tier' => null, 'page' => null]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $filter['tier'] === ''])>Tất cả</a>
          @foreach (Commission::TIERS as $tier => $label)
            <a href="{{ $url(['tier' => $tier, 'page' => null]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $filter['tier'] === $tier])>{{ $tier }}</a>
          @endforeach
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span class="{{ $muted }}">Trạng thái:</span>
        <div class="ant-btn-group" role="group" aria-label="Lọc theo trạng thái">
          <a href="{{ $url(['status' => null, 'page' => null]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $filter['status'] === ''])>Tất cả</a>
          @foreach (Commission::STATUSES as $status => $label)
            <a href="{{ $url(['status' => $status, 'page' => null]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $filter['status'] === $status])>{{ $label }}</a>
          @endforeach
        </div>
      </div>
      <span class="ml-auto {{ $muted }}">{{ $count($wallet['lines']) }} dòng đã cộng ví{{ $wallet['skipped'] ? ' · '.$count($wallet['skipped']).' dòng không được nhận' : '' }}</span>
    </div>
    <div class="ant-table ant-table-small">
      <div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
        <table style="table-layout: auto">
          <thead class="ant-table-thead">
            <tr>
              <th class="ant-table-cell">Thời gian</th>
              <th class="ant-table-cell">Đơn</th>
              <th class="ant-table-cell">Người mua</th>
              <th class="ant-table-cell">Tầng</th>
              <th class="ant-table-cell">Cấp lúc tính</th>
              <th class="ant-table-cell" style="text-align: right">Tiền hàng</th>
              <th class="ant-table-cell" style="text-align: right">Cơ sở</th>
              <th class="ant-table-cell" style="text-align: right">Tỉ lệ</th>
              <th class="ant-table-cell" style="text-align: right">Hoa hồng</th>
              <th class="ant-table-cell" style="text-align: right">Số dư ví sau</th>
            </tr>
          </thead>
          <tbody class="ant-table-tbody" data-commission-rows>
            @forelse ($lines as $line)
              <tr class="ant-table-row" data-commission="{{ $line->id }}">
                <td class="ant-table-cell tabular-nums whitespace-nowrap {{ $muted }}">{{ $line->created_at?->format('d/m/Y H:i') }}</td>
                <td class="ant-table-cell whitespace-nowrap">
                  @can('order')<a href="{{ route('backend.orders.show', $line->order) }}" class="font-mono">{{ $line->order?->code }}</a>@else<span class="font-mono">{{ $line->order?->code }}</span>@endcan
                </td>
                <td class="ant-table-cell whitespace-nowrap">@can('member')<a href="{{ route('backend.members.show', $line->buyer_id) }}">{{ $line->buyer?->full_name }}</a>@else{{ $line->buyer?->full_name }}@endcan</td>
                <td class="ant-table-cell"><span class="ant-tag ant-tag-{{ $line->tier === 'F1' ? 'blue' : 'purple' }} m-0">{{ $line->tier }}</span></td>
                <td class="ant-table-cell whitespace-nowrap">{{ $line->level_name ?? '—' }}</td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">{{ Money::format($line->goods_amount) }} đ</td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right" title="Tiền hàng − {{ Commission::percentLabel($line->fee_percent) }} phí doanh nghiệp">{{ Money::format($line->base_amount) }} đ</td>
                <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Commission::percentLabel($line->rate_percent) }}</td>
                @if ($line->isCredited())
                  <td class="ant-table-cell tabular-nums whitespace-nowrap font-semibold" style="text-align: right">+{{ Money::format($line->amount) }} đ</td>
                  <td class="ant-table-cell tabular-nums whitespace-nowrap {{ $muted }}" style="text-align: right">{{ $line->walletTransaction ? Money::format($line->walletTransaction->balance_after).' đ' : '—' }}</td>
                @else
                  <td class="ant-table-cell whitespace-nowrap" colspan="2" style="text-align: right">
                    <span class="ant-tag m-0">Không được nhận</span>
                    <div class="text-xs {{ $muted }}">{{ $line->skip_reason }}</div>
                  </td>
                @endif
              </tr>
            @empty
              <tr class="ant-table-placeholder">
                <td colspan="10" class="ant-table-cell">
                  <div class="ant-empty ant-empty-normal">
                    <div class="ant-empty-image">{{ ant_icon('wallet', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                    @if ($filter['tier'] !== '' || $filter['status'] !== '')
                      <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có dòng nào khớp bộ lọc</strong></p>
                      <div class="ant-empty-footer"><a href="{{ route('backend.members.commissions', $member) }}" class="ant-btn"><span>Bỏ lọc</span></a></div>
                    @else
                      <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có hoa hồng nào</strong>Hoa hồng được ghi khi đơn của F1 / F2 của thành viên này được xác nhận thanh toán.</p>
                    @endif
                  </div>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div></div>
    </div>
    @if ($lines->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($lines->firstItem()) }}–{{ $count($lines->lastItem()) }} / {{ $count($lines->total()) }} dòng</span>
        {{ $lines->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
