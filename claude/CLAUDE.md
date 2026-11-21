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
- Cơ sở hoa hồng = tiền hàng của đơn (giá bán, đã gồm thuế — KHÔNG tách VAT, chủ dự án chốt 17/11/2026) × (1 − 5% phí DN), không tính ship. Làm tròn xuống từng dòng.
- Người giới thiệu gắn cố định khi đăng ký (link `?ref=CODE`, cookie 30 ngày).
- Thanh toán: VietQR động (số tiền + mã đơn), tiền về TK công ty, xác nhận qua webhook ngân hàng (SePay/PayOS/Casso), idempotent theo mã giao dịch. Không khớp → hàng chờ xử lý tay.
- Nhận hàng: giao tận nơi (khách trả ship) hoặc nhận tại VP tỉnh (hàng của VP). Tồn kho theo dõi tùy chọn từng sản phẩm (để trống = không theo dõi) — tồn chung của công ty, trừ cho mọi đơn kể cả nhận tại VP (giả định, chờ xác nhận).
- Hoa hồng vào ví ngay, chia 70% ví rút tiền / 20% ví tái tiêu dùng / 10% thuế TNCN khấu trừ ngay (19/11/2026). Rút tối thiểu 500k, cần KYC (CCCD + STK chính chủ, 1 CCCD = 1 tài khoản = 1 STK).
- Mọi tỉ lệ, mốc, tham số đều cấu hình trong Admin; đơn lưu snapshot tỉ lệ/cấp.

## Ngoài phạm vi Phase 1 (không code)
- Đổi trả / hoàn tiền / thu hồi hoa hồng / ví âm.
- Tính thưởng KPI tháng ("mặt trời + nhị phân") – chỉ làm màn hình cấu hình bậc KPI (3% / 5% / 7% / 9%, tối thiểu 5 F1).
- Thanh toán tiền hàng cho VP tỉnh – chỉ có báo cáo đơn theo VP.

## Giả định đang chờ xác nhận
- Doanh số xét cấp = tiền hàng gồm VAT, không gồm ship. (Phí 5%: đã chốt 17/11/2026 — tính trên tiền hàng, không tách VAT.)
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
  (`products` xóa mềm, `product_images`; `price` = giá bán — VAT đã bỏ, xem mục "Bỏ VAT"); storefront: `/`, `/danh-muc/{slug}`,
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
  được), /thanh-toan bắt đăng nhập thành viên: giao tận nơi (phí ship COD, không thu trong đơn — xem mục "Phí ship") hoặc nhận tại VP tỉnh
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

## Hoa hồng F1 / F2 + ví (17/11/2026 — Step 8; CHƯA có rút tiền, sổ hoa hồng tổng A12, xuất Excel)

- Chủ dự án chốt 17/11/2026: giá sản phẩm đã gồm thuế nên **không tách VAT khi tính hoa hồng**; hoa hồng tính trên tiền
  hàng (không gồm phí ship) trừ 5% phí doanh nghiệp; cộng ví **ngay khi xác nhận thanh toán**. Ví dụ của chủ dự án: tiền hàng
  15tr × 95% × 20% = 2.850.000 (F1), × 7% = 997.500 (F2). Chủ dự án sẽ tự đặt VAT sản phẩm về 0% sau (không ảnh hưởng hoa hồng).
- Công thức (`App\Services\CommissionEngine`): cơ sở = floor(`orders.goods_amount` × (1 − `business_fee_percent`)); hoa hồng =
  floor(cơ sở × tỉ lệ). F1 → người giới thiệu của người mua, F2 → người giới thiệu của người đó; tỉ lệ theo **cấp người hưởng
  lúc tính**, phiên bản ma trận có hiệu lực lúc `paid_at`. Người hưởng chưa kích hoạt / bị khóa / tỉ lệ 0% → vẫn ghi dòng
  `skipped` kèm lý do, không cộng ví, không tính bù.
- Bảng (migration `2026_11_24_000001`, chỉ ghi thêm): `commission_ledger` (model `Commission`; unique order_id + tier;
  snapshot cấp, tỉ lệ, phí, tiền hàng / cơ sở) và `wallet_transactions` (model `WalletTransaction`; amount có
  dấu, balance_after). Số dư ví = tổng bút toán (`Member::walletBalance()`, tóm tắt `Member::walletSummary()`).
  `orders.commissioned_at` = đơn đã tính hoa hồng (không bao giờ tính hai lần).
- Điểm duy nhất chuyển đơn sang "Đã thanh toán": `App\Services\OrderPayment::confirm()` — một transaction: ghi thanh toán,
  giữ hàng, `CommissionEngine::orderPaid()` (khóa người mua + tuyến trên theo thứ tự id), rồi `LevelEngine::orderPaid()`.
  Đơn đã thanh toán không hủy được ở admin → chưa có thu hồi hoa hồng (ngoài phạm vi Phase 1).
- Hiển thị: website `/tai-khoan` có thẻ "Ví hoa hồng" → `/tai-khoan/vi` (`Frontend\WalletController`, chỉ dòng đã cộng ví,
  không lộ SĐT người mua). Quản trị: chi tiết thành viên có thẻ "Ví hoa hồng" + nút "Xem sổ hoa hồng" →
  `backend/member/{member}/commissions` (quyền `member` HOẶC `commission`; lọc `?tier`, `?status`; đủ các bước tính + số dư
  ví sau từng bút toán + dòng không được nhận); chi tiết đơn có thẻ "Hoa hồng"; thông báo xác nhận thanh toán kèm hoa hồng.
- Dữ liệu mẫu (chỉ máy phát triển): `php artisan db:seed --class=DemoCommissionSeeder` — F0 = thành viên id 1, 4 thành viên
  F2 "(mẫu)" (SĐT 09000001xx), 22 đơn đã thanh toán có mã giao dịch `DEMO-HH-01..22` (đã chạy ở local 17/11/2026, chủ dự án
  giữ lại để đối chiếu — đừng xóa). Test: `tests/Feature/CommissionEngineTest.php`.

## Phí ship giao tận nơi = COD (19/11/2026)

- Chủ dự án: giao tận nơi thì phí ship là **COD** (khách trả đơn vị vận chuyển khi nhận, tùy lúc lên đơn) → **không hiện giá
  ship ở đâu, không cộng vào đơn**. Nhận tại VP tỉnh giữ chữ "Miễn phí giao hàng".
- Đơn mới luôn `shipping_fee = 0`, `total_amount = goods_amount` (số tiền khách chuyển khoản = tiền hàng). Trang thanh toán:
  ô "Giao tận nơi" ghi "Phí ship COD, thanh toán khi nhận hàng"; tóm tắt chỉ còn "Tổng thanh toán". Đã bỏ tham số chung
  `shipping_fee` (khóa cũ trong bảng settings không còn đọc), chữ về phí giao hàng ở giỏ hàng / trang sản phẩm, JS tính ship ở
  checkout. Trang đơn (khách + admin) chỉ hiện dòng "Phí giao hàng" khi đơn cũ có `shipping_fee > 0`.

## Bỏ VAT (19/11/2026)

- Chủ dự án: giá sản phẩm là giá bán đã gồm thuế, **không dùng VAT ở đâu nữa**. Cột `products.vat_rate`,
  `orders.vat_amount`, `order_items.vat_rate` / `vat_amount` **vẫn giữ trong DB** (chủ dự án tự cập nhật dữ liệu cũ về 0) nhưng
  code không đọc / ghi: bỏ khỏi `$fillable` + cast, model đặt mặc định 0 (`$attributes`) nên bản ghi mới luôn 0.
- Đã gỡ: ô VAT + "Giá trước VAT" ở form sản phẩm admin (khung giá chỉ còn "Cơ sở tính hoa hồng" = giá × (1 − phí DN)), cột VAT
  ở danh sách sản phẩm, `Product::priceBeforeVat()`, `OrderItem::vatOf()`, tham số chung `default_vat_percent`, `vat_rate` ở
  API `/api/v1/products` (không nhận, không trả) + tài liệu skill, cột VAT / dòng "Trong đó VAT" ở chi tiết đơn admin, chữ
  "Giá đã gồm VAT" ở trang sản phẩm / giỏ hàng. Checkout không tính VAT nữa.
- Mọi phép tính tiền (doanh số lên cấp, hoa hồng, doanh số nhóm) dùng `goods_amount` / `price` nguyên giá bán.

## Chia hoa hồng vào hai ví + thuế TNCN (19/11/2026)

- Tên hiển thị (20/11/2026): **"Ví rút tiền"** (trước là "Ví có thể rút") và "Ví tái tiêu dùng".

- Chủ dự án yêu cầu: mỗi khoản hoa hồng X (cách tính X không đổi — vẫn KHÔNG tách VAT, đã hỏi lại 19/11/2026) chia
  **70% ví rút tiền** / **20% ví tái tiêu dùng** / **10% thuế TNCN** khấu trừ ngay (không vào ví nào). Tỉ lệ ở Tham số
  chung: `pit_percent` (mặc định 10, trước đây là "thuế khi rút" chưa cấu hình) + `reconsume_percent` (20); ví rút = phần còn
  lại; tổng hai tỉ lệ ≤ 100%. `CommissionEngine::split()`: thuế = floor(X × thuế), tái tiêu dùng = floor(X × tỉ lệ), rút
  được = X − hai phần trên (nhận phần lẻ). VD 2.280.000 → 1.596.000 / 456.000 / 228.000.
- Lưu: `commission_ledger.{reconsume_percent, tax_percent, cash_amount, reconsume_amount, tax_amount}` (snapshot; `tax_percent`
  NULL = chưa chia) và `wallet_transactions.wallet` (`cash` | `reconsume`, `balance_after` theo từng ví). Mỗi dòng hoa hồng
  sinh tối đa 2 bút toán (mỗi ví một). `Member::walletBalance($wallet = 'cash')`; `walletSummary()` thêm `reconsume`, `tax`,
  `month_cash`. `Commission::walletTransaction()` = bút toán ví rút.
- Dữ liệu cũ (đã cộng 100%): migration `2026_11_28_000001_split_commission_wallets` chia lại giống đơn mới, CHỈ GHI THÊM
  (mỗi dòng: bút toán `split` trừ ví rút phần tái tiêu dùng + thuế, bút toán cộng ví tái tiêu dùng), chạy lại không chia đôi,
  ghi một dòng nhật ký. Đã chạy ở local 19/11/2026, số dư khớp.
- Hiển thị: `/tai-khoan/vi` chỉ có 2 thẻ Ví rút tiền / Ví tái tiêu dùng (đã bỏ thẻ F1 / F2 / Tổng / Thuế theo yêu cầu
  19/11/2026). Lịch sử lọc theo kỳ `?ky=tuan|thang|thang-truoc|nam` (mặc định tháng này, `WalletController::PERIODS`, tuần
  bắt đầu thứ Hai), 20 dòng / trang; khung tổng kỳ (hoa hồng, rút được / tái tiêu dùng / thuế) so với kỳ trước (% tăng
  giảm) + biểu đồ lũy kế hai kỳ (SVG vẽ ở server, theo ngày; năm thì theo tháng; kỳ này dừng ở hôm nay; rê / chạm hiện
  tooltip — JS `[data-wallet-chart]`). Mỗi dòng gọn (tầng, người mua, ngày, mã đơn, số vào ví rút), bấm mở `<dialog>`
  chi tiết cách tính (tiền hàng + từng sản phẩm → −5% phí DN → cơ sở × tỉ lệ = hoa hồng → 3 phần) — JS chung
  `[data-dialog-open]` / `[data-dialog-close]`, bấm nền tối để đóng. `/tai-khoan` hiện cả hai
  ví. Admin: sổ hoa hồng có cột ví rút / tái tiêu dùng / thuế; chi tiết thành viên + chi tiết đơn có phần chia.
  Ví tái tiêu dùng dùng được từ 21/11/2026 — xem mục bên dưới. Test: `tests/Feature/WalletSplitTest.php` (các test
  hoa hồng cũ đặt tỉ lệ chia 0% để giữ số đối chiếu).

## Ví tái tiêu dùng: mua hàng, chuyển ví, nạp tiền, chuyển từ ví rút (21/11/2026)

Chủ dự án chốt 21/11/2026 (giữ tên **"Ví tái tiêu dùng"**). Mọi thay đổi số dư ngoài hoa hồng đi qua `App\Services\Wallet`
(`post()` ghi bút toán, ném lỗi nếu số dư âm; khóa thành viên theo thứ tự id). Loại bút toán mới (`WalletTransaction`):
`purchase` (order_id), `transfer_out` / `transfer_in` (counterpart_id), `topup` (topup_id), `convert` (2 bút toán: trừ ví rút,
cộng ví tái tiêu dùng). Migration `2026_11_29_000001` (bảng `wallet_topups`, 3 cột liên kết ở `wallet_transactions`,
`orders.payment_method`).
- **Mua hàng bằng ví**: trang thanh toán có ô "Hình thức thanh toán" — Chuyển khoản | Ví tái tiêu dùng; ví chỉ chọn được khi
  số dư ≥ tổng đơn (không trả nửa ví nửa chuyển khoản; thiếu thì hiện "Còn thiếu … đ" + link nạp). Cùng transaction tạo đơn:
  trừ ví (`Wallet::payOrder`) rồi `OrderPayment::confirm()` với mã giao dịch tự sinh `VI-<mã đơn>`, `confirmed_by` NULL →
  đơn **sang thẳng "Đã thanh toán"**, không cần kế toán, `expires_at` NULL. **Tính như đơn thường**: kích hoạt, doanh số /
  lên cấp, hoa hồng F1 / F2. `orders.payment_method` = `transfer` | `wallet` (`Order::PAYMENT_METHODS`). Đơn trả bằng ví
  không bị giới hạn `MAX_OPEN_UNPAID` (đủ 3 đơn chưa thanh toán vẫn vào được checkout nếu ví đủ, ô chuyển khoản bị khóa).
  Kế toán không nhập tay được mã giao dịch bắt đầu bằng `VI-`. Thông báo "đơn mới" ghi rõ đã thanh toán bằng ví.
- **Chuyển ví cho thành viên khác** (`/tai-khoan/vi/chuyen-tien`, `Frontend\WalletActionController`): theo **mã giới thiệu**
  của người nhận (bất kỳ thành viên nào chưa bị khóa), từ ví tái tiêu dùng sang **ví tái tiêu dùng** người nhận, tối thiểu
  `Wallet::MIN_TRANSFER` = 10.000 đ, không phí, không hạn mức, **người chuyển bắt buộc đã duyệt KYC**. Hai bước: mã + số tiền
  → trang xác nhận (tên rút gọn người nhận) + **mật khẩu đăng nhập** → chuyển ngay, không cần duyệt. Ghi `AuditLog` trên
  thành viên chuyển.
- **Chuyển từ ví rút tiền sang ví tái tiêu dùng** (`/tai-khoan/vi/chuyen-vi`): **một chiều**, tối thiểu 10.000 đ.
- **Nạp tiền** (`/tai-khoan/vi/nap-tien`, `Frontend\WalletTopupController`, model `WalletTopup`): luồng giống đơn chuyển
  khoản — nhập số tiền (tối thiểu 100.000 đ) → lệnh `NAP` + 8 ký tự, hạn `payment_expiry_hours`, tối đa 3 lệnh chưa xong →
  QR công ty (số tiền + nội dung = mã lệnh, `VietQr::imageUrlFor`) → **bắt buộc ảnh bill** (`topup-bills/{id}/…enc`) → "Tôi đã
  chuyển khoản" (thông báo `topup_transferred`) → kế toán xác nhận ở **quản trị → Wallet top-ups**
  (`/backend/wallet-topup`, `Backend\WalletTopupController`, quyền mới `wallet_topup` + `wallet_topup/confirm`, migration tự
  tick cho nhóm đang có `order/confirm_payment`): mã giao dịch (unique **chung với đơn hàng**) + số tiền thực nhận ≥ số trên
  lệnh → `TopupPayment::confirm` **cộng đúng số thực nhận**. Chuyển thiếu → "Chưa nhận được tiền" trả lệnh về "Chờ chuyển
  khoản" để khách chuyển bù; hủy có lý do; lệnh đã hủy tiền về muộn vẫn xác nhận được (bắt buộc ghi chú). Lệnh mới quá hạn:
  lệnh `topups:expire` (scheduler mỗi phút). **Tiền nạp không tính doanh số / hoa hồng** (chỉ tính khi dùng ví mua hàng).
- Hiển thị: `/tai-khoan/vi` có nút Nạp tiền / Chuyển tiền (ví tái tiêu dùng) và Chuyển sang ví tái tiêu dùng (ví rút tiền),
  link "Lịch sử giao dịch ví" → `/tai-khoan/vi/giao-dich?vi=cash|reconsume` (mọi bút toán). Admin: chi tiết thành viên có thẻ
  "Giao dịch ví gần đây" (10 bút toán khác hoa hồng); chi tiết đơn ghi "Hình thức". Test: `tests/Feature/ReconsumeWalletTest.php`.

## Live có đơn thật — KHÔNG xóa đơn; tính bù hoa hồng cho đơn cũ (17/11/2026)

- Chủ dự án 17/11/2026: live đã có đơn thật (9 đơn lúc đó), **tuyệt đối không xóa đơn / không làm mất dữ liệu live**.
- Migration `2026_11_23_000001_clear_test_orders` từng xóa sạch đơn hàng → **đã vô hiệu hóa, `up()` để trống** (giữ file để
  `migrate` ghi nhận tên mà không làm gì). Không khôi phục logic xóa đơn; không viết migration / lệnh nào xóa đơn nữa.
- Tính bù hoa hồng cho đơn đã thanh toán từ trước khi có engine: `php artisan commissions:backfill` (mặc định CHỈ XEM
  TRƯỚC, in từng đơn sẽ trả cho ai) → `php artisan commissions:backfill --apply` (ghi, một transaction, kèm audit log từng
  đơn; chạy lại không tính đôi). Chỉ thêm dòng vào `commission_ledger` / `wallet_transactions` + đánh dấu
  `orders.commissioned_at`. Cấp + kích hoạt của người hưởng lấy **tại lúc đơn được thanh toán**
  (`CommissionEngine::stateAt`: tổng đơn tự mua đã thanh toán trước đó + ngày kích hoạt; không cao hơn cấp hiện tại); tỉ lệ
  theo phiên bản có hiệu lực lúc đó; người hưởng đang bị khóa thì không nhận. Lệnh dừng nếu còn đơn đã thanh toán chưa cộng
  doanh số (`levels:count-paid-orders` phải chạy trước). Test: `tests/Feature/CommissionBackfillTest.php`.
- Quy trình live: sao lưu DB → pull code → `php artisan migrate:status` (xem migration nào còn Pending) →
  `php artisan migrate --force` → `php artisan commissions:backfill` → (nếu được nhắc) `php artisan levels:count-paid-orders`
  → `php artisan commissions:backfill --apply`. Đã diễn tập trên bản sao MySQL: dữ liệu cũ y nguyên, kết quả tính bù trùng
  với kết quả engine tính lúc thanh toán.

## Cây nhị phân + mặt trời (16/11/2026 — đã có xếp cây; CHƯA tính thưởng nhóm)
- Mô hình: kết hợp **hệ mặt trời** (cây giới thiệu `members.referrer_id`: hoa hồng F1 / F2 theo người giới thiệu) và **hệ nhị
  phân** (cây vị trí: mỗi người tối đa 2 nhánh trái / phải; "bậc thưởng nhóm" sẽ tính theo **nhánh yếu hơn**).
- **Ông tổng** (gốc cây chính) = thành viên có mã ở Tham số chung → "Mã giới thiệu của ông tổng" (`root_member_code`; live:
  Nguyen văn trung, mã `U4SNA69`); chưa cấu hình thì là người không có người giới thiệu đăng ký sớm nhất
  (`BinaryTree::root()`). Thành viên **không có người giới thiệu** khác (vd tài khoản thử Nguyễn Hữu Đức `3VBJ366`) là
  **gốc của một cây riêng**, không bị trộn vào cây của ông tổng.
- Chuyển dữ liệu live: migration `2026_11_22_000001_set_binary_root_and_rebuild` đặt ông tổng = `U4SNA69` (nếu có và chưa
  cấu hình) rồi xóa mọi vị trí + xếp lại toàn bộ theo thứ tự đăng ký. Chạy một lần khi triển khai.
- Lưu trữ: `members.parent_id` (người đứng ngay trên trong cây nhị phân), `members.position` (`L` / `R`), `placed_at`;
  unique (`parent_id`, `position`). Gốc cây (ông tổng / gốc cây riêng): `parent_id` null. Doanh số nhánh không lưu, tính
  từ đơn đã thanh toán.
- **Từ 18/11/2026 (chủ dự án yêu cầu): người đăng ký TỰ CHỌN nhánh, bỏ tự động chia vào nhánh yếu.** Form `/dang-ky` có ô
  bắt buộc "Chọn nhánh": **Nhánh A** (= L) / **Nhánh B** (= R) (`branch` = A | B, `BinaryTree::BRANCHES`); người giới thiệu
  hướng dẫn F1 nên chọn nhánh nào. Lưu vào `members.sponsor_side` (migration `2026_11_25_000001`, NULL = dữ liệu cũ). Xếp:
  từ người giới thiệu đi xuống đúng phía đã chọn tới vị trí trống đầu tiên (ngoài cùng của nhánh). Thành viên không có lựa
  chọn (dữ liệu cũ, factory, seeder) vẫn theo quy tắc cũ bên dưới. `rebuild` giữ nguyên nhánh đã chọn.
  **Trên mọi giao diện gọi là "Nhánh A" / "Nhánh B"** (`BinaryTree::SIDES`), không dùng "trái / phải". Trang chi tiết thành
  viên (admin) có dòng "Nhánh tự chọn khi đăng ký". "Nhánh yếu hơn" chỉ còn để hiển thị / tính thưởng nhóm sau này.
- Quy tắc xếp cho thành viên KHÔNG có nhánh tự chọn (`App\Services\BinaryTree`, tự chạy ở sự kiện `Member::created` — mọi đường tạo thành viên đều được xếp):
  bắt đầu từ **người giới thiệu X** (không có người giới thiệu → là gốc một cây, không xếp); đang ở người còn trống nhánh → vào nhánh trống (trái trước);
  kín cả hai → đi xuống **nhánh yếu hơn** rồi lặp lại. Nhánh yếu hơn = **doanh số trong tháng** thấp hơn (tổng
  `goods_amount` đơn đã thanh toán trong tháng của mọi người trong nhánh); bằng nhau → nhánh ít người hơn; vẫn bằng → trái.
  Vị trí **cố định**: doanh số đổi về sau hay quản trị sửa người giới thiệu đều không dời chỗ.
- Thành viên cũ: `php artisan binary:place-members` xếp theo thứ tự đăng ký (xét doanh số tháng tại ngày đăng ký của từng
  người); chưa chạy thì lần có người đăng ký mới cũng tự xếp người cũ trước. `--rebuild` xóa hết vị trí và xếp lại (chỉ
  dùng trước khi vận hành thật).
- Hiển thị: quản trị → chi tiết thành viên có khung "Cây nhị phân" (vị trí, hai nhánh: người đứng đầu, số thành viên,
  doanh số tháng, nhánh yếu hơn, người kế tiếp sẽ vào nhánh nào). Website `/tai-khoan`: ô "Đại lý bạn tuyển dụng" (F1) =
  số F1 + nút Xem chi tiết → `/tai-khoan/thanh-vien` (họ tên + cấp, không lộ SĐT / email); ô "Thành viên" = số
  người dưới vị trí của mình trong cây nhị phân (`Member::branchIds()`); không hiện số người nhánh trái / phải (chủ dự án yêu cầu bỏ 17/11/2026).
- Sơ đồ cây (quản trị): nút "Xem cây nhị phân" trong khung trên → `backend/member/{member}/tree?depth=2..6`
  (`backend.members.tree`, quyền `member`, `MemberController::tree`). Dữ liệu từ `BinaryTree::tree($member, $depth)`: mỗi
  node có cấp bậc (màu theo `MemberController::LEVEL_COLORS`), bên Trái / Phải, doanh số tháng hiện tại của riêng người
  đó, "Cả nhánh" (số người + doanh số tháng gồm cả mình), nhãn "Yếu hơn", ô "Còn trống"; hết số tầng thì có "Xem tiếp N
  người bên dưới"; nút "Lên một tầng" về cây của người đứng trên. View `backend/members/tree.blade.php` +
  `_tree-node.blade.php` (đệ quy), CSS `.btree*` trong `resources/backend/css/app.css`.
- **Doanh số nhóm A / B** (19/11/2026, trang `/tai-khoan`, khung `[data-group-sales]`): nhóm A / B = toàn bộ người ở Nhánh A
  (L) / Nhánh B (R) bên dưới thành viên, cộng dồn tới lá, KHÔNG gồm đơn của chính họ. Doanh số = tiền hàng `goods_amount`
  (giá bán đã gồm thuế — **bỏ qua mọi thông tin VAT**, chủ dự án nhắc lại 19/11/2026: VAT là thông tin cũ, sẽ xóa), không
  ship, không trừ 5% phí, đơn đã thanh toán **trong tháng này** (theo `paid_at`) — `BinaryTree::legs($member)`, cùng số với
  cây nhị phân trong admin.
  Hiển thị PV: 1 PV = 1.000.000 đ, làm tròn xuống, tối đa 2 số lẻ (`App\Support\Pv::format`: 14.155.000 → "14,15").
  Chỉ ghi "Nhóm A" / "Nhóm B" + PV (20/11/2026 chủ dự án bỏ nhãn "Yếu hơn", dòng giải thích và dòng "1 PV = 1.000.000đ"); nhánh trống ghi "Chưa có
  thành viên". Không hiện số người từng nhánh (chủ dự án bỏ 17/11/2026). Test: `tests/Feature/GroupSalesTest.php`.
- Còn phải hỏi khi làm thưởng nhóm: mốc / kỳ tính / cách trả (bảng KPI tháng hiện có?), cách tính doanh số nhánh yếu.
  Test: `tests/Feature/BinaryTreeTest.php`.

## Engine lên cấp – Step 7 (16/11/2026)
- Khi kế toán **xác nhận thanh toán** đơn (`Backend\OrderController::confirmPayment`), cùng transaction gọi
  `App\Services\LevelEngine::orderPaid($order)`: cộng **tiền hàng** của đơn (`goods_amount`: đã gồm VAT, không gồm phí
  giao hàng) vào `members.cumulative_amount`; đơn đầu tiên thì đặt `activated_at`; xét cấp = cấp **đang bật** có mốc cao
  nhất mà doanh số cộng dồn **đã đạt (≥ mốc)** — một đơn có thể nhảy nhiều cấp; **không bao giờ hạ cấp** (đổi mốc / tắt
  cấp không kéo ai xuống). Xét theo mốc trong Affiliate → Levels, không theo tên (tên cấp sửa tự do).
- Mặc định: có đơn đầu → cấp mốc 1 đ (Nhân viên) · đạt 3tr → Cộng tác viên · 15tr → Đại lý · 150tr → cấp 5 · 300tr → cấp 6.
- Mỗi đơn chỉ cộng một lần: `orders.sales_counted_at`. Vết lên cấp: bảng `level_histories` (`LevelHistory`: từ cấp, lên
  cấp, đơn — null khi kích hoạt thủ công, doanh số cộng dồn lúc đó); nhật ký thành viên ghi một dòng cho mỗi đơn.
- Hiển thị: trang Tài khoản của thành viên có khung "Cấp bậc của bạn" (đã mua, còn thiếu bao nhiêu để lên cấp kế, danh
  sách cấp); trang chi tiết thành viên trong quản trị có "Lịch sử lên cấp".
- Đơn đã thanh toán từ trước khi có engine: chạy một lần `php artisan levels:count-paid-orders` (có `--dry-run`).
- Hoa hồng F1 / F2 + ví: xem mục riêng bên dưới. Test: `tests/Feature/LevelEngineTest.php`.

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

## Thêm vào màn hình chính — mở website như ứng dụng (19/11/2026)

- Website là PWA tối giản (KHÔNG có service worker, không chạy offline): `/manifest.webmanifest`
  (`Frontend\ManifestController`, `display: standalone`, tên lấy từ Cài đặt website, nền / theme trắng, 3 lối tắt) + các thẻ
  `<link rel="manifest">`, `apple-mobile-web-app-*`, `apple-touch-icon` ở `frontend/layouts/app.blade.php`.
- Biểu tượng ứng dụng tạo từ **favicon trong Cài đặt** (chủ dự án yêu cầu): `App\Support\AppIcon` đặt favicon lên nền trắng
  vuông bằng GD (192 / 512 / maskable 512 / apple 180), lưu `storage/app/public/pwa/{khóa}-{tên}.png`, đổi favicon thì tự tạo
  lại. Không có favicon / favicon .ico / thiếu GD → bộ icon tĩnh `public/assets/frontend/pwa/` (tạo sẵn từ favicon 19/11/2026).
- Gợi ý cài: partial `frontend/partials/install-app.blade.php` ở trang chủ + trang tài khoản, JS `[data-install-app]` trong
  `frontend/js/app.js` — chỉ hiện trên điện thoại khi chưa mở ở chế độ ứng dụng, đóng thì nhớ 30 ngày (localStorage). Android
  có nút "Cài ứng dụng" khi trình duyệt phát `beforeinstallprompt`, không thì hiện các bước làm tay; iOS chỉ có hướng dẫn
  (Safari → Chia sẻ → Thêm vào Màn hình chính). Cần HTTPS mới cài được. Test: `tests/Feature/PwaTest.php`.

## Ảnh bill chuyển khoản (18/11/2026)

- Chủ dự án yêu cầu: ở trang đơn hàng của thành viên, **bắt buộc tải ảnh bill chuyển khoản** rồi mới bấm được "Tôi đã chuyển
  khoản". Form `[data-bill-form]` (`frontend/orders/show.blade.php`): ô ảnh `bill` dùng chung JS `[data-kyc-image]` (xem
  trước, thu nhỏ ≤ 450 KB); nút gửi bị khóa tới khi có ảnh hợp lệ (JS), máy chủ kiểm tra lại bằng `Order::billRules()`.
- Lưu: `orders.payment_bill` (migration `2026_11_27_000001`), file mã hóa ở `bills/{id đơn}/….enc` trên disk riêng tư
  (`MemberKyc::storeImage($file, $orderId, Order::BILL_DIRECTORY)`, đọc bằng `Order::billImage()`). Đơn bị kế toán trả về
  "Đơn mới" rồi khách báo lại thì ảnh mới thay ảnh cũ. Chủ đơn xem lại: `don-hang/{order}/bill`.
- Quản trị: trang đơn có thẻ "Ảnh bill chuyển khoản" phía trên form xác nhận thanh toán (`backend/order/{order}/bill`,
  quyền `order`); ảnh do khách tự tải nên vẫn phải đối chiếu sao kê. Tin báo "khách báo đã chuyển khoản" có thêm dòng đã gửi
  bill. Test: `tests/Feature/OrderBillTest.php`; helper `TestCase::billUpload()` cho các test khác.

## Mã QR nhận tiền + sửa thông tin tài khoản (18/11/2026)

- Mã QR nhận tiền = ảnh QR tài khoản ngân hàng của thành viên, để kế toán quét khi chi hoa hồng. Lưu THEO THÀNH VIÊN
  (`members.qr_image` + `qr_uploaded_at`, migration `2026_11_26_000001`), file mã hóa trên disk riêng tư như ảnh CCCD
  (`MemberKyc::storeImage / readImage`; `Member::replaceQr()`, `qrImage()`, `hasQr()`, luật kiểm tra `Member::qrImageRules()`).
  KHÔNG bắt buộc. Tải ở hai nơi: ô "Mã QR nhận tiền" trong form KYC (`qr_image`) và trang sửa thông tin.
- Website: `/tai-khoan/thong-tin` (`Frontend\ProfileController`, nút "Sửa" ở mục Thông tin tài khoản): đổi họ tên hiển thị
  (không đổi họ tên trong hồ sơ KYC) + tải / đổi mã QR; số điện thoại, email không sửa ở đây. Ảnh của mình: `/tai-khoan/ma-qr`.
  Mỗi thay đổi ghi audit log. JS ô ảnh dùng chung `[data-kyc-image]`, mức thu nhỏ theo `data-target-bytes` (CCCD 360 KB,
  QR 200 KB → cả form KYC dưới 1 MB vì nginx mặc định giới hạn 1 MB).
- Quản trị: trang chi tiết thành viên và hồ sơ KYC hiện ảnh QR (`backend/member/{member}/qr`, quyền một trong `member`, `kyc`,
  `commission`, `withdrawal`); nhãn "Đổi sau khi duyệt KYC" khi QR tải sau `reviewed_at` của hồ sơ đã duyệt
  (`Member::qrChangedAfterKyc()`) — đối chiếu lại trước khi chuyển tiền. Test: `tests/Feature/MemberProfileTest.php`.

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
