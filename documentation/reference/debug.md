---
title: Debug theo triệu chứng
---

# Debug theo triệu chứng

## Quy trình an toàn

1. Tái hiện trên staging cô lập bằng dữ liệu giả. Ghi thời điểm, route, phiên bản, HTTP status và mã tham chiếu **đã che**; không ghi key, cookie, hàng đã giao hoặc thông tin khách hàng.
2. Xác định bước lỗi ở browser, proxy, PHP, MySQL hay provider. Kiểm tra log với quyền phù hợp, so sánh cấu hình thực tế với `.env.example` và che dữ liệu nhạy cảm.
3. Với đơn, nhập kho hoặc giao dịch chưa rõ kết quả: dừng retry, kiểm tra lịch sử và đối soát với hệ thống liên quan trước khi thao tác tiếp.
4. Thay đổi nhỏ, chạy lại test tương ứng, ghi kết quả và các phần chưa kiểm chứng. Không chạy migration chưa được phê duyệt trên database thật.

## Triệu chứng thường gặp

| Triệu chứng | Kiểm tra trước | Tránh |
| --- | --- | --- |
| API báo thiếu key | Proxy chuyển `X-API-Key`; key đúng định dạng và chưa bị thu hồi. Xem HTTP và JSON. | In key vào log hoặc gửi lại qua URL. |
| Import trả 409 | UID đã có trong kho; kiểm tra bản ghi và quyền trước khi xử lý. | Chuyển sang nhánh legacy để ghi đè. |
| Import trả 503 hoặc timeout | Kiểm tra kho và log đã che trước khi quyết định. | Tự động gửi lại vì kết quả có thể không rõ. |
| Mất phản hồi khi tạo đơn | Kiểm tra lịch sử đơn, số dư và provider. | Tự động retry GET tạo đơn. |
| Không đăng nhập / cookie không giữ | `APP_URL` HTTPS, Host allowlist, Secure cookie và proxy tin cậy. | Tin forwarding header tùy ý từ client. |
| Trang docs lỗi liên kết | Kiểm tra base, artifact và [quy trình Pages](./pages.html). | Coi preview thành công là bằng chứng site đã public. |

Không bật GachtheFast hoặc mở khóa cộng tiền chỉ vì fixture chạy qua; hợp đồng provider và quy trình đối soát còn phải được xác minh. Xem <a href="/SHOPCLONE7_PAYOS/van-hanh/su-co/">hướng dẫn xử lý sự cố đầy đủ</a>.
