{{-- Khung trang quản trị theo template ProCMS (vd projects.html): sidebar tối bên trái, header chỉ có trên mobile,
     nội dung có thanh trên cùng dính đầu trang (page-topbar).
     Trang con khai báo:
       @section('title', 'Dự án')      tiêu đề tab trình duyệt và tiêu đề trên thanh trên cùng
       @section('breadcrumb')          (tuỳ chọn) các cấp cha trước tiêu đề, mỗi cấp
                                        <a href="..." class="page-topbar-crumb">Dự án</a>; layout tự thêm dấu › trước tiêu đề
       @section('topbar-sub')          (tuỳ chọn) chi tiết của trang sau tiêu đề, chữ nhỏ màu xám (vd kỳ báo cáo)
       @section('actions')             (tuỳ chọn) nút bên phải thanh trên cùng (ant-btn…)
       @section('topbar')              (tuỳ chọn) thay toàn bộ phần bên trong thanh trên cùng
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
      @include('backend.partials.header')
      <section class="ant-layout ant-layout-has-sider workspace">
        @include('backend.partials.sidebar')
        <section class="ant-layout">
          <main class="ant-layout-content">
            <div class="page-inner">
              <div class="page-topbar" id="page-topbar">
                <div class="page-topbar-inner">
                  @hasSection('topbar')
                    @yield('topbar')
                  @else
                    <div class="page-topbar-left">
                      @hasSection('breadcrumb')
                        @yield('breadcrumb')
                        <span class="page-topbar-sep" aria-hidden="true">{{ ant_icon('right') }}</span>
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
