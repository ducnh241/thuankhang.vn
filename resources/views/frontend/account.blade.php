@extends('frontend.layouts.app')

{{-- Trang tài khoản thành viên (Step 3): thông tin, cấp, link / mã giới thiệu, người giới thiệu, số F1. Bảng điều khiển
     affiliate đầy đủ (S8: tiến độ lên cấp, F1 / F2, doanh số, hoa hồng) làm ở Step 10. --}}
@section('title', 'Tài khoản')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Xin chào, {{ $member->full_name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Thành viên từ {{ $member->created_at->format('d/m/Y') }}</p>
      </div>
      <div class="flex items-center gap-3">
      <a href="{{ route('member.orders.index') }}" class="rounded-full bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Đơn hàng của tôi</a>
      <form method="POST" action="{{ route('member.logout') }}">
        @csrf
        <button type="submit" class="rounded-full border border-slate-300 px-5 py-2 text-sm font-medium text-slate-700 hover:border-rose-400 hover:text-rose-600">Đăng xuất</button>
      </form>
      </div>
    </div>

    @include('frontend.partials.install-app', ['class' => 'mt-6'])

    {{-- Ví hoa hồng: số dư + hoa hồng tháng này; chi tiết từng dòng ở trang Ví --}}
    <a href="{{ route('member.wallet') }}" class="mt-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-2xl border border-brand-100 bg-brand-50 p-5 hover:border-brand-300" data-wallet-card>
      <div>
        <div class="text-xs font-medium tracking-wide text-brand-800 uppercase">Ví hoa hồng</div>
        <div class="mt-2 text-2xl font-bold text-brand-800 tabular-nums" data-wallet-balance>{{ \App\Support\Money::format($wallet['balance']) }}đ</div>
        <p class="mt-1 text-xs text-brand-800/80">Tháng này: <strong>+{{ \App\Support\Money::format($wallet['month']) }}đ</strong></p>
      </div>
      <span class="text-sm font-medium text-brand-700">Xem chi tiết ›</span>
    </a>

    <div class="mt-4 grid gap-4 md:grid-cols-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Cấp hiện tại</div>
        <div class="mt-2 text-xl font-bold text-brand-700" data-member-level>{{ $member->level?->name }}</div>
        @unless ($member->isActivated())
          <p class="mt-1 text-xs text-slate-500">Mua đơn hàng đầu tiên để kích hoạt tài khoản và bắt đầu nhận hoa hồng.</p>
        @endunless
        <p class="mt-2 text-xs text-slate-500">Đã mua: <strong class="text-slate-700" data-member-cumulative>{{ \App\Support\Money::format($member->cumulative_amount) }}đ</strong></p>
      </div>
      {{-- Đại lý bạn tuyển dụng = thành viên trực tiếp (F1): tổng số + xem danh sách họ tên / cấp --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Đại lý bạn tuyển dụng</div>
        <div class="mt-2 flex items-baseline justify-between gap-3">
          <span class="text-xl font-bold text-slate-900" data-f1-count>{{ number_format($f1Count, 0, ',', '.') }}</span>
          <a href="{{ route('member.team') }}" class="text-sm font-medium text-brand-700 hover:underline">Xem chi tiết ›</a>
        </div>
      </div>
      {{-- Thành viên (của chi nhánh mình): mọi người nằm dưới vị trí của mình trong cây nhị phân, tính xuống tất cả các nhánh con --}}
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Thành viên</div>
        <div class="mt-2 text-xl font-bold text-slate-900" data-team-count>{{ number_format($teamCount, 0, ',', '.') }}</div>
      </div>
    </div>

    {{-- Tiến độ lên cấp: cấp xét theo tổng tiền hàng tự mua cộng dồn của mọi đơn đã thanh toán; không bao giờ hạ cấp --}}
    @php
        $bought = (int) $member->cumulative_amount;
        $floor = (int) ($member->level?->threshold_amount ?? 0);
        $percent = $nextLevel ? (int) max(0, min(100, floor(($bought - $floor) * 100 / max(1, $nextLevel->threshold_amount - $floor)))) : 100;
        $money = fn ($amount) => \App\Support\Money::format($amount);
    @endphp
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6" data-level-progress>
      <h2 class="text-lg font-bold text-slate-900">Cấp bậc của bạn</h2>
      <p class="mt-1 text-sm text-slate-600">Cấp được xét theo tổng tiền hàng bạn tự mua, cộng dồn qua mọi đơn đã thanh toán (không tính phí giao hàng). Đã lên cấp thì không bị hạ.</p>
      <div class="mt-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
          <span class="font-semibold text-brand-700">{{ $member->level?->name }}</span>
          @if ($nextLevel)
            <span class="text-slate-600" data-level-next>Mua thêm <strong class="text-slate-900">{{ $money($nextLevel->threshold_amount - $bought) }}đ</strong> để lên <strong class="text-slate-900">{{ $nextLevel->name }}</strong></span>
          @else
            <span class="text-slate-600" data-level-next>Bạn đang ở cấp cao nhất</span>
          @endif
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="Tiến độ lên cấp kế tiếp">
          <div class="h-full rounded-full bg-brand-500" style="width: {{ $percent }}%"></div>
        </div>
      </div>
      <ol class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
        @foreach ($levels as $item)
          @php $reached = $bought >= $item->threshold_amount || (int) $member->level?->threshold_amount >= $item->threshold_amount; @endphp
          <li @class(['flex items-center justify-between gap-3 rounded-xl border px-3 py-2', 'border-brand-100 bg-brand-50' => $item->id === $member->level_id, 'border-slate-200' => $item->id !== $member->level_id])>
            <span @class(['font-medium', 'text-brand-800' => $reached, 'text-slate-700' => ! $reached])>{{ $reached ? '✓ ' : '' }}{{ $item->name }}</span>
            <span class="shrink-0 text-xs text-slate-500">{{ $item->threshold_amount <= 1 ? 'Có đơn đầu tiên' : 'Từ '.$money($item->threshold_amount).'đ' }}</span>
          </li>
        @endforeach
      </ol>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 class="text-lg font-bold text-slate-900">Giới thiệu bạn bè</h2>
      <p class="mt-1 text-sm text-slate-600">Gửi link hoặc mã dưới đây. Người đăng ký qua link / mã của bạn sẽ là F1 của bạn.</p>
      <div class="mt-4 grid gap-4 md:grid-cols-[200px_minmax(0,1fr)]">
        <div>
          <div class="text-xs text-slate-500">Mã giới thiệu</div>
          <div class="mt-1 flex items-center gap-2">
            <span class="rounded-lg bg-brand-50 px-3 py-2 font-mono text-lg font-bold tracking-wider text-brand-800" data-referral-code>{{ $member->referral_code }}</span>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:border-brand-500 hover:text-brand-700" data-copy="{{ $member->referral_code }}">Sao chép</button>
          </div>
        </div>
        <div class="min-w-0">
          <div class="text-xs text-slate-500">Link giới thiệu</div>
          <div class="mt-1 flex items-center gap-2">
            <input type="text" readonly value="{{ $member->referralLink() }}" class="w-full min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700" aria-label="Link giới thiệu" />
            <button type="button" class="shrink-0 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700" data-copy="{{ $member->referralLink() }}">Sao chép</button>
          </div>
        </div>
      </div>
    </section>

    {{-- KYC (S10): trạng thái hồ sơ + nút sang trang xác minh --}}
    @php
        $kyc = $member->kyc;
        [$kycLabel, $kycClass, $kycText, $kycButton] = match ($kyc?->status) {
            \App\Models\MemberKyc::STATUS_PENDING => ['Chờ duyệt', 'bg-amber-100 text-amber-800', 'Hồ sơ của bạn đang được kiểm tra.', 'Xem hồ sơ'],
            \App\Models\MemberKyc::STATUS_APPROVED => ['Đã duyệt', 'bg-brand-100 text-brand-800', 'Tài khoản đã được xác minh.', 'Xem hồ sơ'],
            \App\Models\MemberKyc::STATUS_REJECTED => ['Không được duyệt', 'bg-rose-100 text-rose-700', 'Hồ sơ chưa hợp lệ: '.($kyc->reject_reason ?: 'thông tin không hợp lệ').'. Hãy sửa và gửi lại.', 'Gửi lại hồ sơ'],
            default => ['Chưa xác minh', 'bg-slate-100 text-slate-600', 'Gửi thông tin tài khoản ngân hàng và ảnh 2 mặt căn cước để xác minh tài khoản.', 'Xác minh ngay'],
        };
    @endphp
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6" data-kyc-card>
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
          <h2 class="flex flex-wrap items-center gap-2 text-lg font-bold text-slate-900">Xác minh tài khoản (KYC) <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $kycClass }}" data-kyc-badge>{{ $kycLabel }}</span></h2>
          <p class="mt-1 text-sm text-slate-600">{{ $kycText }}</p>
        </div>
        <a href="{{ route('member.kyc') }}" @class(['shrink-0 rounded-full px-5 py-2 text-sm font-semibold',
          'bg-brand-600 text-white hover:bg-brand-700' => ! $kyc || $kyc->isRejected(),
          'border border-slate-300 text-slate-700 hover:border-brand-500 hover:text-brand-700' => $kyc && ! $kyc->isRejected()])>{{ $kycButton }}</a>
      </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <div class="flex items-center justify-between gap-4">
        <h2 class="text-lg font-bold text-slate-900">Thông tin tài khoản</h2>
        <a href="{{ route('member.profile.edit') }}" class="shrink-0 rounded-full border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:border-brand-500 hover:text-brand-700" data-profile-edit>Sửa</a>
      </div>
      <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[160px_minmax(0,1fr)]">
        <dt class="text-slate-500">Họ và tên</dt><dd class="font-medium text-slate-900">{{ $member->full_name }}</dd>
        <dt class="text-slate-500">Số điện thoại</dt><dd class="font-medium text-slate-900">{{ $member->phone }}</dd>
        <dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ $member->email ?: '—' }}</dd>
        <dt class="text-slate-500">Người giới thiệu bạn</dt><dd class="font-medium text-slate-900" data-referrer>{{ $member->referrer?->shortName() ?? '—' }}</dd>
        <dt class="text-slate-500">Mã QR nhận tiền</dt>
        <dd class="font-medium text-slate-900" data-account-qr>
          @if ($member->hasQr())
            <a href="{{ route('member.profile.edit') }}" class="inline-block" title="Xem / đổi mã QR">
              <img src="{{ route('member.qr') }}?v={{ $member->qr_uploaded_at?->timestamp }}" alt="Mã QR nhận tiền" loading="lazy" class="size-28 rounded-lg border border-slate-200 bg-white object-contain" />
            </a>
          @else
            Chưa có — <a href="{{ route('member.profile.edit') }}" class="text-brand-700 hover:underline">thêm mã QR</a>
          @endif
        </dd>
      </dl>
    </section>
  </div>
@endsection
