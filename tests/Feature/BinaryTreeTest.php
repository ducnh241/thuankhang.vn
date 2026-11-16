<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use App\Services\BinaryTree;
use App\Support\AffiliateSettings;
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
        $expected = Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray();

        // Dữ liệu cũ: chưa ai có vị trí
        Member::query()->update(['parent_id' => null, 'position' => null, 'placed_at' => null]);
        $this->artisan('binary:place-members')->expectsOutputToContain('Đã xếp 3 thành viên vào cây nhị phân.')->assertSuccessful();
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
            ->expectsOutputToContain('Đã xếp 4 thành viên vào cây nhị phân.')->assertSuccessful();
        $this->assertSame($expected, Member::where('id', '<', $new->id)->orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());
    }

    public function test_configured_root_and_members_without_referrer_form_their_own_tree(): void
    {
        // Tài khoản thử đăng ký trước (không có người giới thiệu) + 2 tài khoản thử dưới nó; rồi mới tới ông tổng thật
        $test = $this->member('Tài khoản thử');
        $t2 = $this->member('Thử 2', $test);
        $trung = $this->member('Nguyen văn trung');
        $ha = $this->member('Hà', $trung);
        $huyen = $this->member('Huyền', $trung);

        // Chưa cấu hình: ông tổng tạm là người không có người giới thiệu đăng ký sớm nhất
        $this->assertSame($test->id, BinaryTree::root()->id);
        // Cấu hình ông tổng là Trung
        AffiliateSettings::save(['root_member_code' => $trung->referral_code]);
        $this->assertSame($trung->id, BinaryTree::root()->id);
        $this->assertTrue($trung->fresh()->isRoot());
        $this->assertFalse($test->fresh()->isRoot());

        // Hai cây tách biệt: tài khoản thử không nằm trong cây của ông tổng và ngược lại; cả hai gốc đều không có parent
        $this->assertSame([null, null], [$trung->fresh()->parent_id, $test->fresh()->parent_id]);
        $this->assertSame([['Nguyen văn trung', 'L'], ['Nguyen văn trung', 'R'], ['Tài khoản thử', 'L']], [$this->slot($ha), $this->slot($huyen), $this->slot($t2)]);
        $this->assertEqualsCanonicalizing([$ha->id, $huyen->id], $trung->branchIds());
        $this->assertSame([$t2->id], $test->branchIds());

        // Quản trị: Trung là "Ông tổng", tài khoản thử là "Gốc cây riêng"
        $this->seed(RoleSeeder::class);
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $trung))->assertSeeInOrder(['Cây nhị phân', 'Ông tổng', 'gốc của cây']);
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $test))->assertSeeInOrder(['Cây nhị phân', 'Gốc cây riêng', 'không thuộc cây của ông tổng'])->assertDontSee('Ông tổng</span>', false);

        // Tham số chung: mã ông tổng phải là thành viên có thật và không có người giới thiệu
        $root = User::factory()->superAdmin()->create();
        $url = '/backend/affiliate/settings';
        $valid = collect(AffiliateSettings::all())->map(fn ($value, $key) => AffiliateSettings::display($key, $value))->except('kpi_pay_day')->all();
        $this->actingAs($root, 'web')->get($url)->assertOk()->assertSee('name="root_member_code" value="'.$trung->referral_code.'"', false);
        $this->actingAs($root, 'web')->from($url)->put($url, [...$valid, 'root_member_code' => 'KHONGCO'])->assertSessionHasErrors(['root_member_code' => 'Không có thành viên nào có mã giới thiệu này.']);
        $this->actingAs($root, 'web')->from($url)->put($url, [...$valid, 'root_member_code' => $ha->referral_code])
            ->assertSessionHasErrors(['root_member_code' => 'Thành viên này có người giới thiệu nên không thể là ông tổng (ông tổng đứng đầu cây).']);
        $this->actingAs($root, 'web')->put($url, [...$valid, 'root_member_code' => strtolower($test->referral_code)])->assertSessionHasNoErrors();
        $this->assertSame($test->id, BinaryTree::root()->id);
    }

    public function test_live_data_migration_makes_trung_the_root_and_rebuilds_positions(): void
    {
        // Dữ liệu như bản dump từ live: 3 tài khoản thử (1 → 2, 3) rồi Trung (mã U4SNA69) và tuyến dưới của Trung
        $make = fn (string $name, ?Member $referrer = null, array $extra = []) => Member::factory()->create(['full_name' => $name, 'referrer_id' => $referrer?->id, ...$extra])->fresh();
        $test = $make('Nguyễn Hữu Đức', null, ['referral_code' => '3VBJ366']);
        $t2 = $make('Nguyễn Hữu Đức 2', $test);
        $t3 = $make('Mỹ Anh', $test);
        $trung = $make('Nguyen văn trung', null, ['referral_code' => 'U4SNA69']);
        $ha = $make('ĐinhThuHa', $trung);
        $chung = $make('Chung Nguyễn', $ha);
        $huyen = $make('Lê Ngọc Huyền', $trung);
        $yen = $make('Trần Thị Yến', $trung);
        $hoa = $make('DinhGiaHoa', $huyen);
        $quoc = $make('Nguyễn văn quốc', $huyen);
        $mai = $make('Thanh Mai', $huyen);
        $nguyet = $make('Minh Nguyệt', $trung);
        // Giả lập trạng thái sai trước khi chuyển: Trung bị xếp dưới tài khoản thử, chưa cấu hình ông tổng
        $trung->forceFill(['parent_id' => $t2->id, 'position' => 'L'])->saveQuietly();

        (require database_path('migrations/2026_11_22_000001_set_binary_root_and_rebuild.php'))->up();

        $this->assertSame('U4SNA69', AffiliateSettings::get('root_member_code'));
        $this->assertSame($trung->id, BinaryTree::root()->id);
        $this->assertNull($trung->fresh()->parent_id);
        // Cây của ông tổng Trung
        $this->assertSame([
            'ĐinhThuHa' => ['Nguyen văn trung', 'L'], 'Chung Nguyễn' => ['ĐinhThuHa', 'L'], 'Lê Ngọc Huyền' => ['Nguyen văn trung', 'R'],
            'Trần Thị Yến' => ['Lê Ngọc Huyền', 'L'], 'DinhGiaHoa' => ['Lê Ngọc Huyền', 'R'], 'Nguyễn văn quốc' => ['Trần Thị Yến', 'L'],
            'Thanh Mai' => ['DinhGiaHoa', 'L'], 'Minh Nguyệt' => ['ĐinhThuHa', 'R'],
        ], collect([$ha, $chung, $huyen, $yen, $hoa, $quoc, $mai, $nguyet])->mapWithKeys(fn ($m) => [$m->full_name => $this->slot($m)])->all());
        $this->assertCount(8, $trung->branchIds());
        // Tài khoản thử: cây riêng, không lẫn vào cây của Trung
        $this->assertSame([null, ['Nguyễn Hữu Đức', 'L'], ['Nguyễn Hữu Đức', 'R']], [$test->fresh()->parent_id, $this->slot($t2), $this->slot($t3)]);
        $this->assertEmpty(array_intersect($trung->branchIds(), [$test->id, $t2->id, $t3->id]));

        // Chạy lại migration: không đổi gì (đã cấu hình ông tổng, xếp lại cho cùng kết quả)
        $before = Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray();
        (require database_path('migrations/2026_11_22_000001_set_binary_root_and_rebuild.php'))->up();
        $this->assertSame($before, Member::orderBy('id')->get(['id', 'parent_id', 'position'])->toArray());
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
