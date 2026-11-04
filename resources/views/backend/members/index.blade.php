@extends('layouts.backend')

@section('title', 'Thành viên')

{{-- Thành viên website (A10), dựng như danh sách Sản phẩm: tab trạng thái (số theo bộ lọc), ô tìm (tên / SĐT / mã /
     email) + nút lọc cấp (list.js), bảng, phân trang. Bấm dòng mở trang chi tiết. --}}
@php
    use App\Http\Controllers\Backend\MemberController;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $url = fn (array $changes = []) => route('backend.members.index', array_filter([...array_filter($filter), ...$changes], fn ($v) => $v !== null && $v !== ''));
    $level = $filter['level_id'] !== '' ? $levels->firstWhere('id', (int) $filter['level_id']) : null;
    $statusTag = fn ($m) => $m->isLocked()
        ? '<span class="ant-tag ant-tag-red m-0">Đang khóa</span>'
        : ($m->isActivated() ? '<span class="ant-tag ant-tag-green m-0">Đã kích hoạt</span>' : '<span class="ant-tag m-0">Chưa kích hoạt</span>');
@endphp

@section('content')
<div id="members" class="mx-auto max-w-[1200px]" data-list>
  <div class="ant-card ant-card-bordered product-index">
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Trạng thái">
        @foreach (MemberController::TABS as $key => $label)
          <a href="{{ $url(['tab' => $key === '' ? null : $key]) }}" role="tab" aria-selected="{{ $filter['tab'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['tab'] === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    <div class="product-search">
      <form method="GET" action="{{ route('backend.members.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except(array_filter($filter), ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="member-search" placeholder="Tìm theo tên, số điện thoại, mã giới thiệu, email" aria-label="Tìm thành viên" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($level)
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="level">Cấp: {{ $level->name }}</button><a href="{{ $url(['level_id' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc cấp">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="level">Cấp{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['q'] !== '' || $level)
          <a href="{{ route('backend.members.index', array_filter(['tab' => $filter['tab']])) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-level">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($levels as $item)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['level_id'] === (string) $item->id]) role="menuitem"><a href="{{ $url(['level_id' => $item->id]) }}">{{ $item->name }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table', 'min-w-0' => $members->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Thành viên</th>
                  <th class="ant-table-cell">Mã giới thiệu</th>
                  <th class="ant-table-cell">Cấp</th>
                  <th class="ant-table-cell max-md:hidden" style="text-align: right">Doanh số cộng dồn</th>
                  <th class="ant-table-cell max-md:hidden">Người giới thiệu</th>
                  <th class="ant-table-cell max-md:hidden" style="text-align: right">F1</th>
                  <th class="ant-table-cell">Trạng thái</th>
                  <th class="ant-table-cell max-sm:hidden">Tham gia</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="member-rows">
                @forelse ($members as $member)
                  @php $href = route('backend.members.show', $member); @endphp
                  <tr data-row-key="{{ $member->id }}" class="ant-table-row product-row" data-href="{{ $href }}">
                    <td class="ant-table-cell">
                      <a href="{{ $href }}" class="product-name">{{ $member->full_name }}</a>
                      <div class="text-xs {{ $muted }} tabular-nums">{{ $member->phone }}</div>
                    </td>
                    <td class="ant-table-cell font-mono">{{ $member->referral_code }}</td>
                    <td class="ant-table-cell">{{ $member->level?->name }}</td>
                    <td class="ant-table-cell tabular-nums max-md:hidden" style="text-align: right">{{ Money::format($member->cumulative_amount) }} đ</td>
                    <td class="ant-table-cell max-md:hidden">
                      @if ($member->referrer)
                        <a href="{{ route('backend.members.show', $member->referrer) }}">{{ $member->referrer->full_name }}</a>
                        <div class="text-xs font-mono {{ $muted }}">{{ $member->referrer->referral_code }}</div>
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums max-md:hidden" style="text-align: right">{{ $count($member->referrals_count) }}</td>
                    <td class="ant-table-cell">{!! $statusTag($member) !!}</td>
                    <td class="ant-table-cell tabular-nums whitespace-nowrap max-sm:hidden">{{ $member->created_at?->format('d/m/Y') }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="8" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('contacts', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if (array_filter($filter))
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy thành viên nào</strong>Thử từ khóa khác hoặc bỏ bộ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.members.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có thành viên nào</strong>Thành viên tự đăng ký trên website (trang Đăng ký), có thể qua link giới thiệu.</p>
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

    @if ($members->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($members->firstItem()) }}–{{ $count($members->lastItem()) }} / {{ $count($members->total()) }} thành viên</span>
        {{ $members->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
