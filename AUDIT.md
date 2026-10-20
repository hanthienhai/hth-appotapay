# Kiểm toán bảo mật plugin hth-appotapay

Ngày: 15/10/2026, bổ sung 17/10/2026 (mục 16–25) · Phiên bản: 0.2.1 · Môi trường thử: WordPress 7.1.3, PHP 8.2.34, WooCommerce 11.2.1, chạy trên WordPress Studio. Đợt 15/10 thử với HPOS bật trên dữ liệu của một shop thật; đợt 17/10 thử trên site trắng (Studio CLI 1.24.0) ở cả HPOS bật và tắt, môi trường local và production

## Phạm vi và cách làm

- Đọc từng dòng hai tệp `includes/class-hth-appotapay-client.php` và `includes/class-hth-appotapay-gateway.php`, rồi đi theo từng đường mà kẻ xấu hoặc sự cố có thể chạm tới: tạo giao dịch, IPN, trang khách quay về, đối soát, tra lại định kỳ, hoàn tiền, cài đặt.
- So code với skill chính thức `AppotaPayInc/skills` (appotapay-auth, appotapay-payment). Đối chiếu tài liệu live trên docs.appotapay.com ngày 15/10/2026: trùng khớp với bản chụp trong skill.
- Bộ thử `tests/run.php` giả lập phản hồi AppotaPay qua `pre_http_request`. Bản 0.2.0: 51 bài ở môi trường local và 52 bài ở production (thêm một bài cho chế độ sandbox), đạt hết ở cả hai. Thêm vào đó là các lần gọi HTTP thật tới sandbox và một lần trả tiền thật bằng thẻ test.
- Đợt 17/10/2026 rà lại lần hai bằng cách đọc mã và đối chiếu tài liệu live (`/payment/result`, `/payment/payment-status`, `/payment/refund`), tìm thêm 2 lỗi mức Vừa và 7 điểm Thấp (mục 16–24). Bộ thử tăng lên 85 bài ở local, 86 ở production, cộng 1 bài cho mỗi hằng số `wp-config.php` đã đặt. Chạy bộ thử trên site trắng ở kho cổ điển (HPOS tắt) lộ thêm mục 25; sau khi sửa, đạt 85/85 ở local với HPOS bật lẫn tắt và 86/86 ở production.

Chạy lại bộ thử:

```bash
studio wp eval-file wp-content/plugins/hth-appotapay/tests/run.php
```

## Lỗi tìm thấy và đã sửa

| # | Mức | Lỗi | Hậu quả nếu để nguyên | Cách sửa | Bài thử |
|---|---|---|---|---|---|
| 1 | Cao | Quên tắt sandbox khi lên site thật | Ai cũng trả được bằng thẻ test công khai trong tài liệu AppotaPay; đơn chuyển "đã thanh toán", shop giao hàng mà không thu được tiền | Trên môi trường `production`, chế độ sandbox ẩn cổng với khách, chỉ quản trị viên thấy; wp-admin hiện cảnh báo vàng | Sandbox + production + khách / quản trị viên |
| 2 | Cao | Hoàn tiền bị mất kết nối, quản trị viên bấm hoàn lại | Lệnh mới mang mã mới, AppotaPay coi là lệnh khác: khách được hoàn hai lần | Lưu lệnh đang treo vào đơn; lần sau tra lệnh cũ trước (`GET /api/v2/transaction/refund/{ref}`), chỉ gửi lệnh mới khi chắc lệnh cũ không tồn tại (lỗi 36) hoặc đã thất bại | 4 bài "Lệnh treo…" |
| 3 | Cao | Khách mở hai tab và trả cả hai lần | Lần trả thứ hai bị bỏ qua không dấu vết: shop giữ tiền thừa của khách mà không biết | Ghi chú "CẢNH BÁO… cần hoàn" (đúng một lần) kèm mã giao dịch thừa; giữ nguyên mã giao dịch gốc | "Thanh toán trùng…" |
| 4 | Vừa | Khoá chống xử lý đôi không có hạn | Nếu PHP chết giữa chừng (hết thời gian, hết RAM), khoá kẹt mãi; đơn đó không bao giờ được xác nhận dù khách đã trả | Khoá tự hết hạn sau 60 giây | "Khoá kẹt quá 60 giây…" |
| 5 | Vừa | IPN tới khi API còn báo `pending` | Plugin trả "ok", AppotaPay thôi gửi lại; nếu khách đã đóng tab, đơn kẹt ở "chờ thanh toán" | Trả 503 để AppotaPay gửi lại sau 5 phút (tối đa 3 lần) | HTTP thật: IPN chữ ký đúng → 503 |
| 6 | Vừa | Callback vẫn được nhận khi chưa nhập Secret Key | Ai cũng ký được bằng khoá rỗng. API tra lại đã chặn được, nhưng không nên dựa vào một lớp | Khoá rỗng thì từ chối mọi callback | "Khoá rỗng…" |
| 7 | Vừa | Secret Key chỉ lưu được trong DB | Khoá nằm trong mọi bản sao lưu DB và trong mã HTML trang cài đặt | Cho đặt khoá bằng hằng số trong `wp-config.php`; ô trong trang cài đặt tự khoá lại | Kiểm tay |
| 8 | Vừa | Đơn đã trả nhận về kết quả lệch bị kéo về "tạm giữ" | Đơn đã thu tiền bỗng thành chưa thanh toán | Đơn đã trả chỉ nhận ghi chú cảnh báo, giữ trạng thái | "Kết quả lệch trên đơn đã trả…" |
| 9 | Thấp | Mã tham chiếu chỉ dựa vào giây hiện tại | Hai lần bấm trong cùng một giây bị AppotaPay báo trùng mã (lỗi 30) | Thêm 3 chữ số ngẫu nhiên | "Mã tham chiếu không trùng…" |
| 10 | Thấp | Không kiểm tiền tệ khi đối soát | Kết quả bằng đơn vị khác VND vẫn được nhận | Bắt buộc `currency = VND` | "Sai tiền tệ…" |
| 11 | Thấp | Chuyển khách tới bất kỳ link nào API trả về | Nếu phản hồi bị giả mạo, khách bị đưa tới trang lừa nhập thẻ | Chỉ nhận link `https://…appotapay.com` | "Từ chối link…" |
| 12 | Thấp | Không ghi lại các lần gọi với chữ ký sai | Không thấy được ai đang dò IPN | Ghi IP vào log khi bật "Ghi log" | Kiểm tay |
| 13 | Thấp | Danh sách mã tham chiếu có phần tử rỗng | Vô hại, chỉ làm bẩn dữ liệu | Lọc phần tử rỗng | "…không có phần tử rỗng" |
| 14 | Cao | Khách trả xong rồi đóng tab, đồng thời cả 3 lần IPN bị chặn | Đơn nằm ở "chờ thanh toán" dù tiền đã về; WooCommerce có thể tự huỷ đơn khi hết giờ giữ hàng | Action Scheduler chạy `hth_appotapay_reconcile` 10 phút một lần: tra lại đơn AppotaPay ở trạng thái chờ thanh toán, lỗi hoặc đã huỷ, tạo trong 24 giờ gần nhất, tối đa 10 đơn mỗi lượt (đơn vừa tra xuống cuối hàng). Dùng chung `reconcile()` với IPN nên giữ nguyên khoá và mọi kiểm tra. Giao dịch đã thất bại được nhớ lại để không tra và ghi chú lặp. Lịch đăng ký khi bật plugin, gỡ khi tắt, tự đăng ký bù khi vào wp-admin | 16 bài mục 6 |
| 15 | Vừa | Tiền tố mã đơn viết cứng trong code | Hai site dùng chung một tài khoản AppotaPay sinh trùng mã đơn: AppotaPay báo lỗi 30, hoặc IPN của site này bị site kia nhận | Thêm ô "Tiền tố mã đơn" (2–6 chữ cái, mặc định `HTH`), kiểm khi lưu | 3 bài mục 0 |
| 16 | Vừa | Lệnh hoàn gặp HTTP 5xx (kể cả 502/504 từ proxy) hoặc body không phải JSON bị coi là "từ chối", lệnh treo bị xoá | AppotaPay đã nhận lệnh nhưng proxy trả 504: quản trị viên bấm hoàn lại, mã mới, khách được hoàn hai lần. Lỗi #2 mới chặn được nửa (chỉ HTTP 0) | Coi HTTP 0, ≥ 500 hay body không phải JSON là "chưa rõ kết quả": giữ lệnh treo, ghi chú, lần hoàn sau tra lệnh cũ trước. Chỉ xoá lệnh treo khi có JSON hợp lệ 2xx/4xx | "Hoàn tiền gặp 504/500/200 không JSON…", "Sau 504, lệnh treo thật ra đã được nhận…", "AppotaPay từ chối rõ ràng (4xx)…" |
| 17 | Vừa | Khoá chống xử lý đôi dùng `add_option()`, không nguyên tử (đọc trước rồi mới ghi) | IPN và trang khách quay về tới cách nhau vài trăm mili giây, cả hai lọt qua: `payment_complete()` chạy hai lần, trừ kho hai lần, email hai lần | `INSERT IGNORE` thẳng vào `wp_options` (UNIQUE KEY `option_name`, autoload tắt): 1 dòng là được khoá. Khoá kẹt quá 60 giây giành lại bằng `UPDATE … WHERE option_value < %d`, cũng nguyên tử | "Lấy khoá lần đầu/lần hai…", "Khoá kẹt quá 60 giây: giành lại…", "Gỡ khoá: option biến mất" |
| 18 | Vừa | Tra lại định kỳ tra mọi mã tham chiếu còn `pending` của mỗi đơn, không có điểm dừng; API không có trạng thái "hết hạn" nên `pending` là mãi mãi | Một đơn bấm thanh toán 5 lần rồi bỏ: 720 lệnh gọi/ngày; một lượt có thể chạy vài phút (timeout 30 giây mỗi lệnh), bị cắt khi cron chạy qua web | Chỉ tra 2 mã mới nhất mỗi đơn; mã tạo quá 2 giờ (đọc từ phần `T<unix time>` trong mã) mà vẫn `pending` thì ghi vào final; dừng lượt khi đã chạy quá 20 giây (filter `hth_appotapay_reconcile_budget`). Timeout riêng: tạo giao dịch 15 giây, lệnh tra 10 giây, hoàn tiền giữ 30 | "Chỉ tra 2 mã mới nhất…", "Mã quá 2 giờ vẫn pending…", "Ngân sách thời gian 0 giây…", 4 bài timeout mục 1 |
| 19 | Thấp | Không kiểm trường `time` của callback | Một cặp `data`/`signature` hợp lệ dùng lại được mãi: ai có link quay về của đơn (referrer, lịch sử trình duyệt) ép site gọi API rồi được đưa tới trang đơn kèm `key`, xem được địa chỉ, số điện thoại khách | Từ chối callback có `time` lệch quá 24 giờ so với giờ máy chủ (tài liệu `/payment/result`: Unix giây, nhận thêm dạng mili giây). Link quay về cũ chỉ về trang checkout kèm thông báo, không tra API, không kèm `key`. Lượt tra lại định kỳ đã phủ 24 giờ nên không mất đơn | 7 bài "Callback time…" |
| 20 | Thấp | `wp_remote_request` theo redirect tới 5 lần, header JWT đi theo | Nếu gateway bị cấu hình redirect sang host khác, JWT đi sang host đó | `redirection => 0`; API JSON không có lý do chuyển hướng | "Tạo giao dịch: không theo redirect…" |
| 21 | Thấp | `admin_init` query bảng Action Scheduler ở mọi request wp-admin, kể cả admin-ajax (heartbeat) | Một query vô ích mỗi request admin trên bảng có thể hàng trăm nghìn dòng | Bỏ qua khi `wp_doing_ajax()`; chỉ kiểm lịch khi option `hth_appotapay_version` khác phiên bản plugin, ghi lại sau khi đăng ký | 2 bài "admin_init…" |
| 22 | Thấp | Log ghi nguyên body phản hồi: `payment.url` của giao dịch đang chờ, số thẻ đã che, tên chủ thẻ | Ai đọc được log mở được link thanh toán của đơn khách đang chờ | Che `payment.url`, `cardNumber`, `cardName`, `tokenInfo.number/holder_name/token` trước khi ghi; body không phải JSON chỉ ghi 300 ký tự đầu | "Log che link thanh toán…", "Log vẫn ghi mã giao dịch…", "Body không phải JSON…" |
| 23 | Thấp | Đã đặt hằng số trong `wp-config.php` nhưng ô Secret Key (dù `disabled`) vẫn in giá trị cũ trong DB vào HTML | Secret Key cũ lộ trong trang cài đặt cho mọi tài khoản `manage_woocommerce`, trái với mục đích của hằng số | `get_option()` của gateway trả rỗng cho 3 khoá khi hằng số tương ứng đã có | "Hằng số … đã đặt: trang cài đặt không in giá trị DB" (chỉ chạy khi site có hằng số) |
| 24 | Thấp | Thiếu `uninstall.php` | Gỡ plugin để lại `woocommerce_hth_appotapay_settings` (có Secret Key nếu không dùng hằng số) và các option `hth_appotapay_lock_*` lẻ | Thêm `uninstall.php` xoá option cài đặt, option phiên bản, mọi `hth_appotapay_lock_*` và lịch tra lại. Meta trên đơn giữ lại làm lịch sử | 2 bài mục 7 |
| 25 | Vừa | Hàng đợi tra lại không chạy ở kho đơn cổ điển (HPOS tắt) | Lượt tra lại xếp đơn theo `date_modified` để đơn vừa tra xuống cuối hàng. Kho cổ điển không đổi `post_modified` khi chỉ lưu meta, nên cùng 10 đơn treo cũ nhất được tra mãi, đơn mới hơn không bao giờ tới lượt. Đợt 15/10 chỉ thử với HPOS bật nên không thấy | Sau mỗi lượt gọi `set_date_modified( time() )` trước khi `save()`, chạy đúng ở cả hai kiểu kho. Bộ thử đặt mốc quá khứ thẳng vào bảng `posts` vì `wp_update_post` luôn ghi `post_modified` = bây giờ | "Lượt sau tra tới đơn chưa được tra", chạy với HPOS tắt |

## Những gì đã đúng từ đầu

- JWT tạo phía máy chủ, sống 5 phút, có header `cty`, mỗi lần gọi một mã `jti` mới.
- Chữ ký HMAC-SHA256 kiểm trên chuỗi `data` thô, so bằng `hash_equals` để không lộ chữ ký qua thời gian phản hồi.
- Không tin dữ liệu callback: chỉ lấy mã đơn từ đó, còn trạng thái và số tiền luôn lấy từ API tra cứu.
- Chỉ nhận mã tham chiếu mà chính đơn đó đã sinh ra; mã hợp lệ của đơn khác bị từ chối.
- Mất mạng khi tạo giao dịch thì báo lỗi cho khách, không tự gửi lại.
- Khách không nhập số thẻ trên site, nên site không phải đạt chuẩn PCI.
- Ghi chú đơn đi qua bộ lọc `wp_kses_post` của WooCommerce; nội dung lỗi từ API không chèn được mã độc.
- Quyền hoàn tiền do WooCommerce kiểm (quyền `edit_shop_orders` và nonce).

## Rủi ro còn lại

Chưa sửa trong code, cần biết trước khi chạy thật:

1. **Giờ giữ hàng của WooCommerce.** Phiên thanh toán AppotaPay sống khoảng 30 phút. Nếu "Giữ hàng" (Cài đặt → Sản phẩm → Kho hàng) ngắn hơn, đơn có thể bị huỷ trước khi khách trả xong. Plugin vẫn xác nhận lại được đơn đã huỷ, nhưng kho bị trả về rồi trừ lại. Nên đặt giờ giữ hàng từ 60 phút trở lên.
2. **Tệp log trên OpenLiteSpeed.** WooCommerce chặn thư mục `uploads/wc-logs` bằng `.htaccess`, mà OLS bỏ qua lệnh `Require all denied`. Tên tệp log có chuỗi băm khó đoán; từ 0.2.1 link thanh toán, số thẻ và tên chủ thẻ được che, nhưng log vẫn kể lại từng giao dịch. Khi chạy thật thì tắt "Ghi log".
6. **Trường `time` của callback.** Tài liệu chỉ ghi `time` là chuỗi "thời gian phản hồi"; ví dụ IPN là Unix giây, ví dụ redirect là số giữ chỗ 11 chữ số. Plugin nhận Unix giây và mili giây. Nếu AppotaPay gửi dạng khác, mọi callback bị từ chối (log ghi "quá cũ hoặc thiếu time" kèm giá trị), đơn vẫn được xác nhận ở lượt tra lại định kỳ trong 10 phút. Sau khi lên 0.2.1, xem log vài giao dịch đầu để chắc callback được nhận.
3. **Trang "Đã nhận đơn hàng".** Đường link khách quay về mang khoá đơn. Ai có link đó xem được thông tin đơn. Đây là cách WooCommerce vẫn làm, plugin không đổi.
4. **Checkout dạng khối (block).** Plugin chỉ chạy với checkout shortcode cổ điển (`[woocommerce_checkout]`). Site nào dùng trang thanh toán dạng khối thì cổng không hiện.
5. **Thư mục `tests/`.** Tệp `tests/run.php` chỉ chạy được qua WP-CLI, nhưng vẫn nên bỏ thư mục này khi chép lên site thật.

## Danh sách việc khi lên production

- [ ] Đặt 3 hằng số `HTH_APPOTAPAY_PARTNER_CODE`, `HTH_APPOTAPAY_API_KEY`, `HTH_APPOTAPAY_SECRET_KEY` trong `wp-config.php` bằng khoá production.
- [ ] Bỏ chọn "Môi trường thử"; tắt "Ghi log".
- [ ] Đặt "Tiền tố mã đơn" riêng cho site, trước khi có đơn đầu tiên.
- [ ] Đăng ký IP gọi ra của máy chủ với AppotaPay.
- [ ] Cho POST tới `/wc-api/hth_appotapay_ipn/` đi qua tường lửa và WAF; không cache `/wc-api/*`.
- [ ] Đồng bộ giờ máy chủ (NTP).
- [ ] Đặt giờ giữ hàng ≥ 60 phút.
- [ ] Sau khi bật plugin, xem Công cụ → Lịch tác vụ (nhóm `hth-appotapay`): `hth_appotapay_reconcile` phải chạy đều 10 phút một lần. Nếu site tắt WP-Cron thì phải có cron hệ thống gọi `wp-cron.php`, không thì việc tra lại không chạy.
- [ ] Đóng gói bằng `git archive --format=zip -o hth-appotapay.zip --prefix=hth-appotapay/ HEAD`. Gói này bỏ sẵn `.git/` và `tests/`. Đừng chép nguyên thư mục, vì OLS sẽ để lộ `.git/` ra web.
- [ ] Trả thử một đơn nhỏ bằng thẻ thật, rồi hoàn tiền chính đơn đó để kiểm cả hai chiều.
- [ ] Bật "Ghi log" cho vài giao dịch đầu, xem IPN và trang quay về được nhận (không có dòng "quá cũ hoặc thiếu time"), rồi tắt.

## Đợt 20/10/2026 — bản 0.3.0 (đơn bằng tiền tệ khác VND)

Phạm vi: đọc lại toàn bộ `class-hth-appotapay-client.php` (không đổi so với 0.2.1) và `class-hth-appotapay-gateway.php`, tập trung vào phần mới: `rate()`, `to_vnd()`, `order_vnd()`, `context_total()`, `get_title()/get_description()`, và các chỗ `process_payment()`, `reconcile()`, `process_refund()` dùng số VND đã ghi. Rà đầu vào thô (`$_GET/$_POST/$_COOKIE`): chỉ còn ở `handle_return()` và đã qua kiểm chữ ký như trước. Bộ thử 111 ca, đạt hết trên Studio (WordPress 7.1, WooCommerce 11.2.1, WOOCS 2.4.5, Polylang Pro 3.7.1), kèm một lần thử tay ra trang sandbox với đúng số VND.

| # | Mức | Lỗi | Hậu quả nếu để nguyên | Cách sửa | Bài thử |
|---|---|---|---|---|---|
| 26 | Thấp | `context_total()` đọc đơn từ `order-pay` trong URL mà không kiểm khoá đơn | Ai gõ URL `/order-pay/<id>/` với id của người khác không xem được gì (WooCommerce không vẽ form khi sai khoá), nhưng plugin vẫn đọc tổng và tiền tệ của đơn đó để dựng mô tả. Không rò rỉ ra ngoài, song là một chỗ tin đầu vào thừa | Chỉ nhận đơn khi `key` trong URL khớp `get_order_key()` (so hằng thời gian), như WooCommerce làm trước khi vẽ form | "Trang order-pay không có khoá đơn đúng…" |
| 27 | Thấp | Tổng đơn đọc qua `get_total()` phụ thuộc ngữ cảnh: WOOCS trả số thập phân theo tiền tệ ĐANG XEM (VND 0, USD 2, PHP 1) và WooCommerce làm tròn tổng theo số đó ngay lúc nạp đơn, nên đơn USD 15,18 nạp trong trang tiếng Việt, wp-admin hay WP-CLI thành 15 (kho vẫn giữ 15,18; `save()` không ghi đè) | Khách trả lại đơn USD từ trang order-pay tiếng Việt bị thu theo 15 thay vì 15,18; ghi chú và đối chiếu lệch vài nghìn đồng | `raw_total()` đọc thẳng `wc_orders.total_amount` (HPOS) hoặc `_order_total`, dùng cho tạo giao dịch, mô tả order-pay, ghi chú và mốc "hoàn nốt" | "Đơn USD 15,18 nạp trong ngữ cảnh VND…", "Plugin đọc tổng thô 15,18…" |
| 28 | Ghi nhận | Đổi tỷ giá WOOCS giữa hai lần bấm thanh toán của cùng đơn | Khách trả theo mã cũ với số VND cũ; `reconcile()` so với số VND của lần bấm mới nhất, thấy lệch → chuyển "tạm giữ" kèm ghi chú, không xác nhận và không mất tiền | Giữ nguyên; đây là hành vi an toàn có chủ ý | "API báo 144 thay vì số VND…" |
| 29 | Ghi nhận | Meta `_woocs_order_rate` sai ở khoảng 1/3 đơn thực tế: WOOCS móc vào `woocommerce_thankyou_order_id` và ghi lại meta theo tiền tệ của *người mở* trang "Cảm ơn"; WooCommerce áp filter này trước khi kiểm khoá đơn, nên bot đi theo thẻ hreflang sang bản `/thanh-toan/order-received/…` (tiền tệ VND) cũng ghi đè được, dù khoá sai | Nếu quy đổi theo meta này, khách bị thu sai tới hàng chục phần trăm | Plugin lấy tỷ giá từ bảng tiền tệ WOOCS theo `get_currency()` của đơn, không đọc meta. Lỗi gốc thuộc WOOCS, cần vá riêng ở site | "Tỷ giá: VND = 1, USD/PHP từ bảng WOOCS…" |

Đã rà và không thấy vấn đề:

- Tiêu đề/mô tả cho tiền tệ khác VND là text do quản trị viên nhập qua WooCommerce Settings API (được lọc như ô `title`/`description` cũ); `{total}` lấy từ `wc_price()` đã bỏ thẻ HTML, `{vnd}` là số.
- Tiền tệ của đơn do WooCommerce xác thực theo danh sách ISO khi `set_currency()`, nên không mang HTML vào thông báo lỗi hay ghi chú.
- Tỷ giá từ filter `hth_appotapay_vnd_rate` phải là số dương, nếu không thì cổng ẩn / từ chối tạo giao dịch.
- Hoàn tiền: số VND hoàn không bao giờ vượt số đã thu (sổ `_hth_appotapay_refunded`); hoàn nốt phần còn lại thì hoàn đúng số VND còn lại; lệnh treo vẫn được tra trước như mục 2 và 16.
- Giới hạn 1.000–500.000.000 ₫ kiểm trên số VND quy đổi; đơn dưới 1.000 ₫ bị từ chối trước khi gọi API.
