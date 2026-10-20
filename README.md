# HTH AppotaPay

Cổng thanh toán [AppotaPay](https://appotapay.com) cho WooCommerce. Khách bấm thanh toán, sang trang của AppotaPay để trả bằng thẻ ATM nội địa, Visa/Master/JCB, ví điện tử hoặc QR, rồi quay về site.

Code theo luồng hosted checkout v2 trong [tài liệu AppotaPay](https://docs.appotapay.com/payment/) và [bộ skill chính thức](https://github.com/AppotaPayInc/skills) của AppotaPay. Đã qua kiểm toán bảo mật, xem [AUDIT.md](AUDIT.md).

## Plugin làm gì

- Tạo giao dịch và chuyển khách sang trang thanh toán của AppotaPay.
- Nhận kết quả qua hai đường: IPN (máy chủ AppotaPay gọi tới) và trang khách quay về. Cả hai đều kiểm chữ ký HMAC-SHA256, rồi gọi API tra trạng thái. Đơn chỉ được xác nhận khi API báo thành công, đúng mã đơn, đúng số tiền và đúng VND.
- 10 phút một lần, tra lại các đơn còn chờ thanh toán trong 24 giờ gần nhất, phòng khi IPN bị chặn và khách đã đóng tab.
- Hoàn tiền ngay trong trang đơn hàng của WooCommerce. Nếu lệnh hoàn bị mất kết nối, lần sau plugin tra lệnh cũ trước, nên không hoàn hai lần.
- Khách xem giá bằng tiền tệ khác VND (qua plugin đổi tiền tệ WOOCS) vẫn trả được: cổng hiện với tiêu đề riêng (mặc định "Pay in VND"), tổng đơn được quy về VND theo tỷ giá trong WOOCS và AppotaPay thu số VND đó. Xem mục "Tiền tệ khác VND".
- Cảnh báo trong ghi chú đơn khi khách trả hai lần cho cùng một đơn.
- Khách không nhập số thẻ trên site, nên site không phải đạt chuẩn PCI.
- Xoá plugin thì `uninstall.php` dọn option cài đặt và khoá lẻ; meta trên đơn hàng giữ lại làm lịch sử.

## Yêu cầu

- WordPress 6.5 trở lên, PHP 8.0 trở lên, WooCommerce 8.0 trở lên (đã thử với 11.2.1, HPOS bật).
- Tiền tệ gốc của cửa hàng là VND. Muốn nhận đơn bằng tiền tệ khác thì cần WOOCS (WooCommerce Currency Switcher) với VND là tiền tệ gốc, hoặc cấp tỷ giá qua filter `hth_appotapay_vnd_rate`.
- Trang thanh toán dùng shortcode cổ điển `[woocommerce_checkout]`. Plugin chưa hỗ trợ checkout dạng khối.
- Tài khoản đối tác tại [partner.appotapay.com](https://partner.appotapay.com), để lấy Partner Code, API Key và Secret Key.

## Cài đặt

1. Đóng gói plugin từ repo:

   ```bash
   git archive --format=zip -o hth-appotapay.zip --prefix=hth-appotapay/ HEAD
   ```

   Gói này đã bỏ `.git/` và `tests/`. Đừng chép nguyên thư mục repo lên máy chủ: trên OpenLiteSpeed, `.git/` sẽ lộ ra web.
2. Vào Plugin → Cài mới → Tải plugin lên, chọn tệp zip, rồi bật plugin.
3. Vào WooCommerce → Cài đặt → Thanh toán → AppotaPay và điền:
   - **Tiền tố mã đơn**: 2–6 chữ cái riêng cho site. Mỗi site dùng chung một tài khoản AppotaPay phải có tiền tố khác nhau.
   - **Partner Code, API Key, Secret Key**: nên đặt trong `wp-config.php` thay vì trong trang cài đặt, để khoá không nằm trong DB và các bản sao lưu:

     ```php
     define( 'HTH_APPOTAPAY_PARTNER_CODE', '...' );
     define( 'HTH_APPOTAPAY_API_KEY', '...' );
     define( 'HTH_APPOTAPAY_SECRET_KEY', '...' );
     ```

## Thử với sandbox

Để chọn "Môi trường thử" và dùng khoá sandbox công khai trong [tài liệu AppotaPay](https://docs.appotapay.com/payment/sandbox):

```
Partner Code: APPOTAPAY
API Key:      FJcmF8uj2ISveL5FvvNk4pnp8xrhINz8
Secret Key:   XAonJgy14YhtePEITXhyBS2unjfJLAV3
```

Thẻ ATM test: ngân hàng MB, số thẻ `9704229306604047`, tên `ARTURO MOEN`, ngày phát hành `01/23`, OTP `123456`. Trên trang của AppotaPay, ô nhập thẻ nằm dưới hình thẻ, phải cuộn xuống mới thấy.

Trên site production, chế độ sandbox chỉ hiện cổng cho quản trị viên. Khách không thấy, nên không ai trả được bằng thẻ test.

Ở máy local, máy chủ AppotaPay không gọi IPN vào được. Đơn sẽ được xác nhận lúc bạn quay về trang site, hoặc ở lượt tra lại định kỳ.

## Tiền tệ khác VND

AppotaPay chỉ thu VND. Khi khách đổi sang USD, PHP… bằng WOOCS, plugin:

- Vẫn hiện cổng, với tiêu đề và mô tả ở hai ô "khi tiền tệ khác VND" (dịch được qua Polylang). Mô tả có `{vnd}` = số VND sẽ thu và `{total}` = tổng đơn theo tiền tệ khách đang xem. Để trống ô tiêu đề thì cổng chỉ hiện với VND như trước.
- Lúc khách bấm thanh toán, tính `VND = tổng đơn ÷ tỷ giá WOOCS của tiền tệ đó` (làm tròn tới đồng), ghi số VND và tỷ giá vào đơn (`_hth_appotapay_vnd`, `_hth_appotapay_rate`) rồi gửi AppotaPay số VND đó. Tỷ giá lấy từ bảng tiền tệ của WOOCS, không đọc meta `_woocs_order_rate` của đơn vì WOOCS hay ghi sai.
- Đối chiếu và hoàn tiền đều dựa trên số VND đã ghi. Hoàn một phần thì quy đổi theo đúng tỷ giá lúc thu; hoàn nốt phần còn lại thì hoàn đúng số VND còn lại, không lệch vì làm tròn; tổng hoàn không vượt số đã thu.
- Đơn WooCommerce vẫn lưu bằng tiền tệ khách chọn; ghi chú đơn kể cả hai số (VND đã thu và tổng theo tiền tệ đơn).

Giá khách thấy bằng USD/PHP đã làm tròn, nên số VND thu về có thể lệch vài đồng so với giá niêm yết VND. Thẻ hoặc ví của khách tự quy đổi về tiền của họ theo tỷ giá ngân hàng, nên số bị trừ sẽ khác chút so với số hiện trên site; mô tả mặc định đã nói điều này. Nhớ cập nhật tỷ giá trong WOOCS định kỳ.

Site không dùng WOOCS có thể cấp tỷ giá (1 VND = bao nhiêu đơn vị tiền tệ đó) qua filter:

```php
add_filter( 'hth_appotapay_vnd_rate', fn( $rate, $currency ) => 'USD' === $currency ? 1 / 26350 : $rate, 10, 2 );
```

## Trước khi chạy thật

- Đăng ký IP gọi ra của máy chủ với AppotaPay. IP lạ bị trả lỗi 403.
- Cho các lần POST tới `/wc-api/hth_appotapay_ipn/` đi qua tường lửa và WAF (Imunify, Wordfence, Cloudflare). Không cache `/wc-api/*`.
- Đồng bộ giờ máy chủ bằng NTP, vì mã xác thực gửi AppotaPay chỉ sống 5 phút.
- Đặt giờ giữ hàng của WooCommerce từ 60 phút trở lên.
- Nếu site tắt WP-Cron, phải có cron hệ thống gọi `wp-cron.php`, nếu không việc tra lại định kỳ sẽ không chạy.
- Nếu nhận tiền tệ khác VND: điền hai ô tiêu đề/mô tả "khi tiền tệ khác VND" (dịch trong Polylang nếu cần) và kiểm tỷ giá trong WOOCS.
- Tắt "Ghi log". Link thanh toán, số thẻ và tên chủ thẻ đã được che trước khi ghi, nhưng log vẫn kể lại từng giao dịch.

Danh sách đầy đủ nằm ở cuối [AUDIT.md](AUDIT.md).

## Bộ thử

Bộ thử chạy bằng WP-CLI trên một site thử. Mọi lệnh gọi AppotaPay đều được giả lập, không gọi mạng thật:

```bash
wp eval-file wp-content/plugins/hth-appotapay/tests/run.php
```

Đừng chạy bộ thử trên site thật, vì nó tạo rồi xoá đơn thử.

## Giấy phép

GPL-2.0-or-later. Xem [LICENSE](LICENSE).

Plugin này không phải sản phẩm chính thức của AppotaPay.
