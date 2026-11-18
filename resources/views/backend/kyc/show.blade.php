@extends('layouts.backend')

{{-- Hồ sơ KYC (A11): thông tin CCCD + tài khoản ngân hàng, ảnh 2 mặt CCCD (quyền kyc/view_document; bấm ảnh mở cỡ đầy đủ ở
     tab mới), lịch sử xử lý; cột phụ: thành viên, duyệt (kèm ô "kích hoạt tài khoản" nếu có quyền member/activate) / không
     duyệt kèm lý do (kyc/review). Hai form trên trang: lỗi phân biệt bằng trường ẩn _form. --}}
@php
    use App\Models\MemberKyc;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canActivate = $me->can('member/activate') && ! $member->isActivated();
    $hasDuplicate = $duplicates['id_number']->isNotEmpty() || $duplicates['bank']->isNotEmpty();
    $nameMatches = MemberKyc::normalizeName($kyc->full_name) === MemberKyc::normalizeName($kyc->bank_account_name);
    $memberStatus = $member->isLocked()
        ? '<span class="ant-tag ant-tag-red m-0">Đang khóa</span>'
        : ($member->isActivated() ? '<span class="ant-tag ant-tag-green m-0">Đã kích hoạt</span>' : '<span class="ant-tag m-0">Chưa kích hoạt</span>');
    $dupList = fn ($items) => $items->map(fn ($d) => e($d->member?->full_name).' ('.e($d->member?->referral_code).', '.e(mb_strtolower($d->statusLabel())).')')->implode(', ');
@endphp

@section('title', $member->full_name.' - KYC')
@section('inline-errors', '1')

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.kyc.index') }}" class="page-topbar-crumb" title="Về danh sách KYC">KYC</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">{{ $member->full_name }}</h1>
    <span class="ant-tag ant-tag-{{ MemberKyc::STATUS_COLORS[$kyc->status] }} m-0" id="kyc-status">{{ $kyc->statusLabel() }}</span>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto grid max-w-[1200px] grid-cols-1 items-start gap-4 lg:grid-cols-3">
  {{-- Cột chính --}}
  <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
    @if ($kyc->isRejected())
      <div class="ant-alert ant-alert-error" role="note">
        {{ ant_icon('close-circle', 'ant-alert-icon') }}
        <div class="ant-alert-content"><div class="ant-alert-message">Không được duyệt. Lý do: {{ $kyc->reject_reason }}</div></div>
      </div>
    @endif
    @if ($hasDuplicate)
      <div class="ant-alert ant-alert-warning" role="alert" data-kyc-duplicates>
        {{ ant_icon('exclamation-circle', 'ant-alert-icon') }}
        <div class="ant-alert-content">
          <div class="ant-alert-message">Trùng với hồ sơ của thành viên khác</div>
          <div class="ant-alert-description">
            @if ($duplicates['id_number']->isNotEmpty())<div>Số CCCD đã có ở: {!! $dupList($duplicates['id_number']) !!}.</div>@endif
            @if ($duplicates['bank']->isNotEmpty())<div>Số tài khoản đã có ở: {!! $dupList($duplicates['bank']) !!}.</div>@endif
          </div>
        </div>
      </div>
    @endif

    <div class="ant-card ant-card-bordered" data-card="identity">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thông tin trên căn cước</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Họ và tên</dt><dd>{{ $kyc->full_name }}</dd>
          <dt>Số CCCD</dt>
          {{-- Số đầy đủ chỉ hiện với người được xem ảnh CCCD (để đối chiếu với ảnh) --}}
          <dd class="tabular-nums">@can('kyc/view_document'){{ $kyc->id_number }}@else{{ $kyc->maskedIdNumber() }}@endcan</dd>
          <dt>Ngày sinh</dt><dd class="tabular-nums">{{ $kyc->birth_date?->format('d/m/Y') }}</dd>
        </dl>
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="bank">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Tài khoản ngân hàng</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Ngân hàng</dt><dd>{{ $kyc->bankName() }}</dd>
          <dt>Số tài khoản</dt><dd class="tabular-nums">{{ $kyc->bank_account_number }}</dd>
          <dt>Tên chủ tài khoản</dt>
          <dd>{{ $kyc->bank_account_name }}
            @if ($nameMatches)
              <span class="ant-tag ant-tag-green ml-1">Trùng họ tên CCCD</span>
            @else
              <span class="ant-tag ant-tag-red ml-1">Khác họ tên CCCD</span>
            @endif
          </dd>
          {{-- Mã QR nhận tiền thành viên tải lên (form KYC hoặc trang thông tin tài khoản) — đối chiếu với số tài khoản trên --}}
          <dt>Mã QR nhận tiền</dt>
          <dd data-kyc-qr>
            @if ($member?->hasQr())
              @php $qrSrc = route('backend.members.qr', $member).'?v='.$member->qr_uploaded_at?->timestamp; @endphp
              <a href="{{ $qrSrc }}" target="_blank" rel="noopener" title="Mở ảnh cỡ đầy đủ ở tab mới"><img src="{{ $qrSrc }}" alt="Mã QR nhận tiền" loading="lazy" style="width: 160px; height: 160px; object-fit: contain; border: 1px solid #f0f0f0; border-radius: 6px; background: #fff" /></a>
              <div class="text-xs {{ $muted }}">Tải lên {{ $member->qr_uploaded_at?->format('d/m/Y H:i') }}
                @if ($member->qrChangedAfterKyc())<span class="ant-tag ant-tag-gold m-0 ml-1">Đổi sau khi duyệt KYC</span>@endif
              </div>
            @else
              <span class="{{ $muted }}">Chưa tải lên</span>
            @endif
          </dd>
        </dl>
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="documents">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Ảnh căn cước công dân</div></div></div>
      <div class="ant-card-body">
        @can('kyc/view_document')
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach (MemberKyc::SIDES as $side => $label)
              @php $src = route('backend.kyc.image', [$kyc, $side]).'?v='.$kyc->submitted_at?->timestamp; @endphp
              <figure class="m-0">
                <a href="{{ $src }}" target="_blank" rel="noopener" class="kyc-photo" title="Mở ảnh cỡ đầy đủ ở tab mới">
                  <img src="{{ $src }}" alt="{{ $label }} CCCD" loading="lazy" />
                </a>
                <figcaption class="mt-1 text-center text-xs {{ $muted }}">{{ $label }}</figcaption>
              </figure>
            @endforeach
          </div>
        @else
          <p class="m-0 {{ $muted }}">Bạn không có quyền xem ảnh CCCD.</p>
        @endcan
      </div>
    </div>

    <div class="ant-card ant-card-bordered" data-card="history">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử xử lý</div></div></div>
      <div class="ant-card-body">
        @forelse ($history as $log)
          <div @class(['flex flex-wrap gap-x-2 text-sm', 'mt-2' => ! $loop->first])>
            <span class="tabular-nums {{ $muted }}">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
            <span class="font-medium">{{ $log->actorName() }}</span>
            <span>{{ $log->description ?: $log->actionLabel() }}</span>
          </div>
        @empty
          <p class="m-0 {{ $muted }}">Chưa có thao tác nào.</p>
        @endforelse
      </div>
    </div>
  </div>

  {{-- Cột phụ --}}
  <div class="flex min-w-0 flex-col gap-4">
    <div class="ant-card ant-card-bordered" data-card="member">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Thành viên</div></div></div>
      <div class="ant-card-body">
        <dl class="profile-facts m-0">
          <dt>Họ và tên</dt>
          <dd>@can('member')<a href="{{ route('backend.members.show', $member) }}">{{ $member->full_name }}</a>@else{{ $member->full_name }}@endcan</dd>
          <dt>Số điện thoại</dt><dd class="tabular-nums">{{ $member->phone }}</dd>
          <dt>Mã giới thiệu</dt><dd class="font-mono">{{ $member->referral_code }}</dd>
          <dt>Cấp</dt><dd>{{ $member->level?->name }}</dd>
          <dt>Tài khoản</dt><dd>{!! $memberStatus !!}</dd>
          <dt>Gửi hồ sơ</dt><dd class="tabular-nums">{{ $kyc->submitted_at?->format('d/m/Y H:i') }}</dd>
          @if ($kyc->reviewed_at)
            <dt>{{ $kyc->isApproved() ? 'Duyệt' : 'Xử lý' }}</dt>
            <dd class="tabular-nums">{{ $kyc->reviewed_at->format('d/m/Y H:i') }}@if ($kyc->reviewer) · {{ $kyc->reviewer->name }}@endif</dd>
          @endif
        </dl>
      </div>
    </div>

    @if ($kyc->isPending())
      @can('kyc/review')
        <div class="ant-card ant-card-bordered" data-card="approve">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Duyệt hồ sơ</div></div></div>
          <div class="ant-card-body">
            <form method="POST" action="{{ route('backend.kyc.approve', $kyc) }}" class="ant-form ant-form-vertical" novalidate id="kyc-approve-form">
              @csrf
              <input type="hidden" name="_form" value="approve" />
              <p class="mt-0 mb-3 {{ $muted }}">Đối chiếu họ tên, số CCCD, ngày sinh với ảnh căn cước và tên chủ tài khoản trước khi duyệt.</p>
              @if ($canActivate)
                <div class="mb-3">
                  <label class="ant-checkbox-wrapper ant-checkbox-wrapper-checked"
                    ><span class="ant-checkbox ant-checkbox-checked"><input type="checkbox" class="ant-checkbox-input" name="activate" value="1" checked id="kyc-activate" /><span class="ant-checkbox-inner"></span></span
                    ><span>Kích hoạt tài khoản thành viên</span></label
                  >
                  <div class="mt-1 text-xs {{ $muted }}">Tài khoản đang "Chưa kích hoạt". Kích hoạt thì thành viên lên cấp {{ $firstLevel?->name ?? 'hưởng hoa hồng đầu tiên' }} và được nhận hoa hồng; không hủy được. Bỏ tick nếu chỉ muốn duyệt hồ sơ.</div>
                </div>
              @endif
              <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Duyệt hồ sơ</span></button>
            </form>
          </div>
        </div>

        <div class="ant-card ant-card-bordered" data-card="reject">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Không duyệt</div></div></div>
          <div class="ant-card-body">
            <form method="POST" action="{{ route('backend.kyc.reject', $kyc) }}" class="ant-form ant-form-vertical" novalidate id="kyc-reject-form">
              @csrf
              <input type="hidden" name="_form" value="reject" />
              <div class="ant-row ant-form-item{{ old('_form') === 'reject' && $errors->has('reason') ? ' ant-form-item-has-error ant-form-item-with-help' : '' }}">
                <div class="ant-col ant-form-item-label"><label for="reject_reason" class="ant-form-item-required" title="Lý do">Lý do</label></div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input"><div class="ant-form-item-control-input-content">
                    <textarea id="reject_reason" name="reason" rows="3" maxlength="500" class="ant-input" placeholder="VD: Ảnh mặt sau bị mờ, không đọc được; tên chủ tài khoản không trùng CCCD">{{ old('_form') === 'reject' && is_string(old('reason')) ? old('reason') : '' }}</textarea>
                  </div></div>
                  {{ old('_form') === 'reject' ? ant_form_error($errors->first('reason')) : '' }}
                </div>
              </div>
              <button type="submit" class="ant-btn ant-btn-dangerous">{{ ant_icon('close-circle') }}<span>Không duyệt</span></button>
              <div class="mt-2 text-xs {{ $muted }}">Thành viên thấy lý do này ở trang KYC và có thể sửa, gửi lại hồ sơ.</div>
            </form>
          </div>
        </div>
      @endcan
    @endif
  </div>
</div>
@endsection
