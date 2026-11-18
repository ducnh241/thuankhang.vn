{{-- Một node của cây nhị phân (đệ quy). Biến: $node (BinaryTree::tree) hoặc null = chỗ trống; $side (L | R | null ở node
     gốc đang xem); $weak (nhánh yếu hơn trong hai nhánh con của node cha); $isTop (node đang xem). Biến dùng chung từ view
     cha: $levelColors, $depth, $muted, $count. --}}
@php use App\Support\Money; $sideLabel = ['L' => 'Nhánh A', 'R' => 'Nhánh B'][$side] ?? null; @endphp
<li>
  @if ($node === null)
    <div class="btree-node btree-empty" data-node="empty" data-side="{{ $side }}">
      <span @class(['btree-side', 'btree-side-left' => $side === 'L', 'btree-side-right' => $side === 'R'])>{{ $sideLabel }}</span>
      <div class="{{ $muted }}">Còn trống</div>
    </div>
  @else
    @php $item = $node['member']; $color = $levelColors[$item->level_id] ?? ''; @endphp
    <div @class(['btree-node', 'btree-top' => $isTop, 'btree-locked' => $item->isLocked()]) data-node="{{ $item->id }}" data-side="{{ $side }}">
      <div class="flex items-center gap-1">
        @if ($sideLabel)
          <span @class(['btree-side', 'btree-side-left' => $side === 'L', 'btree-side-right' => $side === 'R'])>{{ $sideLabel }}</span>
        @else
          <span class="btree-side btree-side-top">{{ $item->parent_id ? 'Đang xem' : 'Gốc' }}</span>
        @endif
        @if ($weak)<span class="ant-tag ant-tag-gold m-0 ml-auto" title="Nhánh yếu hơn: doanh số tháng thấp hơn (bằng nhau thì ít người hơn)">Yếu hơn</span>@endif
      </div>
      <a href="{{ route('backend.members.show', $item) }}" class="btree-name" title="{{ $item->full_name }} · {{ $item->referral_code }}">{{ $item->full_name }}</a>
      <div><span @class(['ant-tag m-0', 'ant-tag-'.$color => $color !== ''])>{{ $item->level?->name ?? '—' }}</span>@if ($item->isLocked()) <span class="ant-tag ant-tag-red m-0">Khóa</span>@endif</div>
      <div class="btree-sales" title="Doanh số tháng này của riêng thành viên">{{ Money::format($node['sales']) }} đ</div>
      @if ($node['branch_count'] > 1)
        <div class="btree-branch" title="Cả nhánh: người này và mọi người bên dưới">Cả nhánh: {{ $count($node['branch_count']) }} người · {{ Money::format($node['branch_sales']) }} đ</div>
      @endif
      @if ($node['more'])
        <a href="{{ route('backend.members.tree', [$item, 'depth' => $depth]) }}" class="btree-more">Xem tiếp {{ $count($node['branch_count'] - 1) }} người bên dưới ›</a>
      @elseif (! $isTop && $node['children'])
        <a href="{{ route('backend.members.tree', [$item, 'depth' => $depth]) }}" class="btree-more" title="Xem cây bắt đầu từ {{ $item->full_name }}">Xem từ đây</a>
      @endif
    </div>
    @if ($node['children'])
      <ul>
        @foreach (['L', 'R'] as $childSide)
          @include('backend.members._tree-node', ['node' => $node['children'][$childSide], 'side' => $childSide, 'weak' => $node['weaker'] === $childSide, 'isTop' => false])
        @endforeach
      </ul>
    @endif
  @endif
</li>
