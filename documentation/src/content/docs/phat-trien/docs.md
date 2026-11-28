---
title: Trang docs & GitHub Pages
description: Xây dựng và phát hành trang tài liệu Starlight.
---

Trang này được dựng bằng Astro + Starlight trong thư mục `documentation/`, tách khỏi ứng dụng PHP. Tìm kiếm chạy trên chỉ mục tĩnh được tạo lúc build; không cần server backend.

## Chạy cục bộ

```bash
npm ci --prefix documentation --ignore-scripts
npm run dev --prefix documentation
```

Mở địa chỉ dev server và thêm đường dẫn cơ sở `/SHOPCLONE7_PAYOS/`. Để kiểm tra bản build production:

```bash
npm run check --prefix documentation
npm run build --prefix documentation
npm run preview --prefix documentation
```

Trang dùng Node 22.23.4 trở lên (khuyến nghị Node 24 LTS). Nội dung nằm trong `documentation/src/content/docs/`; điều hướng và `base` nằm trong `documentation/astro.config.mjs`. Đường dẫn project Pages dự kiến là `https://molik-labs.github.io/SHOPCLONE7_PAYOS/` sau khi triển khai thành công.

## Triển khai

Workflow `.github/workflows/docs-pages.yml` kiểm tra, build và tải artifact Pages; bản trên `main` sẽ triển khai khi Pages được cấu hình dùng GitHub Actions. Nó cũng có thể build trong pull request mà không triển khai. Kiểm tra Settings → Pages, trạng thái Actions và URL thực tế trước khi công bố; chỉ URL dự kiến không chứng minh site đã hoạt động.

Không đưa `.env`, dữ liệu khách hàng, bản backup hoặc thông tin provider vào thư mục docs. Các ghi chú lịch sử ở `docs/` không tự động được xuất bản: chỉ các trang được biên soạn trong `documentation/src/content/docs/` mới được đưa vào site.
