# Xử lý yêu cầu mua chưa rõ kết quả

Tài liệu dành cho nhân viên có quyền hỗ trợ, không phải cơ chế tự động xác nhận giao dịch. Không yêu
cầu khách gửi mật khẩu, token đăng nhập, API key, mã OTP hay nội dung tài khoản đã mua. Xác minh danh
tính khách qua quy trình riêng của shop trước khi xem hoặc cung cấp thông tin giao dịch.

## Khách cần làm gì?

- Không bấm mua lại, không mở tab khác để thử tiếp. Kiểm tra lịch sử đơn và liên hệ shop bằng các liên
  kết trên trang sản phẩm. Giữ tab nếu có thể: dấu cảnh báo chỉ nằm trong sessionStorage của tab.
- Gửi **mã tham chiếu yêu cầu** nếu trang hiển thị, tên sản phẩm và thời điểm gần đúng. Đây **không
  phải mã đơn hàng**, không chứng minh mua thành công. Nếu lưu trữ bị chặn/hỏng, trang có thể không
  có mã; không suy ra không có đơn cũ. Không gửi thông tin đăng nhập hay mã API.

## Đối chiếu thủ công, chỉ đọc

1. Sau khi xác minh khách, lấy ID người mua từ hệ thống quản trị đáng tin cậy, không từ lời khai làm
   bằng chứng duy nhất. Kiểm tra mã tham chiếu chỉ chứa `A-Z`, `a-z`, `0-9`, dấu gạch ngang và dài
   8–64 ký tự. Không đưa mã vào URL, log công khai hoặc câu SQL ghép chuỗi.
2. Với yêu cầu checkout web có mã tham chiếu, mã giao dịch tương ứng được tính bằng chính hàm
   `orbit_order_id($buyerId, $reference)` trong `libs/helper.php`: tiền tố `W` + 30 ký tự đầu của
   SHA-256 trên chuỗi `(int)buyerId . '|' . reference`. Đây là cách xác định mã để tìm kiếm, không
   phải kiểm tra chữ ký, xác thực khách, hay chứng cứ rằng giao dịch đã hoàn tất.
3. Dùng tài khoản chỉ đọc, truy vấn có tham số và giới hạn theo đúng buyer: kiểm tra `product_order`
   theo `buyer` và `trans_id`, `dongtien` theo `user_id` và `transid` lần lượt `ORDER_<trans_id>`,
   `REFUND_<trans_id>`, `PARTIAL_<trans_id>`, `product_sold` theo `buyer` và `trans_id`, và nếu có mã
   giảm giá thì `coupon_used` theo `user_id` và `trans_id`. Tránh xuất các trường chứa nội dung tài
   khoản hoặc dữ liệu cá nhân không cần thiết. Kiểm tra cả thời điểm và trạng thái xoá/hoàn trong bản
   ghi; hệ thống có thể có điều chỉnh thủ công không dùng các tiền tố này.
4. Đối chiếu log có kiểm soát và bằng chứng của nhà cung cấp qua kênh quản trị được phép. Yêu cầu có
   thể vẫn đang chạy; một dòng ledger, một dòng đơn, hay việc không thấy dòng nào không đủ để kết
   luận toàn bộ giao hàng/hoàn tiền. Đặc biệt timeout nhà cung cấp không chứng minh nhà cung cấp
   chưa xử lý. Lưu kết quả đối chiếu và chuyển người có thẩm quyền quyết định theo quy trình shop.

Không gửi lại đơn, không gọi lại nhà cung cấp, không tự chạy UPDATE/DELETE hoặc hoàn tiền chỉ vì thiếu
một bản ghi. Quy trình hiện tại chưa có trạng thái bền vững, chưa có công cụ giải quyết/xoá cảnh báo
được xác minh; can thiệp tiền hoặc dữ liệu cần điều tra và phê duyệt riêng. Trong backend hiện tại,
những nhánh lỗi nhà cung cấp có thể tự hoàn tiền dù kết quả bên ngoài chưa rõ: kiểm tra cả hai phía,
không giả định nhãn lỗi là kết quả cuối cùng.

Thông tin này mô tả mã nguồn hiện tại, không thay thế kiểm tra schema/cấu hình thực tế. Không chạy
truy vấn hay công cụ đối chiếu trên production khi chưa được chủ hệ thống cho phép.
