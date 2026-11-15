#!/usr/bin/env python3
"""
Gọi API quản trị sản phẩm Thuankhang.vn (/api/v1). Chỉ dùng thư viện chuẩn Python 3.

Cấu hình (theo thứ tự ưu tiên):
  1. Biến môi trường TK_API_URL (VD https://thuankhang.vn) và TK_API_TOKEN (tk_…)
  2. Tệp config.json cạnh SKILL.md: {"url": "https://thuankhang.vn", "token": "tk_…"}
  3. Tệp ~/.thuankhang.json (cùng định dạng)

Lệnh:
  me                                   Kiểm tra token, xem quyền
  categories                           Danh sách danh mục sản phẩm (cây, kèm số sản phẩm)
  category-create NAME [--parent-id N] [--description TEXT] [--slug SLUG]
  category-update ID [--name …] [--parent-id N|none] [--description …] [--status 0|1]
  category-delete ID
  products [--q TEXT] [--category-id N] [--page N] [--per-page N]
  product ID_OR_SLUG                   Chi tiết (ảnh, biến thể)
  product-create PAYLOAD.json|-        Tạo sản phẩm từ JSON (tệp hoặc stdin)
  product-update ID_OR_SLUG PAYLOAD.json|-
  product-delete ID_OR_SLUG            Xóa mềm

Trong payload, ảnh có thể ghi {"path": "/đường/dẫn/ảnh.webp"} — script tự đọc tệp và gửi base64.
"""
import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

SKILL_DIR = Path(__file__).resolve().parent.parent


def load_config():
    url, token = os.environ.get("TK_API_URL"), os.environ.get("TK_API_TOKEN")
    for path in (SKILL_DIR / "config.json", Path.home() / ".thuankhang.json"):
        if (url and token) or not path.exists():
            continue
        data = json.loads(path.read_text(encoding="utf-8"))
        url, token = url or data.get("url"), token or data.get("token")
    if not url or not token:
        sys.exit("Thiếu cấu hình: đặt TK_API_URL + TK_API_TOKEN, hoặc tạo config.json cạnh SKILL.md (xem config.example.json).")
    return url.rstrip("/"), token


def call(method, path, body=None, query=None):
    base, token = load_config()
    url = f"{base}/api/v1/{path.lstrip('/')}"
    if query:
        from urllib.parse import urlencode
        q = {k: v for k, v in query.items() if v is not None}
        if q:
            url += "?" + urlencode(q)
    data = json.dumps(body, ensure_ascii=False).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Authorization": f"Bearer {token}", "Accept": "application/json", "Content-Type": "application/json",
        "User-Agent": "thuankhang-products-skill/1.0",
    })
    try:
        with urllib.request.urlopen(req, timeout=120) as res:
            payload = res.read().decode("utf-8")
            return res.status, json.loads(payload) if payload else {}
    except urllib.error.HTTPError as e:
        payload = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(payload)
        except ValueError:
            return e.code, {"message": payload[:500]}
    except urllib.error.URLError as e:
        sys.exit(f"Không kết nối được {base}: {e.reason}. Kiểm tra URL và quyền truy cập mạng (allowed domains).")


def inline_local_images(payload):
    """{"path": "..."} → {"base64": "...", "filename": "..."} cho images / add_images."""
    for key in ("images", "add_images"):
        items = payload.get(key)
        if not isinstance(items, list):
            continue
        for i, item in enumerate(items):
            if isinstance(item, dict) and "path" in item:
                p = Path(item["path"]).expanduser()
                if not p.exists():
                    sys.exit(f"Không thấy tệp ảnh: {p}")
                items[i] = {"base64": base64.b64encode(p.read_bytes()).decode("ascii"), "filename": p.name}
    return payload


def read_payload(src):
    text = sys.stdin.read() if src == "-" else Path(src).read_text(encoding="utf-8")
    return inline_local_images(json.loads(text))


def out(status, data):
    print(json.dumps({"status": status, **(data if isinstance(data, dict) else {"data": data})}, ensure_ascii=False, indent=2))
    if status >= 400:
        sys.exit(1)


def main():
    ap = argparse.ArgumentParser(description="API sản phẩm Thuankhang.vn")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("me")
    sub.add_parser("categories")
    c = sub.add_parser("category-create"); c.add_argument("name"); c.add_argument("--parent-id"); c.add_argument("--description"); c.add_argument("--slug")
    c = sub.add_parser("category-update"); c.add_argument("id"); c.add_argument("--name"); c.add_argument("--parent-id"); c.add_argument("--description"); c.add_argument("--status", choices=["0", "1"])
    c = sub.add_parser("category-delete"); c.add_argument("id")
    c = sub.add_parser("products"); c.add_argument("--q"); c.add_argument("--category-id"); c.add_argument("--page"); c.add_argument("--per-page")
    c = sub.add_parser("product"); c.add_argument("key")
    c = sub.add_parser("product-create"); c.add_argument("payload")
    c = sub.add_parser("product-update"); c.add_argument("key"); c.add_argument("payload")
    c = sub.add_parser("product-delete"); c.add_argument("key")
    a = ap.parse_args()

    if a.cmd == "me":
        out(*call("GET", "me"))
    elif a.cmd == "categories":
        out(*call("GET", "categories"))
    elif a.cmd == "category-create":
        body = {"name": a.name}
        if a.parent_id: body["parent_id"] = int(a.parent_id)
        if a.description: body["description"] = a.description
        if a.slug: body["slug"] = a.slug
        out(*call("POST", "categories", body))
    elif a.cmd == "category-update":
        body = {}
        if a.name: body["name"] = a.name
        if a.parent_id: body["parent_id"] = None if a.parent_id == "none" else int(a.parent_id)
        if a.description is not None: body["description"] = a.description
        if a.status is not None: body["status"] = a.status == "1"
        out(*call("PATCH", f"categories/{a.id}", body))
    elif a.cmd == "category-delete":
        out(*call("DELETE", f"categories/{a.id}"))
    elif a.cmd == "products":
        out(*call("GET", "products", query={"q": a.q, "category_id": a.category_id, "page": a.page, "per_page": a.per_page}))
    elif a.cmd == "product":
        out(*call("GET", f"products/{a.key}"))
    elif a.cmd == "product-create":
        out(*call("POST", "products", read_payload(a.payload)))
    elif a.cmd == "product-update":
        out(*call("PATCH", f"products/{a.key}", read_payload(a.payload)))
    elif a.cmd == "product-delete":
        out(*call("DELETE", f"products/{a.key}"))


if __name__ == "__main__":
    main()
