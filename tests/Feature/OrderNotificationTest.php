<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AffiliateSettings;
use App\Support\NotificationSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Luồng mua hàng (Thêm vào giỏ / Mua ngay → Thanh toán → thông tin chuyển khoản + QR → "Tôi đã chuyển khoản") và thông báo
 * cho kế toán qua Telegram / email (Cài đặt → tab Thông báo): cấu hình, bí mật không lộ, gửi thử, gửi khi có đơn mới /
 * khách báo đã chuyển khoản.
 */
class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw9';

    private const CHAT = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        AffiliateSettings::save(['shipping_fee' => 30000, 'payment_expiry_hours' => 24]);
        // Thông tin chuyển khoản: Cài đặt → tab Thanh toán
        foreach (['pay_bank_name' => 'Vietcombank', 'pay_bank_code' => 'VCB', 'pay_bank_account' => '0011001234567', 'pay_account_name' => 'CONG TY THUAN KHANG'] as $key => $value) {
            Setting::withoutEvents(fn () => Setting::put($key, $value));
        }
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create(['name' => 'Nguyễn Đức']);
    }

    private function product(array $data = []): Product
    {
        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);

        return Product::factory()->create(['category_id' => $category->id, 'status' => true, 'name' => 'Sâm Hàn Quốc', 'price' => 1080000, 'vat_rate' => 8, ...$data]);
    }

    private function form(array $data = []): array
    {
        return ['recipient_name' => 'Trần Thị B', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship', 'province' => 'ha-noi', 'address' => '12 Láng Hạ', 'pickup_point_id' => '', 'note' => '', ...$data];
    }

    private function telegramOn(array $extra = []): void
    {
        NotificationSettings::save(['telegram_enabled' => true, 'telegram_token' => self::TOKEN, 'telegram_chat_id' => self::CHAT, 'events' => ['order_created' => true, 'order_transferred' => true], ...$extra]);
    }

    private function mailOn(array $extra = []): array
    {
        return [
            'mail_enabled' => true, 'mail_recipients' => ['ketoan@congty.vn', 'ketoan2@congty.vn'], 'mail_host' => 'smtp.congty.vn', 'mail_port' => 587, 'mail_encryption' => 'tls',
            'mail_username' => 'thongbao@congty.vn', 'mail_password' => 'mat-khau-smtp-123', 'mail_from_address' => 'thongbao@congty.vn', 'mail_from_name' => 'Thuận Khang', ...$extra,
        ];
    }

    /** Thay mailer SMTP dựng theo cấu hình bằng mailer "array" để đọc lại thư đã gửi; trả về danh sách thư. */
    private function captureMail(): Collection
    {
        $array = Mail::mailer('array');
        $built = [];
        Mail::partialMock()->shouldReceive('build')->andReturnUsing(function (array $config) use ($array, &$built) {
            $built[] = $config;

            return $array;
        });
        $this->smtpConfigs = &$built;

        return $array->getSymfonyTransport()->messages();
    }

    private array $smtpConfigs = [];

    /** Câu trả lời của Telegram giả (đổi giữa chừng test): callable trả Response hoặc ném lỗi kết nối. */
    private $telegramReply;

    /** Giả lập api.telegram.org bằng MỘT stub (Http::fake gọi nhiều lần thì stub đầu luôn thắng), mặc định trả ok. */
    private function fakeTelegram(): void
    {
        $this->telegramReply = fn () => Http::response(['ok' => true, 'result' => []]);
        Http::fake(['api.telegram.org/*' => fn () => ($this->telegramReply)()]);
    }

    public function test_buy_flow_checkout_without_vat_then_payment_info_with_qr(): void
    {
        $member = Member::factory()->create(['full_name' => 'Trần Thị B']);
        $product = $this->product();

        // Thêm vào giỏ: ở lại trang, thông báo có lối sang giỏ hàng / thanh toán. Mua ngay: sang thẳng trang thanh toán
        $this->actingAs($member, 'member')->from($product->url())->post('/gio-hang', ['product_id' => $product->id, 'qty' => 1])
            ->assertRedirect($product->url())->assertSessionHas('cart_added', true);
        $this->get($product->url())->assertSee('data-cart-added', false)->assertSee('href="'.route('shop.checkout').'"', false)->assertSee('Xem giỏ hàng');
        $this->get($product->url())->assertDontSee('data-cart-added', false); // chỉ hiện một lần
        $this->post('/gio-hang', ['product_id' => $product->id, 'qty' => 1, 'buy_now' => 1])->assertRedirect(route('shop.checkout'));

        // Trang thanh toán: không có dòng VAT, nút "Thanh toán"
        $this->get('/thanh-toan')->assertOk()
            ->assertSee('Tiền hàng')->assertDontSee('VAT')->assertSee('2.160.000đ')->assertSee('Phí giao hàng')->assertSee('2.190.000đ')
            ->assertSee('data-checkout-submit>Thanh toán</button>', false)->assertDontSee('Đặt hàng');

        // Bấm Thanh toán → trang đơn: thông tin chuyển khoản đã cấu hình + mã QR, nút "Tôi đã chuyển khoản" / "Hủy đơn"
        $response = $this->post('/thanh-toan', $this->form());
        $order = Order::sole();
        $response->assertRedirect(route('member.orders.show', $order))->assertSessionHas('success');
        $this->assertSame(0, (int) $order->vat_amount); // VAT đã bỏ (19/11/2026): cột cũ luôn ghi 0
        $this->get(route('member.orders.show', $order))->assertOk()
            ->assertSee('data-payment', false)->assertSee('data-qr', false)->assertSee('img.vietqr.io/image/VCB-0011001234567', false)
            ->assertSee('Vietcombank')->assertSee('0011001234567')->assertSee('CONG TY THUAN KHANG')->assertSee('2.190.000đ')->assertSee($order->code)
            ->assertSee('data-qr="vietqr"', false)
            ->assertSee('Tôi đã chuyển khoản')->assertSee('Hủy đơn')->assertDontSee('VAT');

        // Chuyển khoản xong bấm "Tôi đã chuyển khoản" → chờ kế toán xác nhận
        $this->post(route('member.orders.transferred', $order), $this->billUpload())->assertSessionHas('success');
        $this->assertSame(Order::STATUS_AWAITING, $order->fresh()->status);
        $this->get(route('member.orders.show', $order))->assertSee('data-awaiting', false)->assertDontSee('data-payment', false);
        Http::assertNothingSent(); // chưa cấu hình thông báo: không gọi ra ngoài
    }

    public function test_order_page_shows_uploaded_company_qr(): void
    {
        Storage::fake('public');
        $root = $this->root();
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id, 'total_amount' => 1230000]);

        // Root admin tải ảnh mã QR của công ty ở Cài đặt → Thanh toán
        $this->actingAs($root)->put('/backend/setting', ['_tab' => 'payment', 'site_name' => 'Thuận Khang', 'pay_bank_name' => 'Vietcombank', 'pay_bank_code' => 'VCB',
            'pay_bank_account' => '0011001234567', 'pay_account_name' => 'CONG TY THUAN KHANG', 'payment_qr' => UploadedFile::fake()->image('qr-cong-ty.png', 600, 600),
        ])->assertSessionHasNoErrors()->assertRedirect(route('backend.settings.edit', ['tab' => 'payment']));
        $path = Setting::get('pay_qr');
        Storage::disk('public')->assertExists($path);

        // Trang đơn: hiện ảnh QR đã tải (không dùng mã VietQR tự tạo), kèm thông tin để khách nhập tay số tiền + nội dung
        $this->actingAs($member, 'member')->get(route('member.orders.show', $order))->assertOk()
            ->assertSee('src="'.Storage::disk('public')->url($path).'"', false)->assertSee('data-qr="uploaded"', false)->assertDontSee('img.vietqr.io', false)
            ->assertSee('rồi nhập đúng số tiền và nội dung')->assertSee('Vietcombank')->assertSee('0011001234567')->assertSee('1.230.000đ')->assertSee($order->code);

        // Bỏ ảnh QR: quay về mã VietQR tự tạo theo đơn; xóa cả số tài khoản: hướng dẫn gọi hotline
        $this->actingAs($root, 'web')->put('/backend/setting', ['_tab' => 'payment', 'site_name' => 'Thuận Khang', 'pay_bank_code' => 'VCB', 'pay_bank_account' => '0011001234567', 'remove_payment_qr' => '1'])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($path);
        $this->actingAs($member, 'member')->get(route('member.orders.show', $order))
            ->assertSee('data-qr="vietqr"', false)->assertSee('img.vietqr.io/image/VCB-0011001234567', false)->assertSee('VCB');
        $this->actingAs($root, 'web')->put('/backend/setting', ['_tab' => 'payment', 'site_name' => 'Thuận Khang'])->assertSessionHasNoErrors();
        $this->actingAs($member, 'member')->get(route('member.orders.show', $order))->assertDontSee('data-qr', false)->assertSee('liên hệ hotline');
    }

    public function test_notification_tab_and_permissions(): void
    {
        $root = $this->root();

        // Trang Cài đặt có tab "Thông báo" (link sang trang riêng); trang Thông báo có đủ thanh tab quay lại
        $this->actingAs($root)->get('/backend/setting')->assertOk()
            ->assertSee('<a href="'.route('backend.settings.notification.edit').'" class="settings-tab" role="tab" aria-selected="false" data-settings-link>', false);
        $this->actingAs($root)->get('/backend/setting/notification')->assertOk()
            ->assertSee('<title>Thông báo - Cài đặt - '.config('app.name').'</title>', false)
            ->assertSee('<a href="'.route('backend.settings.edit', ['tab' => 'general']).'" class="settings-tab" role="tab" aria-selected="false">', false)
            ->assertSee('class="settings-tab is-active" role="tab" aria-selected="true" data-settings-link', false)
            ->assertSee('id="notification-form"', false)->assertSee('data-edit-form', false)
            ->assertSee('name="telegram_token"', false)->assertSee('name="telegram_chat_id"', false)->assertSee('name="mail_recipients"', false)
            ->assertSee('name="mail_host"', false)->assertSee('name="mail_password"', false)
            ->assertSee('name="events[order_created]" value="1" checked', false)->assertSee('name="events[order_transferred]" value="1" checked', false)
            ->assertSeeInOrder(['data-state="telegram"', 'Chưa cấu hình', 'data-state="mail"', 'Chưa cấu hình'], false)
            ->assertSee('Máy chủ website chưa cấu hình gửi thư'); // MAIL_MAILER=array khi chạy test

        // Có quyền sửa Cài đặt nhưng không phải Root admin: chỉ xem, không lưu / gửi thử được
        $editor = User::factory()->create(['group_id' => Group::factory()->withPermissions('setting', 'setting/edit')->create()->id]);
        $this->actingAs($editor)->get('/backend/setting/notification')->assertOk()
            ->assertSee('Chỉ Root admin sửa được cấu hình thông báo')->assertSee('data-readonly inert', false)->assertDontSee('data-edit-form', false)->assertDontSee('test-telegram-form', false);
        $this->actingAs($editor)->put('/backend/setting/notification', ['telegram_enabled' => '1', 'telegram_token' => self::TOKEN, 'telegram_chat_id' => self::CHAT])->assertForbidden();
        $this->actingAs($editor)->post('/backend/setting/notification/test/telegram')->assertForbidden();
        $this->assertFalse(NotificationSettings::all()['telegram_enabled']);

        // Không có quyền Cài đặt / chưa đăng nhập
        $other = User::factory()->create(['group_id' => Group::factory()->withPermissions('page')->create()->id]);
        $this->actingAs($other)->get('/backend/setting/notification')->assertForbidden();
        $this->actingAs($root)->post('/backend/setting/notification/test/sms')->assertNotFound();
        auth()->logout();
        $this->get('/backend/setting/notification')->assertRedirect('/backend/login');
    }

    public function test_save_telegram_config_keeps_token_secret(): void
    {
        $root = $this->root();
        $url = '/backend/setting/notification';

        // Bật mà thiếu token / chat ID, hoặc sai dạng
        $this->actingAs($root)->from($url)->put($url, ['telegram_enabled' => '1'])->assertRedirect($url)
            ->assertSessionHasErrors(['telegram_token' => 'Nhập bot token để bật thông báo Telegram.', 'telegram_chat_id' => 'Nhập chat ID để bật thông báo Telegram.']);
        $this->actingAs($root)->from($url)->put($url, ['telegram_enabled' => '1', 'telegram_token' => 'abc', 'telegram_chat_id' => 'nhom ke toan'])->assertSessionHasErrors(['telegram_token', 'telegram_chat_id']);
        $this->assertSame(0, Setting::where('key', 'like', 'notify_%')->count());

        $this->actingAs($root)->put($url, ['telegram_enabled' => '1', 'telegram_token' => ' '.self::TOKEN.' ', 'telegram_chat_id' => ' -100 1234567890 ', 'events' => ['order_created' => '1', 'order_transferred' => '0']])
            ->assertRedirect($url)->assertSessionHas('success');
        $config = NotificationSettings::all();
        $this->assertSame([true, self::TOKEN, self::CHAT, ['order_created' => true, 'order_transferred' => false, 'kyc_submitted' => false]],
            [$config['telegram_enabled'], $config['telegram_token'], $config['telegram_chat_id'], $config['events']]);
        $this->assertTrue(NotificationSettings::telegramReady($config));
        // Token mã hóa trong DB, không hiện lại trên form (chỉ bản che bớt), không vào nhật ký
        $this->assertStringNotContainsString('AAHdqTcv', DB::table('settings')->where('key', 'notify_telegram_token')->value('value'));
        $this->actingAs($root)->get($url)->assertOk()->assertDontSee('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw9')
            ->assertSee('Đã lưu: 1234567890:••••••••saw9')->assertSee('name="telegram_chat_id" value="'.self::CHAT.'"', false)
            ->assertSeeInOrder(['data-state="telegram"', 'Đang bật'], false)->assertSee('name="events[order_created]" value="1" checked', false)
            ->assertDontSee('name="events[order_transferred]" value="1" checked', false);
        $logs = fn () => AuditLog::where('auditable_type', Setting::class)->where('description', 'Cập nhật cấu hình thông báo (Telegram / email)');
        $log = $logs()->sole();
        $this->assertNull($log->auditable_id);
        $this->assertSame('•••••• (đã đổi)', $log->changes['new']['telegram_token']);
        $this->assertSame(self::CHAT, $log->changes['new']['telegram_chat_id']);
        $this->assertStringNotContainsString('AAHdqTcv', json_encode($log->toArray()));

        // Để trống ô token = giữ token cũ; tắt thì vẫn giữ cấu hình; không đổi gì thì không ghi nhật ký
        $this->actingAs($root)->put($url, ['telegram_enabled' => '0', 'telegram_token' => '', 'telegram_chat_id' => self::CHAT, 'events' => ['order_created' => '1']])->assertSessionHas('success');
        $config = NotificationSettings::all();
        $this->assertSame([false, self::TOKEN, self::CHAT], [$config['telegram_enabled'], $config['telegram_token'], $config['telegram_chat_id']]);
        $this->actingAs($root)->get($url)->assertSeeInOrder(['data-state="telegram"', 'Đang tắt'], false);
        $this->actingAs($root)->put($url, ['telegram_enabled' => '0', 'telegram_chat_id' => self::CHAT, 'events' => ['order_created' => '1']]);
        $this->assertSame(2, $logs()->count());
        $this->actingAs($root)->put($url, ['telegram_enabled' => ['x'], 'telegram_token' => ['x'], 'events' => 'x', 'mail_recipients' => ['x']])->assertSessionHasErrors();
    }

    public function test_save_mail_config_and_validation(): void
    {
        $root = $this->root();
        $url = '/backend/setting/notification';
        $put = fn (array $data) => $this->actingAs($root)->from($url)->put($url, $data)->assertRedirect($url);

        // Bật email mà thiếu người nhận / thiếu đường gửi (máy chủ website không gửi được thư)
        $put(['mail_enabled' => '1'])->assertSessionHasErrors(['mail_recipients' => 'Nhập ít nhất một địa chỉ email nhận thông báo.']);
        $put(['mail_enabled' => '1', 'mail_recipients' => 'ketoan@congty.vn'])
            ->assertSessionHasErrors(['mail_host' => 'Nhập máy chủ SMTP để gửi được email (máy chủ website chưa cấu hình gửi thư).']);
        $put(['mail_recipients' => 'ketoan@congty.vn, khong-phai-email'])->assertSessionHasErrors(['mail_recipients' => 'Địa chỉ email không hợp lệ: khong-phai-email.']);
        $put(['mail_recipients' => 'a@x.vn,b@x.vn,c@x.vn,d@x.vn,e@x.vn,f@x.vn'])->assertSessionHasErrors(['mail_recipients' => 'Tối đa 5 địa chỉ email.']);
        // Máy chủ SMTP: chỉ tên miền, cổng quen thuộc, phải kèm địa chỉ gửi
        foreach (['127.0.0.1', '10.0.0.5', 'localhost', 'http://smtp.gmail.com', 'smtp gmail'] as $host) {
            $put(['mail_host' => $host, 'mail_from_address' => 'a@x.vn'])->assertSessionHasErrors('mail_host');
        }
        $put(['mail_host' => 'smtp.congty.vn'])->assertSessionHasErrors(['mail_from_address' => 'Nhập địa chỉ gửi (email của tài khoản SMTP).']);
        $put(['mail_host' => 'smtp.congty.vn', 'mail_from_address' => 'a@x.vn', 'mail_port' => '22'])->assertSessionHasErrors('mail_port');
        $put(['mail_host' => 'smtp.congty.vn', 'mail_from_address' => 'a@x.vn', 'mail_encryption' => 'none'])->assertSessionHasErrors('mail_encryption');
        $this->assertSame(0, Setting::where('key', 'like', 'notify_%')->count());

        $put(['mail_enabled' => '1', 'mail_recipients' => "KeToan@CongTy.vn; ketoan2@congty.vn\nketoan@congty.vn", 'mail_host' => 'SMTP.CongTy.vn', 'mail_encryption' => 'ssl',
            'mail_username' => 'thongbao@congty.vn', 'mail_password' => 'mat-khau-smtp-123', 'mail_from_address' => 'ThongBao@congty.vn', 'mail_from_name' => 'Thuận Khang',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $config = NotificationSettings::all();
        $this->assertSame([true, ['ketoan@congty.vn', 'ketoan2@congty.vn'], 'smtp.congty.vn', 465, 'ssl', 'thongbao@congty.vn', 'mat-khau-smtp-123', 'thongbao@congty.vn', 'Thuận Khang'], [
            $config['mail_enabled'], $config['mail_recipients'], $config['mail_host'], $config['mail_port'], $config['mail_encryption'],
            $config['mail_username'], $config['mail_password'], $config['mail_from_address'], $config['mail_from_name'],
        ]);
        $this->assertTrue(NotificationSettings::mailReady($config));
        // Mật khẩu mã hóa, không hiện lại, không vào nhật ký
        $this->assertStringNotContainsString('mat-khau-smtp', DB::table('settings')->where('key', 'notify_mail_password')->value('value'));
        $this->actingAs($root)->get($url)->assertOk()->assertDontSee('mat-khau-smtp-123')->assertSee('Đã lưu — để trống nếu không đổi')
            ->assertSee('name="mail_recipients" value="ketoan@congty.vn, ketoan2@congty.vn"', false)->assertSee('name="mail_host" value="smtp.congty.vn"', false)
            ->assertSee('name="mail_port" value="465"', false)->assertSee('<option value="ssl" selected>', false)->assertSeeInOrder(['data-state="mail"', 'Đang bật'], false);
        $this->assertStringNotContainsString('mat-khau-smtp', json_encode(AuditLog::all()->toArray()));
        $this->assertSame(1, AuditLog::where('description', 'Cập nhật cấu hình thông báo (Telegram / email)')->count());

        // Để trống mật khẩu = giữ nguyên; lỗi thì form hiện lại chữ đã gõ (trừ mật khẩu)
        $put(['mail_enabled' => '1', 'mail_recipients' => 'ketoan@congty.vn', 'mail_host' => 'smtp.congty.vn', 'mail_from_address' => 'thongbao@congty.vn'])->assertSessionHasNoErrors();
        $this->assertSame(['mat-khau-smtp-123', 587, 'tls'], [NotificationSettings::all()['mail_password'], NotificationSettings::all()['mail_port'], NotificationSettings::all()['mail_encryption']]);
        $put(['mail_recipients' => 'sai-email', 'mail_host' => 'smtp.khac.vn', 'mail_from_address' => 'a@x.vn', 'mail_password' => 'bi-mat-moi'])->assertSessionHasErrors('mail_recipients');
        $this->actingAs($root)->get($url)->assertSee('name="mail_recipients" value="sai-email"', false)->assertSee('name="mail_host" value="smtp.khac.vn"', false)->assertDontSee('bi-mat-moi');
    }

    public function test_send_test_messages(): void
    {
        $root = $this->root();
        $url = '/backend/setting/notification';

        // Chưa cấu hình: báo lỗi, không gọi ra ngoài
        $this->fakeTelegram();
        $this->actingAs($root)->post($url.'/test/telegram')->assertRedirect($url)->assertSessionHas('error', 'Chưa gửi thử được. Chưa nhập đủ bot token và chat ID.');
        $this->actingAs($root)->post($url.'/test/mail')->assertSessionHas('error', 'Chưa gửi thử được. Chưa nhập địa chỉ email nhận thông báo.');
        Http::assertNothingSent();

        // Telegram: gửi tới đúng bot / chat, kể cả khi kênh đang tắt
        $this->telegramOn(['telegram_enabled' => false]);
        $this->actingAs($root)->post($url.'/test/telegram')->assertSessionHas('success', 'Đã gửi tin thử. Kiểm tra nhóm / chat Telegram.');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            && $request['chat_id'] === self::CHAT && $request['parse_mode'] === 'HTML'
            && str_contains($request['text'], 'Tin thử từ') && str_contains($request['text'], 'Gửi bởi: Nguyễn Đức'));

        // Telegram từ chối / không kết nối được: báo lý do dễ hiểu, không lộ token
        $this->telegramReply = fn () => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400);
        $this->actingAs($root)->post($url.'/test/telegram')->assertSessionHas('error', 'Chưa gửi thử được. Không tìm thấy chat: kiểm tra chat ID, và nhớ thêm bot vào nhóm (hoặc bấm Start với bot) trước.');
        $this->telegramReply = fn () => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401);
        $this->actingAs($root)->post($url.'/test/telegram')->assertSessionHas('error', 'Chưa gửi thử được. Bot token không đúng (Telegram trả lời: Unauthorized).');
        $this->telegramReply = fn () => throw new ConnectionException('cURL error 28: timeout for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        $this->actingAs($root)->post($url.'/test/telegram')->assertSessionHas('error', 'Chưa gửi thử được. Không kết nối được tới Telegram. Thử lại sau.');

        // Email: gửi qua SMTP đã cấu hình tới các địa chỉ nhận
        $messages = $this->captureMail();
        NotificationSettings::save($this->mailOn(['mail_enabled' => false]));
        $this->actingAs($root)->post($url.'/test/mail')->assertSessionHas('success', 'Đã gửi email thử tới ketoan@congty.vn, ketoan2@congty.vn. Kiểm tra hộp thư (cả mục Spam).');
        $this->assertSame(['transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.congty.vn', 'port' => 587, 'username' => 'thongbao@congty.vn', 'password' => 'mat-khau-smtp-123', 'timeout' => 10], $this->smtpConfigs[0]);
        $email = $messages->sole()->getOriginalMessage();
        $this->assertSame(['ketoan@congty.vn', 'ketoan2@congty.vn'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(['thongbao@congty.vn', 'Thuận Khang'], [$email->getFrom()[0]->getAddress(), $email->getFrom()[0]->getName()]);
        $this->assertStringContainsString('Email thử', $email->getSubject());
        $this->assertStringContainsString('Gửi bởi: Nguyễn Đức', $email->getTextBody());
    }

    public function test_new_order_and_transfer_notify_accountant(): void
    {
        $this->telegramOn();
        NotificationSettings::save([...$this->mailOn(), 'telegram_enabled' => true, 'telegram_chat_id' => self::CHAT, 'events' => ['order_created' => true, 'order_transferred' => true]]);
        $this->fakeTelegram();
        $messages = $this->captureMail();
        $member = Member::factory()->create();
        $product = $this->product();

        // Đơn mới → tin Telegram + email (gửi sau khi đã trả trang cho khách)
        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $product->id, 'qty' => 2, 'buy_now' => 1]);
        $this->post('/thanh-toan', $this->form(['recipient_name' => 'Trần <b>Thị</b> B']))->assertSessionHas('success');
        $order = Order::sole();
        $link = route('backend.orders.show', $order);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage' && $request['chat_id'] === self::CHAT
            && str_contains($request['text'], '<b>🛒 Đơn hàng mới '.$order->code.' — 2.190.000 đ</b>')
            && str_contains($request['text'], 'Khách: Trần &lt;b&gt;Thị&lt;/b&gt; B') // chữ khách nhập được escape
            && str_contains($request['text'], 'Tổng thanh toán: 2.190.000 đ') && str_contains($request['text'], 'Nhận hàng: Giao tận nơi')
            && str_contains($request['text'], 'Hạn thanh toán: ') && str_contains($request['text'], 'Xem đơn: '.$link));
        $email = $messages->sole()->getOriginalMessage();
        $this->assertSame('['.config('app.name').'] Đơn hàng mới '.$order->code.' — 2.190.000 đ', $email->getSubject());
        $this->assertSame(['ketoan@congty.vn', 'ketoan2@congty.vn'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertStringContainsString('Khách: Trần <b>Thị</b> B', $email->getTextBody()); // thư chữ thường, không dựng HTML
        $this->assertStringContainsString('Xem đơn: '.$link, $email->getTextBody());
        $this->assertNull($email->getHtmlBody());

        // Khách báo đã chuyển khoản → tin thứ hai, để kế toán vào xác nhận thanh toán
        $this->post(route('member.orders.transferred', $order), $this->billUpload())->assertSessionHas('success');
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], '💸 Khách báo đã chuyển khoản — đơn '.$order->code)
            && str_contains($request['text'], 'Số tiền: 2.190.000 đ · Nội dung chuyển khoản: '.$order->code) && str_contains($request['text'], 'Xác nhận thanh toán: '.$link)
            && str_contains($request['text'], 'Khách đã gửi ảnh bill chuyển khoản (xem trong trang đơn).'));
        $this->assertSame(2, $messages->count());
        $this->assertSame('['.config('app.name').'] Khách báo đã chuyển khoản — đơn '.$order->code, $messages->last()->getOriginalMessage()->getSubject());

        // Bấm lại (đơn không còn chờ thanh toán): không gửi thêm
        $this->post(route('member.orders.transferred', $order), $this->billUpload())->assertSessionHas('error');
        Http::assertSentCount(2);
    }

    public function test_notifications_respect_switches_and_never_break_checkout(): void
    {
        $product = $this->product();
        // Mỗi đơn một thành viên (một người chỉ được có tối đa Order::MAX_OPEN_UNPAID đơn chưa thanh toán)
        $place = function () use ($product) {
            $this->actingAs(Member::factory()->create(), 'member')->post('/gio-hang', ['product_id' => $product->id, 'qty' => 1, 'buy_now' => 1]);
            $this->post('/thanh-toan', $this->form())->assertSessionHas('success');

            return Order::latest('id')->first();
        };

        // Tắt sự kiện "đơn mới": chỉ báo khi khách báo chuyển khoản
        $this->telegramOn(['events' => ['order_created' => false, 'order_transferred' => true]]);
        $this->fakeTelegram();
        $order = $place();
        Http::assertNothingSent();
        $this->post(route('member.orders.transferred', $order), $this->billUpload());
        Http::assertSentCount(1);

        // Tắt kênh Telegram: không gửi thêm; email chưa bật cũng không gửi
        $messages = $this->captureMail();
        NotificationSettings::save([...$this->mailOn(['mail_enabled' => false]), 'telegram_enabled' => false, 'telegram_chat_id' => self::CHAT, 'events' => ['order_created' => true, 'order_transferred' => true]]);
        $this->post(route('member.orders.transferred', $place()), $this->billUpload());
        Http::assertSentCount(1);
        $this->assertCount(0, $messages);

        // Telegram lỗi / mất mạng: đơn vẫn tạo bình thường, khách không thấy lỗi
        $this->telegramOn();
        $this->telegramReply = fn () => Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429);
        $this->assertSame(Order::STATUS_NEW, $place()->status);
        Http::assertSentCount(2);
        $this->telegramReply = fn () => throw new ConnectionException('timeout');
        $this->assertSame(Order::STATUS_NEW, $place()->status); // lỗi kết nối không được tính là một lần gửi
        Http::assertSentCount(2);
        $this->assertSame(4, Order::count());
    }
}
