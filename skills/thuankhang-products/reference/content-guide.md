# Quy tắc soạn nội dung sản phẩm

Đúc kết từ các sản phẩm đã đăng trên Thuankhang.vn. Giá trên web là giá bán cuối cùng (không tách VAT).

## Trường dữ liệu

| Trường | Quy tắc |
|---|---|
| `name` | Tên tiếng Việt rõ ràng + thương hiệu + quy cách. VD "Nước giặt xả ROW Home Care can lớn 3.6kg", "Bột mè đen hạt chia Mộc Tâm (hộp 12 gói x 15g)", "Bộ dưỡng da THULEE Diamond (Ampoule Toner + White Cream + Night Cream)". Sản phẩm nhiều biến thể: **không** ghi tên biến thể vào tên. |
| `slug` | Bỏ trống → tự tạo theo tên (trùng thì thêm -2, -3). |
| `sku` | IN HOA, chữ / số / `-`: THƯƠNGHIỆU-LOẠI-QUYCÁCH. VD `ROW-NGX-36`, `MT-MEDEN-HATCHIA`, `IDRSHIN-CS-120`. Biến thể: SKU sản phẩm + hậu tố (`ROW-NGX-36-COMFORTS`). |
| `category_id` | Danh mục phù hợp nhất (xem `categories`). |
| `price` | Số nguyên VNĐ (giá bán). |
| `brand` / `origin` | Chỉ điền khi đọc được chắc chắn trên ảnh / nguồn. Không chắc → bỏ trống, nói với người dùng. |
| `stock` | Bỏ trống (không theo dõi tồn) trừ khi người dùng nói số lượng. |
| `is_featured` | `true` (hiện ở "Sản phẩm nổi bật") trừ khi người dùng nói khác. |
| `short_description` | 1–3 câu, ≤ 500 ký tự: là gì, điểm nổi bật, quy cách. Văn bản thường. |
| `description` | HTML gọn (xem mẫu). |
| `meta_title` / `meta_description` | Thường bỏ trống (web dùng tên / mô tả ngắn). Chỉ điền khi người dùng yêu cầu SEO riêng. |
| `variant_label` | Tên nhóm biến thể: "Màu sắc", "Hương", "Loại", "Dung tích". Dùng "Loại" nếu các lựa chọn không cùng bản chất (VD "Phơi trong nhà" không phải mùi hương). |

## Mẫu `description`

```html
<h2>Tên sản phẩm – câu khẩu hiệu ngắn</h2>
<p><em>Khẩu hiệu trên bao bì (nếu có).</em></p>
<p>Giới thiệu 1–2 câu.</p>
<h3>Thành phần</h3><ul><li>…</li></ul>
<h3>Công dụng</h3><ul><li>…</li></ul>          <!-- hoặc "Điểm nổi bật" -->
<h3>Hướng dẫn sử dụng</h3><ol><li>…</li></ol>   <!-- chỉ khi có trên nhãn / nguồn -->
<h3>Quy cách</h3><p>…</p>
<h3>Thông tin sản phẩm</h3><ul><li>Thương hiệu: …</li><li>Xuất xứ: …</li></ul>
```

Combo / bộ sản phẩm: thêm mục "Bộ sản phẩm gồm" (danh sách từng món + dung tích) và mỗi món một tiểu mục.
Nhiều biến thể: thêm mục "Các hương / Các loại" liệt kê từng biến thể (và điểm riêng nếu nhãn có).

## Tuân thủ quảng cáo (bắt buộc)

- **Thực phẩm bảo vệ sức khỏe / thực phẩm chức năng** (thuốc dạng viên, tinh dầu thông đỏ, xương khớp…): công dụng
  viết dạng "Hỗ trợ …"; không dùng "chữa", "điều trị", "khỏi bệnh", "đặc trị"; **cuối mô tả luôn có**:
  `<p><em>Thực phẩm bảo vệ sức khỏe, không phải là thuốc và không có tác dụng thay thế thuốc chữa bệnh.</em></p>`
  Có số công bố / giấy tiếp nhận trên ảnh thì ghi vào "Thông tin công bố".
- **Thực phẩm thường / đồ uống** (bột ngũ cốc, nước mắm…): không ghi công dụng kiểu chữa bệnh ("tốt cho tim mạch",
  "trị …") — bỏ đi hoặc chuyển thành "bổ sung dinh dưỡng".
- **Mỹ phẩm**: không quảng cáo như thuốc. Đổi "làm trắng" → "làm sáng"; "trị nám / mụn / tàn nhang" → "hỗ trợ làm sáng,
  đều màu da, mờ thâm sạm"; "chống viêm, kháng khuẩn", "phục hồi da tổn thương" → bỏ hoặc "làm dịu, phục hồi nền da";
  "xua tan gàu" → "hỗ trợ giảm gàu". Bỏ con số không kiểm chứng ("sạch gấp 10 lần").
- **Hóa chất gia dụng** (xịt côn trùng, tẩy rửa): ghi "Sử dụng theo hướng dẫn trên bao bì."
- Bỏ các dòng khuyến mãi in trên nhãn ("Tiết kiệm 12.000đ") — giá do website quyết định.
- Mỗi lần đã viết nhẹ / bỏ một tuyên bố so với ảnh, **nói lại cho người dùng** trong câu trả lời.

## Ví dụ payload — sản phẩm có biến thể và ảnh từng biến thể

```json
{
  "name": "Nước giặt xả ROW Home Care can lớn 3.6kg",
  "sku": "ROW-NGX-36",
  "category_id": 7,
  "price": 210000,
  "brand": "ROW Home Care",
  "is_featured": true,
  "variant_label": "Loại",
  "short_description": "Nước giặt xả 2in1 ROW Home Care can 3.6kg: giặt xả thơm lâu, sạch sâu vết bẩn. 3 loại: Hương Baccarat, Hương nước hoa, Phơi trong nhà.",
  "description": "<h2>Nước giặt xả ROW Home Care – can lớn 3.6kg</h2><p><em>Giặt xả thơm lâu, sạch sâu vết bẩn.</em></p>…",
  "images": [
    {"path": "/mnt/user-data/uploads/baccarat.webp"},
    {"path": "/mnt/user-data/uploads/nuoc-hoa.webp"},
    {"path": "/mnt/user-data/uploads/phoi-trong-nha.webp"}
  ],
  "variants": [
    {"name": "Hương Baccarat", "sku": "ROW-NGX-36-BACCARAT", "image": 0},
    {"name": "Hương nước hoa", "sku": "ROW-NGX-36-NUOCHOA", "image": 1},
    {"name": "Phơi trong nhà", "sku": "ROW-NGX-36-PHOINHA", "image": 2}
  ]
}
```

Thêm biến thể "Hương Comforts" giá riêng 200.000đ vào sản phẩm trên (giả sử đang có 3 ảnh, id biến thể 11/12/13):

```json
{
  "add_images": [{"path": "/mnt/user-data/uploads/comforts.webp"}],
  "variants": [
    {"id": 11, "name": "Hương Baccarat", "sku": "ROW-NGX-36-BACCARAT", "image": 0},
    {"id": 12, "name": "Hương nước hoa", "sku": "ROW-NGX-36-NUOCHOA", "image": 1},
    {"id": 13, "name": "Phơi trong nhà", "sku": "ROW-NGX-36-PHOINHA", "image": 2},
    {"name": "Hương Comforts", "sku": "ROW-NGX-36-COMFORTS", "price": 200000, "image": 3}
  ]
}
```
