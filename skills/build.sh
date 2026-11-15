#!/usr/bin/env bash
# Đóng gói skill thành zip để tải lên claude.ai (Settings → Skills → Upload). Kèm config.json nếu có.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist
out="dist/thuankhang-products.zip"
rm -f -- "$out"
zip -rq "$out" thuankhang-products -x '*/__pycache__/*' '*.pyc' '*/.DS_Store'
echo "Đã tạo skills/$out"
[ -f thuankhang-products/config.json ] && echo "(Có kèm config.json — chứa token, không chia sẻ file zip này.)" || true
