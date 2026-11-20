@extends('layouts.backend')

{{-- Chi tiết thành viên (A10): thông tin, cây giới thiệu (F1, bấm mở rộng xem F2 của từng F1 — table-expand.js), lịch sử
     thay đổi; cột phụ: người giới thiệu (+ form sửa, bắt buộc lý do — member/referrer), khóa / mở tài khoản (member/lock). --}}
@php
    use App\Http\Controllers\Backend\MemberController;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $statusTag = fn ($m) => $m->isLocked()
        ? '<span class="ant-tag ant-tag-red m-0">Đang khóa</span>'
        : ($m->isActivated() ? '<span class="ant-tag ant-tag-green m-0">Đã kích hoạt</span>' : '<span class="ant-tag m-0">Chưa kích hoạt</span>');
    $hasError = fn (string $field) => $errors->has($field) ? ' ant-form-item-has-error ant-form-item-with-help' : '';
    $memberLink = fn ($m) => '<a href="'.e(route('backend.members.show', $m)).'">'.e($m->full_name).'</a>';
@endphp

@section('title', $member->full_name.' - Thành viên')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.members.index') }}" class="page-topbar-crumb" title="Về danh sách thành viên">Thành viên</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">{{ $member->full_name }}</h1>
    {!! $statusTag($member) !!}
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-3">
  {{-- Cột chính --}}
  <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
    <div class="ant-card ant-card-bordered" data-card="info">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Họ và tên</dt><dd>{{ $member->full_name }}</dd>
          <dt>Số điện thoại</dt><dd class="tabular-nums">{{ $member->phone }}</dd>
          <dt>Email</dt><dd>{{ $member->email ?: '—' }}</dd>
          <dt>Mã giới thiệu</dt><dd class="font-mono">{{ $member->referral_code }}</dd>
          <dt>Cấp hiện tại</dt><dd>{{ $member->level?->name }}</dd>
          <dt>Doanh số cộng dồn</dt>
          <dd class="tabular-nums">{{ Money::format($member->cumulative_amount) }} đ
            @if ($nextLevel)<span class="{{ $muted }}">— còn {{ Money::format($nextLevel->threshold_amount - $member->cumulative_amount) }} đ để lên {{ $nextLevel->name }}</span>@endif
          </dd>
          <dt>Kích hoạt</dt><dd>{{ $member->activated_at?->format('d/m/Y H:i') ?? 'Chưa (chưa có đơn thanh toán)' }}</dd>
          <dt>KYC</dt>
          <dd data-member-kyc>
            @if ($member->kyc)
              <span class="ant-tag ant-tag-{{ \App\Models\MemberKyc::STATUS_COLORS[$member->kyc->status] }}">{{ $member->kyc->statusLabel() }}</span>
              @can('kyc')<a href="{{ route('backend.kyc.show', $member->kyc) }}">Xem hồ sơ</a>@endcan
            @else
              Chưa gửi hồ sơ
            @endif
          </dd>
          <dt>Mã QR nhận tiền</dt>
          <dd data-member-qr>
            @if ($member->hasQr())
              @php $qrSrc = route('backend.members.qr', $member).'?v='.$member->qr_uploaded_at?->timestamp; @endphp
              <a href="{{ $qrSrc }}" target="_blank" rel="noopener" title="Mở ảnh cỡ đầy đủ ở tab mới"><img src="{{ $qrSrc }}" alt="Mã QR nhận tiền" loading="lazy" style="width: 120px; height: 120px; object-fit: contain; border: 1px solid #f0f0f0; border-radius: 6px; background: #fff" /></a>
              <div class="text-xs {{ $muted }}">Tải lên {{ $member->qr_uploaded_at?->format('d/m/Y H:i') }}
                @if ($member->qrChangedAfterKyc())<span class="ant-tag ant-tag-gold m-0 ml-1" title="Đối chiếu lại với tài khoản ngân hàng trong hồ sơ KYC trước khi chuyển tiền">Đổi sau khi duyệt KYC</span>@endif
              </div>
            @else
              Chưa có
            @endif
          </dd>
          <dt>Tham gia</dt><dd class="tabular-nums">{{ $member->created_at?->format('d/m/Y H:i') }}</dd>
          <dt>Đăng nhập gần nhất</dt><dd class="tabular-nums">{{ $member->last_login_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        </dl>
      </div>
    </div>

    {{-- Đơn hàng gần nhất của thành viên --}}
    @can('order')
      <div class="ant-card ant-card-bordered" data-card="orders">
        <div class="ant-card-head"><div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">Đơn hàng <span class="font-normal {{ $muted }}">— {{ $count($orderCount) }} đơn</span></div>
          @if ($orderCount > $orders->count())
            <div class="ant-card-extra"><a href="{{ route('backend.orders.index', ['q' => $member->phone]) }}">Xem tất cả</a></div>
          @endif
        </div></div>
        <div class="ant-table ant-table-small">
          <div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto">
              <tbody class="ant-table-tbody">
                @forelse ($orders as $order)
                  @php $orderStatus = $order->displayStatus(); @endphp
                  <tr class="ant-table-row" data-order="{{ $order->code }}">
                    <td class="ant-table-cell"><a href="{{ route('backend.orders.show', $order) }}" class="font-mono">{{ $order->code }}</a></td>
                    <td class="ant-table-cell tabular-nums {{ $muted }}">{{ $order->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($order->total_amount) }} đ</td>
                    <td class="ant-table-cell"><span class="ant-tag ant-tag-{{ \App\Models\Order::STATUS_COLORS[$orderStatus] }} m-0">{{ $order->statusLabel() }}</span></td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="4" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có đơn hàng.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div></div>
        </div>
      </div>
    @endcan

    {{-- Cây giới thiệu: F1 (người thành viên giới thiệu trực tiếp), mở rộng từng F1 để xem F2 --}}
    <div class="ant-card ant-card-bordered" data-card="tree">
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">
            Cây giới thiệu
            <span class="font-normal {{ $muted }}">— {{ $count($member->referrals_count) }} F1 · {{ $count($f2Count) }} F2</span>
          </div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto">
            <thead class="ant-table-thead">
              <tr>
                <th class="ant-table-cell ant-table-row-expand-icon-cell" style="width: 48px"></th>
                <th class="ant-table-cell">F1</th>
                <th class="ant-table-cell">Cấp</th>
                <th class="ant-table-cell" style="text-align: right">Doanh số</th>
                <th class="ant-table-cell" style="text-align: right">Số F1 của họ</th>
                <th class="ant-table-cell">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="ant-table-tbody">
              @forelse ($f1 as $child)
                @php $grand = $f2[$child->id] ?? collect(); @endphp
                <tr class="ant-table-row" data-f1="{{ $child->id }}">
                  <td class="ant-table-cell ant-table-row-expand-icon-cell">
                    @if ($grand->isNotEmpty())
                      <button type="button" class="ant-table-row-expand-icon ant-table-row-expand-icon-collapsed" data-expand="f2-{{ $child->id }}" aria-label="Mở rộng" aria-expanded="false"></button>
                    @endif
                  </td>
                  <td class="ant-table-cell">{!! $memberLink($child) !!}<div class="text-xs tabular-nums {{ $muted }}">{{ $child->phone }} · {{ $child->created_at?->format('d/m/Y') }}</div></td>
                  <td class="ant-table-cell">{{ $child->level?->name }}</td>
                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($child->cumulative_amount) }} đ</td>
                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ $count($child->referrals_count) }}</td>
                  <td class="ant-table-cell">{!! $statusTag($child) !!}</td>
                </tr>
                @if ($grand->isNotEmpty())
                  <tr class="ant-table-expanded-row ant-table-expanded-row-level-1 hidden" data-expanded-for="f2-{{ $child->id }}">
                    <td class="ant-table-cell" colspan="6">
                      <div class="mb-1 text-xs {{ $muted }}">F2 qua {{ $child->full_name }}</div>
                      <div class="ant-table ant-table-small ant-table-bordered">
                        <div class="ant-table-container"><div class="ant-table-content">
                          <table style="table-layout: auto">
                            <tbody class="ant-table-tbody">
                              @foreach ($grand as $g)
                                <tr class="ant-table-row" data-f2="{{ $g->id }}">
                                  <td class="ant-table-cell">{!! $memberLink($g) !!} <span class="text-xs tabular-nums {{ $muted }}">{{ $g->phone }}</span></td>
                                  <td class="ant-table-cell">{{ $g->level?->name }}</td>
                                  <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($g->cumulative_amount) }} đ</td>
                                  <td class="ant-table-cell">{!! $statusTag($g) !!}</td>
                                </tr>
                              @endforeach
                            </tbody>
                          </table>
                        </div></div>
                      </div>
                    </td>
                  </tr>
                @endif
              @empty
                <tr class="ant-table-placeholder"><td colspan="6" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa giới thiệu ai.</p></div></td></tr>
              @endforelse
            </tbody>
          </table>
        </div></div>
      </div>
      @if ($member->referrals_count > MemberController::F1_LIMIT)
        <div class="ant-card-body text-xs {{ $muted }}">Đang hiện {{ MemberController::F1_LIMIT }} F1 mới nhất / {{ $count($member->referrals_count) }}.</div>
      @endif
    </div>

    {{-- Vết lên cấp (Step 7): do đơn nào, doanh số cộng dồn lúc đó --}}
    <div class="ant-card ant-card-bordered" data-card="levels">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử lên cấp</div></div></div>
      <div class="ant-card-body">
        @forelse ($levelHistory as $row)
          <div @class(['flex flex-wrap gap-x-2 text-sm', 'mt-2' => ! $loop->first])>
            <span class="tabular-nums {{ $muted }}">{{ $row->created_at?->format('d/m/Y H:i') }}</span>
            <span class="font-medium">{{ $row->fromLevel?->name ?? '—' }} → {{ $row->toLevel?->name }}</span>
            <span class="{{ $muted }}">
              @if ($row->order)
                đơn @can('order')<a href="{{ route('backend.orders.show', $row->order) }}" class="font-mono">{{ $row->order->code }}</a>@else<span class="font-mono">{{ $row->order->code }}</span>@endcan,
              @elseif ($row->note)
                {{ mb_strtolower($row->note) }},
              @endif
              doanh số cộng dồn {{ Money::format($row->cumulative_after) }} đ
            </span>
          </div>
        @empty
          <p class="m-0 {{ $muted }}">Chưa lên cấp lần nào. Cấp tự xét khi đơn của thành viên được xác nhận thanh toán.</p>
        @endforelse
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="history">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử thay đổi</div></div></div>
      <div class="ant-card-body">
        @forelse ($history as $log)
          <div @class(['flex flex-wrap gap-x-2 text-sm', 'mt-2' => ! $loop->first])>
            <span class="tabular-nums {{ $muted }}">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
            <span class="font-medium">{{ $log->actorName() }}</span>
            <span>{{ $log->description ?: $log->actionLabel() }}</span>
          </div>
        @empty
          <p class="m-0 {{ $muted }}">Chưa có thay đổi nào.</p>
        @endforelse
      </div>
    </div>
  </div>

  {{-- Cột phụ --}}
  <div class="flex min-w-0 flex-col gap-4">
    {{-- Ví hoa hồng (Step 8): ví rút tiền (70%) + ví tái tiêu dùng (20%), thuế TNCN (10%) đã trừ; chi tiết ở trang Sổ hoa hồng --}}
    <div class="ant-card ant-card-bordered" data-card="wallet">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Ví hoa hồng</div></div></div>
      <div class="ant-card-body">
        <div class="text-xs {{ $muted }}">Ví rút tiền</div>
        <div class="text-2xl font-semibold tabular-nums" data-wallet-balance>{{ Money::format($wallet['balance']) }} đ</div>
        <dl class="profile-facts m-0 mt-3">
          <dt>Ví tái tiêu dùng</dt><dd class="tabular-nums" data-wallet-reconsume>{{ Money::format($wallet['reconsume']) }} đ</dd>
          <dt>Thuế TNCN đã trừ</dt><dd class="tabular-nums" data-wallet-tax>{{ Money::format($wallet['tax']) }} đ</dd>
          <dt>Hoa hồng tháng này</dt><dd class="tabular-nums" data-wallet-month>{{ Money::format($wallet['month']) }} đ</dd>
          <dt>F1 (trực tiếp)</dt><dd class="tabular-nums">{{ Money::format($wallet['f1']) }} đ</dd>
          <dt>F2 (gián tiếp)</dt><dd class="tabular-nums">{{ Money::format($wallet['f2']) }} đ</dd>
          <dt>Số dòng</dt><dd class="tabular-nums">{{ $count($wallet['lines']) }} đã cộng ví{{ $wallet['skipped'] ? ' · '.$count($wallet['skipped']).' không được nhận' : '' }}</dd>
        </dl>
        <a href="{{ route('backend.members.commissions', $member) }}" class="ant-btn mt-3" id="view-commissions">{{ ant_icon('wallet') }}<span>Xem sổ hoa hồng</span></a>
      </div>
    </div>

    {{-- Cây nhị phân: vị trí của thành viên + hai nhánh bên dưới — Nhánh A (L) và Nhánh B (R): số người, doanh số tháng,
         nhánh yếu hơn (để tính thưởng nhóm). Người đăng ký tự chọn nhánh; vị trí gán một lần, không sửa được. --}}
    @php
        $sides = \App\Services\BinaryTree::SIDES;
    @endphp
    <div class="ant-card ant-card-bordered" data-card="binary">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Cây nhị phân</div></div></div>
      <div class="ant-card-body">
        <div class="mb-3" data-binary-position>
          <span class="{{ $muted }}">Vị trí:</span>
          @if ($member->parent)
            {{ $sides[$member->position] ?? 'Nhánh' }} của {!! $memberLink($member->parent) !!}
          @elseif ($member->isRoot())
            <span class="ant-tag ant-tag-blue m-0">Ông tổng</span> gốc của cây
          @elseif ($member->referrer_id === null)
            <span class="ant-tag m-0">Gốc cây riêng</span> không có người giới thiệu, không thuộc cây của ông tổng
          @else
            <span class="ant-tag ant-tag-orange m-0">Chưa xếp</span>
          @endif
        </div>
        @if ($member->referrer)
          <div class="mb-3 text-sm" data-binary-chosen>
            <span class="{{ $muted }}">Nhánh tự chọn khi đăng ký:</span>
            @if ($member->sponsor_side)
              <strong>{{ $sides[$member->sponsor_side] }}</strong> của {!! $memberLink($member->referrer) !!}
            @else
              <span class="{{ $muted }}">không có (đăng ký trước khi có lựa chọn — hệ thống tự xếp)</span>
            @endif
          </div>
        @endif
        @foreach ($sides as $side => $label)
          @php $leg = $legs[$side]; @endphp
          <div @class(['rounded border border-[#f0f0f0] px-3 py-2', 'mt-2' => ! $loop->first]) data-leg="{{ $side }}">
            <div class="flex flex-wrap items-center gap-x-2">
              <span class="font-medium">{{ $label }}</span>
              @if ($legs['weaker'] === $side && $legs['L']['head'] && $legs['R']['head'])<span class="ant-tag ant-tag-gold m-0">Yếu hơn</span>@endif
              <span class="ml-auto tabular-nums {{ $muted }}">{{ $count($leg['count']) }} thành viên</span>
            </div>
            @if ($leg['head'])
              <div class="text-sm">Đứng đầu: {!! $memberLink($leg['head']) !!} <span class="{{ $muted }}">· {{ $leg['head']->level?->name }}</span></div>
              <div class="text-xs {{ $muted }}">Doanh số tháng này: <span class="tabular-nums">{{ Money::format($leg['sales']) }} đ</span></div>
            @else
              <div class="text-sm {{ $muted }}">Còn trống</div>
            @endif
          </div>
        @endforeach
        <a href="{{ route('backend.members.tree', $member) }}" class="ant-btn mt-3" id="view-binary-tree">{{ ant_icon('apartment') }}<span>Xem cây nhị phân</span></a>
<div class="mt-3 text-xs {{ $muted }}" data-binary-next>Người đăng ký bằng mã của thành viên này tự chọn <strong>Nhánh A</strong> hoặc <strong>Nhánh B</strong> lúc đăng ký và được xếp vào vị trí trống ngoài cùng của nhánh đã chọn. Vị trí đã xếp là cố định.</div>
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="referrer">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Người giới thiệu</div></div></div>
      <div class="ant-card-body">
        @if ($member->referrer)
          <div class="font-medium">{!! $memberLink($member->referrer) !!}</div>
          <div class="text-xs {{ $muted }}"><span class="font-mono">{{ $member->referrer->referral_code }}</span> · {{ $member->referrer->level?->name }}</div>
          @if ($member->referrer->referrer)
            <div class="mt-2 text-xs {{ $muted }}">Tuyến trên tiếp (nhận F2 từ thành viên này): {!! $memberLink($member->referrer->referrer) !!}</div>
          @endif
        @else
          <p class="m-0 {{ $muted }}">Không có người giới thiệu.</p>
        @endif

        @can('member/referrer')
          <form method="POST" action="{{ route('backend.members.referrer', $member) }}" class="ant-form ant-form-vertical mt-4 border-t border-[#f0f0f0] pt-4" novalidate id="referrer-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="referrer" />
            <div class="ant-row ant-form-item{{ $hasError('referral_code') }}">
              <div class="ant-col ant-form-item-label"><label for="new_referrer" title="Mã người giới thiệu mới">Đổi sang mã người giới thiệu</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <input type="text" id="new_referrer" name="referral_code" value="{{ old('_form') === 'referrer' && is_string(old('referral_code')) ? old('referral_code') : '' }}" class="ant-input [&:not(:placeholder-shown)]:uppercase" maxlength="20" placeholder="Để trống = không có người giới thiệu" />
                </div></div>
                {{ ant_form_error($errors->first('referral_code')) }}
              </div>
            </div>
            <div class="ant-row ant-form-item{{ old('_form') === 'referrer' ? $hasError('reason') : '' }}">
              <div class="ant-col ant-form-item-label"><label for="referrer_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
              <div class="ant-col ant-form-item-control">
                <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                  <textarea id="referrer_reason" name="reason" rows="2" maxlength="500" class="ant-input" placeholder="VD: Khách nhập nhầm mã khi đăng ký">{{ old('_form') === 'referrer' && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                </div></div>
                {{ old('_form') === 'referrer' ? ant_form_error($errors->first('reason')) : '' }}
              </div>
            </div>
            <button type="submit" class="ant-btn">{{ ant_icon('sync') }}<span>Đổi người giới thiệu</span></button>
            <div class="mt-2 text-xs {{ $muted }}">Chỉ áp dụng cho các đơn thanh toán sau khi đổi; hoa hồng đã ghi không thay đổi. Vị trí trong cây nhị phân không đổi theo. Có ghi nhật ký.</div>
          </form>
        @endcan
      </div>
    </div>

    {{-- Kích hoạt thủ công (member/activate): thường tự kích hoạt khi có đơn thanh toán đầu tiên --}}
    @if (! $member->isActivated())
      @can('member/activate')
        <div class="ant-card ant-card-bordered" data-card="activate">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Kích hoạt tài khoản</div></div></div>
          <div class="ant-card-body">
            <form method="POST" action="{{ route('backend.members.activate', $member) }}" class="ant-form ant-form-vertical" novalidate id="activate-form">
              @csrf
              <input type="hidden" name="_form" value="activate" />
              <p class="mt-0 mb-3 {{ $muted }}">Tài khoản tự kích hoạt khi có đơn thanh toán đầu tiên. Kích hoạt thủ công (vd khách đã mua ngoài hệ thống) thì thành viên được nhận hoa hồng từ đơn của F1 / F2 và lên cấp {{ $firstLevel?->name ?? 'hưởng hoa hồng đầu tiên' }}. Không hủy được.</p>
              <div class="ant-row ant-form-item{{ old('_form') === 'activate' ? $hasError('reason') : '' }}">
                <div class="ant-col ant-form-item-label"><label for="activate_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="activate_reason" name="reason" rows="2" maxlength="500" class="ant-input" placeholder="VD: Khách đã mua tại văn phòng, đơn số …">{{ old('_form') === 'activate' && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                  </div></div>
                  {{ old('_form') === 'activate' ? ant_form_error($errors->first('reason')) : '' }}
                </div>
              </div>
              <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Kích hoạt</span></button>
            </form>
          </div>
        </div>
      @endcan
    @endif

    @can('member/lock')
      <div class="ant-card ant-card-bordered" data-card="lock">
        <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Trạng thái tài khoản</div></div></div>
        <div class="ant-card-body">
          <form method="POST" action="{{ route('backend.members.lock', $member) }}" class="ant-form ant-form-vertical" novalidate>
            @csrf
            <input type="hidden" name="action" value="{{ $member->isLocked() ? 'unlock' : 'lock' }}" />
            <p class="mt-0 mb-3 {{ $muted }}">
              {{ $member->isLocked()
                  ? 'Tài khoản đang bị khóa: không đăng nhập được, không nhận hoa hồng (ghi "bỏ qua").'
                  : 'Khóa: thành viên bị đăng xuất, không đăng nhập được và không nhận hoa hồng cho tới khi mở lại.' }}
            </p>
            <div class="ant-row ant-form-item">
              <div class="ant-col ant-form-item-label"><label for="lock_reason" title="Lý do">Lý do (không bắt buộc)</label></div>
              <div class="ant-col ant-form-item-control"><div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                <textarea id="lock_reason" name="reason" rows="2" maxlength="500" class="ant-input"></textarea>
              </div></div></div>
            </div>
            @if ($member->isLocked())
              <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('unlock-tt') }}<span>Mở khóa tài khoản</span></button>
            @else
              <button type="submit" class="ant-btn ant-btn-dangerous">{{ ant_icon('lock') }}<span>Khóa tài khoản</span></button>
            @endif
          </form>
        </div>
      </div>
    @endcan
  </div>
</div>
@endsection
