<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 3 – Thành viên trong trang quản trị (A10): danh sách, cây F1 / F2, khóa / mở, sửa người giới thiệu có lý do.
 * Quyền theo RoleSeeder: Vận hành (xem, khóa, sửa người giới thiệu), CSKH (xem, khóa).
 */
class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $role)->value('id')]);
    }

    public function test_list_search_tabs_and_level_filter(): void
    {
        $ops = $this->staff('Vận hành');
        $agent = Level::where('name', 'Đại lý')->value('id');
        $a = Member::factory()->create(['full_name' => 'Nguyễn Văn An', 'phone' => '0911000001', 'referral_code' => 'ANAN123']);
        Member::factory()->create(['full_name' => 'Trần Bình', 'referrer_id' => $a->id, 'level_id' => $agent, 'cumulative_amount' => 16_000_000])->forceFill(['activated_at' => now()])->save();
        Member::factory()->locked()->create(['full_name' => 'Lê Khóa']);

        $this->actingAs($ops)->get('/backend')->assertSee(route('backend.members.index'), false);
        $this->actingAs($ops)->get('/backend/member')->assertOk()
            ->assertSee('Nguyễn Văn An')->assertSee('ANAN123')->assertSee('Trần Bình')->assertSee('16.000.000 đ')
            ->assertSee('Đã kích hoạt')->assertSee('Đang khóa')->assertSeeInOrder(['Tất cả', '3', 'Đã kích hoạt', '1', 'Chưa kích hoạt', '2', 'Đang khóa', '1']);

        $this->actingAs($ops)->get('/backend/member?q=binh')->assertDontSee('Nguyễn Văn An'); // không dấu không khớp có dấu là bình thường
        $this->actingAs($ops)->get('/backend/member?q=Bình')->assertSee('Trần Bình')->assertDontSee('Lê Khóa');
        $this->actingAs($ops)->get('/backend/member?q=%2B84+911+000+001')->assertSee('Nguyễn Văn An')->assertDontSee('Trần Bình'); // SĐT dạng +84
        $this->actingAs($ops)->get('/backend/member?q=anan123')->assertSee('Nguyễn Văn An');
        $this->actingAs($ops)->get('/backend/member?tab=locked')->assertSee('Lê Khóa')->assertDontSee('Trần Bình');
        $this->actingAs($ops)->get('/backend/member?level_id='.$agent)->assertSee('Trần Bình')->assertDontSee('Lê Khóa')->assertSee('Cấp: Đại lý');
        $this->actingAs($ops)->get('/backend/member?level_id=abc&tab=x&q[]=1')->assertOk();
    }

    public function test_detail_shows_f1_f2_tree(): void
    {
        $ops = $this->staff('Vận hành');
        $top = Member::factory()->create(['full_name' => 'Tuyến Trên']);
        $member = Member::factory()->create(['full_name' => 'Chính Mình', 'referrer_id' => $top->id]);
        $f1a = Member::factory()->create(['full_name' => 'Con Một', 'referrer_id' => $member->id]);
        $f1b = Member::factory()->create(['full_name' => 'Con Hai', 'referrer_id' => $member->id]);
        Member::factory()->create(['full_name' => 'Cháu Một', 'referrer_id' => $f1a->id]);
        Member::factory()->create(['full_name' => 'Cháu Hai', 'referrer_id' => $f1a->id]);
        Member::factory()->create(['full_name' => 'Chắt', 'referrer_id' => Member::where('full_name', 'Cháu Một')->value('id')]);

        $this->actingAs($ops)->get(route('backend.members.show', $member))->assertOk()
            ->assertSee('Tuyến Trên')->assertSee('2 F1 · 2 F2')
            ->assertSee('data-f1="'.$f1a->id.'"', false)->assertSee('data-f1="'.$f1b->id.'"', false)
            ->assertSee('data-expand="f2-'.$f1a->id.'"', false)->assertDontSee('data-expand="f2-'.$f1b->id.'"', false)
            ->assertSee('Cháu Một')->assertSee('Cháu Hai')->assertDontSee('Chắt'); // chỉ 2 tầng
        $this->actingAs($ops)->get('/backend/member/99999')->assertNotFound();
    }

    public function test_lock_and_unlock(): void
    {
        $cs = $this->staff('CSKH');
        $member = Member::factory()->create();

        $this->actingAs($cs)->get(route('backend.members.show', $member))->assertSee('Khóa tài khoản')->assertDontSee('id="referrer-form"', false);
        $this->actingAs($cs)->from(route('backend.members.show', $member))->post(route('backend.members.lock', $member), ['action' => 'lock', 'reason' => 'Nghi gian lận'])
            ->assertRedirect(route('backend.members.show', $member))->assertSessionHas('success');
        $this->assertTrue($member->fresh()->isLocked());
        $log = AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->where('action', 'updated')->sole();
        $this->assertSame([$cs->id, 'Khóa tài khoản. Lý do: Nghi gian lận', ['status' => 'locked']], [$log->user_id, $log->description, $log->changes['new']]);

        $this->actingAs($cs)->post(route('backend.members.lock', $member), ['action' => 'lock'])->assertSessionHas('error');
        $this->actingAs($cs)->post(route('backend.members.lock', $member), ['action' => 'unlock'])->assertSessionHas('success');
        $this->assertFalse($member->fresh()->isLocked());
        $this->actingAs($cs)->post(route('backend.members.lock', $member), ['action' => 'xoa'])->assertSessionHasErrors('action');

        // Kế toán không có quyền thành viên
        $this->actingAs($this->staff('Kế toán'))->get('/backend/member')->assertForbidden();
        $this->actingAs($this->staff('Kế toán'))->post(route('backend.members.lock', $member), ['action' => 'lock'])->assertForbidden();
    }

    public function test_change_referrer(): void
    {
        $ops = $this->staff('Vận hành');
        $old = Member::factory()->create(['full_name' => 'Người Cũ', 'referral_code' => 'CU12345']);
        $new = Member::factory()->create(['full_name' => 'Người Mới', 'referral_code' => 'MOI1234']);
        $member = Member::factory()->create(['referrer_id' => $old->id, 'referral_code' => 'MINH123']);
        $child = Member::factory()->create(['referrer_id' => $member->id, 'referral_code' => 'CON1234']);
        $grandchild = Member::factory()->create(['referrer_id' => $child->id, 'referral_code' => 'CHAU123']);
        Member::factory()->locked()->create(['referral_code' => 'KHOA123']);
        $url = route('backend.members.referrer', $member);

        foreach ([
            [['referral_code' => 'MOI1234', 'reason' => ''], 'reason'],
            [['referral_code' => 'KHONGCO', 'reason' => 'Nhập nhầm mã'], 'referral_code'],
            [['referral_code' => 'MINH123', 'reason' => 'Nhập nhầm mã'], 'referral_code'],
            [['referral_code' => 'chau123', 'reason' => 'Nhập nhầm mã'], 'referral_code'], // tuyến dưới (F2) → vòng
            [['referral_code' => 'KHOA123', 'reason' => 'Nhập nhầm mã'], 'referral_code'],
            [['referral_code' => 'CU12345', 'reason' => 'Nhập nhầm mã'], 'referral_code'],
        ] as [$data, $field]) {
            $this->actingAs($ops)->put($url, $data)->assertSessionHasErrors($field);
        }
        $this->assertSame($old->id, $member->fresh()->referrer_id);

        $this->actingAs($ops)->put($url, ['referral_code' => 'moi1234', 'reason' => 'Khách nhập nhầm mã khi đăng ký'])
            ->assertRedirect(route('backend.members.show', $member))->assertSessionHas('success', 'Đã đổi người giới thiệu thành Người Mới (MOI1234).');
        $this->assertSame($new->id, $member->fresh()->referrer_id);
        $log = AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->where('action', 'updated')->sole();
        $this->assertSame('Sửa người giới thiệu. Lý do: Khách nhập nhầm mã khi đăng ký', $log->description);
        $this->assertSame(['Người Cũ (CU12345)', 'Người Mới (MOI1234)'], [$log->changes['old']['referrer'], $log->changes['new']['referrer']]);
        $this->actingAs($ops)->get(route('backend.members.show', $member))->assertSee('Sửa người giới thiệu. Lý do: Khách nhập nhầm mã khi đăng ký');

        // Bỏ người giới thiệu
        $this->actingAs($ops)->put($url, ['referral_code' => '', 'reason' => 'Theo yêu cầu công ty'])->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->referrer_id);

        // CSKH không có quyền sửa người giới thiệu
        $this->actingAs($this->staff('CSKH'))->put($url, ['referral_code' => 'MOI1234', 'reason' => 'Thử quyền'])->assertForbidden();
        $this->assertSame([$member->id, $child->id], [$child->fresh()->referrer_id, $grandchild->fresh()->referrer_id]);
    }

    public function test_manual_activation(): void
    {
        $ops = $this->staff('Vận hành');
        $member = Member::factory()->create();
        $url = route('backend.members.activate', $member);
        $show = route('backend.members.show', $member);

        $this->actingAs($ops)->get($show)->assertSee('id="activate-form"', false)->assertSee('lên cấp Nhân viên');
        $this->actingAs($ops)->from($show)->post($url, ['_form' => 'activate', 'reason' => ''])->assertSessionHasErrors(['reason' => 'Nhập lý do kích hoạt.']);
        // Lỗi chỉ hiện ở form kích hoạt, không lẫn sang form đổi người giới thiệu
        $html = $this->actingAs($ops)->from($show)->followingRedirects()->post($url, ['_form' => 'activate', 'reason' => 'abc'])->getContent();
        $this->assertSame(1, substr_count($html, 'Lý do quá ngắn'));
        $this->assertNull($member->fresh()->activated_at);

        $this->actingAs($ops)->from($show)->post($url, ['_form' => 'activate', 'reason' => 'Khách đã mua tại văn phòng'])
            ->assertRedirect($show)->assertSessionHas('success', "Đã kích hoạt tài khoản {$member->full_name} (cấp Nhân viên).");
        $member->refresh();
        $this->assertTrue($member->isActivated());
        $this->assertSame(['Nhân viên', 0], [$member->level->name, $member->cumulative_amount]);
        $log = AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->where('action', 'updated')->sole();
        $this->assertSame([$ops->id, 'Kích hoạt tài khoản thủ công. Lý do: Khách đã mua tại văn phòng', 'Nhân viên'],
            [$log->user_id, $log->description, $log->changes['new']['level']]);

        // Đã kích hoạt: không còn form, gửi lại báo lỗi; danh sách vào tab Đã kích hoạt
        $this->actingAs($ops)->get($show)->assertDontSee('id="activate-form"', false);
        $this->actingAs($ops)->post($url, ['reason' => 'Kích hoạt lại'])->assertSessionHas('error', 'Tài khoản đã được kích hoạt từ trước.');
        $this->actingAs($ops)->get('/backend/member?tab=activated')->assertSee($member->full_name);

        // Thành viên đã ở cấp cao hơn cấp gốc: giữ nguyên cấp
        $agent = Member::factory()->create(['level_id' => Level::where('name', 'Đại lý')->value('id')]);
        $this->actingAs($ops)->post(route('backend.members.activate', $agent), ['reason' => 'Chuyển từ hệ thống cũ']);
        $this->assertSame('Đại lý', $agent->fresh()->level->name);

        // CSKH không có quyền kích hoạt
        $other = Member::factory()->create();
        $this->actingAs($this->staff('CSKH'))->get(route('backend.members.show', $other))->assertDontSee('id="activate-form"', false);
        $this->actingAs($this->staff('CSKH'))->post(route('backend.members.activate', $other), ['reason' => 'Thử quyền nè'])->assertForbidden();
        $this->assertNull($other->fresh()->activated_at);
    }

    public function test_downline_ids(): void
    {
        $a = Member::factory()->create();
        $b = Member::factory()->create(['referrer_id' => $a->id]);
        $c = Member::factory()->create(['referrer_id' => $b->id]);
        $d = Member::factory()->create(['referrer_id' => $c->id]);
        Member::factory()->create();

        $this->assertEqualsCanonicalizing([$b->id, $c->id, $d->id], $a->downlineIds());
        $this->assertSame([], $d->downlineIds());
    }
}
