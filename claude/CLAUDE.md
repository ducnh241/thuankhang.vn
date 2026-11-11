# Dự án Affiliate – Ngữ cảnh cho Claude

Website bán hàng có hệ thống affiliate 2 tầng (F1/F2), tự động lên cấp theo doanh số cá nhân cộng dồn, thanh toán QR động.
Đang ở **Phase 1**. Ngôn ngữ giao tiếp: tiếng Việt.

## Tài liệu nguồn (đọc trước khi code)
- `Dac-ta-trien-khai-Affiliate-Phase1.html` – đặc tả đầy đủ: phạm vi, quy tắc nghiệp vụ, mô hình dữ liệu, lộ trình Step 0–11, màn hình Admin/Storefront, công thức hoa hồng, edge case, câu hỏi mở.
- `[Anh Trung] Hỏi đáp dự án Affiliate.xlsx` – hỏi đáp gốc với khách hàng (anh Trung).

Nếu đặc tả và file Excel mâu thuẫn, hỏi lại người dùng, không tự suy đoán.

## Quy tắc nghiệp vụ cốt lõi (đã chốt)
- Cấp bậc (mốc cộng dồn tiền tự mua, cấu hình được): Chưa kích hoạt (0) → Nhân viên → CTV 3tr → Đại lý 15tr → VP tỉnh 150tr / 300tr. (Đã bỏ cấp Cửa hàng 10tr — 10/11/2026.)
- Lên cấp tự động ngay khi đơn được thanh toán. **Không bao giờ hạ cấp.**
- Hoa hồng 2 tầng F1/F2, tỉ lệ theo **cấp của người hưởng** (ma trận cấu hình; mặc định F1: CTV 10%, Đại lý 20%). Áp dụng cho mọi đơn, kể cả mua lại.
- Người hưởng phải **đã kích hoạt** (≥1 đơn thanh toán) và không bị khoá; nếu không → ledger `skipped` kèm lý do.
- Tự mua không có hoa hồng/giảm giá cho chính mình, nhưng vẫn sinh F1/F2 cho tuyến trên.
- Cơ sở hoa hồng = giá trước VAT × (1 − 5% phí DN), không tính ship. Làm tròn xuống từng dòng.
- Người giới thiệu gắn cố định khi đăng ký (link `?ref=CODE`, cookie 30 ngày).
- Thanh toán: VietQR động (số tiền + mã đơn), tiền về TK công ty, xác nhận qua webhook ngân hàng (SePay/PayOS/Casso), idempotent theo mã giao dịch. Không khớp → hàng chờ xử lý tay.
- Nhận hàng: giao tận nơi (khách trả ship) hoặc nhận tại VP tỉnh (hàng của VP). Tồn kho theo dõi tùy chọn từng sản phẩm (để trống = không theo dõi) — tồn chung của công ty, trừ cho mọi đơn kể cả nhận tại VP (giả định, chờ xác nhận).
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
- Step 4 (VP tỉnh / điểm nhận hàng): xong — bảng `pickup_points` (xóa mềm, `owner_member_id` duy nhất, gỡ khi xóa),
  34 tỉnh/thành ở `config/provinces.php`, admin /backend/pickup-point (quyền `pickup_point/*`), chủ phải là thành viên
  cấp VP, giới hạn điểm đang bật mỗi tỉnh theo `max_vp_per_province`. Checkout dùng `PickupPoint::available()`.
- Step 5 (Giỏ hàng & checkout): xong — giỏ trong session (`App\Support\Cart`, /gio-hang, khách chưa đăng nhập vẫn thêm
  được), /thanh-toan bắt đăng nhập thành viên: giao tận nơi (phí ship = tham số `shipping_fee`) hoặc nhận tại VP tỉnh
  (ship 0, chỉ `PickupPoint::available()`). Bảng `orders` + `order_items` (snapshot tên / giá / VAT), mã = tiền tố
  `order_code_prefix` (mặc định `TK`) + 8 ký tự. Thành viên: /don-hang (đơn của tôi, QR VietQR — `App\Support\VietQr`).
  Admin /backend/order (A8): lọc trạng thái / điểm / hình thức / thời gian / mã giao dịch. Luồng trạng thái: mục dưới.
- Trang chủ theo luồng Shoplife (`Phan-tich-luong-Shoplife.md` mục 4.3): thẻ thành viên (cấp, mã / link mời, số đơn chờ
  thanh toán) hoặc lời mời đăng ký; hàng nổi bật cuộn ngang; hàng link danh mục gốc (sang trang danh mục) + toàn bộ SP "N sản phẩm" + sắp
  xếp; thẻ SP có lượt bán (`Product::scopeForCard` — `sold_qty` chỉ tính đơn đã thanh toán) và nút "+" thêm nhanh vào giỏ;
  điện thoại có thanh điều hướng dưới (Trang chủ / Giỏ hàng / Đơn hàng / Tài khoản). Chưa hiện tỉ lệ hoa hồng theo SP (chờ câu 9).
- Chi tiết sản phẩm theo Shoplife + S2: lượt bán, chọn số lượng −/+, Thêm vào giỏ / Mua ngay (điện thoại: thanh mua cố định
  phía dưới), Chat Zalo (`social_zalo` trong Cài đặt website, chỉ link http(s)) / gọi hotline, ô giới thiệu sản phẩm: thành
  viên đã kích hoạt có link `/san-pham/{slug}?ref=MÃ` + nút sao chép; chưa kích hoạt / khách chỉ thấy lời nhắc.
- Thương hiệu / xuất xứ / tồn kho: cột `products.brand`, `origin`, `stock` (NULL = không theo dõi), nhập ở form sản phẩm admin.
  Tồn kho: giỏ chặn thêm quá số còn; checkout khóa dòng sản phẩm (`lockForUpdate`) rồi kiểm tra, trừ tồn khi tạo đơn
  (`Order::takeStock()`, cờ `orders.stock_reserved`); hủy đơn (khách / admin / `orders:expire`) trả lại (`releaseStock()`,
  không trả hai lần); khôi phục đơn hủy thành đã thanh toán thì trừ lại (tồn có thể âm). Hết hàng: thẻ SP mờ + nút "+" khóa,
  trang SP "Hết hàng".
- Biến thể: `products.variant_label` (tên nhóm, VD Màu sắc) + bảng `product_variants` (tên, SKU, giá NULL = giá SP, tồn NULL =
  không theo dõi, bật / tắt), nhập ở thẻ "Biến thể" trong form sản phẩm admin (repeater; đồng bộ sửa / thêm / xóa, ghi nhật ký).
  Có biến thể đang bán → khách bắt buộc chọn (trang SP chọn sẵn biến thể đầu còn hàng; thẻ SP "Từ X" + nút "+" mở trang SP),
  giá / tồn theo biến thể, tồn của SP không dùng. Giỏ: khóa dòng `productId` hoặc `productId:variantId` (`Cart::key()`),
  route `/gio-hang/{line}`. Đơn: `order_items.variant_id` (null khi biến thể bị xóa) + snapshot `variant_name`; giữ / trả tồn
  theo biến thể.
  Ảnh biến thể: `product_variants.image_id` (một ảnh trong bộ ảnh SP, chọn ở cột "Ảnh" của bảng biến thể; ảnh bị gỡ → về
  ảnh đại diện). Website: chọn biến thể thì đổi ảnh chính; giỏ hàng / trang đơn của khách dùng ảnh biến thể.
- SEO sản phẩm: `products.meta_title` (70) / `meta_description` (160), để trống thì website dùng tên / mô tả ngắn. Form admin:
  thẻ "Hiển thị trên Google" (xem trước kiểu Google + giá, bút mở ô tiêu đề / mô tả SEO / đường dẫn — đường dẫn đã chuyển từ
  đầu form xuống đây); thẻ Biến thể thu gọn khi chưa có dòng, bấm "Thêm biến thể" mới hiện.
- Đánh giá sao: bảng `product_reviews` (1–5 sao + nhận xét, unique product + member, `is_visible`). Chỉ thành viên có đơn đã
  thanh toán chứa SP mới gửi được (`ProductReview::canReview`), gửi lại = sửa. Trang SP: điểm TB, phân bố sao, 20 đánh giá
  mới nhất; thẻ SP hiện ★ điểm TB. Admin /backend/review: lọc, ẩn / hiện (quyền `review`, `review/moderate` — Vận hành,
  CSKH), ghi nhật ký tay (`ProductReview` thuộc `AuditLog::MANUAL_SUBJECTS`). Nhóm quyền cũ trên DB cần tick thêm 2 quyền này.

## Trạng thái đơn hàng (đã chốt 04/11/2026)
`App\Models\Order`, cột `status`. Mọi lần đổi trạng thái chạy trong transaction có `lockForUpdate` và ghi `AuditLog` tay
(Order thuộc `AuditLog::MANUAL_SUBJECTS`).

| Trạng thái (`status`) | Nhãn | Vào khi | Ai |
|---|---|---|---|
| `new` | Đơn mới | Khách đặt hàng. Trang đơn hiện QR + thông tin chuyển khoản, đếm hạn `payment_expiry_hours` | Hệ thống |
| `awaiting_confirmation` | Chờ xác nhận thanh toán | Khách bấm "Tôi đã chuyển khoản" (`confirm_requested_at`). **Dừng đếm hạn** | Thành viên |
| `paid` | Đã thanh toán | Kế toán đối chiếu sao kê → xác nhận (webhook Step 6 sẽ tự chuyển đơn khớp) | `order/confirm_payment` |
| `processing` | Đang giao / Chờ nhận tại VP (theo hình thức nhận) | Bắt đầu giao / hàng về VP | `order/status` |
| `completed` | Hoàn tất | Khách đã nhận hàng | `order/status` |
| `cancelled` | Đã hủy | Khách tự hủy, admin hủy, hoặc quá hạn (`cancel_source` = customer / admin / expired, `cancel_reason`) | Thành viên / `order/cancel` / lệnh |

Chuyển trạng thái hợp lệ:
- `new` → `awaiting_confirmation` (thành viên, khi còn hạn) · `new` → `cancelled` (thành viên tự hủy, khi còn hạn).
- `new` | `awaiting_confirmation` → `paid` (xác nhận thanh toán) · → `cancelled` (admin hủy, bắt buộc lý do).
- `awaiting_confirmation` → `new` ("Chưa nhận được tiền", bắt buộc lý do; hạn thanh toán tính lại từ lúc đó).
- `cancelled` → `paid`: tiền về muộn, Kế toán xác nhận kèm **ghi chú bắt buộc** (khôi phục đơn).
- `paid` → `processing` → `completed` (`Order::TRANSITIONS`). Đơn đã thanh toán **không hủy / không lùi** (Phase 1 không hoàn tiền).
- Đơn `new` quá hạn: lệnh `orders:expire` (chạy mỗi phút qua scheduler — server cần cron `schedule:run`) chuyển
  `cancelled` / nguồn `expired`. Trong lúc chờ lệnh, `displayStatus()` đã coi là "Đã hủy". Đơn chờ xác nhận không bao giờ tự hủy.

Xác nhận thanh toán (admin, quyền `order/confirm_payment` — mặc định chỉ nhóm Kế toán):
- Bắt buộc **mã giao dịch ngân hàng** (`payment_reference`, in hoa, unique toàn bảng — chống dùng một khoản tiền cho hai đơn)
  và **số tiền thực nhận** (`paid_amount`, không được thấp hơn tổng đơn; chuyển thiếu → giữ chờ khách chuyển bù). Lưu
  `paid_at`, `confirmed_by`, `payment_note`. Chuyển thừa: ghi nhận, hiện tag "Thừa … đ" ở chi tiết đơn.
- Việc kèm theo khi sang `paid` (Step 7–8, TODO trong `OrderController::confirmPayment`): kích hoạt tài khoản, cộng doanh số /
  lên cấp, ghi hoa hồng F1 / F2 — cùng transaction.
- Nhóm quyền đã tạo trên DB trước thay đổi này phải tick thêm quyền `order/confirm_payment` (RoleSeeder không ghi đè).
