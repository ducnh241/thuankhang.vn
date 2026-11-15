<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Totp;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Xác thực 2 lớp (TOTP) của tài khoản quản trị: bật / xác nhận / tắt ở trang Tài khoản, bước nhập mã khi đăng nhập. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function code(User $user, int $offsetSteps = 0): string
    {
        return Totp::code($user->fresh()->two_factor_secret, Totp::stepAt() + $offsetSteps);
    }

    /** Tài khoản đã bật 2 lớp + các mã khôi phục gốc. */
    private function userWithTwoFactor(array $attributes = []): array
    {
        $user = User::factory()->superAdmin()->create($attributes);
        $service = new TwoFactor;
        $service->begin($user);
        $codes = $service->confirm($user, $this->code($user));
        $this->travel(Totp::PERIOD)->seconds(); // mã vừa dùng để xác nhận không dùng lại được

        return [$user->fresh(), $codes];
    }

    public function test_totp_matches_rfc_6238_vectors_and_rejects_bad_input(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::stepAt($time)));
            $this->assertSame(Totp::stepAt($time), Totp::verify($secret, $code, null, 1, $time));
            $this->assertSame(Totp::stepAt($time), Totp::verify($secret, $code, null, 1, $time + 30), 'lệch 1 bước vẫn nhận');
            $this->assertNull(Totp::verify($secret, $code, null, 1, $time + 90), 'mã quá cũ');
            $this->assertNull(Totp::verify($secret, $code, Totp::stepAt($time), 1, $time), 'mã đã dùng');
        }
        foreach (['', '12345', '1234567', 'abcdef', '12345a'] as $bad) {
            $this->assertNull(Totp::verify($secret, $bad, null, 1, 59));
        }
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
        $this->assertStringStartsWith('otpauth://totp/', Totp::uri('ABC', 'a@b.vn', 'Shop'));
    }

    public function test_enable_needs_password_then_confirm_with_code(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user)->get('/backend/profile')->assertOk()->assertSee('Bật xác thực 2 lớp')->assertSee('Chưa bật');

        // Sai / thiếu mật khẩu → không tạo khóa
        $this->post('/backend/profile/two-factor', ['current_password' => 'sai'])->assertSessionHasErrorsIn('twofactor', 'current_password');
        // (assertSessionHasErrorsIn làm mất túi lỗi trong session của test → gửi lại rồi mới xem trang hiện lỗi)
        $this->post('/backend/profile/two-factor', ['current_password' => 'sai']);
        $this->get('/backend/profile')->assertSee('Mật khẩu không đúng.');
        $this->post('/backend/profile/two-factor')->assertSessionHasErrorsIn('twofactor', 'current_password');
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->post('/backend/profile/two-factor', ['current_password' => 'password'])->assertRedirect('/backend/profile#two-factor');
        $user->refresh();
        $this->assertSame(32, strlen($user->two_factor_secret));
        $this->assertFalse($user->hasTwoFactor());
        // Khóa lưu mã hóa trong database
        $this->assertStringNotContainsString($user->two_factor_secret, (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'));

        $this->get('/backend/profile')->assertOk()->assertSee('Chờ xác nhận')->assertSee('data-two-factor-qr', false)->assertSee('<svg', false)
            ->assertSee(implode(' ', str_split($user->two_factor_secret, 4)));

        // Mã sai → chưa bật, lỗi hiện ngay dưới ô mã; mã đúng → bật + 8 mã khôi phục hiện một lần
        $this->post('/backend/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrorsIn('twofactor', 'code');
        $this->post('/backend/profile/two-factor/confirm', ['code' => '000000']);
        $this->get('/backend/profile')->assertSee('Mã không đúng. Kiểm tra giờ trên điện thoại rồi nhập mã mới nhất.');
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $response = $this->post('/backend/profile/two-factor/confirm', ['code' => $this->code($user)])->assertRedirect('/backend/profile#two-factor');
        $codes = session('recovery_codes');
        $this->assertCount(TwoFactor::RECOVERY_CODES, $codes);
        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertCount(TwoFactor::RECOVERY_CODES, $user->two_factor_recovery_codes);
        $this->assertNotContains($codes[0], $user->two_factor_recovery_codes, 'chỉ lưu bản băm');

        // Trang sau khi bật: hiện đủ mã khôi phục (một lần), không còn mã QR
        $response->assertSessionHas('recovery_codes');
        $page = $this->get('/backend/profile')->assertSee('Đang bật')->assertSee('Tắt xác thực 2 lớp')->assertDontSee('data-two-factor-qr', false);
        foreach ($codes as $code) {
            $page->assertSee($code);
        }
        $page->assertDontSee('ant-alert-description', false); // lớp này bị ẩn khi alert không có .ant-alert-with-description
        $this->get('/backend/profile')->assertDontSee($codes[0]);

        // Nhật ký: có dòng "Bật xác thực 2 lớp", không dòng nào chứa khóa / mã khôi phục
        $this->assertTrue(AuditLog::where('description', 'Bật xác thực 2 lớp')->where('user_id', $user->id)->exists());
        $dump = AuditLog::all()->toJson();
        $this->assertStringNotContainsString($user->two_factor_secret, $dump);
        $this->assertStringNotContainsString($codes[0], $dump);
        $this->assertStringNotContainsString('two_factor', $dump);
    }

    public function test_cancel_pending_setup_and_disable_or_regenerate_need_password(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user)->post('/backend/profile/two-factor', ['current_password' => 'password']);
        $this->delete('/backend/profile/two-factor')->assertRedirect('/backend/profile#two-factor'); // hủy khi chưa xác nhận: không cần mật khẩu
        $this->assertNull($user->fresh()->two_factor_secret);

        [$user, $codes] = $this->userWithTwoFactor();
        $this->actingAs($user);
        $this->delete('/backend/profile/two-factor', ['current_password' => 'sai'])->assertSessionHasErrorsIn('twofactor', 'current_password');
        $this->post('/backend/profile/two-factor/recovery-codes')->assertSessionHasErrorsIn('twofactor', 'current_password');
        $this->assertTrue($user->fresh()->hasTwoFactor());

        // Bật rồi mà gọi lại "bật" thì không đổi khóa
        $secret = $user->two_factor_secret;
        $this->post('/backend/profile/two-factor', ['current_password' => 'password']);
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->post('/backend/profile/two-factor/recovery-codes', ['current_password' => 'password'])->assertSessionHas('recovery_codes');
        $this->assertNotSame($codes, session('recovery_codes'));
        $this->assertNull((new TwoFactor)->verify($user->fresh(), $codes[0]), 'mã khôi phục cũ hết hiệu lực');

        $this->delete('/backend/profile/two-factor', ['current_password' => 'password'])->assertSessionHas('success');
        $user->refresh();
        $this->assertFalse($user->hasTwoFactor());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertTrue(AuditLog::where('description', 'Tắt xác thực 2 lớp')->exists());
    }

    public function test_login_requires_code_after_password(): void
    {
        [$user] = $this->userWithTwoFactor();

        // Đúng mật khẩu → chưa đăng nhập, sang bước nhập mã
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect('/backend/login/two-factor');
        $this->assertGuest();
        $this->get('/backend')->assertRedirect('/backend/login');
        $this->get('/backend/login/two-factor')->assertOk()->assertSee('Xác thực 2 lớp')->assertSee('name="code"', false);
        $this->assertFalse(AuditLog::where('action', 'login')->exists());

        // Sai mã → vẫn chưa đăng nhập, ghi nhật ký
        $this->from('/backend/login/two-factor')->post('/backend/login/two-factor', ['code' => '000000'])
            ->assertRedirect('/backend/login/two-factor')->assertSessionHasErrors('code');
        $this->post('/backend/login/two-factor', ['code' => '000000']);
        $this->get('/backend/login/two-factor')->assertSee('Mã không đúng hoặc đã dùng.');
        $this->post('/backend/login/two-factor', ['code' => ''])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'login_failed')->where('description', 'like', 'Sai mã xác thực 2 lớp%')->exists());

        // Đúng mã (có dấu cách cũng nhận) → đăng nhập, giữ lựa chọn "ghi nhớ"
        $code = $this->code($user);
        $this->post('/backend/login/two-factor', ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
        $this->assertTrue(AuditLog::where('action', 'login')->where('description', 'Ghi nhớ đăng nhập')->exists());
        $this->assertNull(session(TwoFactorChallengeController::SESSION_KEY));

        // Mã vừa dùng không dùng lại được (kẻ nhìn trộm mã); mã của 30 giây sau thì được
        $this->post('/backend/logout');
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/backend/login/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->travel(Totp::PERIOD)->seconds();
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
    }

    public function test_challenge_page_needs_a_pending_login_and_expires(): void
    {
        [$user] = $this->userWithTwoFactor();

        // Chưa qua bước mật khẩu
        $this->get('/backend/login/two-factor')->assertRedirect('/backend/login');
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend/login');
        $this->assertGuest();

        // Quá 10 phút
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $this->travel(11)->minutes();
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // Tài khoản bị khóa giữa chừng
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $user->update(['status' => false]);
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend/login');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        [$user, $codes] = $this->userWithTwoFactor();

        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/backend/login/two-factor', ['code' => ' '.strtoupper($codes[0]).' '])->assertRedirect('/backend')->assertSessionHas('error');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(TwoFactor::RECOVERY_CODES - 1, $user->fresh()->two_factor_recovery_codes);

        $this->post('/backend/logout');
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/backend/login/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/backend/login/two-factor', ['code' => $codes[1]])->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
    }

    public function test_too_many_wrong_codes_send_back_to_login(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        for ($i = 0; $i < TwoFactorChallengeController::MAX_ATTEMPTS; $i++) {
            $this->post('/backend/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        // Hết lượt: kể cả mã đúng cũng bị từ chối, phải đăng nhập lại và chờ
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/backend/login/two-factor');
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend/login');
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/backend/login/two-factor', ['code' => $this->code($user)])->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
    }

    public function test_accounts_without_two_factor_log_in_as_before_and_pending_setup_does_not_block(): void
    {
        $user = User::factory()->superAdmin()->create();
        (new TwoFactor)->begin($user); // mới tạo khóa, chưa xác nhận
        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
    }

    public function test_manager_can_reset_two_factor_of_others_only(): void
    {
        $this->seed(RoleSeeder::class);
        $root = User::factory()->superAdmin()->create();
        [$target] = $this->userWithTwoFactor(['is_super_admin' => false, 'group_id' => Group::where('name', 'Vận hành')->value('id')]);
        [$rootWith2fa] = $this->userWithTwoFactor();
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);

        // Không có quyền user/edit → 403; không quản lý được Root → 403
        $this->actingAs($ops)->delete("/backend/users/{$target->id}/two-factor")->assertForbidden();
        $this->assertTrue($target->fresh()->hasTwoFactor());

        // Tự tắt của mình qua đường này: không được (phải dùng trang Tài khoản + mật khẩu)
        $this->actingAs($rootWith2fa)->delete("/backend/users/{$rootWith2fa->id}/two-factor")->assertSessionHas('error');
        $this->assertTrue($rootWith2fa->fresh()->hasTwoFactor());

        $this->actingAs($root)->get("/backend/users/{$target->id}/edit")->assertOk()->assertSee('Xác thực 2 lớp')->assertSee('Tắt giúp');
        $this->actingAs($root)->delete("/backend/users/{$target->id}/two-factor")->assertRedirect("/backend/users/{$target->id}/edit");
        $this->assertFalse($target->fresh()->hasTwoFactor());
        $this->assertNull($target->fresh()->two_factor_secret);
        $this->assertTrue(AuditLog::where('description', "Tắt xác thực 2 lớp của {$target->name}")->where('user_id', $root->id)->exists());
        $this->actingAs($root)->get("/backend/users/{$target->id}/edit")->assertDontSee('Tắt giúp');
    }
}
