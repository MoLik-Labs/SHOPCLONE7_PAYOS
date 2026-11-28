---
title: API & an toàn
---

# API & an toàn

Trang này là bản tham chiếu nhanh; xem <a href="/SHOPCLONE7_PAYOS/api/xac-thuc/">hướng dẫn xác thực</a> và <a href="/SHOPCLONE7_PAYOS/api/nhap-kho/">hướng dẫn nhập kho</a> để thực hiện chuyển đổi.

| Đường dẫn của shop | Phương thức | Lưu ý |
| --- | --- | --- |
| `/api/profile.php` | GET | Hồ sơ và số dư. |
| `/api/products.php` | GET | Các danh mục con và sản phẩm đang bật. |
| `/api/product.php?product=3` | GET | Dữ liệu sản phẩm theo ID. |
| `/api/importAccount.php` | POST | Header admin key và form `code`, `account`, `filter=1`. |
| `/api/api.php?action=create-order&service_id=3&amount=1` | GET | **Có tác động:** trừ số dư và tạo đơn. |
| `/api/api.php?action=get-order-detail&order_id=...` | GET | Chỉ tra cứu đơn thuộc tài khoản xác thực. |

Dùng `X-API-Key` qua HTTPS; header sai định dạng không fallback sang query key. Kiểm tra cả HTTP status lẫn trường `status` hoặc `code` trong JSON. Không ghi khóa, response chứa hàng đã giao hay số dư vào log.

::: danger Kết quả không rõ
Không tự động retry tạo đơn hoặc nhập kho sau timeout. Đối soát đơn, số dư và kho trước khi quyết định. Kiểm tra UID rồi insert chưa atomic; các request đồng thời vẫn có thể trùng khi database thiếu ràng buộc phù hợp.
:::

Hai cờ `API_ALLOW_QUERY_KEYS=false` và `API_ALLOW_LEGACY_IMPORT_GET=false` không tắt nhánh import legacy dùng key trong POST body. Hạn chế client cũ ở edge và chuyển đổi có kiểm soát. Chi tiết và bằng chứng fixture nằm trong [API integration review](https://github.com/MoLik-Labs/SHOPCLONE7_PAYOS/blob/main/docs/frontend/34-api-integration-review.md).
