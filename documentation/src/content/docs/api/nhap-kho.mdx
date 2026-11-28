---
title: Nhập kho
description: POST dùng header và giới hạn của việc chống trùng UID.
---

Gửi POST form tới `/api/importAccount.php` bằng key của tài khoản admin có quyền `edit_stock_product`. Body gồm `code`, `account` dạng `UID|...`, và tùy chọn `filter=1`. Nếu không truyền `filter`, hệ thống dùng mặc định; header path không chấp nhận `filter=0`.

```http
POST /api/importAccount.php HTTP/1.1
Host: shop.example
X-API-Key: <ADMIN_API_KEY>
Content-Type: application/x-www-form-urlencoded

code=SKU-EXAMPLE&account=fixture%7Cexample&filter=1
```

Đây là dữ liệu minh họa, không gửi thông tin thật qua một trang docs tĩnh. Header path từ chối `api_key` hoặc `account` trong URL, đọc body POST và không tự ghi đè hoặc chuyển UID đã tồn tại. Một UID đã thấy trong kho trả `409`; lỗi kiểm tra/lưu có thể trả `503`.

:::danger[Không bảo đảm chống trùng đồng thời]
Kiểm tra UID và insert là hai bước, không atomic. Hai request đồng thời vẫn có thể tạo trùng khi database không có ràng buộc phù hợp. Không coi API này là idempotent. Nếu timeout hoặc lỗi không rõ kết quả, kiểm tra kho trước khi thao tác tiếp.
:::

POST legacy có thể dùng key trong body và hành vi cập nhật UID khác. Tắt GET legacy và query key không tắt nhánh này; hãy hạn chế client cũ ở edge rồi di chuyển theo [hướng dẫn xác thực](../xac-thuc/).
