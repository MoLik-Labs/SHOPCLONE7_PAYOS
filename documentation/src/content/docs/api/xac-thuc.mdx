---
title: Xác thực API
description: Sử dụng X-API-Key và chuyển đổi an toàn từ tích hợp cũ.
---

Các endpoint hồ sơ, sản phẩm, action và nhập kho hỗ trợ header `X-API-Key`. Luôn dùng HTTPS và không đặt API key hoặc thông tin tài khoản vào URL, analytics hay log. Khóa là thông tin bí mật; giới hạn quyền và xoay khóa nếu bị lộ.

```http
GET /api/profile.php HTTP/1.1
Host: shop.example
X-API-Key: <API_KEY>
```

Đây là sơ đồ yêu cầu, không phải key thật. Server ưu tiên header; header sai định dạng sẽ không fallback về key trong URL. Định dạng header được hỗ trợ: 16–128 ký tự ASCII chữ, số, gạch dưới hoặc gạch ngang. Khóa đang được tạo trong hệ thống là 32 ký tự hex; khóa cũ khác định dạng cần được kiểm tra và xoay trước khi chuyển đổi.

## Tắt đường cũ sau khi di chuyển

- `API_ALLOW_QUERY_KEYS=false`: từ chối xác thực key trong query ở các endpoint được hỗ trợ.
- `API_ALLOW_LEGACY_IMPORT_GET=false`: từ chối nhập kho bằng GET kiểu cũ.

Hai cờ độc lập. Nếu không cấu hình, phiên bản hiện tại giữ hành vi cũ để tương thích; `.env.example` đặt cả hai thành `false`. Giá trị không nhận diện sẽ fail closed. Kiểm tra từng tích hợp ở staging trước khi đổi.

:::caution[Chưa loại bỏ POST legacy]
Tắt hai cờ **không tắt** nhánh nhập kho dùng key trong POST body. Nhánh cũ còn có thể cập nhật UID hoặc cho phép trùng. Hãy hạn chế ở edge và chuyển client sang header; xem [nhập kho](../nhap-kho/).
:::

Đảm bảo reverse proxy chuyển `X-API-Key` tới PHP và che header, query string, request/response body trong log. `Cache-Control: no-store` không xóa được log đã ghi trước đó.

Xem thêm [đánh giá tích hợp API và bằng chứng kiểm thử](https://github.com/MoLik-Labs/SHOPCLONE7_PAYOS/blob/main/docs/frontend/34-api-integration-review.md) trong repository.
