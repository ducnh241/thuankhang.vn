@extends('frontend.layouts.app')

{{-- Đăng ký thành viên (S3). Ô mã người giới thiệu điền sẵn từ link ?ref=; gõ mã thì tra tên người giới thiệu để xác nhận
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
          @include('frontend.partials.field', ['name' => 'referral_code', 'label' => 'Mã người giới thiệu', 'value' => $codeValue, 'attrs' => 'maxlength="20" autocomplete="off" style="text-transform: uppercase" data-referrer-input'])
          <p class="mt-1 text-sm" data-referrer-result aria-live="polite">
            @if ($referrerName)<span class="text-brand-700">Người giới thiệu: <strong>{{ $referrerName }}</strong></span>@endif
          </p>
          <p class="mt-1 text-xs text-slate-500">Không bắt buộc. Người giới thiệu được gắn cố định khi đăng ký, không đổi được về sau.</p>
        </div>

        <button type="submit" class="w-full rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">Đăng ký</button>
      </form>
    </div>
  </div>
@endsection
