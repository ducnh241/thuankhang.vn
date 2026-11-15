{{-- Khối thương hiệu bên trái của trang đăng nhập / nhập mã xác thực (chỉ màn hình rộng) --}}
<aside class="login-brand hidden flex-col justify-between p-12 text-white lg:flex">
  <div class="flex items-center gap-3 text-xl font-semibold text-white">
    @include('backend.partials.logo', ['class' => 'size-7 rounded-md'])
    <span>{{ config('app.name') }}</span>
  </div>
  <div class="max-w-md">
    <h2 class="mb-4 text-3xl font-semibold leading-tight text-white">Hệ thống quản trị bán hàng và affiliate.</h2>
    <ul class="m-0 flex list-none flex-col gap-4 p-0 text-[rgba(255,255,255,0.85)]">
      <li class="flex gap-3">
        {{ ant_icon('shop', 'mt-0.5 text-lg') }}
        <span>Sản phẩm, đơn hàng và thanh toán QR quản lý tập trung ở một nơi.</span>
      </li>
      <li class="flex gap-3">
        {{ ant_icon('team', 'mt-0.5 text-lg') }}
        <span>Cây giới thiệu F1/F2, tự động lên cấp và tính hoa hồng vào ví.</span>
      </li>
      <li class="flex gap-3">
        {{ ant_icon('safety-certificate', 'mt-0.5 text-lg') }}
        <span>Phân quyền theo nhóm, chỉ tài khoản được cấp quyền mới vào được trang quản trị.</span>
      </li>
    </ul>
  </div>
  <div class="text-xs text-[rgba(255,255,255,0.65)]">© {{ date('Y') }} {{ config('app.name') }}</div>
</aside>
