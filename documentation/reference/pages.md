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

Dùng Node 22.23.4 trở lên; workflow dùng Node 24. Để chạy browser smoke tùy chọn, cài phụ thuộc `tools/dev` theo hướng dẫn trong repository, chuẩn bị Chromium, chạy preview, rồi chạy `node documentation/scripts/browser-smoke.cjs`. Có thể đặt `DOCS_URL` và `CHROMIUM_PATH` cho môi trường của bạn. Smoke không thay thế kiểm thử accessibility hay site public. Mở cả trang chủ lẫn `/SHOPCLONE7_PAYOS/reference/`, thử tìm kiếm ở từng phần, mở một trang `.html` và làm mới trình duyệt. Tìm kiếm Starlight bao gồm cả hai phần; tìm kiếm VitePress chỉ bao gồm tham chiếu.

## Nếu chưa thấy trang public

1. Kiểm tra repository Settings → Pages, nguồn **GitHub Actions**.
2. Workflow chỉ triển khai từ `main`; pull request chỉ build. Kiểm tra commit đã được merge và job `Documentation` trong Actions.
3. Xem log `check`, build, lint, kiểm tra link, upload và deploy; không công bố URL trước khi kiểm tra trực tiếp.
4. Nếu asset hoặc link lỗi, xác minh `base` của cả hai site và thử lại trên artifact được build mới. Kiểm tra link nội bộ không thay thế việc kiểm tra liên kết bên ngoài.

<a href="/SHOPCLONE7_PAYOS/phat-trien/docs/">Quay lại hướng dẫn đóng góp tài liệu</a>.

## Những lỗi đã khoanh vùng khi tích hợp

- **Lặp base trong link hoặc logo:** cả hai framework tự thêm base cho một số cấu hình. Kiểm tra link trong HTML đã build, không gắn `/SHOPCLONE7_PAYOS/` hai lần; `check:links` sẽ báo đường dẫn không tồn tại.
- **Tìm không thấy trang:** dùng tìm kiếm tại Starlight để tìm cả hai phần; tìm kiếm trong VitePress chỉ tìm trang tham chiếu. Nếu một trang tham chiếu mới không xuất hiện, kiểm tra bước build Pagefind sau VitePress.
- **Chưa thấy thay đổi ở preview:** build lại cả hai phần rồi kiểm tra artifact `documentation/dist/`; dev server Astro chỉ phục vụ Starlight, không build VitePress.
- **Trang 404:** đặt `draft: true` cho nội dung 404 tùy chỉnh để tránh xung đột route; preview trả 404 cùng nội dung tiếng Việt. Cần kiểm tra lại hành vi của GitHub Pages sau khi triển khai.

Các kiểm tra này không chứng minh môi trường production hoặc khả năng thanh toán thực tế.
