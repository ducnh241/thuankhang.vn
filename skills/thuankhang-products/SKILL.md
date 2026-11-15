---
name: thuankhang-products
description: Đăng, sửa, xóa sản phẩm và danh mục trên website Thuankhang.vn qua API quản trị (/api/v1). Dùng khi người dùng gửi ảnh / link / thông tin sản phẩm và nói "đăng sản phẩm", "thêm sản phẩm này", "tạo sản phẩm giá …", "sửa giá / mô tả / ảnh sản phẩm", "thêm hương / màu / loại (biến thể)", "xóa sản phẩm", "tạo / sửa / xóa danh mục", hoặc hỏi sản phẩm nào đang có trên Thuankhang.vn.
---

# Đăng sản phẩm Thuankhang.vn

Website bán hàng affiliate (Laravel). Skill này thao tác **chỉ qua API** — không đụng code / database. Mọi lệnh chạy bằng
`scripts/tk_api.py` (Python 3, không cần cài thêm). Chi tiết endpoint và payload: `reference/api.md`. Quy tắc viết nội
dung: `reference/content-guide.md` — **đọc trước khi viết mô tả**.

## 0. Kiểm tra kết nối (đầu mỗi phiên)

```bash
python3 scripts/tk_api.py me
```

- Lỗi "Thiếu cấu hình": cần `config.json` cạnh SKILL.md (`{"url": "https://thuankhang.vn", "token": "tk_…"}`) hoặc biến
  môi trường `TK_API_URL`, `TK_API_TOKEN`. Token tạo ở **Admin → Hồ sơ → Token API**. Nếu người dùng dán token vào chat,
  dùng biến môi trường cho lệnh, **không** in token ra câu trả lời.
- Lỗi kết nối: domain website phải nằm trong danh sách mạng được phép của môi trường chạy code (claude.ai: Settings →
  Capabilities → allowed domains).
- `abilities` thiếu `product/add|edit|delete` → báo người dùng cấp quyền cho tài khoản, dừng.

## 1. Quy trình đăng sản phẩm mới

1. **Đọc đầu vào**: ảnh (tệp đính kèm thường ở `/mnt/user-data/uploads/`), giá ("350k" = 350000, "4tr2" = 4200000,
   "3tr" = 3000000), link (trang công khai: đọc bằng công cụ web; không đăng nhập hộ người dùng). Đọc kỹ chữ trên ảnh:
   tên, thương hiệu, dung tích / quy cách, thành phần, công dụng, số công bố, cách dùng.
2. **Kiểm tra trùng** trước khi tạo:
   ```bash
   python3 scripts/tk_api.py products --q "từ khóa tên / thương hiệu"
   ```
   - Đã có đúng sản phẩm → **không tạo mới**; báo người dùng (kèm link) và hỏi có muốn sửa / thêm ảnh không.
   - Cùng sản phẩm khác hương / màu / loại / dung tích (VD nước giặt xả 3.6kg đã có Baccarat, giờ thêm Comforts) →
     **thêm biến thể** vào sản phẩm có sẵn (mục 3), không tạo sản phẩm riêng. Nếu sản phẩm cũ chưa có biến thể mà giờ có
     loại thứ hai → chuyển nó thành sản phẩm nhiều biến thể (đổi tên bỏ phần riêng của loại cũ, thêm `variant_label`).
   - Khác kích cỡ hẳn (3.6kg vs 10kg, giá khác nhiều) → sản phẩm riêng.
3. **Chọn danh mục**: `python3 scripts/tk_api.py categories`. Dùng danh mục phù hợp nhất (ưu tiên danh mục con). Chưa có
   thì tạo (con của danh mục gốc hợp lý), VD:
   ```bash
   python3 scripts/tk_api.py category-create "Chăm sóc tóc" --parent-id 3 --description "Dầu gội, dầu xả và sản phẩm chăm sóc tóc."
   ```
4. **Soạn payload JSON** theo `reference/content-guide.md` (tên, SKU, mô tả ngắn + chi tiết HTML, thương hiệu / xuất xứ
   chỉ khi chắc chắn, `is_featured: true`, tồn kho để trống). Ảnh: `{"path": "/mnt/user-data/uploads/x.webp"}` (script tự
   gửi base64) hoặc `"https://…"` (link công khai) — **thứ tự = thứ tự hiển thị, ảnh đầu là ảnh đại diện** (chọn ảnh thấy
   rõ sản phẩm nhất làm ảnh đầu).
5. **Tạo**:
   ```bash
   python3 scripts/tk_api.py product-create /tmp/payload.json
   ```
   Lỗi 422 → đọc `errors`, sửa payload, gửi lại (VD SKU trùng → đổi SKU).
6. **Báo lại** người dùng: link sản phẩm (`url`), danh mục, giá, thương hiệu / SKU, ảnh, biến thể, và **những gì để trống
   hoặc đã viết nhẹ đi** (thương hiệu không đọc được, xuất xứ, cách dùng, công dụng đã sửa cho đúng quy định…).

## 2. Sửa sản phẩm

`product-update ID_OR_SLUG payload.json` chỉ gửi trường cần đổi. Lưu ý:
- `images` = **thay toàn bộ** bộ ảnh theo thứ tự (giữ ảnh cũ bằng `{"id": …}` lấy từ lệnh `product`); muốn **thêm** ảnh
  vào cuối thì dùng `add_images`.
- `variants` = **danh sách đầy đủ** (biến thể cũ giữ `id`; biến thể không có trong danh sách sẽ bị xóa).
- Luôn `product ID` để xem hiện trạng trước khi sửa ảnh / biến thể.

## 3. Thêm biến thể (hương / màu / loại)

1. `python3 scripts/tk_api.py product <slug>` → lấy `images` (id, index) và `variants` hiện có.
2. Payload: `add_images` chứa ảnh mới (nếu có) **hoặc** `images` đầy đủ; `variants` = biến thể cũ (giữ `id`, `image`) +
   biến thể mới. `image` là **số thứ tự ảnh sau khi lưu** (0 = ảnh đầu); với `add_images`, ảnh mới nối sau ảnh hiện có.
3. Giá riêng của biến thể chỉ đặt khi khác giá sản phẩm (`price` để trống = giá sản phẩm).
4. Cập nhật mô tả ngắn / chi tiết để liệt kê đủ các loại.

## 4. Xóa

- Sản phẩm: `product-delete ID` — xóa mềm (đơn cũ vẫn giữ thông tin). **Hỏi xác nhận** trước khi xóa, nêu rõ tên + link.
- Danh mục: `category-delete ID` — bị từ chối (409) khi còn sản phẩm hoặc danh mục con; báo người dùng chuyển / xóa
  trước. Hỏi xác nhận trước khi xóa.

## Nguyên tắc

- Không bịa thông tin không có trong ảnh / nguồn (thương hiệu, xuất xứ, thành phần, số công bố, dung tích). Thiếu thì để
  trống và nói rõ trong câu trả lời.
- Không chép nguyên văn mô tả từ website khác — viết lại theo ý chính.
- Không in token / config ra câu trả lời.
- Mỗi lần đăng xong, nói ngắn gọn bằng tiếng Việt: đã làm gì, link, cần người dùng xác nhận gì.
