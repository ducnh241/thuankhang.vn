<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sửa thông tin tài khoản thành viên (/tai-khoan/thong-tin): đổi họ tên, tải / đổi ảnh mã QR nhận tiền; ô mã QR (không bắt
 * buộc) ở form KYC; quản trị xem mã QR ở trang chi tiết thành viên và hồ sơ KYC. Ảnh QR lưu mã hóa trên disk riêng tư.
 */
class MemberProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake(MemberKyc::DISK);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function qr(string $name = 'qr.png', int $size = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $size, $size);
    }

    private function update(Member $member, array $data)
    {
        return $this->actingAs($member, 'member')->from('/tai-khoan/thong-tin')->put('/tai-khoan/thong-tin', $data);
    }

    public function test_member_edits_name_and_uploads_qr_from_the_profile_page(): void
    {
        $this->get('/tai-khoan/thong-tin')->assertRedirect('/dang-nhap');
        $this->put('/tai-khoan/thong-tin')->assertRedirect('/dang-nhap');
        $this->get('/tai-khoan/ma-qr')->assertRedirect('/dang-nhap');

        $member = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $kyc = MemberKyc::factory()->create(['member_id' => $member->id, 'full_name' => 'Nguyễn Văn An']);

        // Trang tài khoản: nút Sửa + chưa có mã QR
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertOk()
            ->assertSeeInOrder(['Thông tin tài khoản', 'href="'.route('member.profile.edit').'"', 'Sửa', 'Mã QR nhận tiền', 'Chưa có', 'thêm mã QR'], false);
        $this->get('/tai-khoan/ma-qr')->assertNotFound();
        $this->get('/tai-khoan/thong-tin')->assertOk()
            ->assertSeeInOrder(['Sửa thông tin tài khoản', 'name="full_name"', 'value="Nguyễn Văn An"', $member->phone, 'Mã QR nhận tiền', 'name="qr_image"', 'Chưa có mã QR.', 'Lưu thay đổi'], false);

        // Đổi họ tên (gọn khoảng trắng) + tải mã QR
        $file = $this->qr();
        $raw = file_get_contents($file->getRealPath());
        $this->update($member, ['full_name' => '  Nguyễn   Văn  Bình ', 'qr_image' => $file])
            ->assertRedirect(route('member.account'))->assertSessionHas('success', 'Đã cập nhật họ tên và mã QR nhận tiền.');
        $member->refresh();
        $this->assertSame('Nguyễn Văn Bình', $member->full_name);
        $this->assertNotNull($member->qr_uploaded_at);
        // Ảnh lưu mã hóa trên disk riêng tư, trong thư mục của thành viên
        $this->assertMatchesRegularExpression('#^kyc/'.$member->id.'/[A-Za-z0-9]{40}\.enc$#', $member->qr_image);
        Storage::disk(MemberKyc::DISK)->assertExists($member->qr_image);
        $this->assertStringNotContainsString(substr($raw, 0, 8), Storage::disk(MemberKyc::DISK)->get($member->qr_image));
        // Họ tên trong hồ sơ KYC (theo CCCD) không đổi theo
        $this->assertSame('Nguyễn Văn An', $kyc->fresh()->full_name);
        // Nhật ký: một dòng đổi tên, một dòng tải mã QR
        $logs = AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->where('action', 'updated')->orderBy('id')->pluck('description')->all();
        $this->assertSame(["Thành viên {$member->referral_code} tự đổi họ tên: Nguyễn Văn An → Nguyễn Văn Bình",
            "Thành viên Nguyễn Văn Bình ({$member->referral_code}) tải lên mã QR nhận tiền ở trang thông tin tài khoản"], $logs);

        // Xem lại ảnh của chính mình: đúng nội dung đã tải, không cho lưu đệm
        $response = $this->actingAs($member, 'member')->get('/tai-khoan/ma-qr')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($raw, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/tai-khoan')->assertSee('Xin chào, Nguyễn Văn Bình')->assertSee('<img src="'.route('member.qr').'?v='.$member->qr_uploaded_at->timestamp.'"', false);
        $this->get('/tai-khoan/thong-tin')->assertSee('Đã có mã QR (tải lên '.now()->format('d/m/Y').').');

        // Đổi mã QR: ảnh cũ bị xóa
        $oldPath = $member->qr_image;
        $this->update($member, ['full_name' => 'Nguyễn Văn Bình', 'qr_image' => $this->qr('moi.jpg')])->assertSessionHas('success', 'Đã cập nhật mã QR nhận tiền.');
        $member->refresh();
        $this->assertNotSame($oldPath, $member->qr_image);
        Storage::disk(MemberKyc::DISK)->assertMissing($oldPath);
        $this->assertSame("Thành viên Nguyễn Văn Bình ({$member->referral_code}) đổi mã QR nhận tiền ở trang thông tin tài khoản",
            AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->latest('id')->value('description'));

        // Không đổi gì: giữ nguyên mã QR, không ghi nhật ký
        $count = AuditLog::count();
        $this->update($member, ['full_name' => 'Nguyễn Văn Bình'])->assertSessionHas('success', 'Không có thay đổi nào.');
        $this->assertSame([$member->qr_image, $count], [$member->fresh()->qr_image, AuditLog::count()]);

        // Người khác không xem được mã QR của mình
        $other = Member::factory()->create();
        auth('member')->logout();
        $this->flushSession();
        $this->actingAs($other, 'member')->get('/tai-khoan/ma-qr')->assertNotFound();
    }

    public function test_profile_update_validates_name_and_qr_image(): void
    {
        $member = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);

        $this->update($member, ['full_name' => '   '])->assertSessionHasErrors(['full_name' => 'Nhập họ tên.']);
        $this->update($member, ['full_name' => str_repeat('a', 101)])->assertSessionHasErrors(['full_name' => 'Họ tên tối đa 100 ký tự.']);
        $this->update($member, ['full_name' => ['x']])->assertSessionHasErrors('full_name');
        $name = ['full_name' => 'Nguyễn Văn An'];
        $this->update($member, [...$name, 'qr_image' => UploadedFile::fake()->create('qr.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors(['qr_image' => 'Ảnh mã QR phải là ảnh JPG, PNG hoặc WEBP.']);
        $this->update($member, [...$name, 'qr_image' => UploadedFile::fake()->createWithContent('qr.jpg', '<?php echo 1;')])->assertSessionHasErrors('qr_image');
        $this->update($member, [...$name, 'qr_image' => $this->qr('nho.png', 100)])
            ->assertSessionHasErrors(['qr_image' => 'Ảnh mã QR quá nhỏ, không quét được. Hãy chọn ảnh rõ hơn.']);
        $this->update($member, [...$name, 'qr_image' => $this->qr('to.png')->size(MemberKyc::maxKilobytes() + 1)])->assertSessionHasErrors('qr_image');
        $this->update($member, [...$name, 'qr_image' => 'khong-phai-file'])->assertSessionHasErrors('qr_image');

        $member->refresh();
        $this->assertSame(['Nguyễn Văn An', null], [$member->full_name, $member->qr_image]);
        $this->assertSame([], Storage::disk(MemberKyc::DISK)->allFiles());
        // Lỗi hiện lại trên form
        $this->actingAs($member, 'member')->followingRedirects()->from('/tai-khoan/thong-tin')
            ->put('/tai-khoan/thong-tin', [...$name, 'qr_image' => $this->qr('nho.png', 100)])
            ->assertOk()->assertSee('Ảnh mã QR quá nhỏ, không quét được. Hãy chọn ảnh rõ hơn.');
    }

    public function test_kyc_form_takes_an_optional_qr_image(): void
    {
        $kycData = fn (array $extra = []) => [
            'full_name' => 'Nguyễn Văn An', 'id_number' => fake()->unique()->numerify('0010########'), 'birth_date' => '20/05/1990',
            'bank_code' => 'VCB', 'bank_account_number' => fake()->unique()->numerify('00110########'), 'bank_account_name' => 'nguyen van an',
            'front_image' => UploadedFile::fake()->image('truoc.jpg', 800, 500), 'back_image' => UploadedFile::fake()->image('sau.png', 800, 500), ...$extra,
        ];
        $submit = fn (Member $member, array $extra = []) => $this->actingAs($member, 'member')->from('/tai-khoan/kyc')->post('/tai-khoan/kyc', $kycData($extra));
        $switch = function () {
            auth('member')->logout();
            $this->flushSession();
        };

        // Form có ô mã QR, ghi rõ không bắt buộc
        $a = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $this->actingAs($a, 'member')->get('/tai-khoan/kyc')->assertOk()
            ->assertSeeInOrder(['Tên chủ tài khoản', 'name="qr_image"', 'Mã QR nhận tiền', '(không bắt buộc)', 'Ảnh căn cước công dân'], false);

        // Gửi hồ sơ không kèm mã QR: vẫn được
        $submit($a)->assertRedirect(route('member.kyc'))->assertSessionHas('success');
        $this->assertSame([1, null], [MemberKyc::count(), $a->fresh()->qr_image]);
        $this->actingAs($a->fresh(), 'member')->get('/tai-khoan/kyc')->assertSeeInOrder(['Thông tin đã gửi', 'Mã QR nhận tiền', 'Chưa có.', 'Thêm mã QR']);

        // Gửi kèm mã QR: lưu theo thành viên, xem lại được ở trang KYC
        $switch();
        $b = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $submit($b, ['qr_image' => $this->qr()])->assertRedirect(route('member.kyc'))->assertSessionHas('success');
        $b->refresh();
        $this->assertTrue($b->hasQr());
        Storage::disk(MemberKyc::DISK)->assertExists($b->qr_image);
        $this->assertSame("Thành viên Nguyễn Văn An ({$b->referral_code}) tải lên mã QR nhận tiền ở form KYC",
            AuditLog::where('auditable_type', Member::class)->where('auditable_id', $b->id)->latest('id')->value('description'));
        $this->get('/tai-khoan/kyc')->assertSeeInOrder(['Thông tin đã gửi', '<img src="'.route('member.qr'), 'Mã QR nhận tiền', 'Đã tải lên '.now()->format('d/m/Y'), 'Đổi mã QR'], false);

        // Gửi lần hai khi hồ sơ đang chờ duyệt: bị chặn, mã QR không bị thay
        $path = $b->qr_image;
        $submit($b, ['qr_image' => $this->qr('khac.png')])->assertSessionHas('error');
        $this->assertSame($path, $b->fresh()->qr_image);

        // Ảnh mã QR không hợp lệ: báo lỗi, không tạo hồ sơ
        $switch();
        $c = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $submit($c, ['qr_image' => UploadedFile::fake()->create('qr.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors(['qr_image' => 'Ảnh mã QR phải là ảnh JPG, PNG hoặc WEBP.']);
        $this->assertSame([2, null], [MemberKyc::count(), $c->fresh()->qr_image]);
    }

    public function test_admin_sees_member_qr_and_is_warned_when_it_changed_after_kyc_approval(): void
    {
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $content = User::factory()->create(['group_id' => Group::factory()->create(['permission_data' => ['backend_access', 'order']])->id]);
        $member = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $kyc = MemberKyc::factory()->create(['member_id' => $member->id]);

        // Chưa có mã QR
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $member))->assertOk()->assertSeeInOrder(['Mã QR nhận tiền', 'Chưa có']);
        $this->get(route('backend.kyc.show', $kyc))->assertOk()->assertSeeInOrder(['Tài khoản ngân hàng', 'Mã QR nhận tiền', 'Chưa tải lên']);
        $this->get(route('backend.members.qr', $member))->assertNotFound();

        // Thành viên tải mã QR
        $file = $this->qr();
        $raw = file_get_contents($file->getRealPath());
        $this->update($member, ['full_name' => 'Nguyễn Văn An', 'qr_image' => $file])->assertSessionHas('success');
        $member->refresh();
        $src = route('backend.members.qr', $member).'?v='.$member->qr_uploaded_at->timestamp;

        $this->actingAs($ops, 'web')->get(route('backend.members.show', $member))->assertOk()
            ->assertSee('<img src="'.$src.'" alt="Mã QR nhận tiền"', false)->assertSee('Tải lên '.$member->qr_uploaded_at->format('d/m/Y H:i'))->assertDontSee('Đổi sau khi duyệt KYC');
        $this->get(route('backend.kyc.show', $kyc))->assertOk()->assertSee('<img src="'.$src.'" alt="Mã QR nhận tiền"', false);
        $response = $this->get(route('backend.members.qr', $member))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($raw, $response->getContent());

        // Kế toán (quyền commission) xem được để chi hoa hồng; người không có quyền liên quan thì không
        $this->actingAs($accountant, 'web')->get(route('backend.members.qr', $member))->assertOk();
        $this->actingAs($content, 'web')->get(route('backend.members.qr', $member))->assertForbidden();
        auth('web')->logout();
        $this->flushSession();
        $this->get(route('backend.members.qr', $member))->assertRedirect();

        // KYC đã duyệt rồi thành viên đổi mã QR → cảnh báo để đối chiếu lại
        $kyc->forceFill(['status' => MemberKyc::STATUS_APPROVED, 'reviewed_at' => now()->subDay()])->save();
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $member))->assertSee('Đổi sau khi duyệt KYC');
        $this->get(route('backend.kyc.show', $kyc))->assertSee('Đổi sau khi duyệt KYC');
        // Mã QR tải trước khi duyệt: không cảnh báo
        $kyc->forceFill(['reviewed_at' => now()->addHour()])->save();
        $this->get(route('backend.members.show', $member))->assertDontSee('Đổi sau khi duyệt KYC');
    }
}
