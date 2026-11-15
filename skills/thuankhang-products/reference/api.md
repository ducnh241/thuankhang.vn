# API sản phẩm & danh mục — Thuankhang.vn (`/api/v1`)

- **Xác thực**: header `Authorization: Bearer tk_…`. Token tạo / thu hồi ở **Admin → Hồ sơ → Token API**; mang đúng quyền
  của tài khoản (nhóm quyền): `product` (xem), `product/add`, `product/edit`, `product/delete`. Tài khoản bị khóa / mất
  quyền quản trị → token hết tác dụng.
- Gửi `Accept: application/json`, thân JSON (`Content-Type: application/json`).
- Giới hạn 120 yêu cầu / phút / tài khoản.
- Mọi thay đổi ghi vào **Nhật ký hệ thống** dưới tên tài khoản sở hữu token.

| Mã | Ý nghĩa |
|---|---|
| 200 / 201 | Thành công: `{"data": …}` |
| 401 | Thiếu / sai / hết hạn token |
| 403 | Tài khoản không có quyền cho thao tác này (hoặc bị khóa) |
| 404 | Không tìm thấy |
| 409 | Không xóa được (danh mục còn sản phẩm / danh mục con) — `{"message"}` |
| 422 | Dữ liệu không hợp lệ — `{"message", "errors": {"trường": ["lỗi"]}}` |

## Kiểm tra token

`GET /me` → `{"data": {"name", "email", "abilities": ["product", "product/add", …]}}`

## Danh mục sản phẩm

| | |
|---|---|
| `GET /categories` | Cây danh mục (cha trước con): `id, name, slug, parent_id, description, status, url, product_count` |
| `GET /categories/{id}` | Một danh mục |
| `POST /categories` | `name` (bắt buộc), `slug`? (bỏ trống = theo tên), `parent_id`?, `description`?, `status`? (mặc định `true`) |
| `PATCH /categories/{id}` | Các trường trên (gửi trường nào sửa trường đó). Không được đặt cha là chính nó / con của nó. |
| `DELETE /categories/{id}` | 409 nếu còn sản phẩm (kể cả đã xóa mềm) hoặc danh mục con |

## Sản phẩm

`GET /products?q=&category_id=&status=&page=&per_page=` (≤ 100) — tìm theo tên / SKU / slug / thương hiệu, mới nhất
trước. Trả `data[]` (`id, name, slug, sku, url, category, price, status, is_featured, brand, cover, variant_count`) và
`meta {page, per_page, total, last_page}`.

`GET /products/{id|slug}` — chi tiết:

```json
{"data": {"id": 9, "name": "…", "slug": "…", "sku": "…", "price": 210000, "vat_rate": 10, "stock": null,
  "brand": "…", "origin": null, "short_description": "…", "description": "<h2>…</h2>", "meta_title": null,
  "meta_description": null, "status": true, "is_featured": true, "sort_order": 0, "variant_label": "Loại",
  "url": "https://…/san-pham/…", "category": {"id": 7, "name": "Gia dụng", "slug": "gia-dung"},
  "images": [{"id": 18, "index": 0, "url": "https://…/storage/products/….webp"}],
  "variants": [{"id": 11, "name": "Hương Baccarat", "sku": "…", "price": null, "effective_price": 210000,
                "stock": null, "active": true, "image_id": 18, "image": 0}]}}
```

`POST /products` — tạo. `PATCH /products/{id|slug}` — sửa (chỉ gửi trường cần đổi). `DELETE /products/{id|slug}` — xóa
mềm (đơn hàng cũ vẫn giữ tên / giá đã mua).

| Trường | Kiểu | Ghi chú |
|---|---|---|
| `name` | chuỗi ≤ 255 | bắt buộc khi tạo |
| `category_id` | số | bắt buộc khi tạo; danh mục sản phẩm |
| `price` | số nguyên ≥ 0 | bắt buộc khi tạo; VNĐ **đã gồm VAT** |
| `slug` | chuỗi | bỏ trống = theo tên (trùng thì thêm -2…) |
| `sku` | `A-Z 0-9 . _ -`, ≤ 50 | tự IN HOA; không trùng |
| `vat_rate` | số 0–100 | bỏ trống = VAT mặc định của website |
| `stock` | số nguyên / `null` | `null` = không theo dõi tồn kho |
| `brand`, `origin` | chuỗi ≤ 100 | |
| `short_description` | chuỗi ≤ 500 | văn bản thường |
| `description` | HTML | website tự lọc thẻ / thuộc tính nguy hiểm khi hiển thị |
| `meta_title` (≤ 70), `meta_description` (≤ 160) | chuỗi | SEO; trống = dùng tên / mô tả ngắn |
| `status` | bool | `false` = ẩn khỏi website |
| `is_featured` | bool | hiện ở "Sản phẩm nổi bật" |
| `sort_order` | số −9999…9999 | nhỏ đứng trước |
| `variant_label` | chuỗi ≤ 50 | tên nhóm biến thể |
| `images` | mảng ≤ 10 | **thay toàn bộ** bộ ảnh theo thứ tự; phần tử đầu = ảnh đại diện |
| `add_images` | mảng | thêm ảnh vào cuối (tổng ≤ 10) |
| `variants` | mảng ≤ 50 | **danh sách đầy đủ** biến thể (đồng bộ) |

**Phần tử ảnh** (`images` / `add_images`), một trong:
- `"https://…"` hoặc `{"url": "https://…"}` — website tự tải về (chỉ link công khai; chặn địa chỉ nội bộ);
- `{"base64": "<dữ liệu>", "filename": "a.webp"}` — có thể kèm tiền tố `data:image/…;base64,`;
- `{"id": 18}` — giữ ảnh đang có (chỉ dùng trong `images` khi sửa).

PNG / JPG / WEBP, ≤ 4 MB / ảnh. Ảnh cũ không có trong `images` bị xóa.

**Phần tử biến thể** (`variants`): `id`? (giữ / sửa biến thể cũ; không có = thêm mới), `name` (bắt buộc, không trùng),
`sku`?, `price`? (`null` = giá sản phẩm), `stock`? (`null` = không theo dõi), `active`? (mặc định `true`),
`image`? = số thứ tự ảnh **sau khi lưu** (0 = ảnh đầu) hoặc `image_id`? = id ảnh. Biến thể cũ không có trong danh sách
bị xóa (đơn cũ vẫn giữ tên biến thể đã mua).

## Ví dụ curl

```bash
curl -s https://thuankhang.vn/api/v1/products \
  -H "Authorization: Bearer $TK_API_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Nước lau kính ROW Home Care","category_id":7,"price":42000,"sku":"ROW-NLK",
       "brand":"ROW Home Care","is_featured":true,"images":["https://example.com/nuoc-lau-kinh.webp"]}'
```
