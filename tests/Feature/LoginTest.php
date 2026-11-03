<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/backend')->assertRedirect('/backend/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/backend/login')->assertOk()->assertSee('name="email"', false);
    }

    public function test_login_page_uses_new_admin_template(): void
    {
        $html = $this->get('/backend/login')->assertOk()
            // Form thật của Laravel theo markup Ant Design của template
            ->assertSee('<form id="login_form" method="POST" action="'.route('login.store').'" class="ant-form ant-form-vertical edit-form" novalidate>', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember" value="1"', false)
            ->assertSee('data-required="Vui lòng nhập email." data-type="email" data-type-message="Email không hợp lệ."', false)
            ->assertSee('data-required="Vui lòng nhập mật khẩu."', false)
            ->assertSee('ant-input-password-icon', false)
            ->assertSee('id="i-mail"', false) // bộ icon SVG nhúng trong trang
            // Asset mới build bằng Vite, không còn file của giao diện cũ
            ->assertSee('assets/backend/build/assets/app-', false)
            ->assertSee('assets/backend/img/favicon.svg', false)
            ->assertDontSee('assets/frontend/build', false) // trang quản trị không nạp CSS / JS website
            // Không mang theo phần demo của template
            ->assertDontSee('admin123')
            ->assertDontSee('Demo account')
            ->getContent();

        // Chưa nhập gì: con trỏ ở ô email, ô ghi nhớ chưa tích
        $this->assertMatchesRegularExpression('/id="login_email"[^>]*autofocus/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="login_password"[^>]*autofocus/', $html);
        $this->assertStringNotContainsString('ant-checkbox-wrapper-checked', $html);
        $this->assertFileExists(public_path('assets/backend/build/manifest.json'));
    }

    public function test_failed_login_shows_error_alert_and_keeps_email_and_remember_but_not_password(): void
    {
        $user = User::factory()->superAdmin()->create();

        $html = $this->from('/backend/login')
            ->followingRedirects()
            ->post('/backend/login', ['email' => $user->email, 'password' => 'mat-khau-sai', 'remember' => '1'])
            ->assertOk()
            ->assertSee('<div class="ant-alert ant-alert-error" role="alert">', false)
            ->assertSee('Email hoặc mật khẩu không đúng.')
            ->assertSee('value="'.$user->email.'"', false)
            ->assertDontSee('mat-khau-sai')
            ->getContent();

        // Đã có email: con trỏ chuyển sang ô mật khẩu; ô ghi nhớ giữ trạng thái đã tích
        $this->assertMatchesRegularExpression('/id="login_password"[^>]*autofocus/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="login_email"[^>]*autofocus/', $html);
        $this->assertStringContainsString('class="ant-checkbox-wrapper ant-checkbox-wrapper-checked"', $html);
        $this->assertMatchesRegularExpression('/name="remember" value="1"\s+checked/', $html);
        $this->assertGuest();
    }

    public function test_logout_shows_signed_out_message(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->followingRedirects()
            ->post('/backend/logout')
            ->assertOk()
            ->assertSee('<div class="ant-alert ant-alert-success" role="status">', false)
            ->assertSee('Bạn đã đăng xuất.');
        $this->assertGuest();

        // Tải lại trang thì thông báo không còn
        $this->get('/backend/login')->assertDontSee('Bạn đã đăng xuất.');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);

        $this->post('/backend/logout')->assertRedirect('/backend/login');
        $this->assertGuest();
    }

    public function test_remember_me_sets_remember_cookie(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->post('/backend/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertCookie('remember_web_'.sha1(\Illuminate\Auth\SessionGuard::class));
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->from('/backend/login')
            ->post('/backend/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertRedirect('/backend/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_too_many_failures(): void
    {
        $user = User::factory()->superAdmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/backend/login', ['email' => $user->email, 'password' => 'nope']);
        }

        // Lần thứ 6, dù đúng mật khẩu, vẫn bị chặn.
        $this->from('/backend/login')
            ->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_redirects_back_to_originally_requested_backend_page(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->get('/backend')->assertRedirect('/backend/login');

        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend');
    }

    public function test_authenticated_user_cannot_see_login_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/backend/login')
            ->assertRedirect('/backend');
    }

    public function test_logout_requires_post(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/backend/logout')
            ->assertStatus(405);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/backend/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
