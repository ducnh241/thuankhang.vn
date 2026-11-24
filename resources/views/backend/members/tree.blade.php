@extends('layouts.backend')

{{-- Cây nhị phân từ một thành viên trở xuống (mở từ nút "Xem cây nhị phân" ở trang chi tiết thành viên). Mỗi node: Nhánh
     A (bên trái, L) / Nhánh B (bên phải, R), họ tên, cấp bậc (màu theo thứ tự cấp), doanh số tháng hiện tại của riêng người đó và của cả nhánh; chỗ
     trống vẽ ô nét đứt; nhánh yếu hơn có nhãn. ?depth = số tầng bên dưới. Node ở tầng cuối còn người bên dưới thì có link
     "Xem tiếp" (mở cây từ node đó). Vẽ bằng danh sách lồng nhau + CSS (.btree trong app.css), cuộn ngang khi cây rộng. --}}
@php
    use App\Http\Controllers\Backend\MemberController;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $month = now()->format('m/Y');
    [$minDepth, $maxDepth] = MemberController::TREE_DEPTHS;
    $left = $tree['children']['L'] ?? null;
    $right = $tree['children']['R'] ?? null;
@endphp

@section('title', 'Cây nhị phân - '.$member->full_name)

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.members.index') }}" class="page-topbar-crumb max-md:hidden" title="Về danh sách thành viên">Thành viên</a>
    <span class="page-topbar-sep max-md:hidden" aria-hidden="true">/</span>
    <a href="{{ route('backend.members.show', $member) }}" class="page-topbar-crumb" title="Về trang chi tiết">{{ $member->full_name }}</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">Cây nhị phân</h1>
  </div>
  <div class="page-topbar-right">
    @if ($member->parent)
      <a href="{{ route('backend.members.tree', [$member->parent, 'depth' => $depth]) }}" class="ant-btn" title="Xem cây từ người đứng trên: {{ $member->parent->full_name }}">{{ ant_icon('arrow-up') }}<span class="max-sm:hidden">Lên một tầng</span></a>
    @endif
  </div>
@endsection

@section('content')
<div class="mx-auto flex max-w-[1400px] flex-col gap-4">
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-body flex flex-wrap items-center gap-x-6 gap-y-3">
      <div>
        <div class="text-xs {{ $muted }}">Doanh số tháng {{ $month }} (tiền hàng các đơn đã thanh toán)</div>
        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm" data-tree-summary>
          <span>Nhánh A: <strong class="tabular-nums">{{ Money::format($left['branch_sales'] ?? 0) }} đ</strong> <span class="{{ $muted }}">· {{ $count($left['branch_count'] ?? 0) }} người</span></span>
          <span>Nhánh B: <strong class="tabular-nums">{{ Money::format($right['branch_sales'] ?? 0) }} đ</strong> <span class="{{ $muted }}">· {{ $count($right['branch_count'] ?? 0) }} người</span></span>
          @if ($tree['weaker'])<span class="ant-tag ant-tag-gold m-0">Yếu hơn: {{ $tree['weaker'] === 'L' ? 'Nhánh A' : 'Nhánh B' }}</span>@endif
        </div>
      </div>
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <span class="{{ $muted }}">Số tầng:</span>
        <div class="ant-btn-group" role="group" aria-label="Số tầng hiển thị">
          @foreach (range($minDepth, $maxDepth) as $option)
            <a href="{{ route('backend.members.tree', [$member, 'depth' => $option]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $option === $depth]) @if ($option === $depth) aria-current="true" @endif>{{ $option }}</a>
          @endforeach
        </div>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-[#f0f0f0] px-6 py-3 text-xs {{ $muted }}" data-tree-legend>
      <span>Cấp bậc:</span>
      @foreach ($levels as $level)
        <span @class(['ant-tag m-0', 'ant-tag-'.$levelColors[$level->id] => $levelColors[$level->id] !== ''])>{{ $level->name }}</span>
      @endforeach
      <span class="ml-auto">Mỗi ô: doanh số tháng của riêng người đó · "Cả nhánh" gồm người đó và mọi người bên dưới.</span>
    </div>
  </div>

  {{-- Thu phóng (−, %, +, Vừa khung; Ctrl / ⌘ + lăn chuột; phím + − 0 khi đang chọn sơ đồ) + xem toàn màn hình; kéo chuột để
       di chuyển. JS: resources/backend/js/tree-zoom.js ([data-tree-zoom]); mức thu phóng nhớ theo trình duyệt. --}}
  <div class="ant-card ant-card-bordered btree-card" data-tree-zoom>
    <div class="btree-toolbar" role="toolbar" aria-label="Thu phóng sơ đồ">
      <span class="btree-toolbar-title">{{ $member->full_name }} <span class="{{ $muted }}">· {{ $depth }} tầng</span></span>
      <div class="ant-btn-group">
        <button type="button" class="ant-btn ant-btn-sm" data-zoom="out" title="Thu nhỏ (−)" aria-label="Thu nhỏ">{{ ant_icon('zoom-out') }}</button>
        <button type="button" class="ant-btn ant-btn-sm btree-zoom-value" data-zoom="reset" title="Về 100% (0)" aria-label="Về kích thước gốc">100%</button>
        <button type="button" class="ant-btn ant-btn-sm" data-zoom="in" title="Phóng to (+)" aria-label="Phóng to">{{ ant_icon('zoom-in') }}</button>
      </div>
      <button type="button" class="ant-btn ant-btn-sm" data-zoom="fit" title="Thu nhỏ vừa khung để thấy cả cây">{{ ant_icon('column-width') }}<span class="max-sm:hidden">Vừa khung</span></button>
      <button type="button" class="ant-btn ant-btn-sm" data-fullscreen title="Xem toàn màn hình (Esc để thoát)">
        <span data-fullscreen-enter>{{ ant_icon('fullscreen') }}<span class="max-sm:hidden">Toàn màn hình</span></span>
        <span data-fullscreen-exit hidden>{{ ant_icon('fullscreen-exit') }}<span class="max-sm:hidden">Thoát toàn màn hình</span></span>
      </button>
    </div>
    <div class="btree" id="binary-tree" tabindex="0" aria-label="Cây nhị phân của {{ $member->full_name }}">
      <ul>
        @include('backend.members._tree-node', ['node' => $tree, 'side' => null, 'weak' => false, 'isTop' => true])
      </ul>
    </div>
  </div>
</div>
@endsection
