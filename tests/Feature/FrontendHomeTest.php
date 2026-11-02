<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_public_and_renders_portfolio(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<title>Nguyễn Hữu Đức — Product Management Leader</title>', false)
            ->assertSee('Một người làm sản phẩm với gốc rễ kỹ thuật')
            ->assertSee(asset('assets/frontend/images/avatar.png'), false)
            // Không lộ gì của backend
            ->assertDontSee('/backend/login')->assertDontSee('csrf');
        $this->assertFileExists(public_path('assets/frontend/images/avatar.png'));
    }

    public function test_home_page_works_for_logged_in_user_too(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->get('/')->assertOk()->assertSee('Product Management Leader');
    }
}
