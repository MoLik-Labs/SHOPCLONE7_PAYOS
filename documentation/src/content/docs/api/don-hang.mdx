---
title: Tạo & tra cứu đơn
description: Hợp đồng hiện tại và quy trình khi không nhận được phản hồi.
---

Action API hiện sử dụng `X-API-Key` cho xác thực:

| Action | Đường dẫn | Tác động |
| --- | --- | --- |
| `create-order` | `/api/api.php?action=create-order&service_id=3&amount=1` | **Thay đổi số dư và tạo đơn** qua GET. |
| `get-order-detail` | `/api/api.php?action=get-order-detail&order_id=...` | Tra cứu nội dung đã giao thuộc tài khoản xác thực. |

Số lượng phải tuân thủ khoảng min/max của sản phẩm. Lưu `order_id` và bảo vệ nội dung đã giao. Không đưa yêu cầu tạo đơn vào liên kết để người dùng mở sẵn, bộ tiền tải, crawler hoặc công cụ tự gọi lại.

:::danger[Không retry tự động]
`create-order` chưa có idempotency bền vững xuyên qua các bước thanh toán, coupon và nhà cung cấp. Timeout có thể là một kết quả không rõ; hãy kiểm tra lịch sử và đối soát trước khi quyết định thao tác tiếp. Thay GET bằng header key không làm mutation an toàn để retry.
:::

Không xem HTTP 200 là bằng chứng thành công; kiểm tra `code`, `message` và nội dung JSON. Không ghi hàng đã giao, key hoặc số dư vào log.
