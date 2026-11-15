# Skills cho Claude

## thuankhang-products — đăng / sửa / xóa sản phẩm và danh mục qua API

Cho phép đăng sản phẩm từ Claude (claude.ai hoặc Claude Code) bằng cách gửi ảnh + giá, không cần mở code / database.

### Cài lên claude.ai

1. Tạo token: **Admin → Hồ sơ → Token API → Tạo** (nên dùng tài khoản nhóm "Vận hành"; token có đúng quyền của tài khoản).
2. Đóng gói: `skills/build.sh` → tạo `skills/dist/thuankhang-products.zip`.
   - Muốn skill tự có cấu hình: tạo `skills/thuankhang-products/config.json` từ `config.example.json` **trước khi** đóng gói
     (file này bị `.gitignore`, không commit). Không có thì dán token khi Claude hỏi.
3. claude.ai → Settings → Capabilities: bật **Code execution**, thêm domain website (VD `thuankhang.vn`) vào danh sách
   domain được phép truy cập mạng; vào **Skills → Upload skill** → chọn file zip.
4. Website phải chạy công khai (HTTPS) — Claude trên cloud không truy cập được `thuankhang.vn.local` / máy cá nhân.

### Dùng trong Claude Code (máy local)

Chép thư mục `skills/thuankhang-products` vào `~/.claude/skills/` (hoặc `.claude/skills/` của dự án), đặt
`TK_API_URL` / `TK_API_TOKEN` hoặc `config.json`.

### Tài liệu API

`skills/thuankhang-products/reference/api.md`.
