{{-- Bước 2 đăng nhập quản trị (tài khoản bật xác thực 2 lớp): nhập mã 6 số từ ứng dụng xác thực, hoặc một mã khôi phục.
     Form gửi POST /backend/login/two-factor (TwoFactorChallengeController). Dùng chung khung + JS với trang đăng nhập. --}}
@php $error = $errors->first(); @endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @include('backend.partials.head', ['title' => 'Xác thực 2 lớp'])
  </head>
  <body data-page="login">
    @include('backend.partials.sprite')

    <div class="login-page flex min-h-full flex-col">
      <main class="flex flex-1 items-center justify-center bg-white px-4 py-12 sm:px-6">
        <div class="w-full max-w-[360px]">
          <section id="login-view">
            <h1 class="mb-1 text-2xl font-semibold text-[rgba(0,0,0,0.85)]">Xác thực 2 lớp</h1>
            <p class="mb-6 text-[rgba(0,0,0,0.45)]">Mở ứng dụng xác thực trên điện thoại và nhập mã 6 số của tài khoản này.</p>

            <div class="mb-4" id="login-alert" aria-live="assertive">
              @if ($error)
                <div class="ant-alert ant-alert-error" role="alert">
                  {{ ant_icon('close-circle', 'ant-alert-icon') }}
                  <div class="ant-alert-content"><div class="ant-alert-message">{{ $error }}</div></div>
                </div>
              @endif
            </div>

            <form id="login_form" method="POST" action="{{ route('login.two-factor.store') }}" class="ant-form ant-form-vertical edit-form" novalidate autocomplete="off">
              @csrf
              <div class="ant-row ant-form-item" data-required="Nhập mã xác thực.">
                <div class="ant-col ant-form-item-label">
                  <label for="two_factor_code" class="ant-form-item-required" title="Mã xác thực">Mã xác thực</label>
                </div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input">
                    <div class="ant-form-item-control-input-content">
                      <span class="ant-input-affix-wrapper ant-input-affix-wrapper-lg"
                        ><span class="ant-input-prefix">{{ ant_icon('safety-certificate') }}</span
                        ><input type="text" id="two_factor_code" name="code" class="ant-input tabular-nums" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" maxlength="30" autofocus
                      /></span>
                    </div>
                  </div>
                  <div class="ant-form-item-extra">Mất điện thoại? Nhập một <strong>mã khôi phục</strong> (dạng <code>abcde-fghij</code>) thay cho mã 6 số.</div>
                </div>
              </div>

              <button type="submit" class="ant-btn ant-btn-primary ant-btn-lg ant-btn-block" id="login-submit">
                <span data-label data-text="Xác nhận" data-loading-text="Đang kiểm tra…">Xác nhận</span>
              </button>
            </form>

            <p class="mt-6 text-center"><a href="{{ route('login') }}">← Đăng nhập bằng tài khoản khác</a></p>
          </section>
        </div>
      </main>
      <footer class="px-4 pb-6 text-center text-xs text-[rgba(0,0,0,0.45)]">© {{ date('Y') }} {{ config('app.name') }}</footer>
    </div>
  </body>
</html>
