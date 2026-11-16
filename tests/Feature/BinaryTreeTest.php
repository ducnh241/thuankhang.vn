<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use App\Services\BinaryTree;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cây nhị phân của thành viên (BinaryTree): ông tổng là gốc; người mới bắt đầu từ người giới thiệu, vào nhánh còn trống
 * (trái trước), hai nhánh đã có người thì đi xuống nhánh yếu hơn — doanh số tháng thấp hơn, bằng nhau thì ít người hơn,
 * vẫn bằng thì nhánh trái. Vị trí gán một lần, cố định.
 */
class BinaryTreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
    }

    private function member(string $name, ?Member $referrer = null): Member
    {
        return Member::factory()->create(['full_name' => $name, 'referrer_id' => $referrer?->id])->fresh();
    }

    /** Đơn đã thanh toán của thành viên (mặc định trong tháng này). */
    private function paid(Member $member, int $goods, $paidAt = null): Order
    {
        return Order::factory()->status(Order::STATUS_PAID)->create(['member_id' => $member->id, 'goods_amount' => $goods, 'paid_at' => $paidAt ?? now()]);
    }

    /** [tên người đứng trên, nhánh] của một thành viên. */
    private function slot(Member $member): array
    {
        $member->refresh();

        return [$member->parent?->full_name, $member->position];
    }

    public function test_root_then_two_legs_then_weaker_leg_by_monthly_sales(): void
    {
        // Thành viên đầu tiên là ông tổng; hai người kế tiếp vào nhánh trái rồi nhánh phải
        $tong = $this->member('Tổng');
        $this->assertSame([null, null, true], [$tong->parent_id, $tong->position, $tong->isRoot()]);
        $ha = $this->member('Hà', $tong);
        $huyen = $this->member('Huyền', $tong);
        $this->assertSame([['Tổng', 'L'], ['Tổng', 'R']], [$this->slot($ha), $this->slot($huyen)]);
        $this->assertFalse($ha->isRoot());

        // Nhánh Hà có doanh số tháng này, nhánh Huyền chưa có → nhánh Huyền yếu hơn: F1 thứ 3 của Tổng xuống dưới Huyền
        $this->paid($ha, 2_000_000);
        $tuyet = $this->member('Tuyết', $tong);
        $this->assertSame(['Huyền', 'L'], $this->slot($tuyet));
        $this->assertSame($tong->id, $tuyet->referrer_id); // người giới thiệu vẫn là Tổng (hoa hồng F1 theo cây giới thiệu)

        // Doanh số nhánh tính cả tuyến dưới trong nhánh: Tuyết mua 5tr → nhánh Huyền (5tr) mạnh hơn nhánh Hà (2tr)
        $this->paid($tuyet, 5_000_000);
        $legs = BinaryTree::legs($tong);
        $this->assertSame([1, 2_000_000, 2, 5_000_000, 'L'], [$legs['L']['count'], $legs['L']['sales'], $legs['R']['count'], $legs['R']['sales'], $legs['weaker']]);
        $this->assertSame(['Hà', 'L'], $this->slot($this->member('Người thứ tư', $tong)));

        // Đơn tháng trước, đơn chưa thanh toán, đơn đã hủy không tính vào doanh số tháng
        $this->paid($ha, 50_000_000, now()->subMonthNoOverflow()->startOfMonth());
        Order::factory()->create(['member_id' => $ha->id, 'goods_amount' => 50_000_000]);
        Order::factory()->create(['member_id' => $ha->id, 'goods_amount' => 50_000_000, 'status' => Order::STATUS_CANCELLED]);
        $this->assertSame(2_000_000, BinaryTree::legs($tong)['L']['sales']);
    }

    public function test_ties_go_to_the_smaller_leg_then_left_and_descend_until_a_free_slot(): void
    {
        $tong = $this->member('Tổng');
        $a = $this->member('A', $tong); // trái
        $b = $this->member('B', $tong); // phải
        // Chưa ai có doanh số, hai nhánh bằng người → nhánh trái; trong nhánh A còn trống → dưới A, bên trái
        $c = $this->member('C', $tong);
        $this->assertSame(['A', 'L'], $this->slot($c));
        // Giờ nhánh trái 2 người, nhánh phải 1 người → nhánh phải (ít người hơn)
        $d = $this->member('D', $tong);
        $this->assertSame(['B', 'L'], $this->slot($d));
        // Lại bằng nhau (2 – 2) → trái: A đã có con trái, còn trống bên phải
        $this->assertSame(['A', 'R'], $this->slot($this->member('E', $tong)));
        $this->assertSame(['B', 'R'], $this->slot($this->member('F', $tong)));
        // Hai nhánh đều đủ 3 người, A và B đều kín → xuống tiếp nhánh yếu hơn của A (bằng nhau → trái: dưới C)
        $this->assertSame(['C', 'L'], $this->slot($this->member('G', $tong)));

        // Người do B giới thiệu luôn nằm trong nhánh của B, kể cả khi B đã kín hai bên
        $h = $this->member('H', $b);
        $this->assertSame(['D', 'L'], $this->slot($h));
        $this->assertContains($h->id, $b->branchIds());
        $this->assertNotContains($h->id, $a->branchIds());
        // Mỗi chỗ chỉ một người
        $this->assertSame(Member::whereNotNull('parent_id')->count(), Member::whereNotNull('parent_id')->get()->unique(fn ($m) => $m->parent_id.$m->position)->count());
    }

    public function test_positions_are_fixed_and_everyone_gets_exactly_one_slot(): void
    {
        $tong = $this->member('Tổng');
        $a = $this->member('A', $tong);
        $b = $this->member('B', $tong);
        $c = $this->member('C', $a);
        $before = Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray();

        // Doanh số đổi về sau, hay quản trị đổi người giới thiệu: vị trí đã xếp không đổi
        $this->paid($b, 9_000_000);
        $this->seed(RoleSeeder::class);
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->actingAs($ops, 'web')->put(route('backend.members.referrer', $c), ['referral_code' => $b->referral_code, 'reason' => 'Khách nhập nhầm mã'])->assertSessionHas('success');
        $this->assertSame($b->id, $c->fresh()->referrer_id);
        BinaryTree::place($c->fresh()); // gọi lại cũng không xếp lại
        $this->assertSame($before, Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());

        // 40 thành viên do nhiều người giới thiệu: ai cũng có đúng một chỗ, không chỗ nào hai người, không ai quá 2 con
        $pool = collect([$tong, $a, $b, $c]);
        foreach (range(1, 40) as $i) {
            $pool->push($this->member("TV $i", $pool->random()));
        }
        $placed = Member::whereNotNull('parent_id')->get();
        $this->assertSame(Member::count() - 1, $placed->count());
        $this->assertSame($placed->count(), $placed->unique(fn ($m) => $m->parent_id.'-'.$m->position)->count());
        $this->assertLessThanOrEqual(2, $placed->groupBy('parent_id')->map->count()->max());
        $this->assertSame(Member::count() - 1, count($tong->branchIds())); // cả hệ thống nằm dưới ông tổng
        // Người do X giới thiệu luôn nằm trong nhánh của X (trừ C — đã bị đổi người giới thiệu sau khi xếp)
        foreach (Member::whereNotNull('referrer_id')->where('id', '!=', $c->id)->get() as $member) {
            $this->assertContains($member->id, BinaryTree::branchIds($member->referrer_id), $member->full_name);
        }
    }

    public function test_registration_places_new_member_and_account_shows_legs(): void
    {
        $tong = $this->member('Tổng');
        $ha = $this->member('Đinh Thu Hà', $tong);

        $this->post('/dang-ky', ['full_name' => 'Nguyễn Thị Huyền', 'phone' => '0912345678', 'email' => '', 'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123', 'referral_code' => $ha->referral_code])->assertRedirect(route('member.account'));
        $this->post('/dang-xuat'); // đăng ký xong là đăng nhập thật; đăng xuất để các bước sau dùng actingAs
        $huyen = Member::where('phone', '0912345678')->sole();
        $this->assertSame(['Đinh Thu Hà', 'L'], $this->slot($huyen));
        $this->assertNotNull($huyen->placed_at);
        $yen = $this->member('Yến', $huyen);
        $this->member('A', $yen);
        $this->member('B', $yen);

        // Huyền: 1 F1 (Yến), nhánh 3 người (Yến + A + B) — đều ở nhánh trái
        $this->actingAs($huyen->fresh(), 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('data-f1-count>1<', false)->assertSee('data-team-count>3<', false)->assertSee('Nhánh trái 3 · Nhánh phải 0');
        $this->get('/tai-khoan/thanh-vien')->assertSee('data-team-total>3<', false);
        // Tổng: nhánh trái 5 người (Hà, Huyền, Yến, A, B), nhánh phải trống
        auth('member')->logout(); // đổi sang tài khoản khác: bỏ phiên của Huyền trước (phiên cũ giữ dấu mật khẩu của Huyền)
        $this->flushSession();
        $this->actingAs($tong->fresh(), 'member')->get('/tai-khoan')->assertOk()->assertSee('data-team-count>5<', false)->assertSee('Nhánh trái 5 · Nhánh phải 0');
    }

    public function test_command_places_existing_members_in_registration_order(): void
    {
        $tong = $this->member('Tổng');
        $a = $this->member('A', $tong);
        $b = $this->member('B', $tong);
        $c = $this->member('C', $a);
        $d = $this->member('D'); // không có người giới thiệu (đăng ký từ thời chưa bắt buộc mã) → xếp từ ông tổng
        $expected = Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray();
        $this->assertSame('Tổng', $d->fresh()->parent->parent->full_name);

        // Dữ liệu cũ: chưa ai có vị trí
        Member::query()->update(['parent_id' => null, 'position' => null, 'placed_at' => null]);
        $this->artisan('binary:place-members')->expectsOutputToContain('Đã xếp 4 thành viên vào cây nhị phân.')->assertSuccessful();
        $this->assertSame($expected, Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());
        $this->artisan('binary:place-members')->expectsOutputToContain('Không có thành viên nào cần xếp.')->assertSuccessful();

        // Chưa chạy lệnh mà có người đăng ký mới: người cũ tự được xếp trước, đúng thứ tự
        Member::query()->update(['parent_id' => null, 'position' => null, 'placed_at' => null]);
        $new = $this->member('Mới', $c);
        $this->assertSame($expected, Member::where('id', '<', $new->id)->orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());
        $this->assertSame(['C', 'L'], $this->slot($new));

        // --rebuild: hỏi lại trước khi xóa; đồng ý thì xếp lại từ đầu, kết quả như cũ
        $this->artisan('binary:place-members --rebuild')->expectsConfirmation('Xóa toàn bộ vị trí trong cây nhị phân và xếp lại từ đầu?', 'no')->assertSuccessful();
        $this->artisan('binary:place-members --rebuild')->expectsConfirmation('Xóa toàn bộ vị trí trong cây nhị phân và xếp lại từ đầu?', 'yes')
            ->expectsOutputToContain('Đã xếp 5 thành viên vào cây nhị phân.')->assertSuccessful();
        $this->assertSame($expected, Member::where('id', '<', $new->id)->orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());
    }

    public function test_admin_member_page_shows_binary_position_and_legs(): void
    {
        $this->seed(RoleSeeder::class);
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $tong = $this->member('Tổng Công Ty');
        $ha = $this->member('Đinh Thu Hà', $tong);
        $huyen = $this->member('Nguyễn Thị Huyền', $tong);
        $this->paid($ha, 2_000_000);
        $tuyet = $this->member('Tuyết', $tong); // xuống nhánh Huyền (yếu hơn)

        $this->actingAs($ops, 'web')->get(route('backend.members.show', $tong))->assertOk()
            ->assertSeeInOrder(['Cây nhị phân', 'Ông tổng', 'Nhánh trái', '1 thành viên', 'Đinh Thu Hà', '2.000.000 đ', 'Nhánh phải', 'Yếu hơn', '2 thành viên', 'Nguyễn Thị Huyền', '0 đ'])
            ->assertSee('sẽ vào <strong>nhánh phải</strong> (nhánh yếu hơn', false);
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $tuyet))->assertOk()
            ->assertSeeInOrder(['Cây nhị phân', 'nhánh trái của', 'Nguyễn Thị Huyền', 'Còn trống', 'Còn trống'])
            ->assertSee('sẽ vào <strong>nhánh trái</strong> (nhánh còn trống)', false)->assertDontSee('Ông tổng');
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $huyen))->assertSee('nhánh phải của')->assertSee('sẽ vào <strong>nhánh phải</strong> (nhánh còn trống)', false);
    }
}
