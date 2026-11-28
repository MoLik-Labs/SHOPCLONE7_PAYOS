# Nhật ký debug tài liệu

## Phạm vi

Kiểm tra trên bản build tĩnh và preview cô lập, không kết nối database hoặc provider. Đây không phải kiểm thử production, audit accessibility đầy đủ hay xác minh thanh toán. GitHub Pages chỉ phục vụ tài liệu, không chạy ứng dụng PHP.

## Vấn đề, nguyên nhân và cách kiểm tra lại

1. **Tìm kiếm bỏ sót tham chiếu:** Starlight build trước khi VitePress thêm HTML. Pagefind của Starlight vì vậy chỉ chứa trang chính. Build hiện chạy `scripts/search-index.mjs` sau cả hai framework, gắn `data-pagefind-body` cho nội dung VitePress và fail nếu marker biến mất. Browser smoke tìm `lặp base` từ trang chủ và xác nhận có kết quả từ `reference/pages.html`. Tìm kiếm trong VitePress vẫn chỉ tìm phần tham chiếu.
2. **Cảnh báo route `/404`:** nội dung 404 tùy chỉnh vừa được Starlight đưa vào danh sách route vừa trùng route 404 của Astro. Đặt `draft: true` để loại khỏi danh sách route production; trang 404 vẫn đọc entry này. Preview trả HTTP 404 và nội dung tiếng Việt. Cần xác minh lại trên GitHub Pages sau khi phát hành.
3. **Đường dẫn bị lặp base:** hai framework có quy tắc tự thêm base; dùng `/reference/` trong cấu hình sidebar Starlight thay vì gắn sẵn `/SHOPCLONE7_PAYOS/` hai lần. `check:links` kiểm tra target và anchor trong artifact dưới project base, cùng fixture cho lỗi base thiếu.

4. **Cảnh báo Node 20 trong Actions:** workflow trước dùng action chạy Node 20, được runner ép sang Node 24. Đã cập nhật các action Pages/checkout/setup-node và ghim SHA phiên bản hỗ trợ Node 24. Cần xác nhận lại job deploy thật sau khi merge.

## Chạy lại

```bash
npm ci --prefix documentation --ignore-scripts
npm run check --prefix documentation
npm run build --prefix documentation
npm run lint:content --prefix documentation
npm run test:links --prefix documentation
npm run check:links --prefix documentation
npm run preview --prefix documentation
```

Dùng Node >= 22.23.4 (CI dùng Node 24). Khi preview chạy và `tools/dev` đã có `puppeteer-core` cùng Chromium phù hợp, chạy `node documentation/scripts/browser-smoke.cjs`; có thể đặt `DOCS_URL` và `CHROMIUM_PATH`. Bộ smoke kiểm tra 390px/1440px, điều hướng, reload, tìm kiếm, 404 và lỗi JS; không kiểm tra link ngoài hay hành vi GitHub Pages thực tế.

Nimbus lint chỉ kiểm tra các trang MDX trong `src/content/` với năm rule được gọi tường minh; nó không lint Markdown VitePress hoặc chứng minh nội dung kỹ thuật đúng. CI build, lint và kiểm tra link trước khi upload artifact; workflow chỉ deploy trên `main`. Xem Settings → Pages và Actions sau khi merge để xác nhận URL public.
