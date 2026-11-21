@extends('layouts.backend')

@section('title', 'Nạp ví')

{{-- Nạp ví tái tiêu dùng, dựng như danh sách KYC: tab trạng thái (mặc định "Chờ xác nhận" — báo trước nằm trên), ô tìm (mã lệnh /
     mã giao dịch / tên / SĐT / mã giới thiệu), bảng, phân trang. Bấm dòng mở lệnh để xác nhận. --}}
@php
    use App\Http\Controllers\Backend\WalletTopupController;
    use App\Models\WalletTopup;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.wallet-topups.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
@endphp

@section('content')
<div id="topup-list" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (WalletTopupController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.wallet-topups.index') }}" role="search">
        <input type="hidden" name="tab" value="{{ $filter['tab'] }}" />
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="topup-search" placeholder="Tìm theo mã lệnh, mã giao dịch, tên, số điện thoại, mã giới thiệu" aria-label="Tìm lệnh nạp" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $topups->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Mã lệnh</th>
                  <th class="ant-table-cell">Thành viên</th>
                  <th class="ant-table-cell" style="text-align: right">Số tiền</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell max-sm:hidden">Tạo lúc</th>
                  <th class="ant-table-cell max-md:hidden">Báo chuyển lúc</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="topup-rows">
                @forelse ($topups as $topup)
                  @php $href = route('backend.wallet-topups.show', $topup); $status = $topup->displayStatus(); @endphp
                  <tr data-row-key="{{ $topup->id }}" class="ant-table-row product-row" data-href="{{ $href }}">
                    <td class="ant-table-cell"><a href="{{ $href }}" class="product-name font-mono">{{ $topup->code }}</a>
                      @if ($topup->payment_reference)<div class="text-xs {{ $muted }} font-mono">GD {{ $topup->payment_reference }}</div>@endif
                    </td>
                    <td class="ant-table-cell">
                      {{ $topup->member?->full_name }}
                      <div class="text-xs {{ $muted }} tabular-nums">{{ $topup->member?->phone }} · <span class="font-mono">{{ $topup->member?->referral_code }}</span></div>
                    </td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">
                      {{ Money::format($topup->amount) }} đ
                      @if ($topup->paid_amount !== null && $topup->paid_amount !== $topup->amount)<div class="text-xs {{ $muted }}">nhận {{ Money::format($topup->paid_amount) }} đ</div>@endif
                    </td>
                    <td class="ant-table-cell"><span class="ant-tag ant-tag-{{ WalletTopup::STATUS_COLORS[$status] }} m-0">{{ $topup->statusLabel() }}</span></td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-sm:hidden">{{ $topup->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden">{{ $topup->confirm_requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('wallet', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($filter['q'] !== '')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy lệnh nào</strong>Thử từ khóa khác hoặc xem tab khác.</p>
                          <div class="ant-empty-footer"><a href="{{ $url(['q' => null]) }}" class="ant-btn"><span>Xóa từ khóa</span></a></div>
                        @elseif ($filter['tab'] === WalletTopup::STATUS_AWAITING)
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có lệnh nào chờ xác nhận</strong>Lệnh thành viên báo đã chuyển khoản (kèm ảnh bill) sẽ hiện ở đây.</p>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có lệnh nào</strong></p>
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    @if ($topups->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($topups->firstItem()) }}–{{ $count($topups->lastItem()) }} / {{ $count($topups->total()) }} lệnh</span>
        {{ $topups->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
