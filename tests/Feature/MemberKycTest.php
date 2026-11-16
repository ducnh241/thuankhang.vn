<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step 9 – KYC: thành viên gửi hồ sơ ở /tai-khoan/kyc (S10: thông tin CCCD, tài khoản ngân hàng, ảnh 2 mặt CCCD), quản trị
 * duyệt / không duyệt ở /backend/kyc (A11). Trạng thái: chờ duyệt → đã duyệt | không được duyệt (gửi lại được).
 * Quyền theo RoleSeeder: Vận hành (kyc, kyc/view_document, kyc/review); CSKH không vào được.
 */
class MemberKycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake(MemberKyc::DISK);
    }

    private function staff(string $role): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $role)->value('id')]);
    }

    private function member(array $attributes = []): Member
    {
        return Member::factory()->create(['full_name' => 'Nguyễn Văn An', ...$attributes]);
    }

    private function valid(array $overrides = []): array
    {
        return [
            'full_name' => 'Nguyễn Văn An', 'id_number' => '001090012345', 'birth_date' => '1990-05-20',
            'bank_code' => 'VCB', 'bank_account_number' => '0011001234567', 'bank_account_name' => 'nguyen van an',
            'front_image' => UploadedFile::fake()->image('truoc.jpg', 800, 500),
            'back_image' => UploadedFile::fake()->image('sau.png', 800, 500),
            ...$overrides,
        ];
    }

    private function submit(Member $member, array $overrides = [])
    {
        return $this->actingAs($member, 'member')->from('/tai-khoan/kyc')->post('/tai-khoan/kyc', $this->valid($overrides));
    }

    public function test_member_sees_kyc_entry_and_form(): void
    {
        $this->get('/tai-khoan/kyc')->assertRedirect('/dang-nhap');
        $this->post('/tai-khoan/kyc')->assertRedirect('/dang-nhap');

        $member = $this->member();
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('Xác minh tài khoản (KYC)')->assertSee('Chưa xác minh')->assertSee('Xác minh ngay')->assertSee(route('member.kyc'), false);
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc')->assertOk()
            ->assertSee('action="'.route('member.kyc.store').'" enctype="multipart/form-data"', false)
            ->assertSee('name="full_name" value="Nguyễn Văn An"', false) // điền sẵn họ tên tài khoản
            ->assertSee('<option value="VCB" >Vietcombank</option>', false)
            ->assertSee('name="front_image" type="file" accept="image/jpeg,image/png,image/webp"', false)
            ->assertSee('name="back_image" type="file"', false)
            ->assertSee('Gửi hồ sơ')->assertDontSee('data-kyc-status', false);
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc/anh/front')->assertNotFound(); // chưa có hồ sơ
    }

    public function test_member_submits_kyc_and_data_is_stored_encrypted(): void
    {
        $member = $this->member();
        $front = UploadedFile::fake()->image('truoc.jpg', 800, 500);
        $plain = file_get_contents($front->getRealPath());

        $this->submit($member, ['front_image' => $front, 'id_number' => '0010 9001 2345', 'bank_account_number' => '0011.001.234567'])
            ->assertRedirect('/tai-khoan/kyc')->assertSessionHas('success');

        $kyc = MemberKyc::sole();
        $this->assertSame([$member->id, 'pending', 'Nguyễn Văn An', '001090012345', '1990-05-20', 'VCB', '0011001234567', 'NGUYEN VAN AN'],
            [$kyc->member_id, $kyc->status, $kyc->full_name, $kyc->id_number, $kyc->birth_date->format('Y-m-d'), $kyc->bank_code, $kyc->bank_account_number, $kyc->bank_account_name]);
        $this->assertNotNull($kyc->submitted_at);
        // Số CCCD mã hóa trong DB, chỉ có bản băm để tra trùng
        $raw = DB::table('member_kycs')->first();
        $this->assertStringNotContainsString('001090012345', $raw->id_number);
        $this->assertSame(MemberKyc::hashIdNumber('001090012345'), $raw->id_number_hash);
        // Ảnh nằm ở disk riêng tư, đã mã hóa; giải mã ra đúng ảnh gốc
        $this->assertMatchesRegularExpression('#^kyc/'.$member->id.'/[A-Za-z0-9]{40}\.enc$#', $kyc->front_image);
        Storage::disk('local')->assertExists([$kyc->front_image, $kyc->back_image]);
        $stored = Storage::disk('local')->get($kyc->front_image);
        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, Crypt::decryptString($stored));
        Storage::disk('public')->assertMissing($kyc->front_image);
        // Nhật ký: có dòng gửi hồ sơ, không có số CCCD / số tài khoản
        $log = AuditLog::where('auditable_type', MemberKyc::class)->sole();
        $this->assertSame(['created', $kyc->id, "Thành viên Nguyễn Văn An ({$member->referral_code}) gửi hồ sơ KYC", null],
            [$log->action, $log->auditable_id, $log->description, $log->changes]);
        $this->assertStringNotContainsString('001090012345', json_encode($log->toArray()));

        // Trang KYC: chờ duyệt, thông tin che bớt, không còn form; ảnh chỉ chính chủ xem được
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc')->assertOk()
            ->assertSee('data-kyc-status="pending"', false)->assertSee('Hồ sơ đang chờ duyệt')
            ->assertSee('••••••••2345')->assertDontSee('001090012345')->assertSee('•••••••••4567')->assertDontSee('0011001234567')
            ->assertSee('Vietcombank')->assertSee('NGUYEN VAN AN')->assertDontSee('data-kyc-form', false);
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertSee('Chờ duyệt')->assertSee('Xem hồ sơ');
        $response = $this->actingAs($member, 'member')->get('/tai-khoan/kyc/anh/front')->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($plain, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc/anh/back')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc/anh/x')->assertNotFound();
        $this->actingAs($this->member(), 'member')->get('/tai-khoan/kyc/anh/front')->assertNotFound();

        // Đang chờ duyệt: không gửi đè được
        $this->submit($member, ['id_number' => '001090099999'])->assertRedirect('/tai-khoan/kyc')->assertSessionHas('error');
        $this->assertSame('001090012345', MemberKyc::sole()->id_number);
    }

    public function test_kyc_validation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $member = $this->member();
        $errors = fn (array $overrides) => $this->submit($member, $overrides)->assertRedirect('/tai-khoan/kyc');

        $this->actingAs($member, 'member')->from('/tai-khoan/kyc')->post('/tai-khoan/kyc', [])
            ->assertSessionHasErrors(['full_name', 'id_number', 'birth_date', 'bank_code', 'bank_account_number', 'bank_account_name', 'front_image', 'back_image']);
        $errors(['id_number' => '00109001234'])->assertSessionHasErrors(['id_number' => 'Số CCCD gồm đúng 12 chữ số.']);
        $errors(['id_number' => '00109001234a'])->assertSessionHasErrors('id_number');
        $errors(['birth_date' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('birth_date');
        $errors(['birth_date' => '20/05/1990'])->assertSessionHasErrors('birth_date');
        $errors(['bank_code' => 'XYZ'])->assertSessionHasErrors(['bank_code' => 'Chọn ngân hàng trong danh sách.']);
        $errors(['bank_account_number' => '12AB'])->assertSessionHasErrors('bank_account_number');
        // Tên chủ tài khoản phải trùng họ tên trên CCCD (so không dấu, không phân biệt hoa thường)
        $errors(['bank_account_name' => 'NGUYEN VAN BINH'])->assertSessionHasErrors(['bank_account_name' => 'Tên chủ tài khoản phải trùng với họ tên trên CCCD (tài khoản chính chủ).']);
        // Ảnh: thiếu, sai loại, giả ảnh, quá nhỏ, quá lớn
        $errors(['back_image' => null])->assertSessionHasErrors(['back_image' => 'Chọn ảnh mặt sau CCCD.']);
        $errors(['front_image' => UploadedFile::fake()->create('cccd.pdf', 100, 'application/pdf')])->assertSessionHasErrors(['front_image' => 'Ảnh mặt trước CCCD phải là ảnh JPG, PNG hoặc WEBP.']);
        $errors(['front_image' => UploadedFile::fake()->createWithContent('cccd.jpg', '<?php echo 1;')])->assertSessionHasErrors('front_image');
        $errors(['front_image' => UploadedFile::fake()->image('nho.jpg', 100, 60)])->assertSessionHasErrors('front_image');
        $errors(['front_image' => UploadedFile::fake()->image('to.jpg', 800, 500)->size(MemberKyc::maxKilobytes() + 1)])->assertSessionHasErrors('front_image');
        $errors(['full_name' => ['x'], 'id_number' => ['x'], 'bank_code' => ['x']])->assertSessionHasErrors(['full_name', 'id_number', 'bank_code']);

        $this->assertSame(0, MemberKyc::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_one_id_number_and_one_bank_account_per_member(): void
    {
        $other = MemberKyc::factory()->create(['id_number' => '001090012345', 'id_number_hash' => MemberKyc::hashIdNumber('001090012345'), 'bank_code' => 'VCB', 'bank_account_number' => '0011001234567']);
        $member = $this->member();

        // Trùng với hồ sơ đang chờ duyệt / đã duyệt của người khác → chặn
        foreach ([MemberKyc::STATUS_PENDING, MemberKyc::STATUS_APPROVED] as $status) {
            $other->update(['status' => $status]);
            $this->submit($member)->assertSessionHasErrors([
                'id_number' => 'Số CCCD này đã được dùng để xác minh một tài khoản khác.',
                'bank_account_number' => 'Số tài khoản này đã được dùng để xác minh một tài khoản khác.',
            ]);
            // Cùng số tài khoản nhưng ngân hàng khác thì không tính là trùng
            $this->submit($member, ['bank_code' => 'TCB'])->assertSessionHasErrors('id_number')->assertSessionDoesntHaveErrors('bank_account_number');
        }
        $this->assertSame(1, MemberKyc::count());

        // Hồ sơ của người kia bị từ chối thì không còn giữ CCCD / số tài khoản
        $other->update(['status' => MemberKyc::STATUS_REJECTED]);
        $this->submit($member)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('pending', $member->kyc()->value('status'));
    }

    public function test_rejected_kyc_can_be_resubmitted(): void
    {
        $member = $this->member();
        $this->submit($member);
        $kyc = MemberKyc::sole();
        $old = [$kyc->front_image, $kyc->back_image];
        $kyc->update(['status' => 'rejected', 'reject_reason' => 'Ảnh mặt sau bị mờ', 'reviewed_at' => now(), 'reviewed_by' => $this->staff('Vận hành')->id]);

        // Thấy lý do + form điền sẵn thông tin cũ (ảnh phải chọn lại)
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertSee('Không được duyệt')->assertSee('Ảnh mặt sau bị mờ')->assertSee('Gửi lại hồ sơ');
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc')->assertOk()
            ->assertSee('data-kyc-status="rejected"', false)->assertSee('Ảnh mặt sau bị mờ')
            ->assertSee('name="id_number" value="001090012345"', false)->assertSee('name="birth_date" type="date" value="1990-05-20"', false)
            ->assertSee('<option value="VCB" selected>Vietcombank</option>', false)->assertSee('Gửi lại hồ sơ');

        $this->submit($member, ['bank_code' => 'TCB', 'bank_account_number' => '19031234567890'])->assertRedirect('/tai-khoan/kyc')->assertSessionHas('success');
        $kyc = MemberKyc::sole();
        $this->assertSame(['pending', null, null, null, 'TCB', '19031234567890'],
            [$kyc->status, $kyc->reject_reason, $kyc->reviewed_at, $kyc->reviewed_by, $kyc->bank_code, $kyc->bank_account_number]);
        // Ảnh cũ bị xóa, chỉ còn 2 ảnh mới
        Storage::disk('local')->assertMissing($old);
        $this->assertEqualsCanonicalizing([$kyc->front_image, $kyc->back_image], Storage::disk('local')->allFiles());
        $this->assertSame(['created', 'updated'], AuditLog::where('auditable_type', MemberKyc::class)->orderBy('id')->pluck('action')->all());
        $this->assertStringContainsString('gửi lại hồ sơ KYC', AuditLog::latest('id')->value('description'));
    }

    public function test_admin_queue_search_and_permissions(): void
    {
        $ops = $this->staff('Vận hành');
        $an = MemberKyc::factory()->create(['member_id' => $this->member(['phone' => '0911000001', 'referral_code' => 'ANAN123'])->id, 'submitted_at' => now()->subDay(),
            'id_number' => '001090012345', 'id_number_hash' => MemberKyc::hashIdNumber('001090012345'), 'bank_account_number' => '0011001234567']);
        MemberKyc::factory()->create(['member_id' => $this->member(['full_name' => 'Trần Bình'])->id, 'full_name' => 'Trần Bình', 'submitted_at' => now()]);
        MemberKyc::factory()->approved()->create(['member_id' => $this->member(['full_name' => 'Lê Duyệt'])->id, 'full_name' => 'Lê Duyệt']);
        MemberKyc::factory()->rejected()->create(['member_id' => $this->member(['full_name' => 'Phạm Chối'])->id, 'full_name' => 'Phạm Chối']);

        // Menu + tab mặc định "Chờ duyệt": gửi trước nằm trên
        $this->actingAs($ops)->get('/backend')->assertSee(route('backend.kyc.index'), false);
        $this->actingAs($ops)->get('/backend/kyc')->assertOk()
            ->assertSeeInOrder(['Chờ duyệt', '2', 'Đã duyệt', '1', 'Không được duyệt', '1', 'Tất cả', '4'])
            ->assertSeeInOrder(['Nguyễn Văn An', 'Trần Bình'])->assertDontSee('Lê Duyệt')->assertDontSee('Phạm Chối')
            ->assertSee('0011001234567')->assertDontSee('001090012345'); // danh sách không hiện số CCCD
        $this->actingAs($ops)->get('/backend/kyc?tab=approved')->assertSee('Lê Duyệt')->assertDontSee('Trần Bình');
        $this->actingAs($ops)->get('/backend/kyc?tab=rejected')->assertSee('Phạm Chối')->assertDontSee('Lê Duyệt');
        $this->actingAs($ops)->get('/backend/kyc?tab=all')->assertSee('Lê Duyệt')->assertSee('Phạm Chối')->assertSee('Trần Bình');
        // Tìm: tên, SĐT, mã giới thiệu, số tài khoản, đủ 12 số CCCD
        foreach (['Văn An', '0911000001', 'anan123', '00110012345', '001090012345', '0010 9001 2345'] as $q) {
            $this->actingAs($ops)->get('/backend/kyc?tab=all&q='.urlencode($q))->assertOk()->assertSee($an->member->phone)->assertDontSee('Trần Bình');
        }
        $this->actingAs($ops)->get('/backend/kyc?tab=x&q[]=1&page=abc')->assertOk();
        $this->actingAs($ops)->get('/backend/kyc?tab=approved&q=khong-co')->assertOk()->assertSee('Không tìm thấy hồ sơ nào');

        // CSKH không có quyền KYC; chưa đăng nhập về trang đăng nhập
        $cskh = $this->staff('CSKH');
        $this->actingAs($cskh)->get('/backend')->assertDontSee(route('backend.kyc.index'), false);
        $this->actingAs($cskh)->get('/backend/kyc')->assertForbidden();
        $this->actingAs($cskh)->get('/backend/kyc/'.$an->id)->assertForbidden();
        $this->actingAs($cskh)->post('/backend/kyc/'.$an->id.'/approve')->assertForbidden();
        $this->actingAs($cskh)->post('/backend/kyc/'.$an->id.'/reject', ['reason' => 'Ảnh mờ quá'])->assertForbidden();
        $this->assertSame('pending', $an->fresh()->status);
        auth()->logout();
        $this->get('/backend/kyc')->assertRedirect('/backend/login');
    }

    public function test_admin_detail_and_document_permission(): void
    {
        $member = $this->member();
        $this->submit($member, ['bank_account_name' => 'NGUYEN VAN AN']);
        $kyc = MemberKyc::sole();
        $plain = Crypt::decryptString(Storage::disk('local')->get($kyc->front_image));
        auth('member')->logout(); // từ đây dùng guard quản trị (actingAs không kèm guard sẽ theo guard vừa dùng là "member")

        $ops = $this->staff('Vận hành');
        $this->actingAs($ops, 'web')->get('/backend/kyc/'.$kyc->id)->assertOk()
            ->assertSee('Nguyễn Văn An')->assertSee('001090012345')->assertSee('20/05/1990')->assertSee('Vietcombank')->assertSee('0011001234567')
            ->assertSee('Trùng họ tên CCCD')->assertSee(route('backend.kyc.image', [$kyc, 'front']), false)->assertSee(route('backend.kyc.image', [$kyc, 'back']), false)
            ->assertSee('id="kyc-approve-form"', false)->assertSee('id="kyc-reject-form"', false)
            ->assertSee('name="activate" value="1" checked', false) // Vận hành có member/activate, tài khoản chưa kích hoạt
            ->assertSee(route('backend.members.show', $member), false)->assertDontSee('data-kyc-duplicates', false)
            ->assertSee('gửi hồ sơ KYC');
        $response = $this->actingAs($ops, 'web')->get('/backend/kyc/'.$kyc->id.'/image/front')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($plain, $response->getContent());
        $this->actingAs($ops, 'web')->get('/backend/kyc/'.$kyc->id.'/image/x')->assertNotFound();
        $this->actingAs($ops, 'web')->get('/backend/kyc/999999')->assertNotFound();

        // Chỉ có quyền xem KYC (không xem ảnh, không duyệt): số CCCD che bớt, không có ảnh / form
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('kyc')->create()->id]);
        $this->actingAs($viewer, 'web')->get('/backend/kyc/'.$kyc->id)->assertOk()
            ->assertSee('••••••••2345')->assertDontSee('001090012345')->assertSee('Bạn không có quyền xem ảnh CCCD')
            ->assertDontSee('/image/front', false)->assertDontSee('id="kyc-approve-form"', false)->assertDontSee('id="kyc-reject-form"', false);
        $this->actingAs($viewer, 'web')->get('/backend/kyc/'.$kyc->id.'/image/front')->assertForbidden();
        $this->actingAs($viewer, 'web')->post('/backend/kyc/'.$kyc->id.'/approve')->assertForbidden();

        // Trang thành viên có dòng KYC + link hồ sơ; cảnh báo khi trùng với hồ sơ người khác
        $this->actingAs($ops, 'web')->get('/backend/member/'.$member->id)->assertSee('data-member-kyc', false)->assertSee('Chờ duyệt')->assertSee(route('backend.kyc.show', $kyc), false);
        $this->actingAs($ops, 'web')->get('/backend/member/'.$this->member()->id)->assertSee('Chưa gửi hồ sơ');
        $dup = MemberKyc::factory()->create(['member_id' => $this->member(['full_name' => 'Người Trùng', 'referral_code' => 'TRUNG22'])->id,
            'id_number_hash' => $kyc->id_number_hash, 'bank_code' => 'VCB', 'bank_account_number' => '0011001234567']);
        $this->actingAs($ops, 'web')->get('/backend/kyc/'.$kyc->id)->assertSee('data-kyc-duplicates', false)->assertSee('Người Trùng (TRUNG22, chờ duyệt)');
        $this->assertSame('pending', $dup->status);
    }

    public function test_admin_approves_and_activates_account(): void
    {
        $ops = $this->staff('Vận hành');
        $member = $this->member();
        $kyc = MemberKyc::factory()->create(['member_id' => $member->id]);
        $this->assertFalse($member->isActivated());

        $this->actingAs($ops)->post('/backend/kyc/'.$kyc->id.'/approve', ['activate' => '1'])
            ->assertRedirect('/backend/kyc/'.$kyc->id)->assertSessionHas('success', 'Đã duyệt hồ sơ KYC của Nguyễn Văn An và kích hoạt tài khoản.');
        $kyc->refresh();
        $this->assertSame(['approved', $ops->id, null], [$kyc->status, $kyc->reviewed_by, $kyc->reject_reason]);
        $this->assertNotNull($kyc->reviewed_at);
        // Tài khoản được kích hoạt: lên cấp hưởng hoa hồng đầu tiên
        $member->refresh();
        $this->assertTrue($member->isActivated());
        $this->assertSame(Level::earning()->firstWhere('active', true)->id, $member->level_id);
        $this->assertSame("Duyệt hồ sơ KYC của Nguyễn Văn An ({$member->referral_code})", AuditLog::where('auditable_type', MemberKyc::class)->latest('id')->value('description'));
        $this->assertSame('Kích hoạt tài khoản khi duyệt hồ sơ KYC', AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->latest('id')->value('description'));

        // Đã xử lý rồi: không duyệt / từ chối lại được; trang hồ sơ không còn form
        $this->actingAs($ops)->from('/backend/kyc/'.$kyc->id)->post('/backend/kyc/'.$kyc->id.'/approve')->assertRedirect('/backend/kyc/'.$kyc->id)->assertSessionHas('error');
        $this->actingAs($ops)->from('/backend/kyc/'.$kyc->id)->post('/backend/kyc/'.$kyc->id.'/reject', ['reason' => 'Đổi ý rồi nhé'])->assertSessionHas('error');
        $this->assertSame('approved', $kyc->fresh()->status);
        $this->actingAs($ops)->get('/backend/kyc/'.$kyc->id)->assertSee('Đã duyệt')->assertSee($ops->name)->assertDontSee('id="kyc-approve-form"', false);
        // Thành viên thấy đã duyệt
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc')->assertSee('data-kyc-status="approved"', false)->assertSee('Tài khoản đã được xác minh')->assertDontSee('data-kyc-form', false);
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertSee('Đã duyệt');
    }

    public function test_approve_without_activation_and_duplicate_guard(): void
    {
        $ops = $this->staff('Vận hành');

        // Bỏ tick kích hoạt: chỉ duyệt hồ sơ
        $a = MemberKyc::factory()->create(['member_id' => $this->member()->id]);
        $this->actingAs($ops)->post('/backend/kyc/'.$a->id.'/approve')->assertSessionHas('success', 'Đã duyệt hồ sơ KYC của Nguyễn Văn An.');
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertFalse($a->member->fresh()->isActivated());

        // Có quyền duyệt nhưng không có member/activate: tick kích hoạt bị bỏ qua, form không có ô tick
        $reviewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('kyc', 'kyc/review')->create()->id]);
        $b = MemberKyc::factory()->create(['member_id' => $this->member()->id]);
        $this->actingAs($reviewer)->get('/backend/kyc/'.$b->id)->assertSee('id="kyc-approve-form"', false)->assertDontSee('name="activate"', false);
        $this->actingAs($reviewer)->post('/backend/kyc/'.$b->id.'/approve', ['activate' => '1'])->assertSessionHas('success');
        $this->assertSame('approved', $b->fresh()->status);
        $this->assertFalse($b->member->fresh()->isActivated());

        // Trùng CCCD / số tài khoản với hồ sơ ĐÃ DUYỆT của người khác: không duyệt được
        $c = MemberKyc::factory()->create(['member_id' => $this->member()->id, 'id_number_hash' => $a->id_number_hash]);
        $this->actingAs($ops)->from('/backend/kyc/'.$c->id)->post('/backend/kyc/'.$c->id.'/approve')
            ->assertRedirect('/backend/kyc/'.$c->id)->assertSessionHas('error', 'Không duyệt được: số CCCD này đã được duyệt cho một thành viên khác.');
        $d = MemberKyc::factory()->create(['member_id' => $this->member()->id, 'bank_code' => $a->bank_code, 'bank_account_number' => $a->bank_account_number]);
        $this->actingAs($ops)->from('/backend/kyc/'.$d->id)->post('/backend/kyc/'.$d->id.'/approve')
            ->assertSessionHas('error', 'Không duyệt được: số tài khoản này đã được duyệt cho một thành viên khác.');
        $this->assertSame(['pending', 'pending'], [$c->fresh()->status, $d->fresh()->status]);
    }

    public function test_admin_rejects_with_reason(): void
    {
        $ops = $this->staff('Vận hành');
        $member = $this->member();
        $kyc = MemberKyc::factory()->create(['member_id' => $member->id]);
        $url = '/backend/kyc/'.$kyc->id;

        $this->actingAs($ops)->from($url)->post($url.'/reject', ['_form' => 'reject'])->assertRedirect($url)->assertSessionHasErrors(['reason' => 'Nhập lý do không duyệt.']);
        $this->actingAs($ops)->from($url)->post($url.'/reject', ['_form' => 'reject', 'reason' => 'mờ'])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $kyc->fresh()->status);

        $this->actingAs($ops)->post($url.'/reject', ['_form' => 'reject', 'reason' => '  Ảnh mặt trước bị lóa, không đọc được số  '])->assertRedirect($url)->assertSessionHas('success');
        $kyc->refresh();
        $this->assertSame(['rejected', 'Ảnh mặt trước bị lóa, không đọc được số', $ops->id], [$kyc->status, $kyc->reject_reason, $kyc->reviewed_by]);
        $this->assertFalse($member->fresh()->isActivated());
        $this->assertStringContainsString('Không duyệt hồ sơ KYC của Nguyễn Văn An', AuditLog::where('auditable_type', MemberKyc::class)->latest('id')->value('description'));
        $this->actingAs($ops)->get($url)->assertSee('Không được duyệt. Lý do: Ảnh mặt trước bị lóa, không đọc được số')->assertDontSee('id="kyc-reject-form"', false);

        // Thành viên thấy lý do và form gửi lại
        $this->actingAs($member, 'member')->get('/tai-khoan/kyc')->assertSee('Hồ sơ không được duyệt')->assertSee('Ảnh mặt trước bị lóa, không đọc được số')->assertSee('data-kyc-form', false);
    }
}
