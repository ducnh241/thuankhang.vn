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
  (mã giới thiệu từ cookie ref, tra tên GET /ma-gioi-thieu/{code}; **từ 16/11/2026 bắt buộc có mã người giới thiệu hợp lệ**
  — bỏ "tài khoản gốc" `root_referral_code`; riêng tài khoản đầu tiên của hệ thống không cần mã), /dang-nhap (SĐT),
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

## API quản trị sản phẩm + skill Claude (15/11/2026)
- `/api/v1` (routes/api.php): `me`, `categories` CRUD (danh mục sản phẩm), `products` CRUD (ảnh URL / base64 / giữ id,
  biến thể đồng bộ, xóa mềm). Controller `App\Http\Controllers\Api\V1`. Tài liệu: `skills/thuankhang-products/reference/api.md`.
- Xác thực Bearer `tk_…` (bảng `api_tokens`, chỉ lưu SHA-256), middleware `AuthenticateApiToken` đặt user vào guard `web`
  → dùng chung Gate `product/*` + Nhật ký. Token tạo / thu hồi ở Admin → Hồ sơ → Token API.
- Dùng chung với admin: `App\Services\ProductVariantSync` (đồng bộ biến thể), `App\Services\ProductImageImporter`
  (tải ảnh từ link công khai — chặn IP nội bộ kể cả qua redirect — hoặc base64; PNG/JPG/WEBP ≤ 4MB).
- Skill: `skills/thuankhang-products` (SKILL.md + `scripts/tk_api.py` + quy tắc nội dung), đóng gói `skills/build.sh`
  → `skills/dist/*.zip` (gitignore; `config.json` chứa token cũng gitignore).

## Quản lý file (15/11/2026)
- Màn /backend/file (menu Files, quyền `file`, `file/upload`, `file/delete` — mặc định nhóm Vận hành; nhóm quyền cũ trên DB
  phải tick thêm): tải ảnh / tài liệu lên lấy link, tab Ảnh / Tài liệu, tìm theo tên, sao chép link, xóa từng file / hàng loạt.
- Bảng `file_uploads` (model `FileUpload`), file ở disk public `uploads/YYYY/MM/<ngẫu nhiên>.<đuôi theo nội dung>`. Chỉ nhận
  `FileUpload::TYPES` (không SVG / HTML — cùng tên miền với website); ≤ 10 MB / file (hoặc `upload_max_filesize` của PHP nếu
  thấp hơn — container local đang 2 MB), 10 file / lần. Gửi quá `post_max_size` → về lại màn hình kèm `?too_large=1`.
- Danh sách đọc từ đĩa (`App\Support\FileLibrary`): thư viện + ảnh sản phẩm (`products/`), ảnh trang (`pages/`), logo /
  favicon (`settings/`), lọc theo "Nguồn", ghi đang dùng ở sản phẩm / trang nào ("Không còn dùng" nếu không ai tham chiếu).
  File ngoài thư viện chỉ xem / lấy link — không xóa ở đây. Avatar không liệt kê. Lọc + phân trang trong bộ nhớ.
  Chưa theo dõi link của file thư viện đang được chèn ở đâu.
- Chèn ảnh trong trình soạn thảo (mô tả sản phẩm, nội dung trang, chân trang — `resources/backend/js/editor.js`, Tiptap
  `@tiptap/extension-image`): nút "Chèn ảnh", dán hoặc kéo thả ảnh → `POST /backend/file/editor-image` (ai có một trong
  `FileUpload::EDITOR_ABILITIES` đều gọi được) lưu vào thư viện, chèn `<img src="/storage/uploads/…">` (link tương đối).
  Nút chỉ hiện khi trang có `<meta name="editor-upload">` (backend/partials/head). Không nhận ảnh data URL.
  Chọn ảnh rồi dùng thanh công cụ: nút Căn lề → `data-align="center|right"` trên `<img>`; nút Link → gắn link cho ảnh
  (`<a href><img></a>`, node ảnh có thuộc tính `href` / `target`). Bấm đúp ảnh (hoặc nút Chèn ảnh khi đang chọn ảnh) mở
  hộp "Sửa ảnh": alt, chiều rộng (px hoặc "Rộng 100%" → thuộc tính `width`, cao tự theo tỉ lệ), xóa. `RichText::clean` giữ `data-align`,
  `width`, thẻ `<a>` bao ảnh, và đổi `style="text-align: …"` của đoạn / tiêu đề thành thuộc tính `align` (bộ lọc bỏ mọi `style` — trước đó căn lề chữ bị mất trên website).

## Luồng mua hàng + thông báo cho kế toán (16/11/2026)
- Luồng: **Thêm vào giỏ** (ở lại trang, thông báo có nút "Xem giỏ hàng" / "Thanh toán") hoặc **Mua ngay** (sang thẳng
  `/thanh-toan`) → nhập người nhận + hình thức nhận như cũ → bấm **Thanh toán** → tạo đơn, sang trang đơn hiện thông tin
  chuyển khoản đã cấu hình + mã QR → khách chuyển khoản rồi bấm "Tôi đã chuyển khoản", hoặc "Hủy đơn". Trạng thái đơn
  không đổi (mục dưới).
- Thông tin chuyển khoản + mã QR: **Cài đặt → tab "Thanh toán"** (`/backend/setting?tab=payment`): tên ngân hàng, mã ngân
  hàng VietQR, số tài khoản, tên chủ tài khoản (khóa `pay_*` trong settings — chuyển từ `aff_bank_*` của Tham số chung,
  migration `2026_11_19_000001`), và **ảnh mã QR của công ty** tải lên (`pay_qr`, disk public `settings/`). Trang đơn: có
  ảnh QR tải lên thì hiện ảnh đó (khách tự nhập số tiền + nội dung); chưa tải thì tự tạo mã VietQR theo đơn nếu có mã
  ngân hàng + số tài khoản (`App\Support\VietQr`). **Chỉ Root admin sửa được** các ô này (`SiteSettings::rootOnlyKeys()`)
  — đây là tài khoản khách chuyển tiền vào.
- Trang thanh toán và trang đơn của khách **không tách dòng VAT** (giá đã gồm VAT); `orders.vat_amount` vẫn lưu để tính
  hoa hồng.
- Thông báo cho kế toán: **Cài đặt → tab "Thông báo"** (`/backend/setting/notification`, trang riêng dùng chung thanh tab
  `backend/settings/_nav`). Sự kiện (chung cho các kênh): có đơn mới, khách báo đã chuyển khoản, có hồ sơ KYC
  mới / gửi lại (`StaffNotifier::kycSubmitted`, gọi ở `Frontend\KycController::store`; tin chỉ có tên + mã thành viên + link duyệt). Kênh: **Telegram** (bot
  token + chat ID) và **Email** (địa chỉ nhận, SMTP: máy chủ / mã hóa / cổng / tài khoản / mật khẩu, người gửi; để trống
  SMTP thì dùng mailer của `.env` nếu đó là mailer thật). Mỗi kênh có nút gửi thử (dùng cấu hình đã lưu).
- Code: `App\Support\NotificationSettings` (khóa `notify_*` trong bảng settings), `App\Services\StaffNotifier` (gọi ở
  `CheckoutController::store` và `OrderController::confirmTransfer`; gửi bằng `defer()` SAU khi trả trang — Telegram / SMTP
  lỗi không ảnh hưởng đặt hàng, chỉ ghi log), `Backend\NotificationSettingController`.
- Bảo mật: bot token và mật khẩu SMTP lưu mã hóa (APP_KEY), không hiện lại trên form (để trống = giữ nguyên), không vào
  nhật ký (controller ghi một dòng tóm tắt). Sửa / gửi thử: quyền `setting/edit` **và** Root admin; người có quyền
  `setting` chỉ xem. Máy chủ SMTP chỉ nhận tên miền + cổng 25 / 465 / 587 / 2525. Tin chỉ gồm mã đơn, tên người nhận, số
  tiền, hình thức nhận, link đơn trong quản trị. Test: `tests/Feature/OrderNotificationTest.php`.

## KYC thành viên (16/11/2026 — phần KYC của Step 9; ví / rút tiền chưa làm)
- Luồng: thành viên vào Tài khoản → **Xác minh tài khoản (KYC)** `/tai-khoan/kyc` → nhập họ tên theo CCCD, số CCCD (12 số),
  ngày sinh, ngân hàng (`config/banks.php`) + số tài khoản + tên chủ tài khoản, tải ảnh 2 mặt CCCD → gửi. Quản trị duyệt ở
  `/backend/kyc` (A11, menu KYC): **Duyệt** (có ô tick "Kích hoạt tài khoản thành viên" — mặc định tick khi tài khoản chưa
  kích hoạt và người duyệt có `member/activate`; dùng `Member::activateManually()`) hoặc **Không duyệt** kèm lý do.
- Trạng thái (`MemberKyc::STATUSES`): `pending` Chờ duyệt → `approved` Đã duyệt | `rejected` Không được duyệt (thông tin
  không hợp lệ; thành viên thấy lý do, sửa và gửi lại — đè hồ sơ cũ, ảnh cũ bị xóa). Chờ duyệt / đã duyệt không sửa được.
- Bảng `member_kycs` (mỗi thành viên một dòng), model `App\Models\MemberKyc`. Quy tắc 1 CCCD = 1 tài khoản = 1 STK: CCCD /
  (ngân hàng + STK) đang ở hồ sơ chờ duyệt / đã duyệt của người khác thì chặn lúc gửi; lúc duyệt kiểm lại với hồ sơ đã
  duyệt. Tên chủ tài khoản phải trùng họ tên CCCD (so không dấu — `MemberKyc::normalizeName`).
- Bảo mật: số CCCD mã hóa trong DB (cast `encrypted`) + `id_number_hash` (HMAC theo APP_KEY) để tra trùng / tìm kiếm; ảnh
  CCCD mã hóa bằng APP_KEY, lưu disk riêng tư `storage/app/private/kyc/{member_id}/*.enc`, chỉ xem qua route có kiểm quyền
  (`member.kyc.image` — chính chủ; `backend.kyc.image` — quyền `kyc/view_document`). Nhật ký (`AuditLog`, ghi tay) chỉ
  ghi thao tác + lý do, không ghi số CCCD / STK. **Đổi APP_KEY thì phải khai báo APP_PREVIOUS_KEYS**, không thì không đọc
  lại được ảnh / số CCCD cũ.
- Ngày sinh: ô chữ gõ số `dd/mm/yyyy` (bàn phím số, JS `[data-date-input]` tự chèn dấu `/`) thay cho lịch của điện thoại —
  chọn năm sinh bằng lịch Android phải lướt rất lâu. Máy chủ nhận `d/m/Y` (cả `-`, `.`, dấu cách) và đổi sang `Y-m-d`.
- Ảnh: JPG / PNG / WEBP, tối đa `MemberKyc::MAX_KILOBYTES` (5 MB) hoặc mức PHP nếu thấp hơn. JS website
  (`[data-kyc-image]`) thu nhỏ ảnh xuống ≤ 480 KB trước khi gửi để hai ảnh lọt cả giới hạn 1 MB mặc định của nginx; máy chủ
  thật vẫn nên đặt `client_max_body_size` ≥ 12M (form KYC, Quản lý file, ảnh sản phẩm).
- Quyền (đã khai báo từ Step 0): `kyc`, `kyc/view_document`, `kyc/review` — RoleSeeder cấp cho Vận hành.
  Test: `tests/Feature/MemberKycTest.php`.

## Khung trang quản trị – layout Admin Pro (15/11/2026)
- Nguồn: repo template `template-admin`, nhánh **`admin-pro`** (`src/partials/pro-sidebar.html`, `pro-header.html`,
  `src/css/pro.css`, `src/js/pro.js`). Chỉ chuyển **sidebar, header, breadcrumb**; nội dung trang vẫn là Ant Design 4
  (`vendor/antd.css`) nên sidebar dùng class riêng `pro-*`, không dùng `ant-menu*` (tránh đụng CSS Ant 4).
- Sidebar (`backend/partials/sidebar`): nền `#001529`, 208px; logo + tên app, menu theo `config('backend.menu')` (xếp liền
  nhau như template, không hiện tên nhóm OVERVIEW / SHOP…), nút thu gọn ở cuối. Thu gọn còn 48px (chỉ icon): rê chuột / bấm menu cha hiện mục con bên
  phải, mục thường hiện tên. Trạng thái nhớ bằng cookie `backend_sider` (JS ghi, không mã hóa — `encryptCookies(except:)`
  trong `bootstrap/app.php`) để server dựng sẵn class `is-collapsed`. Logo: `config("app.logo")` (APP_LOGO), mặc định
  `public/assets/backend/img/logo.svg` = logo của template; khối logo + tên app theo đúng `.ant-pro-sider-logo`.
- Header (`backend/partials/header`): thanh trắng 48px đầu vùng nội dung — nút thu gọn (mobile < 992px: logo + mở sidebar
  dạng drawer), bên phải nút "Xem website" (tab mới) rồi avatar + tên mở menu tài khoản. Desktop header cuộn theo trang; mobile header dính đầu trang.
- Breadcrumb = thanh trang `#page-topbar` (cùng màu nền với nội dung, dính đầu trang; cuộn xuống thì thanh mỏng lại
  (padding 8px 24px) và có đường kẻ mép dưới — class `is-stuck`): layout tự thêm cấp "Trang chủ" (trừ Dashboard),
  trang con vẫn khai báo `page-topbar-left` (crumb / `page-topbar-sep` là dấu `/` / title) + `page-topbar-right` như cũ.
- JS: `resources/backend/js/layout.js`; test: `tests/Feature/BackendLayoutTest.php`.

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

## Bảo mật (rà soát 15/11/2026 — nhánh `security/hardening`)
- **Đăng nhập**: giới hạn theo email/SĐT + IP (5 lần / phút) **và** theo IP (admin 20, thành viên 30 lần sai / 10 phút).
  API: sai token 20 lần / phút / IP → 429 (`AuthenticateApiToken`).
- **Xác thực 2 lớp (TOTP)** cho tài khoản quản trị — tự bật ở Tài khoản (cần mật khẩu): `App\Support\Totp` (RFC 6238),
  `App\Services\TwoFactor`, cột `users.two_factor_*` (khóa mã hóa, mã khôi phục chỉ lưu SHA-256, `two_factor_last_step`
  chống dùng lại mã). Đăng nhập: `LoginController` chỉ kiểm tra mật khẩu → `TwoFactorChallengeController`
  (`/backend/login/two-factor`, hạn 10 phút, sai 5 lần khóa 15 phút) mới `Auth::login`. Mất thiết bị: mã khôi phục, hoặc
  người có quyền `user/edit` bấm "Tắt giúp" ở trang sửa người dùng. Các cột này lưu `saveQuietly` + ghi `AuditLog` tay.
- **Mật khẩu quản trị** tối thiểu `User::PASSWORD_MIN` = 12 ký tự (`User::passwordRule()`); thành viên website: 8 ký tự, có chữ + số.
- **Mã theo dõi** (`SiteSettings::CODE_KEYS`): chỉ Root admin sửa được — mã chạy cùng tên miền với trang quản trị.
- **Đơn chưa thanh toán**: tối đa `Order::MAX_OPEN_UNPAID` = 3 đơn (mới còn hạn / chờ xác nhận) mỗi thành viên — chống giữ tồn
  kho bằng đơn ảo (`Order::openLimitReached`, khóa dòng thành viên khi đặt).
- **API tải ảnh từ link** (`ProductImageImporter`): chỉ IPv4 công khai, ghim IP đã kiểm tra (`CURLOPT_RESOLVE`), tự xử lý
  chuyển hướng (≤ 3, kiểm tra lại từng bước), hủy tải khi quá 4 MB.
- Còn mở (chưa làm): OTP / CAPTCHA khi đăng ký thành viên; hạn dùng token API; Content-Security-Policy. Khi deploy:
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS, `trustProxies` nếu đứng sau proxy / Cloudflare.
