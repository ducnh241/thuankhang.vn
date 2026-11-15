{{-- Khung trang quản trị theo layout Admin Pro của template (nhánh admin-pro, vd projects.html): sidebar tối bên trái
     (thu gọn được), header trắng 48px ở đầu vùng nội dung, dưới header là thanh trang (cùng màu nền với nội dung) dính đầu trang
     (page-topbar): breadcrumb "Trang chủ / … / trang hiện tại" bên trái, nút của trang bên phải.
     Trang con khai báo:
       @section('title', 'Dự án')      tiêu đề tab trình duyệt và cấp cuối của breadcrumb
       @section('breadcrumb')          (tuỳ chọn) các cấp cha trước tiêu đề, mỗi cấp
                                        <a href="..." class="page-topbar-crumb">Dự án</a>; layout tự thêm dấu / trước tiêu đề
       @section('topbar-sub')          (tuỳ chọn) chi tiết của trang sau tiêu đề, chữ nhỏ màu xám (vd kỳ báo cáo)
       @section('actions')             (tuỳ chọn) nút bên phải thanh trang (ant-btn…)
       @section('topbar')              (tuỳ chọn) thay phần bên trong thanh trang (sau cấp "Trang chủ" do layout thêm):
                                        .page-topbar-left (crumb / sep / title / tag) + .page-topbar-right
       @section('content')             nội dung trang --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    {{-- @section('title', $ten) đã escape sẵn → giải mã lại để head escape đúng một lần (tên có "&" không hiện "&amp;") --}}
    @include('backend.partials.head', ['title' => html_entity_decode(trim($__env->yieldContent('title', 'Dashboard')), ENT_QUOTES | ENT_HTML5)])
    @stack('head')
  </head>
  <body>
    @include('backend.partials.sprite')

    <div class="page">
      <section class="ant-layout ant-layout-has-sider workspace">
        @include('backend.partials.sidebar')
        <section class="ant-layout">
          @include('backend.partials.header')
          <main class="ant-layout-content">
            <div class="page-inner">
              <div class="page-topbar" id="page-topbar">
                <div class="page-topbar-inner">
                  {{-- Cấp đầu của breadcrumb, chung mọi trang (Dashboard chính là trang chủ nên không có) --}}
                  @unless (request()->routeIs('backend.dashboard'))
                    <div class="page-topbar-home max-md:hidden">
                      <a href="{{ route('backend.dashboard') }}" class="page-topbar-crumb">Trang chủ</a>
                      <span class="page-topbar-sep" aria-hidden="true">/</span>
                    </div>
                  @endunless
                  @hasSection('topbar')
                    @yield('topbar')
                  @else
                    <div class="page-topbar-left">
                      @hasSection('breadcrumb')
                        @yield('breadcrumb')
                        <span class="page-topbar-sep" aria-hidden="true">/</span>
                      @endif
                      <h1 class="page-topbar-title">@yield('title', 'Dashboard')</h1>
                      @hasSection('topbar-sub')
                        <span class="page-topbar-divider max-md:hidden" aria-hidden="true"></span>
                        <span class="page-topbar-sub min-w-0 shrink truncate max-md:hidden">@yield('topbar-sub')</span>
                      @endif
                    </div>
                    @hasSection('actions')
                      <div class="page-topbar-right">@yield('actions')</div>
                    @endif
                  @endif
                </div>
              </div>
              @include('backend.partials.flash')
              @yield('content')
            </div>
          </main>
        </section>
      </section>
    </div>

    @include('backend.partials.toast')
    @include('backend.partials.account-menu')
    @include('backend.partials.delete-confirm')
    @stack('scripts')
  </body>
</html>
