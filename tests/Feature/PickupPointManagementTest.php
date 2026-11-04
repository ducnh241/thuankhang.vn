<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\PickupPoint;
use App\Models\User;
use App\Support\AffiliateSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 4 – VP tỉnh / điểm nhận hàng (A7): CRUD, gán tài khoản VP, bật / tắt, giới hạn số điểm mỗi tỉnh.
 * Nghiệm thu: chỉ điểm đang bật mới hiện ở checkout (scope available()).
 */
class PickupPointManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function ops(): User
    {
        return User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
    }

    private function vpMember(array $data = []): Member
    {
        return Member::factory()->create(['level_id' => Level::where('name', 'VP tỉnh cấp 1')->value('id'), ...$data]);
    }

    private function form(array $data = []): array
    {
        return [
            'name' => 'VP Thuận Khang Hà Nội', 'province' => 'ha-noi', 'address' => '12 Láng Hạ, Đống Đa', 'phone' => '024 3888 9999',
            'opening_hours' => '8:00 – 17:30, Thứ 2 – Thứ 7', 'owner_member_id' => '', 'active' => '1', 'sort_order' => '0', ...$data,
        ];
    }

    public function test_create_edit_and_delete(): void
    {
        $ops = $this->ops();
        $vp = $this->vpMember(['full_name' => 'Chủ VP Hà Nội']);

        $this->actingAs($ops)->get('/backend')->assertSee(route('backend.pickup-points.index'), false);
        $this->actingAs($ops)->get('/backend/pickup-point/create')->assertOk()->assertSee('Chủ VP Hà Nội')->assertSee('TP. Hồ Chí Minh');

        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => (string) $vp->id]))->assertSessionHasNoErrors();
        $point = PickupPoint::sole();
        $this->assertSame(['Hà Nội', $vp->id, true], [$point->provinceName(), $point->owner_member_id, $point->active]);
        $this->assertSame($point->id, $vp->ownedPickupPoint->id);
        $this->assertTrue(AuditLog::where('auditable_type', PickupPoint::class)->where('action', 'created')->where('user_id', $ops->id)->exists());

        $this->actingAs($ops)->get('/backend/pickup-point')->assertOk()
            ->assertSee('VP Thuận Khang Hà Nội')->assertSee('12 Láng Hạ, Đống Đa')->assertSee('Chủ VP Hà Nội')->assertSee('Đang bật');

        $this->actingAs($ops)->put(route('backend.pickup-points.update', $point), $this->form(['active' => '0', 'owner_member_id' => '', 'phone' => '']))
            ->assertRedirect(route('backend.pickup-points.edit', $point))->assertSessionHas('success');
        $point->refresh();
        $this->assertSame([false, null, null], [$point->active, $point->owner_member_id, $point->phone]);

        $this->actingAs($ops)->delete(route('backend.pickup-points.destroy', $point))->assertRedirect(route('backend.pickup-points.index'));
        $this->assertSoftDeleted($point);
    }

    public function test_only_active_points_are_available_for_checkout(): void
    {
        PickupPoint::factory()->create(['name' => 'Bật B', 'province' => 'ha-noi', 'sort_order' => 2]);
        PickupPoint::factory()->create(['name' => 'Bật A', 'province' => 'ha-noi', 'sort_order' => 1]);
        PickupPoint::factory()->create(['name' => 'Tắt', 'province' => 'ha-noi', 'active' => false]);
        PickupPoint::factory()->create(['name' => 'Đã xóa', 'province' => 'ha-noi'])->delete();

        $this->assertSame(['Bật A', 'Bật B'], PickupPoint::available()->pluck('name')->all());
    }

    public function test_validation_and_owner_rules(): void
    {
        $ops = $this->ops();
        $vp = $this->vpMember();
        $notVp = Member::factory()->create(['level_id' => Level::where('name', 'Đại lý')->value('id')]);
        $lockedVp = $this->vpMember(['status' => Member::STATUS_LOCKED]);
        PickupPoint::factory()->create(['owner_member_id' => $vp->id]);

        foreach ([
            'name' => ['name' => ''], 'province' => ['province' => 'sai-tinh'], 'address' => ['address' => ''],
            'phone' => ['phone' => 'abc'],
        ] as $field => $data) {
            $this->actingAs($ops)->post('/backend/pickup-point', $this->form($data))->assertSessionHasErrors($field);
        }
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => (string) $vp->id]))
            ->assertSessionHasErrors(['owner_member_id' => 'Thành viên này đã là chủ một điểm nhận hàng khác.']);
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => (string) $notVp->id]))
            ->assertSessionHasErrors(['owner_member_id' => 'Chỉ gán được thành viên đang ở cấp VP tỉnh (hiện: Đại lý).']);
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => (string) $lockedVp->id]))->assertSessionHasErrors('owner_member_id');
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => '99999']))->assertSessionHasErrors('owner_member_id');
        $this->assertSame(1, PickupPoint::count());

        // Xóa điểm có chủ: chủ được gỡ, gán được cho điểm mới
        $this->actingAs($ops)->delete(route('backend.pickup-points.destroy', PickupPoint::where('owner_member_id', $vp->id)->first()));
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['owner_member_id' => (string) $vp->id]))->assertSessionHasNoErrors();
        $this->assertSame($vp->id, PickupPoint::latest('id')->value('owner_member_id'));
    }

    public function test_max_active_points_per_province(): void
    {
        $ops = $this->ops();
        AffiliateSettings::save(['max_vp_per_province' => 2]);
        PickupPoint::factory()->count(2)->create(['province' => 'da-nang']);
        $off = PickupPoint::factory()->create(['province' => 'da-nang', 'active' => false]);

        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['province' => 'da-nang']))
            ->assertSessionHasErrors(['province' => 'Đà Nẵng đã có 2 điểm đang bật (tối đa 2 — Tham số chung). Tắt bớt điểm khác hoặc lưu điểm này ở trạng thái tắt.']);
        // Lưu ở trạng thái tắt, hoặc tỉnh khác: được
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['province' => 'da-nang', 'active' => '0']))->assertSessionHasNoErrors();
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['province' => 'hue']))->assertSessionHasNoErrors();
        // Bật lại điểm đang tắt khi tỉnh đã đủ: bị chặn; sửa điểm đang bật (không tính chính nó): được
        $this->actingAs($ops)->put(route('backend.pickup-points.update', $off), $this->form(['province' => 'da-nang']))->assertSessionHasErrors('province');
        $on = PickupPoint::where('province', 'da-nang')->where('active', true)->first();
        $this->actingAs($ops)->put(route('backend.pickup-points.update', $on), $this->form(['province' => 'da-nang', 'name' => 'Đổi tên']))->assertSessionHasNoErrors();
        $this->actingAs($ops)->get('/backend/pickup-point?province=da-nang')->assertSee('Đủ')->assertSee('Tỉnh: Đà Nẵng');

        // Không giới hạn
        AffiliateSettings::save(['max_vp_per_province' => null]);
        $this->actingAs($ops)->post('/backend/pickup-point', $this->form(['province' => 'da-nang']))->assertSessionHasNoErrors();
    }

    public function test_permissions_and_list_filters(): void
    {
        PickupPoint::factory()->create(['name' => 'Điểm Hà Nội', 'province' => 'ha-noi']);
        PickupPoint::factory()->create(['name' => 'Điểm Cần Thơ', 'province' => 'can-tho', 'active' => false]);
        $root = User::factory()->superAdmin()->create();

        $this->actingAs($root)->get('/backend/pickup-point?tab=inactive')->assertSee('Điểm Cần Thơ')->assertDontSee('Điểm Hà Nội');
        $this->actingAs($root)->get('/backend/pickup-point?province=ha-noi')->assertSee('Điểm Hà Nội')->assertDontSee('Điểm Cần Thơ');
        $this->actingAs($root)->get('/backend/pickup-point?q=cần')->assertSee('Điểm Cần Thơ');
        $this->actingAs($root)->get('/backend/pickup-point?province=x&tab=y&q[]=1')->assertOk();

        foreach (['CSKH', 'Kế toán'] as $role) {
            $user = User::factory()->create(['group_id' => Group::where('name', $role)->value('id')]);
            $this->actingAs($user)->get('/backend/pickup-point')->assertForbidden();
            $this->actingAs($user)->post('/backend/pickup-point', $this->form())->assertForbidden();
        }
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'pickup_point')->create()->id]);
        $this->actingAs($viewer)->get('/backend/pickup-point')->assertOk()->assertDontSee(route('backend.pickup-points.create'), false);
        $this->actingAs($viewer)->get(route('backend.pickup-points.edit', PickupPoint::first()))->assertForbidden();
    }

    public function test_provinces_config_has_34_units(): void
    {
        $this->assertCount(34, PickupPoint::provinces());
        $this->assertSame('TP. Hồ Chí Minh', PickupPoint::provinces()['ho-chi-minh']);
    }
}
