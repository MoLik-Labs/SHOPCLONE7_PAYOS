# Giảm khóa nhầm ở checkout

## Thay đổi

Một số lỗi kiểm tra trước khi bắt đầu xử lý đơn trước đây khiến tab bị khóa vô thời hạn, kể cả khi
request hiện tại chưa giữ mã giảm giá, trừ ví, thay đổi kho hoặc gọi nhà cung cấp. Endpoint web nay trả
`status=error`, `outcome=not_started` và đúng `request_id` cho các nhánh đã được khoanh vùng:

- Thao tác quá nhanh, sản phẩm/số lượng không hợp lệ, không đủ kho, nhà cung cấp bị tắt.
- Số dư không đủ **nếu không có mã giảm giá**. Khi có mã giảm giá, việc giữ/nhả mã có thể đã xảy ra;
  không gắn nhãn này và vẫn yêu cầu đối chiếu.

Khi nhận đúng nhãn và đúng mã của request đang chờ, trình duyệt kiểm tra marker trong sessionStorage,
tạo mã request mới khác mã cũ, xóa marker và kiểm tra lại; sau đó người mua có thể **tự** sửa và bấm
mua lần nữa. Không tự gửi lại. Nếu marker thay đổi, xóa không thành công, mã không khớp, response
thiếu cấu trúc, request mất kết nối, debit trả lỗi hoặc kết quả nhà cung cấp không rõ, nút vẫn khóa
và yêu cầu kiểm tra lịch sử/liên hệ hỗ trợ.

Nhãn chỉ mô tả **request đó** và không chứng minh không có request khác cùng key đang xử lý. Marker
vẫn chỉ có hiệu lực trong cùng tab và có thể bị bỏ qua bởi tab, thiết bị hay client trực tiếp.
Đây không phải là durable claim, không có bảo đảm exactly-once xuyên hệ thống. Không mở khóa các chốt tiền khi
chưa có hợp đồng provider được xác minh, hoặc bỏ kiểm tra CMSNT, CSRF hay các chốt 410.

## Kiểm thử

- `php tests/checkout_preflight_test.php`: **PASS** 14/14 trên PHP 7.4.33 và PHP 8.2.20. Chạy endpoint
  thật trong tiến trình riêng với dependency giả và dữ liệu tổng hợp, không DB/mạng. Kiểm tra cả nhánh
  giữ coupon, debit và đơn cũ không bị gắn nhãn. WASM báo NOT RUN vì không chạy subprocess native.
- `node tests/js/checkout_recovery_test.mjs`: **PASS** mô phỏng response đúng/sai mã, marker thay đổi,
  xóa marker thất bại, mất response và lưu trữ lỗi.
- `node tools/dev/browser-checkout.cjs`: **PASS** 13 kiểm tra trên Chromium với endpoint giả; gồm cho
  phép thử mới sau preflight, mã mới khác mã cũ, và khóa lại khi response sau bị mất.
- `bash tools/dev/run.sh`: **PASS** LINT 325 file, 0 lỗi; SCAN 240 file, 0 finding; 38 trang; 61 suite,
  6 NOT RUN, 0 partial, 0 failure. Kiểm thử native ở trên chạy riêng; 5 suite DB cũng được chạy riêng
  ở vòng trước, xem `30-native-database-review.md`.
- `git diff --check`: **PASS**.

Chưa chạy checkout thật với database gốc, hosting đích hoặc nhà cung cấp thật. Không deploy hay thay
đổi database production. GTF vẫn bị khóa vì hợp đồng chưa xác minh; SEC-01 đã được sửa trong mã và
kiểm thử fixture ở vòng sau (xem `../gachthefast/13-security-hardening-retest.md`). payOS phải
được cấu hình và kiểm thử trước khi bật. `install.php` không phải cài đặt an toàn tự động và schema gốc
vẫn cần lấy từ gói có bản quyền.
