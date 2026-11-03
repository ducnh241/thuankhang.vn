{{-- Phần <head> dùng chung của giao diện admin mới (template ProCMS: Ant Design 4 + Tailwind CSS v4 + jQuery).
     CSS / JS build bằng Vite từ resources/backend vào public/assets/backend/build (npm run build).
     Tham số: $title (tên trang, đứng trước tên app) --}}
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<meta name="robots" content="noindex, nofollow" />
<title>{{ $title }} - {{ config('app.name') }}</title>
<link rel="icon" href="{{ asset_v('assets/backend/img/favicon.svg') }}" type="image/svg+xml" />
{{-- Không nạp font Inter từ Google: link font của template (cú pháp "|" cũ) bị Google trả lỗi 400, nên template thực tế
     hiển thị bằng font hệ thống (San Francisco trên Mac, Segoe UI trên Windows; Inter chỉ khi máy có cài sẵn).
     Nạp Inter thật thì chữ rộng / cao hơn template. --}}
@vite(['resources/backend/css/app.css', 'resources/backend/js/app.js'], 'assets/backend/build')
