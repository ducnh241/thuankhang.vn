{{-- Bảng người trong một nhóm đồng chia. $rows (dòng dạng chung của ProfitShareController), $mode: paid (được chia: đã nhận
     trước / tháng này / sau khi nhận / mức ra) | waiting (hàng chờ) | skipped (bị khóa) | exited (đã nhận đủ từ trước). --}}
@php
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $canMember = auth()->user()->can('member');
    $canOrder = auth()->user()->can('order');
    $right = 'style="text-align: right"';
@endphp
<div class="ant-table ant-table-small">
  <div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
    <table style="table-layout: auto" data-people="{{ $mode }}">
      <thead class="ant-table-thead">
        <tr>
          @if (in_array($mode, ['paid', 'waiting'], true))<th class="ant-table-cell" style="width: 56px">Hạng</th>@endif
          <th class="ant-table-cell">Thành viên</th>
          <th class="ant-table-cell">Vào nhóm</th>
          <th class="ant-table-cell" {!! $right !!}>Đã nhận trước</th>
          @if ($mode === 'paid')
            <th class="ant-table-cell" {!! $right !!}>Nhận tháng này</th>
            <th class="ant-table-cell" {!! $right !!}>Sau khi nhận / mức ra</th>
          @else
            <th class="ant-table-cell" {!! $right !!}>Mức ra</th>
          @endif
          <th class="ant-table-cell">Ghi chú</th>
        </tr>
      </thead>
      <tbody class="ant-table-tbody">
        @foreach ($rows as $row)
          @php $m = $row['member']; @endphp
          <tr class="ant-table-row" data-member="{{ $m?->id }}" data-amount="{{ $row['amount'] }}">
            @if (in_array($mode, ['paid', 'waiting'], true))<td class="ant-table-cell tabular-nums">{{ $row['rank'] }}</td>@endif
            <td class="ant-table-cell">
              @if ($m && $canMember)<a href="{{ route('backend.members.show', $m) }}">{{ $m->full_name }}</a>@else{{ $m?->full_name ?? '—' }}@endif
              <div class="text-xs {{ $muted }}"><span class="font-mono">{{ $m?->referral_code }}</span> · <span class="tabular-nums">{{ $m?->phone }}</span>@if ($m?->isLocked()) <span class="ant-tag ant-tag-red m-0">Khóa</span>@endif</div>
            </td>
            <td class="ant-table-cell whitespace-nowrap">
              <span class="tabular-nums">{{ $row['joined_at']?->format('d/m/Y H:i') }}</span>
              <div class="text-xs {{ $muted }}">đơn
                @if ($row['order'] && $canOrder)<a href="{{ route('backend.orders.show', $row['order']) }}" class="font-mono">{{ $row['order']->code }}</a>@else<span class="font-mono">{{ $row['order']?->code }}</span>@endif
                · {{ Money::format($row['order']?->goods_amount ?? 0) }} đ</div>
            </td>
            <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ Money::format($row['before']) }} đ</td>
            @if ($mode === 'paid')
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}><strong @class(['text-[#389e0d]' => $row['amount'] > 0])>+{{ Money::format($row['amount']) }} đ</strong>
                @if ($row['balance_after'] !== null)<div class="text-xs {{ $muted }}">ví TTD sau: {{ Money::format($row['balance_after']) }} đ</div>@endif
              </td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ Money::format($row['after']) }} / {{ Money::format($row['cap']) }} đ
                <div class="ml-auto mt-1 h-1.5 w-28 overflow-hidden rounded bg-[#f0f0f0]"><div class="h-full bg-[#52c41a]" style="width: {{ $row['cap'] > 0 ? min(100, round($row['after'] / $row['cap'] * 100)) : 0 }}%"></div></div>
              </td>
            @else
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ Money::format($row['cap']) }} đ</td>
            @endif
            <td class="ant-table-cell">
              @if ($row['exits'])<span class="ant-tag ant-tag-green m-0">Nhận đủ — ra khỏi nhóm</span>@endif
              @if ($row['note'])<span class="text-xs {{ $muted }}">{{ $row['note'] }}</span>@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div></div>
</div>
