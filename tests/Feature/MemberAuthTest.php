<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureReferralCode;
use App\Models\Level;
use App\Models\Member;
use App\Models\User;
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
            'password_confirmation' => 'matkhau123', 'referral_code' => '', 'branch' => 'A', ...$data,
        ];
    }

    public function test_register_via_referral_link(): void
    {
        $a = Member::factory()->create(['full_name' => 'Trần Thị Mai Anh', 'referral_code' => 'ANHTM88']);

        // Mở link giới thiệu → cookie ref → trang đăng ký điền sẵn mã + tên rút gọn của người giới thiệu
        $this->get('/?ref=anhtm88')->assertCookie(CaptureReferralCode::COOKIE, 'ANHTM88');
        $this->withCookie(CaptureReferralCode::COOKIE, 'ANHTM88')->get('/dang-ky')->assertOk()
            ->assertSee('value="ANHTM88"', false)->assertSee('Người giới thiệu: <strong>Trần T. M. Anh</strong>', false);
        $this->get('/dang-ky?ref=anhtm88')->assertSee('value="ANHTM88"', false)
            // Chọn nhánh của người giới thiệu: hai lựa chọn A / B, chưa chọn sẵn cái nào
            ->assertSeeInOrder(['Chọn nhánh', 'name="branch" value="A"', 'Nhánh A', 'name="branch" value="B"', 'Nhánh B', 'Hỏi người giới thiệu bạn nên chọn Nhánh A hay Nhánh B'], false)
            ->assertDontSee('" checked', false);

        $this->post('/dang-ky', $this->form(['phone' => '+84 912.345.678', 'email' => 'Binh@Example.com', 'referral_code' => 'anhtm88']))
            ->assertRedirect(route('member.account'))->assertSessionHas('success');

        $member = Member::where('phone', '0912345678')->sole();
        $this->assertSame([$a->id, 'binh@example.com', 'Nguyễn Văn Bình'], [$member->referrer_id, $member->email, $member->full_name]);
        // Nhánh A = L: đứng ngay dưới người giới thiệu ở nhánh A
        $this->assertSame(['L', $a->id, 'L'], [$member->sponsor_side, $member->parent_id, $member->position]);
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
        $this->post('/dang-ky', $this->form(['referral_code' => 'KHONGCO']))->assertSessionHasErrors(['referral_code' => 'Mã giới thiệu không tồn tại. Kiểm tra lại mã với người giới thiệu bạn.']);
        $this->post('/dang-ky', $this->form(['referral_code' => 'khoa123']))->assertSessionHasErrors(['referral_code' => 'Mã giới thiệu này đang tạm khóa. Hãy dùng mã của người giới thiệu khác.']);
        $this->post('/dang-ky', $this->form(['phone' => ['x'], 'full_name' => ['y']]))->assertSessionHasErrors(['phone', 'full_name']);

        $this->assertSame(2, Member::count());
        $this->assertGuest('member');
        // Lỗi: form hiện lại dữ liệu đã nhập (trừ mật khẩu)
        $this->from('/dang-ky')->followingRedirects()->post('/dang-ky', $this->form(['referral_code' => 'KHONGCO']))
            ->assertOk()->assertSee('value="Nguyễn Văn Bình"', false)->assertSee('value="KHONGCO"', false)->assertDontSee('matkhau123');
    }

    public function test_register_requires_a_valid_referral_code(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        // Hệ thống chưa có thành viên nào: tài khoản đầu tiên đăng ký được không cần mã (chưa có ai để giới thiệu), không chọn nhánh
        $this->get('/dang-ky')->assertOk()->assertDontSee('Bắt buộc: chỉ đăng ký được khi có mã của người giới thiệu')->assertDontSee('name="branch"', false);
        $this->post('/dang-ky', $this->form(['phone' => '0911111111', 'branch' => null]))->assertRedirect(route('member.account'));
        $first = Member::where('phone', '0911111111')->sole();
        $this->assertSame([null, null, null], [$first->referrer_id, $first->sponsor_side, $first->parent_id]);
        $this->post('/dang-xuat');

        // Từ đó trở đi: bắt buộc có mã, và mã phải hợp lệ
        $this->get('/dang-ky')->assertOk()->assertSee('Bắt buộc: chỉ đăng ký được khi có mã của người giới thiệu')
            ->assertSee('Mã người giới thiệu<span class="text-rose-500"> *</span>', false);
        foreach (['', '   ', null] as $empty) {
            $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => $empty]))
                ->assertSessionHasErrors(['referral_code' => 'Nhập mã người giới thiệu. Chỉ đăng ký được khi có mã của người giới thiệu.']);
        }
        $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => 'SAIMA99']))->assertSessionHasErrors('referral_code');
        $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => ['x']]))->assertSessionHasErrors('referral_code');
        // Có mã thì bắt buộc chọn nhánh A hoặc B
        foreach (['', null] as $empty) {
            $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => $first->referral_code, 'branch' => $empty]))
                ->assertSessionHasErrors(['branch' => 'Chọn Nhánh A hoặc Nhánh B. Hỏi người giới thiệu bạn nên chọn nhánh nào.']);
        }
        foreach (['C', 'L', 'R', ['A']] as $invalid) {
            $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => $first->referral_code, 'branch' => $invalid]))->assertSessionHasErrors('branch');
        }
        $this->from('/dang-ky')->followingRedirects()->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => $first->referral_code, 'branch' => '']))
            ->assertOk()->assertSee('Chọn Nhánh A hoặc Nhánh B. Hỏi người giới thiệu bạn nên chọn nhánh nào.');
        $this->assertSame(1, Member::count());
        $this->assertGuest('member');

        // Có mã hợp lệ (không phân biệt hoa thường): đăng ký được, gắn đúng người giới thiệu
        $this->post('/dang-ky', $this->form(['phone' => '0922222222', 'referral_code' => strtolower($first->referral_code), 'branch' => 'b']))->assertRedirect(route('member.account'));
        $second = Member::where('phone', '0922222222')->sole();
        // Nhánh B = R
        $this->assertSame([$first->id, 'R', $first->id, 'R'], [$second->referrer_id, $second->sponsor_side, $second->parent_id, $second->position]);
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
            ->assertSeeInOrder(['Đại lý bạn tuyển dụng', '3'])
            ->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
        $this->get('/')->assertSee('data-member-menu', false)->assertSee('Đỗ Thu Hà');
    }

    public function test_account_shows_direct_members_and_branch_size(): void
    {
        // Ví dụ: Huyền giới thiệu Yến; Yến giới thiệu A và B. Hà là tuyến trên của Huyền.
        $levels = Level::ordered()->pluck('id', 'name');
        $ha = Member::factory()->create(['full_name' => 'Đinh Thu Hà']);
        $huyen = Member::factory()->create(['full_name' => 'Nguyễn Thị Huyền', 'referrer_id' => $ha->id]);
        $yen = Member::factory()->create(['full_name' => 'Trần Hải Yến', 'referrer_id' => $huyen->id, 'level_id' => $levels['Cộng tác viên'], 'phone' => '0911000111', 'email' => 'yen@example.com']);
        Member::factory()->create(['full_name' => 'Thành viên A', 'referrer_id' => $yen->id, 'level_id' => $levels['Nhân viên']]);
        Member::factory()->create(['full_name' => 'Thành viên B', 'referrer_id' => $yen->id]);
        Member::factory()->create(['full_name' => 'Người Ngoài Nhánh', 'referrer_id' => $ha->id]);

        // Trang tài khoản của Huyền: F1 = 1 (Yến) + nút xem chi tiết; thành viên chi nhánh = 3 (Yến + A + B)
        $this->actingAs($huyen, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('<span class="text-xl font-bold text-slate-900" data-f1-count>1</span>', false)
            ->assertSee('<a href="'.route('member.team').'"', false)->assertSee('Xem chi tiết')
            ->assertSeeInOrder(['uppercase">Thành viên</div>', '<div class="mt-2 text-xl font-bold text-slate-900" data-team-count>3</div>'], false)
            // Người giới thiệu vẫn xem được ở phần Thông tin tài khoản
            ->assertSeeInOrder(['Thông tin tài khoản', 'Người giới thiệu bạn', 'Đinh T. Hà']);

        // Trang danh sách: chỉ thành viên trực tiếp (Yến) với họ tên + cấp hiện tại; không lộ SĐT / email
        $this->get('/tai-khoan/thanh-vien')->assertOk()
            ->assertSee('data-team-direct>1<', false)->assertSee('data-team-total>3<', false)
            ->assertSeeInOrder(['Họ và tên', 'Cấp hiện tại', '<span class="sr-only">Số thành viên bên dưới</span>', 'Trần Hải Yến', 'Tham gia ngày '.$yen->created_at->format('d/m/Y'), 'Cộng tác viên', 'data-below="2"'], false)
            ->assertDontSee('Thành viên A')->assertDontSee('Thành viên B')->assertDontSee('Người Ngoài Nhánh')->assertDontSee('Đinh Thu Hà')
            ->assertDontSee('0911000111')->assertDontSee('yen@example.com');

        // Yến: 2 thành viên trực tiếp (A, B), nhánh 2 người; Hà: 2 trực tiếp, nhánh 5 người; A: chưa có ai
        $this->actingAs($yen, 'member')->get('/tai-khoan/thanh-vien')->assertOk()
            ->assertSee('data-team-direct>2<', false)->assertSee('data-team-total>2<', false)
            ->assertSee('Thành viên A')->assertSee('Nhân viên')->assertSee('Thành viên B')->assertSee('Chưa kích hoạt')->assertDontSee('Nguyễn Thị Huyền')
            ->assertSee('data-below="0"', false)->assertDontSee('data-below="1"', false);
        $this->actingAs($ha, 'member')->get('/tai-khoan')->assertSee('data-f1-count>2<', false)->assertSee('data-team-count>5<', false);
        // Số bên cạnh mỗi người = số người bên dưới trong CÂY NHỊ PHÂN, không phải số F1: Huyền chỉ có 1 F1 (Yến) nhưng bên
        // dưới có 3 người (Yến, A, B); Người Ngoài Nhánh chưa có ai
        $this->get('/tai-khoan/thanh-vien')->assertOk()->assertSee('data-team-total>5<', false)
            ->assertSeeInOrder(['Người Ngoài Nhánh', 'data-below="0"', 'Nguyễn Thị Huyền', 'data-below="3"'], false);
        $leaf = Member::where('full_name', 'Thành viên A')->sole();
        $this->actingAs($leaf, 'member')->get('/tai-khoan')->assertSee('data-f1-count>0<', false)->assertSee('data-team-count>0<', false);
        $this->actingAs($leaf, 'member')->get('/tai-khoan/thanh-vien')->assertOk()->assertSee('Bạn chưa giới thiệu ai')->assertSee($leaf->referral_code);

        // Chưa đăng nhập: về trang đăng nhập
        auth('member')->logout();
        $this->get('/tai-khoan/thanh-vien')->assertRedirect(route('member.login'));
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
