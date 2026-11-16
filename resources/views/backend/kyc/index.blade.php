@extends('layouts.backend')

@section('title', 'KYC')

{{-- KYC thành viên (A11), dựng như danh sách Thành viên: tab trạng thái (mặc định "Chờ duyệt" — hồ sơ gửi trước nằm trên),
     ô tìm (tên / SĐT / mã giới thiệu / số tài khoản / đủ 12 số CCCD), bảng, phân trang. Bấm dòng mở hồ sơ để duyệt. --}}
@php
    use App\Http\Controllers\Backend\KycController;
    use App\Models\MemberKyc;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.kyc.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
@endphp

@section('content')
<div id="kyc-list" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (KycController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.kyc.index') }}" role="search">
        <input type="hidden" name="tab" value="{{ $filter['tab'] }}" />
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="kyc-search" placeholder="Tìm theo tên, số điện thoại, mã giới thiệu, số tài khoản, số CCCD (đủ 12 số)" aria-label="Tìm hồ sơ KYC" />
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
            <table @class(['product-table', 'min-w-0' => $kycs->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Thành viên</th>
                  <th class="ant-table-cell">Họ tên trên CCCD</th>
                  <th class="ant-table-cell max-md:hidden">Ngân hàng</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell max-sm:hidden">Gửi lúc</th>
                  <th class="ant-table-cell max-md:hidden">Xử lý lúc</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="kyc-rows">
                @forelse ($kycs as $kyc)
                  @php $href = route('backend.kyc.show', $kyc); @endphp
                  <tr data-row-key="{{ $kyc->id }}" class="ant-table-row product-row" data-href="{{ $href }}">
                    <td class="ant-table-cell">
                      <a href="{{ $href }}" class="product-name">{{ $kyc->member?->full_name }}</a>
                      <div class="text-xs {{ $muted }} tabular-nums">{{ $kyc->member?->phone }} · <span class="font-mono">{{ $kyc->member?->referral_code }}</span></div>
                    </td>
                    <td class="ant-table-cell">{{ $kyc->full_name }}</td>
                    <td class="ant-table-cell max-md:hidden">
                      {{ $kyc->bankName() }}
                      <div class="text-xs {{ $muted }} tabular-nums">{{ $kyc->bank_account_number }}</div>
                    </td>
                    <td class="ant-table-cell"><span class="ant-tag ant-tag-{{ MemberKyc::STATUS_COLORS[$kyc->status] }} m-0">{{ $kyc->statusLabel() }}</span></td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-sm:hidden">{{ $kyc->submitted_at?->format('d/m/Y H:i') }}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden">{{ $kyc->reviewed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('safety-certificate', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($filter['q'] !== '')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy hồ sơ nào</strong>Thử từ khóa khác hoặc xem tab khác.</p>
                          <div class="ant-empty-footer"><a href="{{ $url(['q' => null]) }}" class="ant-btn"><span>Xóa từ khóa</span></a></div>
                        @elseif ($filter['tab'] === MemberKyc::STATUS_PENDING)
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có hồ sơ nào chờ duyệt</strong>Hồ sơ thành viên gửi từ trang Tài khoản trên website sẽ hiện ở đây.</p>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có hồ sơ nào</strong></p>
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

    @if ($kycs->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($kycs->firstItem()) }}–{{ $count($kycs->lastItem()) }} / {{ $count($kycs->total()) }} hồ sơ</span>
        {{ $kycs->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
