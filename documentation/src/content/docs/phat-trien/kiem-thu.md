---
title: Kiểm thử
description: Chạy regression gate và báo cáo đúng các suite bị bỏ qua.
---

Không chạy test với khách hàng, tiền hoặc cơ sở dữ liệu thật. Các fixture database cần instance cô lập, database test riêng và tài khoản chuyên dụng; xem [tests/README.md](https://github.com/MoLik-Labs/SHOPCLONE7_PAYOS/blob/main/tests/README.md) trong repository để biết điều kiện.

```bash
npm ci --prefix tools/dev
bash tools/dev/run.sh
node tools/dev/phpcl.mjs tests/brand_identity_test.php
node tests/js/api_integrations_test.mjs
```

Gate gồm lint, quét tĩnh, render preview và nhiều suite fixture. PHP-WASM **không thay thế** PHP/MySQL thật: suite không có phụ thuộc phải được báo **NOT RUN**, không gọi là pass. Các kiểm thử callback/provider giả không chứng minh hợp đồng của provider thực.

## Preview storefront

```bash
node tools/dev/phpcl.mjs tools/preview-build.php --out=/tmp/shop-preview
```

Preview dùng dữ liệu giả, các liên kết có thể bị vô hiệu hóa và không thực hiện thanh toán. Xem `tools/dev/` cho browser audit và `tests/README.md` cho các lệnh có điều kiện.

Trang docs có lệnh riêng tại [GitHub Pages](../docs/).
