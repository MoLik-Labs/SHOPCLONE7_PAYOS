---
title: Cài đặt an toàn
description: Chuẩn bị môi trường thử nghiệm và phụ thuộc cần thiết.
---

:::caution[Chỉ dùng staging]
Không chạy các bước có tác động lên database thật. Sao lưu, phục hồi một bản sao **cô lập**, sử dụng thông tin thử nghiệm và rà soát quyền trước khi bắt đầu.
:::

## Yêu cầu

- PHP 7.4 trở lên; khuyến nghị chọn phiên bản còn được hỗ trợ bảo mật và xác minh tính tương thích. Cần `mysqli`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `gd`.
- MySQL 5.7+ hoặc MariaDB 10.3+, charset `utf8mb4` và schema shop tương thích.
- Composer 2; Apache với `mod_rewrite` hoặc cấu hình Nginx tương đương.
- Node.js chỉ cần cho công cụ kiểm thử và trang docs, không cần để chạy shop PHP.

## Chuẩn bị

1. Lấy schema gốc hợp lệ hoặc bản sao shop, khôi phục vào database staging riêng.
2. Cài phụ thuộc và kiểm tra extension trên chính máy chạy PHP:

   ```bash
   composer install --no-dev --optimize-autoloader
   composer check-platform-reqs --no-dev
   cp .env.example .env
   ```

3. Cấu hình `.env`/biến môi trường theo [hướng dẫn cấu hình](../cau-hinh/). Không commit file `.env`.
4. Bảo vệ thư mục upload, cấu hình HTTPS, Host allowlist và rewrite. Với Nginx phải tự áp dụng các giới hạn tương đương `.htaccess`.
5. Chạy kiểm thử, kiểm tra đăng nhập, sản phẩm, kho và luồng mua trên **dữ liệu giả**.

:::danger[Không chạy migration mù quáng]
`install.php` là script cũ có DDL phụ thuộc schema, không phải bước cài đặt production tự động. `update.php` có thể ghi đè tùy chỉnh. Migration payOS có thể thay đổi engine và kiểu cột. Cần rà soát, diễn tập trên bản sao và phê duyệt riêng trước khi áp dụng.
:::
