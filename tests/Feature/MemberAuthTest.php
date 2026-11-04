<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureReferralCode;
use App\Models\Level;
use App\Models\Member;
use App\Models\User;
use App\Support\AffiliateSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Step 3 – Tài khoản thành viên & mã giới thiệu: đăng ký / đăng nhập trên website, bảng members + guard "member" tách
 * khỏi tài khoản quản trị. Nghiệm thu: đăng ký qua link A → referrer = A; mã không tồn tại báo lỗi.
 */
class MemberAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
    }

    private function form(array $data = []): array
    {
        return [
            'full_name' => 'Nguyễn Văn Bình', 'phone' => '0912 345 678', 'email' => '', 'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123', 'referral_code' => '', ...$data,
        ];
    }

    public function test_register_via_referral_link(): void
    {
        $a = Member::factory()->create(['full_name' => 'Trần Thị Mai Anh', 'referral_code' => 'ANHTM88']);

        // Mở link giới thiệu → cookie ref → trang đăng ký điền sẵn mã + tên rút gọn của người giới thiệu
        $this->get('/?ref=anhtm88')->assertCookie(CaptureReferralCode::COOKIE, 'ANHTM88');
        $this->withCookie(CaptureReferralCode::COOKIE, 'ANHTM88')->get('/dang-ky')->assertOk()
            ->assertSee('value="ANHTM88"', false)->assertSee('Người giới thiệu: <strong>Trần T. M. Anh</strong>', false);
        $this->get('/dang-ky?ref=anhtm88')->assertSee('value="ANHTM88"', false);

        $this->post('/dang-ky', $this->form(['phone' => '+84 912.345.678', 'email' => 'Binh@Example.com', 'referral_code' => 'anhtm88']))
            ->assertRedirect(route('member.account'))->assertSessionHas('success');

        $member = Member::where('phone', '0912345678')->sole();
        $this->assertSame([$a->id, 'binh@example.com', 'Nguyễn Văn Bình'], [$member->referrer_id, $member->email, $member->full_name]);
        $this->assertSame('Chưa kích hoạt', $member->level->name);
        $this->assertNull($member->activated_at);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{7}$/', $member->referral_code);
        $this->assertNotSame($member->password, 'matkhau123');
        // Đăng nhập bằng guard member, không phải tài khoản quản trị
        $this->assertAuthenticatedAs($member, 'member');
        $this->assertGuest('web');
        $this->assertSame(0, User::count()); // không tạo tài khoản quản trị
    }

    public function test_register_validation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // gửi form nhiều lần liên tiếp
        Member::factory()->create(['phone' => '0987654321', 'email' => 'da@co.vn']);
        Member::factory()->locked()->create(['referral_code' => 'KHOA123']);

        foreach ([
            'full_name' => ['full_name' => ''],
            'phone' => ['phone' => '0123'],
            'email' => ['email' => 'khong-hop-le'],
            'password' => ['password' => 'ngan', 'password_confirmation' => 'ngan'],
        ] as $field => $data) {
            $this->post('/dang-ky', $this->form($data))->assertSessionHasErrors($field);
        }
        $this->post('/dang-ky', $this->form(['phone' => '+84987654321']))->assertSessionHasErrors(['phone' => 'Số điện thoại này đã đăng ký. Hãy đăng nhập.']);
        $this->post('/dang-ky', $this->form(['email' => 'DA@CO.VN']))->assertSessionHasErrors('email');
        $this->post('/dang-ky', $this->form(['password' => 'chiconchu', 'password_confirmation' => 'chiconchu']))->assertSessionHasErrors('password');
        $this->post('/dang-ky', $this->form(['password_confirmation' => 'khac12345']))->assertSessionHasErrors(['password' => 'Mật khẩu nhập lại không khớp.']);
        $this->post('/dang-ky', $this->form(['referral_code' => 'KHONGCO']))->assertSessionHasErrors(['referral_code' => 'Mã giới thiệu không tồn tại. Kiểm tra lại hoặc để trống.']);
        $this->post('/dang-ky', $this->form(['referral_code' => 'khoa123']))->assertSessionHasErrors('referral_code');
        $this->post('/dang-ky', $this->form(['phone' => ['x'], 'full_name' => ['y']]))->assertSessionHasErrors(['phone', 'full_name']);

        $this->assertSame(2, Member::count());
        $this->assertGuest('member');
        // Lỗi: form hiện lại dữ liệu đã nhập (trừ mật khẩu)
        $this->from('/dang-ky')->followingRedirects()->post('/dang-ky', $this->form(['referral_code' => 'KHONGCO']))
            ->assertOk()->assertSee('value="Nguyễn Văn Bình"', false)->assertSee('value="KHONGCO"', false)->assertDontSee('matkhau123');
    }

    public function test_register_without_code_uses_root_account(): void
    {
        // Chưa cấu hình tài khoản gốc → không có người giới thiệu
        $this->post('/dang-ky', $this->form(['phone' => '0911111111']))->assertRedirect();
        $this->assertNull(Member::where('phone', '0911111111')->value('referrer_id'));
        $this->post('/dang-xuat');

        $root = Member::factory()->create(['referral_code' => 'CONGTY']);
        AffiliateSettings::save(['root_referral_code' => 'CONGTY']);
        $this->post('/dang-ky', $this->form(['phone' => '0922222222']))->assertRedirect();
        $this->assertSame($root->id, Member::where('phone', '0922222222')->value('referrer_id'));
    }

    public function test_referrer_lookup(): void
    {
        Member::factory()->create(['full_name' => 'Lê Hoàng Nam', 'referral_code' => 'NAM2024', 'phone' => '0933333333']);
        Member::factory()->locked()->create(['referral_code' => 'KHOA999']);

        $this->getJson('/ma-gioi-thieu/nam2024')->assertOk()->assertExactJson(['name' => 'Lê H. Nam']);
        $this->getJson('/ma-gioi-thieu/KHONGCO')->assertNotFound();
        $this->getJson('/ma-gioi-thieu/KHOA999')->assertNotFound();
        $this->getJson('/ma-gioi-thieu/a-b')->assertNotFound();
        $this->assertStringNotContainsString('0933333333', $this->getJson('/ma-gioi-thieu/NAM2024')->getContent());
    }

    public function test_login_and_logout(): void
    {
        $member = Member::factory()->create(['phone' => '0944444444', 'password' => 'matkhau123']);

        $this->get('/tai-khoan')->assertRedirect('/dang-nhap');
        $this->post('/dang-nhap', ['phone' => '0944444444', 'password' => 'sai'])->assertSessionHasErrors(['phone' => 'Số điện thoại hoặc mật khẩu không đúng.']);
        $this->assertGuest('member');

        // Số có khoảng trắng / +84 vẫn đăng nhập được; quay về trang định vào trước đó
        $this->get('/tai-khoan');
        $this->post('/dang-nhap', ['phone' => '+84 944 444 444', 'password' => 'matkhau123', 'remember' => '1'])->assertRedirect('/tai-khoan');
        $this->assertAuthenticatedAs($member, 'member');
        $this->assertNotNull($member->fresh()->last_login_at);
        $this->get('/dang-nhap')->assertRedirect(route('member.account'));
        $this->get('/dang-ky')->assertRedirect(route('member.account'));

        $this->post('/dang-xuat')->assertRedirect(route('home'));
        $this->assertGuest('member');
    }

    public function test_login_is_rate_limited_and_locked_members_are_blocked(): void
    {
        Member::factory()->create(['phone' => '0955555555', 'password' => 'matkhau123']);
        foreach (range(1, 5) as $i) {
            $this->post('/dang-nhap', ['phone' => '0955555555', 'password' => 'sai']);
        }
        $this->post('/dang-nhap', ['phone' => '0955555555', 'password' => 'matkhau123'])->assertSessionHasErrors('phone');
        $this->assertGuest('member');

        $locked = Member::factory()->locked()->create(['phone' => '0966666666', 'password' => 'matkhau123']);
        $this->post('/dang-nhap', ['phone' => '0966666666', 'password' => 'matkhau123'])
            ->assertSessionHasErrors(['phone' => 'Tài khoản đang bị khóa. Vui lòng liên hệ hotline để được hỗ trợ.']);
        $this->assertGuest('member');

        // Bị khóa khi đang đăng nhập → bị đăng xuất ở lần truy cập sau
        // (đăng nhập thật trên cùng trình duyệt vừa thử tài khoản bị khóa)
        $active = Member::factory()->create(['phone' => '0988888888', 'password' => 'matkhau123']);
        $this->post('/dang-nhap', ['phone' => '0988888888', 'password' => 'matkhau123'])->assertRedirect('/tai-khoan');
        $this->get('/tai-khoan')->assertOk();
        $active->update(['status' => Member::STATUS_LOCKED]);
        $this->app['auth']->forgetGuards(); // request mới nạp lại thành viên từ DB (test dùng chung một app)
        $this->get('/tai-khoan')->assertRedirect(route('member.login'));
        $this->assertGuest('member');
        $this->assertTrue($locked->isLocked());
    }

    public function test_account_page_and_header(): void
    {
        $referrer = Member::factory()->create(['full_name' => 'Phạm Quốc Huy']);
        $member = Member::factory()->create(['full_name' => 'Đỗ Thu Hà', 'referral_code' => 'THUHA99', 'referrer_id' => $referrer->id, 'phone' => '0977777777']);
        Member::factory()->count(3)->create(['referrer_id' => $member->id]);

        $this->get('/')->assertSee('href="'.route('member.login').'"', false)->assertSee('href="'.route('member.register').'"', false)
            ->assertDontSee('data-member-menu', false);

        $this->actingAs($member, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('Xin chào, Đỗ Thu Hà')->assertSee('THUHA99')->assertSee(url('/').'?ref=THUHA99', false)
            ->assertSee('Phạm Q. Huy')->assertSee('Chưa kích hoạt')->assertSee('0977777777')
            ->assertSeeInOrder(['Người bạn đã giới thiệu (F1)', '3'])
            ->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
        $this->get('/')->assertSee('data-member-menu', false)->assertSee('Đỗ Thu Hà');
    }

    public function test_members_and_admins_are_separate(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@example.com']);
        $member = Member::factory()->create(['password' => 'matkhau123']);

        // Quản trị đăng nhập không phải là thành viên; thành viên không vào được trang quản trị
        $this->actingAs($admin)->get('/tai-khoan')->assertRedirect(route('member.login'));
        auth()->guard('web')->logout();
        $this->actingAs($member, 'member');
        auth()->shouldUse('web'); // actingAs() đổi guard mặc định của cả app test; request thật tới /backend dùng guard web
        $this->get('/backend')->assertRedirect('/backend/login');

        // Đăng xuất thành viên không đăng xuất quản trị dùng chung trình duyệt
        $this->actingAs($admin)->actingAs($member, 'member')->post('/dang-xuat');
        auth()->shouldUse('web');
        $this->assertGuest('member');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->get('/backend')->assertOk();
    }

    public function test_helpers(): void
    {
        $this->assertSame(['0912345678', '0912345678', '0312345678', null, null, null],
            array_map([Member::class, 'normalizePhone'], ['0912 345 678', '+84912345678', '84 312.345.678', '0212345678', '091234567', ['x']]));
        $this->assertSame('Nguyễn V. An', (new Member(['full_name' => '  Nguyễn  Văn An ']))->shortName());
        $this->assertSame('Trần Bình', (new Member(['full_name' => 'Trần Bình']))->shortName());
        $codes = collect(range(1, 50))->map(fn () => Member::generateReferralCode());
        $codes->each(fn ($code) => $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{7}$/', $code));
        $this->assertSame(1, Level::where('threshold_amount', 0)->count());
    }
}
