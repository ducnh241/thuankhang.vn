@extends('frontend.layouts.app')

{{-- Đại lý bạn tuyển dụng = thành viên trực tiếp (F1) của tôi: họ tên + ngày tham gia, cấp hiện tại, số người nằm dưới
     người đó trong cây nhị phân (như "Cả nhánh" ở sơ đồ cây, không tính chính người đó). Ô tóm tắt phía trên: số F1 và tổng thành viên của cả nhánh
     (F1 + các tầng dưới). Không hiện số điện thoại / email / doanh số của người khác. --}}
@section('title', 'Đại lý bạn tuyển dụng')
@section('robots', 'noindex, nofollow')

@php $count = fn ($n) => number_format((int) $n, 0, ',', '.'); @endphp

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold text-slate-900">Đại lý bạn tuyển dụng</h1>
      <a href="{{ route('member.account') }}" class="text-sm text-brand-700 hover:underline">‹ Tài khoản</a>
    </div>
    <p class="mt-1 text-sm text-slate-600">Những người đăng ký bằng mã / link giới thiệu của bạn (F1).</p>

    <div class="mt-5 grid grid-cols-2 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Đại lý bạn tuyển dụng</div>
        <div class="mt-2 text-xl font-bold text-slate-900" data-team-direct>{{ $count($directs->total()) }}</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">Thành viên</div>
        <div class="mt-2 text-xl font-bold text-slate-900" data-team-total>{{ $count($teamCount) }}</div>
      </div>
    </div>

    @if ($directs->isEmpty())
      <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">
        Bạn chưa giới thiệu ai. Gửi mã <strong class="font-mono text-slate-700">{{ $member->referral_code }}</strong> hoặc link giới thiệu ở trang Tài khoản cho bạn bè.
      </div>
    @else
      <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
            <tr>
              <th scope="col" class="w-14 px-4 py-3 font-medium max-sm:hidden">#</th>
              <th scope="col" class="px-3 py-3 font-medium sm:px-4">Họ và tên</th>
              <th scope="col" class="px-3 py-3 font-medium max-sm:hidden sm:px-4">Cấp hiện tại</th>
              <th scope="col" class="px-3 py-3 text-right font-medium whitespace-nowrap sm:px-4" title="Số người nằm dưới thành viên này trong cây nhị phân"><span class="sr-only">Số thành viên bên dưới</span></th>{{-- không hiện chữ, chỉ cho trình đọc màn hình --}}
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100" data-team-rows>
            @foreach ($directs as $direct)
              <tr>
                <td class="px-4 py-3 text-slate-500 tabular-nums max-sm:hidden">{{ $directs->firstItem() + $loop->index }}</td>
                <td class="px-3 py-3 sm:px-4">
                  <div class="font-medium text-slate-900">{{ $direct->full_name }}</div>
                  <div class="mt-0.5 text-xs whitespace-nowrap text-slate-500 tabular-nums" data-joined>Tham gia ngày {{ $direct->created_at?->format('d/m/Y') }}</div>
                  {{-- Điện thoại: cấp hiện dưới tên để bảng vừa màn hình --}}
                  <div class="mt-1.5 sm:hidden"><span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-brand-800">{{ $direct->level?->name ?? '—' }}</span></div>
                </td>
                <td class="px-3 py-3 max-sm:hidden sm:px-4"><span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-brand-800">{{ $direct->level?->name ?? '—' }}</span></td>
                @php $under = $below[$direct->id] ?? 0; @endphp
                <td class="px-3 py-3 text-right tabular-nums sm:px-4" data-below="{{ $under }}" title="{{ $count($under) }} người bên dưới trong cây nhị phân">
                  <span @class(['font-semibold', 'text-brand-700' => $under > 0, 'text-slate-400' => $under === 0])>{{ $count($under) }}</span>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      {{ $directs->links('frontend.partials.pagination') }}
    @endif
  </div>
@endsection
