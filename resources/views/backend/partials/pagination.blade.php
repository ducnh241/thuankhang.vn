{{-- Phân trang kiểu Ant Design (ant-pagination mini): ‹ 1 ••• 4 5 [6] 7 8 ••• 20 ›, mỗi trang là một link.
     Dùng: {{ $paginator->links('backend.partials.pagination') }} --}}
@if ($paginator->hasPages())
  @php
      $current = $paginator->currentPage();
      $last = $paginator->lastPage();
      // Như Ant Design (Pager, pageBufferSize = 2): ≤ 9 trang thì hiện hết; nhiều hơn thì 5 trang quanh trang hiện tại,
      // luôn có trang đầu / cuối, khoảng bị ẩn là nút ••• nhảy 5 trang
      $jumpPrev = $jumpNext = false;
      if ($last <= 9) {
          [$from, $to] = [1, $last];
      } else {
          $from = max(1, $current - 2);
          $to = min($last, $current + 2);
          if ($current - 1 <= 2) $to = 5;
          if ($last - $current <= 2) $from = $last - 4;
          $jumpPrev = $current - 1 >= 4;
          $jumpNext = $last - $current >= 4;
      }
  @endphp
  <ul class="ant-pagination mini" unselectable="unselectable" aria-label="Phân trang">
    <li title="Trang trước" @class(['ant-pagination-prev', 'ant-pagination-disabled' => $paginator->onFirstPage()])>
      @if ($paginator->onFirstPage())
        <button class="ant-pagination-item-link" type="button" tabindex="-1" disabled>{{ ant_icon('left') }}</button>
      @else
        <a class="ant-pagination-item-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước">{{ ant_icon('left') }}</a>
      @endif
    </li>
    @if ($from > 1)
      <li title="1" class="ant-pagination-item ant-pagination-item-1"><a href="{{ $paginator->url(1) }}">1</a></li>
      @if ($jumpPrev)
        <li title="Lùi 5 trang" class="ant-pagination-jump-prev ant-pagination-jump-prev-custom-icon">
          <a class="ant-pagination-item-link" href="{{ $paginator->url(max(1, $current - 5)) }}" aria-label="Lùi 5 trang"><div class="ant-pagination-item-container">{{ ant_icon('double-left', 'ant-pagination-item-link-icon') }}<span class="ant-pagination-item-ellipsis">•••</span></div></a>
        </li>
      @endif
    @endif
    @for ($n = $from; $n <= $to; $n++)
      <li title="{{ $n }}" @class(['ant-pagination-item', 'ant-pagination-item-'.$n, 'ant-pagination-item-active' => $n === $current])>
        <a href="{{ $paginator->url($n) }}" @if ($n === $current) aria-current="page" @endif>{{ $n }}</a>
      </li>
    @endfor
    @if ($to < $last)
      @if ($jumpNext)
        <li title="Tới 5 trang" class="ant-pagination-jump-next ant-pagination-jump-next-custom-icon">
          <a class="ant-pagination-item-link" href="{{ $paginator->url(min($last, $current + 5)) }}" aria-label="Tới 5 trang"><div class="ant-pagination-item-container">{{ ant_icon('double-right', 'ant-pagination-item-link-icon') }}<span class="ant-pagination-item-ellipsis">•••</span></div></a>
        </li>
      @endif
      <li title="{{ $last }}" class="ant-pagination-item ant-pagination-item-{{ $last }}"><a href="{{ $paginator->url($last) }}">{{ $last }}</a></li>
    @endif
    <li title="Trang sau" @class(['ant-pagination-next', 'ant-pagination-disabled' => ! $paginator->hasMorePages()])>
      @if ($paginator->hasMorePages())
        <a class="ant-pagination-item-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau">{{ ant_icon('right') }}</a>
      @else
        <button class="ant-pagination-item-link" type="button" tabindex="-1" disabled>{{ ant_icon('right') }}</button>
      @endif
    </li>
  </ul>
@endif
