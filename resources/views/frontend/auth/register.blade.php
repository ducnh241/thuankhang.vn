@extends('frontend.layouts.app')

{{-- Đăng ký thành viên (S3). Bắt buộc có mã người giới thiệu hợp lệ và chọn Nhánh A / B ($codeRequired; chỉ tài khoản đầu
     tiên của hệ thống không cần). Ô mã điền sẵn từ link ?ref=; gõ mã thì tra tên người giới thiệu để xác nhận
     (frontend/js/app.js, [data-referrer-lookup]). --}}
@section('title', 'Đăng ký')
@section('robots', 'noindex, nofollow')

@php
    $codeValue = session()->hasOldInput() ? (string) old('referral_code') : $code;
    $referrerName = ! session()->hasOldInput() && $referrer ? $referrer->shortName() : null;
@endphp

@section('content')
  <div class="mx-auto max-w-md px-4 pt-10">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
      <h1 class="text-2xl font-bold text-slate-900">Đăng ký thành viên</h1>
      <p class="mt-1 text-sm text-slate-500">Đã có tài khoản? <a href="{{ route('member.login') }}" class="font-medium text-brand-700 hover:underline">Đăng nhập</a></p>

      <form method="POST" action="{{ route('member.register.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        @include('frontend.partials.field', ['name' => 'full_name', 'label' => 'Họ và tên', 'required' => true, 'value' => old('full_name'), 'attrs' => 'autocomplete="name" maxlength="100" autofocus'])
        @include('frontend.partials.field', ['name' => 'phone', 'label' => 'Số điện thoại', 'type' => 'tel', 'required' => true, 'value' => old('phone'), 'attrs' => 'autocomplete="tel" inputmode="tel" maxlength="20" placeholder="09xxxxxxxx"', 'hint' => 'Dùng để đăng nhập.'])
        @include('frontend.partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => old('email'), 'attrs' => 'autocomplete="email" maxlength="150"', 'hint' => 'Không bắt buộc.'])
        @include('frontend.partials.field', ['name' => 'password', 'label' => 'Mật khẩu', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"', 'hint' => 'Ít nhất 8 ký tự, có cả chữ và số.'])
        @include('frontend.partials.field', ['name' => 'password_confirmation', 'label' => 'Nhập lại mật khẩu', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password"'])

        <div data-referrer-lookup data-url="{{ url('ma-gioi-thieu') }}">
          @include('frontend.partials.field', ['name' => 'referral_code', 'label' => 'Mã người giới thiệu', 'required' => $codeRequired, 'value' => $codeValue, 'attrs' => 'maxlength="20" autocomplete="off" style="text-transform: uppercase" data-referrer-input'])
          <p class="mt-1 text-sm" data-referrer-result aria-live="polite">
            @if ($referrerName)<span class="text-brand-700">Người giới thiệu: <strong>{{ $referrerName }}</strong></span>@endif
          </p>
          <p class="mt-1 text-xs text-slate-500">@if ($codeRequired)Bắt buộc: chỉ đăng ký được khi có mã của người giới thiệu. Chưa có mã? Hỏi người đã giới thiệu bạn{{ $site['site_hotline'] ? ' hoặc gọi hotline '.$site['site_hotline'] : '' }}. @endif Người giới thiệu được gắn cố định khi đăng ký, không đổi được về sau.</p>
        </div>

        {{-- Nhánh của người giới thiệu (A = L, B = R): người đăng ký tự chọn theo hướng dẫn của người giới thiệu; gắn cố định --}}
        @if ($codeRequired)
          <fieldset data-branch-choice @if ($errors->has('branch')) aria-describedby="f_branch_error" @endif>
            <legend class="mb-1.5 block text-sm font-medium text-slate-700">Chọn nhánh<span class="text-rose-500"> *</span></legend>
            <div class="grid grid-cols-2 gap-3">
              @foreach (\App\Services\BinaryTree::BRANCHES as $branch => $side)
                <label @class(['flex cursor-pointer items-center justify-center gap-2 rounded-lg border bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-100',
                    'border-rose-400' => $errors->has('branch'), 'border-slate-300' => ! $errors->has('branch')])>
                  <input type="radio" name="branch" value="{{ $branch }}" class="size-4 accent-brand-600" @checked(old('branch') === $branch) required>
                  Nhánh {{ $branch }}
                </label>
              @endforeach
            </div>
            @error('branch')<p id="f_branch_error" class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            <p class="mt-1 text-xs text-slate-500">Hỏi người giới thiệu bạn nên chọn Nhánh A hay Nhánh B. Nhánh được gắn cố định khi đăng ký, không đổi được về sau.</p>
          </fieldset>
        @endif

        <button type="submit" class="w-full rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">Đăng ký</button>
      </form>
    </div>
  </div>
@endsection
