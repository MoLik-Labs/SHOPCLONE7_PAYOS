---
title: Kiểm tra GitHub Pages
---

# Kiểm tra GitHub Pages

Tài liệu là static HTML; GitHub Pages không chạy PHP, MySQL, cron hay callback thanh toán. Trang Starlight nằm dưới `/SHOPCLONE7_PAYOS/`; tham chiếu VitePress nằm dưới `/SHOPCLONE7_PAYOS/reference/`.

## Kiểm tra trước khi phát hành

```bash
npm ci --prefix documentation --ignore-scripts
npm run check --prefix documentation
npm run build --prefix documentation
npm run lint:content --prefix documentation
npm run test:links --prefix documentation
npm run check:links --prefix documentation
npm run preview --prefix documentation
```

Dùng Node 22.23.4 trở lên; workflow dùng Node 24. Để chạy browser smoke tùy chọn, cài phụ thuộc `tools/dev` theo hướng dẫn trong repository, chuẩn bị Chromium, chạy preview, rồi chạy `node documentation/scripts/browser-smoke.cjs`. Có thể đặt `DOCS_URL` và `CHROMIUM_PATH` cho môi trường của bạn. Smoke không thay thế kiểm thử accessibility hay site public. Mở cả trang chủ lẫn `/SHOPCLONE7_PAYOS/reference/`, thử tìm kiếm ở từng phần, mở một trang `.html` và làm mới trình duyệt. Hai chỉ mục tìm kiếm độc lập; không giả định tìm kiếm ở một phần sẽ tìm thấy mọi trang của phần kia.

## Nếu chưa thấy trang public

1. Kiểm tra repository Settings → Pages, nguồn **GitHub Actions**.
2. Workflow chỉ triển khai từ `main`; pull request chỉ build. Kiểm tra commit đã được merge và job `Documentation` trong Actions.
3. Xem log `check`, build, lint, kiểm tra link, upload và deploy; không công bố URL trước khi kiểm tra trực tiếp.
4. Nếu asset hoặc link lỗi, xác minh `base` của cả hai site và thử lại trên artifact được build mới. Kiểm tra link nội bộ không thay thế việc kiểm tra liên kết bên ngoài.

<a href="/SHOPCLONE7_PAYOS/phat-trien/docs/">Quay lại hướng dẫn đóng góp tài liệu</a>.

## Những lỗi đã khoanh vùng khi tích hợp

- **Lặp base trong link hoặc logo:** cả hai framework tự thêm base cho một số cấu hình. Kiểm tra link trong HTML đã build, không gắn `/SHOPCLONE7_PAYOS/` hai lần; `check:links` sẽ báo đường dẫn không tồn tại.
- **Tìm không thấy trang:** Starlight và VitePress có hai chỉ mục tĩnh độc lập; dùng tìm kiếm trong đúng phần.
- **Chưa thấy thay đổi ở preview:** build lại cả hai phần rồi kiểm tra artifact `documentation/dist/`; dev server Astro chỉ phục vụ Starlight, không build VitePress.
- **Cảnh báo 404 của Astro:** build hiện báo route `/404` xung đột với route 404 ưu tiên; `dist/404.html` vẫn được tạo. Cần kiểm tra hành vi lỗi thực tế của GitHub Pages sau khi triển khai.

Các kiểm tra này không chứng minh môi trường production hoặc khả năng thanh toán thực tế.
