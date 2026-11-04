# Dự án Affiliate – Ngữ cảnh cho Claude

Website bán hàng có hệ thống affiliate 2 tầng (F1/F2), tự động lên cấp theo doanh số cá nhân cộng dồn, thanh toán QR động.
Đang ở **Phase 1**. Ngôn ngữ giao tiếp: tiếng Việt.

## Tài liệu nguồn (đọc trước khi code)
- `Dac-ta-trien-khai-Affiliate-Phase1.html` – đặc tả đầy đủ: phạm vi, quy tắc nghiệp vụ, mô hình dữ liệu, lộ trình Step 0–11, màn hình Admin/Storefront, công thức hoa hồng, edge case, câu hỏi mở.
- `[Anh Trung] Hỏi đáp dự án Affiliate.xlsx` – hỏi đáp gốc với khách hàng (anh Trung).

Nếu đặc tả và file Excel mâu thuẫn, hỏi lại người dùng, không tự suy đoán.

## Quy tắc nghiệp vụ cốt lõi (đã chốt)
- Cấp bậc (mốc cộng dồn tiền tự mua, cấu hình được): Chưa kích hoạt (0) → Nhân viên → CTV 3tr → Cửa hàng 10tr → Đại lý 15tr → VP tỉnh 150tr / 300tr.
- Lên cấp tự động ngay khi đơn được thanh toán. **Không bao giờ hạ cấp.**
- Hoa hồng 2 tầng F1/F2, tỉ lệ theo **cấp của người hưởng** (ma trận cấu hình; mặc định F1: CTV 10%, Đại lý 20%). Áp dụng cho mọi đơn, kể cả mua lại.
- Người hưởng phải **đã kích hoạt** (≥1 đơn thanh toán) và không bị khoá; nếu không → ledger `skipped` kèm lý do.
- Tự mua không có hoa hồng/giảm giá cho chính mình, nhưng vẫn sinh F1/F2 cho tuyến trên.
- Cơ sở hoa hồng = giá trước VAT × (1 − 5% phí DN), không tính ship. Làm tròn xuống từng dòng.
- Người giới thiệu gắn cố định khi đăng ký (link `?ref=CODE`, cookie 30 ngày).
- Thanh toán: VietQR động (số tiền + mã đơn), tiền về TK công ty, xác nhận qua webhook ngân hàng (SePay/PayOS/Casso), idempotent theo mã giao dịch. Không khớp → hàng chờ xử lý tay.
- Nhận hàng: giao tận nơi (khách trả ship) hoặc nhận tại VP tỉnh (hàng của VP, hệ thống không quản lý tồn kho).
- Hoa hồng vào ví ngay. Rút tối thiểu 500k, cần KYC (CCCD + STK chính chủ, 1 CCCD = 1 tài khoản = 1 STK). Thuế TNCN khấu trừ riêng khi rút.
- Mọi tỉ lệ, mốc, tham số đều cấu hình trong Admin; đơn lưu snapshot tỉ lệ/cấp.

## Ngoài phạm vi Phase 1 (không code)
- Đổi trả / hoàn tiền / thu hồi hoa hồng / ví âm.
- Tính thưởng KPI tháng ("mặt trời + nhị phân") – chỉ làm màn hình cấu hình bậc KPI (3% / 5% / 7% / 9%, tối thiểu 5 F1).
- Thanh toán tiền hàng cho VP tỉnh – chỉ có báo cáo đơn theo VP.

## Giả định đang chờ xác nhận
- Phí 5% tính trên giá trước VAT; doanh số xét cấp = tiền hàng gồm VAT, không gồm ship.
- Mốc cấp Nhân viên; tên 2 mức VP tỉnh; tài khoản gốc cho người đăng ký không có mã; tỉ lệ TNCN.

## Nguyên tắc kỹ thuật
- Tiền lưu số nguyên (VNĐ). Ledger hoa hồng và giao dịch ví chỉ ghi thêm (append-only); số dư = tổng bút toán.
- Xử lý `order.paid` trong 1 transaction DB, lock đơn, unique (order_id, tier).
- Không hardcode secret; dùng biến môi trường. Không log/hiển thị dữ liệu CCCD.

## Tech stack
Laravel 13 (PHP 8.3) + MySQL 8, admin Blade + Ant Design/Tailwind/jQuery (Vite), dựng trên ProCMS có sẵn.
- Phân quyền: `config/permission.php` → Gate; vai trò = Nhóm quyền (`groups.permission_data`), seed bởi `RoleSeeder`.
- Nhật ký: `audit_logs` (model `AuditLog`, chỉ ghi thêm). Model quản trị mới → thêm vào `AuditLog::SUBJECTS`;
  xoá bằng query → gọi `AuditLog::recordBulkDelete`.
- Chạy lệnh trong container: `docker exec -w /data/code/2026/thuankhang.vn php_laravel php artisan …`.

## Tiến độ
- Step 0 (Nền tảng): xong — audit_logs + màn hình Nhật ký, 4 vai trò, khai báo quyền A2–A14, CI, .env.example.
- Đã gỡ module ProCMS cũ: dự án, tag, gia hạn, hosting, khách hàng, thanh toán, báo cáo doanh thu (code + bảng, migration
  `2026_11_03_000002_drop_project_module_tables`). Còn lại: Trang, Menu, Danh mục tin tức, Cài đặt, Người dùng, Nhóm quyền.
- Step 1 (Cấu hình): xong — bảng `levels`, `commission_rates` (phiên bản theo effective_from, chỉ ghi thêm), `kpi_tiers`;
  tham số chung trong `settings` (khóa `aff_*`, đọc qua `App\Support\AffiliateSettings::get()`); màn hình A2–A5 ở
  /backend/affiliate/*; seed `AffiliateConfigSeeder`. Tra tỉ lệ: `CommissionRate::rateFor($levelId, 'F1', $paidAt)`.
- Step 2 (Sản phẩm): xong — admin danh mục sản phẩm (`Category::TYPE_PRODUCT` = 4) + CRUD sản phẩm /backend/product
  (`products` xóa mềm, `product_images`; `price` đã gồm VAT; `Product::priceBeforeVat()`); storefront: `/`, `/danh-muc/{slug}`,
  `/tim-kiem`, `/san-pham/{slug}` (controller App\Http\Controllers\Frontend, view resources/views/frontend, CSS/JS
  resources/frontend, build riêng `vite.frontend.config.js` → public/assets/frontend/build). Sản phẩm hiện trên web: `App\Support\Catalog::products()`. HTML mô tả in ra qua `App\Support\RichText::clean()`.
  Cookie `ref` (?ref=CODE) ghi ở mọi trang website (middleware CaptureReferralCode). Demo: DemoProductSeeder (ảnh Unsplash).
- Step 3 (Tài khoản & ref): xong — website: bảng `members` (tách khỏi `users` quản trị), guard `member`, /dang-ky
  (mã giới thiệu từ cookie ref, tra tên GET /ma-gioi-thieu/{code}, không mã → `root_referral_code`), /dang-nhap (SĐT),
  /dang-xuat, /tai-khoan. Admin /backend/member (A10): danh sách, cây F1/F2, khóa/mở (member/lock), sửa người giới thiệu
  có lý do (member/referrer, chặn vòng qua `Member::downlineIds()`). Nhật ký / backend luôn dùng guard `web`.