@extends('frontend.layouts.app')

{{-- Đăng nhập thành viên bằng số điện thoại + mật khẩu (guard "member"). --}}
@section('title', 'Đăng nhập')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-md px-4 pt-10">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
      <h1 class="text-2xl font-bold text-slate-900">Đăng nhập</h1>
      <p class="mt-1 text-sm text-slate-500">Chưa có tài khoản? <a href="{{ route('member.register') }}" class="font-medium text-brand-700 hover:underline">Đăng ký</a></p>

      <form method="POST" action="{{ route('member.login.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        @include('frontend.partials.field', ['name' => 'phone', 'label' => 'Số điện thoại', 'type' => 'tel', 'required' => true, 'value' => old('phone'), 'attrs' => 'autocomplete="tel" inputmode="tel" maxlength="20" autofocus'])
        @include('frontend.partials.field', ['name' => 'password', 'label' => 'Mật khẩu', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"'])
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('remember')) />
          Ghi nhớ đăng nhập
        </label>
        <button type="submit" class="w-full rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">Đăng nhập</button>
      </form>
      <p class="mt-4 text-center text-xs text-slate-500">Quên mật khẩu? Vui lòng liên hệ hotline @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-medium text-brand-700">{{ $site['site_hotline'] }}</a>@endif để được hỗ trợ.</p>
    </div>
  </div>
@endsection
