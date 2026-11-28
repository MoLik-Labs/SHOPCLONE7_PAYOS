---
title: Thanh toán & đối soát
description: Trạng thái tích hợp và cách xử lý kết quả không chắc chắn.
---

:::danger[Không tự bật theo tài liệu]
Không gửi tiền thật hay bật provider chỉ dựa vào kiểm thử fixture. Kiểm tra hợp đồng, webhook, số tiền, currency, xác thực và khôi phục trên staging với nhà cung cấp trước.
:::

## payOS

payOS mặc định tắt trong `.env.example`. Cấu hình bằng thông tin do provider cấp trong môi trường riêng; xem lại migration, schema, engine và backup trước khi thay đổi database. Không đưa thông tin provider vào Git hoặc log.

## GachtheFast

Tích hợp thẻ hiện **NO-GO**: tắt và khóa cộng tiền. Nguồn gốc, phản hồi, callback retry và quy tắc xử lý một số trạng thái chưa được provider xác nhận. Không mở khóa chỉ vì test giả lập thành công; luồng bank chưa được triển khai.

## Khi mất phản hồi

1. Không tự động gửi lại yêu cầu mua, nạp hoặc cộng tiền.
2. Lưu mã tham chiếu và thời điểm; kiểm tra lịch sử đơn/giao dịch và log đã được bảo vệ.
3. Đối soát trạng thái với hệ thống liên quan và provider; chuyển cho người được phân quyền xử lý.
4. Chỉ thử lại sau khi đã xác định kết quả và phương án an toàn.

Đây không phải cam kết exactly-once. Những vấn đề chưa rõ của checkout cần được xử lý thủ công theo quy trình nội bộ.
