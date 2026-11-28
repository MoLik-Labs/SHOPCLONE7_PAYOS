---
title: Cấu hình
description: Các nhóm biến môi trường quan trọng và nguyên tắc bảo vệ bí mật.
---

Tham khảo `.env.example` trong repository để biết đầy đủ biến và giá trị mặc định. Dùng biến môi trường của hosting hoặc `.env` không được theo dõi bởi Git. Không đăng key, mật khẩu, callback thật hay dữ liệu khách hàng lên tài liệu công khai.

| Nhóm | Nội dung chính | Lưu ý |
| --- | --- | --- |
| Database | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Tài khoản với quyền phù hợp, database riêng cho staging. |
| Ứng dụng | `APP_URL`, `SECRET_KEY`, `TRUSTED_PROXY_IPS` | `APP_URL` là HTTPS origin chuẩn, không kèm path; chỉ tin IP proxy trực tiếp đã xác định. Không tự ý đổi key khi còn dữ liệu mã hóa. |
| API | `API_ALLOW_QUERY_KEYS`, `API_ALLOW_LEGACY_IMPORT_GET` | `.env.example` tắt đường cũ; khi hai biến vắng mặt hệ thống giữ chế độ tương thích. Xem [xác thực](../../api/xac-thuc/). |
| payOS | `PAYOS_ENABLED`, `PAYOS_CLIENT_ID`, `PAYOS_API_KEY`, `PAYOS_CHECKSUM_KEY` | Mặc định tắt; cấu hình và xác minh ở staging trước. |
| Cron | `CRON_TOKEN` | Bảo vệ lời gọi HTTP, không đưa token vào URL hoặc log. |
| GachtheFast | `GTF_*` | Đang tắt và khóa cộng tiền; không bật trước khi hoàn tất các điều kiện kiểm chứng. |

`APP_URL` ảnh hưởng URL tuyệt đối và cookie Secure. Reverse proxy phải ghi đè forwarding headers từ client; cấu hình Host allowlist ở edge. Tránh bật `DB_DEBUG` trên production vì SQL lỗi có thể chứa dữ liệu nhạy cảm.
