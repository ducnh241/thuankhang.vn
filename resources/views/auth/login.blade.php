{{-- Trang đăng nhập: một cột, form căn giữa trang (không logo / tên hệ thống phía trên, © ở chân trang). Form gửi POST /backend/login; ô trống / email sai định dạng
     được kiểm tra ở trình duyệt (resources/backend/js/forms.js), lỗi đăng nhập do server trả về hiện ở khung báo lỗi. --}}
@php
    $remember = (bool) old('remember');
    $error = $errors->first();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @include('backend.partials.head', ['title' => 'Đăng nhập'])
  </head>
  <body data-page="login">
    @include('backend.partials.sprite')

    <div class="login-page flex min-h-full flex-col">
      <main class="flex flex-1 items-center justify-center bg-white px-4 py-12 sm:px-6">
        <div class="w-full max-w-[360px]">
          <section id="login-view">
            <h1 class="mb-1 text-2xl font-semibold text-[rgba(0,0,0,0.85)]">Đăng nhập</h1>
            <p class="mb-6 text-[rgba(0,0,0,0.45)]">Chào mừng trở lại. Đăng nhập để vào trang quản trị.</p>

            <div class="mb-4" id="login-alert" aria-live="assertive">
              @if ($error)
                <div class="ant-alert ant-alert-error" role="alert">
                  {{ ant_icon('close-circle', 'ant-alert-icon') }}
                  <div class="ant-alert-content"><div class="ant-alert-message">{{ $error }}</div></div>
                </div>
              @elseif (session('status'))
                <div class="ant-alert ant-alert-success" role="status">
                  {{ ant_icon('check-circle', 'ant-alert-icon') }}
                  <div class="ant-alert-content"><div class="ant-alert-message">{{ session('status') }}</div></div>
                </div>
              @endif
            </div>

            <form id="login_form" method="POST" action="{{ route('login.store') }}" class="ant-form ant-form-vertical edit-form" novalidate>
              @csrf
              <div class="ant-row ant-form-item" data-required="Vui lòng nhập email." data-type="email" data-type-message="Email không hợp lệ.">
                <div class="ant-col ant-form-item-label">
                  <label for="login_email" class="ant-form-item-required" title="Email">Email</label>
                </div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input">
                    <div class="ant-form-item-control-input-content">
                      <span class="ant-input-affix-wrapper ant-input-affix-wrapper-lg"
                        ><span class="ant-input-prefix">{{ ant_icon('mail') }}</span
                        ><input type="email" id="login_email" name="email" value="{{ old('email') }}" class="ant-input" autocomplete="username" placeholder="ban@congty.vn" maxlength="255" @if (! old('email')) autofocus @endif
                      /></span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="ant-row ant-form-item" data-required="Vui lòng nhập mật khẩu.">
                <div class="ant-col ant-form-item-label">
                  <label for="login_password" class="ant-form-item-required" title="Mật khẩu">Mật khẩu</label>
                </div>
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input">
                    <div class="ant-form-item-control-input-content">
                      <span class="ant-input-affix-wrapper ant-input-affix-wrapper-lg ant-input-password"
                        ><span class="ant-input-prefix">{{ ant_icon('lock') }}</span
                        ><input type="password" id="login_password" name="password" class="ant-input" autocomplete="current-password" placeholder="Mật khẩu" maxlength="255" @if (old('email')) autofocus @endif
                        /><span class="ant-input-suffix">{{ ant_icon('eye-invisible', 'ant-input-password-icon') }}</span
                      ></span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="ant-row ant-form-item">
                <div class="ant-col ant-form-item-control">
                  <div class="ant-form-item-control-input">
                    <div class="ant-form-item-control-input-content">
                      <label @class(['ant-checkbox-wrapper', 'ant-checkbox-wrapper-checked' => $remember])
                        ><span @class(['ant-checkbox', 'ant-checkbox-checked' => $remember])
                          ><input type="checkbox" class="ant-checkbox-input" id="login_remember" name="remember" value="1" @checked($remember) /><span class="ant-checkbox-inner"></span></span
                        ><span>Ghi nhớ đăng nhập</span></label
                      >
                    </div>
                  </div>
                </div>
              </div>

              <button type="submit" class="ant-btn ant-btn-primary ant-btn-lg ant-btn-block" id="login-submit">
                <span data-label data-text="Đăng nhập" data-loading-text="Đang đăng nhập…">Đăng nhập</span>
              </button>
            </form>

            <div class="ant-alert ant-alert-info mt-6" role="note">
              {{ ant_icon('info-circle', 'ant-alert-icon') }}
              <div class="ant-alert-content">
                <div class="ant-alert-message">Chưa có tài khoản hoặc quên mật khẩu? Liên hệ quản trị viên để được cấp hoặc đặt lại.</div>
              </div>
            </div>
          </section>
        </div>
      </main>
      <footer class="px-4 pb-6 text-center text-xs text-[rgba(0,0,0,0.45)]">© {{ date('Y') }} {{ config('app.name') }}</footer>
    </div>
  </body>
</html>
