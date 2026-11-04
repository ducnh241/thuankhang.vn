<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 4 vai trò mặc định Affiliate Phase 1 (Super admin, Vận hành, Kế toán, CSKH) — đặc tả mục 6.1. */
class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $role)->value('id')]);
    }

    public function test_seeds_four_roles_with_expected_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(['Super admin', 'Vận hành', 'Kế toán', 'CSKH'], Group::pluck('name')->all());
        $this->assertEqualsCanonicalizing(Group::allPermissionKeys(), Group::where('name', 'Super admin')->value('permission_data'));

        foreach (['Super admin', 'Vận hành', 'Kế toán', 'CSKH'] as $role) {
            $this->assertTrue($this->member($role)->canAccessBackend(), $role);
        }

        $ops = $this->member('Vận hành');
        $this->assertTrue($ops->can('product/add'));
        $this->assertTrue($ops->can('kyc/review'));
        $this->assertTrue($ops->can('member/referrer'));
        $this->assertFalse($ops->can('bank_transaction/match'));
        $this->assertFalse($ops->can('affiliate_commission/edit'));
        $this->assertFalse($ops->can('audit_log'));

        $accountant = $this->member('Kế toán');
        $this->assertTrue($accountant->can('bank_transaction/match'));
        $this->assertTrue($accountant->can('withdrawal/paid'));
        $this->assertTrue($accountant->can('commission/export'));
        $this->assertTrue($accountant->can('order'));
        $this->assertFalse($accountant->can('order/cancel'));
        $this->assertTrue($accountant->can('order/confirm_payment'));
        $this->assertFalse($accountant->can('kyc/view_document'));

        $cs = $this->member('CSKH');
        $this->assertTrue($cs->can('order/status'));
        $this->assertTrue($cs->can('member/lock'));
        $this->assertFalse($cs->can('member/referrer'));
        $this->assertFalse($cs->can('bank_transaction'));
        $this->assertFalse($cs->can('withdrawal'));

        $this->assertTrue($this->member('Super admin')->can('audit_log'));
    }

    public function test_role_menu_and_routes_are_blocked_by_permission(): void
    {
        $this->seed(RoleSeeder::class);
        $cs = $this->member('CSKH');

        $this->actingAs($cs)->get('/backend')->assertOk()
            ->assertDontSee(route('backend.audit-logs.index'), false)
            ->assertDontSee(route('backend.groups.index'), false);
        $this->actingAs($cs)->get('/backend/audit-log')->assertForbidden();
        $this->actingAs($cs)->get('/backend/groups')->assertForbidden();
    }

    public function test_reseeding_does_not_overwrite_customised_roles(): void
    {
        $this->seed(RoleSeeder::class);
        Group::where('name', 'CSKH')->sole()->update(['permission_data' => ['backend_access']]);

        $this->seed(RoleSeeder::class);

        $this->assertSame(4, Group::count());
        $this->assertSame(['backend_access'], Group::where('name', 'CSKH')->value('permission_data'));
    }
}
