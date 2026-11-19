<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ảnh bill chuyển khoản: ở trang đơn hàng, thành viên BẮT BUỘC tải ảnh bill rồi mới báo "Tôi đã chuyển khoản" được. Ảnh lưu
 * mã hóa trên disk riêng tư; chủ đơn xem lại được, kế toán thấy ở trang đơn trong quản trị để đối chiếu.
 */
class OrderBillTest extends TestCase
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

    private function bill(string $name = 'bill.png', int $width = 600, int $height = 900): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function report(Member $member, Order $order, array $data = [])
    {
        return $this->actingAs($member, 'member')->from(route('member.orders.show', $order))->post(route('member.orders.transferred', $order), $data);
    }

    public function test_bill_image_is_required_before_reporting_the_transfer(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id]);

        // Trang đơn: ô tải bill bắt buộc nằm trong form "Tôi đã chuyển khoản"
        $this->actingAs($member, 'member')->get(route('member.orders.show', $order))->assertOk()
            ->assertSeeInOrder(['data-bill-form', 'name="bill"', 'required', 'Ảnh bill chuyển khoản', 'Bắt buộc có ảnh bill mới bấm được', 'data-bill-submit', 'Tôi đã chuyển khoản', 'Hủy đơn'], false);

        // Không có ảnh / ảnh không hợp lệ: không báo được, đơn vẫn là đơn mới
        $this->report($member, $order)->assertSessionHasErrors(['bill' => 'Tải ảnh bill chuyển khoản lên trước khi bấm “Tôi đã chuyển khoản”.']);
        $this->report($member, $order, ['bill' => ''])->assertSessionHasErrors('bill');
        $this->report($member, $order, ['bill' => 'khong-phai-file'])->assertSessionHasErrors('bill');
        $this->report($member, $order, ['bill' => UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors(['bill' => 'Ảnh bill phải là ảnh JPG, PNG hoặc WEBP.']);
        $this->report($member, $order, ['bill' => UploadedFile::fake()->createWithContent('bill.jpg', '<?php echo 1;')])->assertSessionHasErrors('bill');
        $this->report($member, $order, ['bill' => $this->bill('nho.png', 100, 100)])
            ->assertSessionHasErrors(['bill' => 'Ảnh bill quá nhỏ, không đọc được. Hãy chụp màn hình giao dịch rõ hơn.']);
        $this->report($member, $order, ['bill' => $this->bill()->size(MemberKyc::maxKilobytes() + 1)])->assertSessionHasErrors('bill');
        $order->refresh();
        $this->assertSame([Order::STATUS_NEW, null, null], [$order->status, $order->payment_bill, $order->confirm_requested_at]);
        $this->assertSame([], Storage::disk(MemberKyc::DISK)->allFiles());
        // Lỗi hiện ngay dưới ô ảnh
        $this->actingAs($member, 'member')->followingRedirects()->from(route('member.orders.show', $order))->post(route('member.orders.transferred', $order))
            ->assertOk()->assertSee('Tải ảnh bill chuyển khoản lên trước khi bấm “Tôi đã chuyển khoản”.');
    }

    public function test_member_reports_transfer_with_a_bill_and_can_view_it_again(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id]);
        $file = $this->bill();
        $raw = file_get_contents($file->getRealPath());

        $this->report($member, $order, ['bill' => $file])->assertSessionHas('success', 'Cảm ơn bạn! Chúng tôi sẽ đối chiếu và xác nhận thanh toán sớm nhất.');
        $order->refresh();
        $this->assertSame(Order::STATUS_AWAITING, $order->status);
        $this->assertNotNull($order->confirm_requested_at);
        // Lưu mã hóa trong thư mục riêng của đơn
        $this->assertMatchesRegularExpression('#^bills/'.$order->id.'/[A-Za-z0-9]{40}\.enc$#', $order->payment_bill);
        Storage::disk(MemberKyc::DISK)->assertExists($order->payment_bill);
        $this->assertStringNotContainsString(substr($raw, 0, 8), Storage::disk(MemberKyc::DISK)->get($order->payment_bill));
        $this->assertSame("Đơn {$order->code}: thành viên báo đã chuyển khoản, kèm ảnh bill",
            AuditLog::where('auditable_type', Order::class)->where('auditable_id', $order->id)->latest('id')->value('description'));

        // Trang đơn: đang chờ xác nhận, có ảnh bill đã gửi; xem lại đúng ảnh đã tải, không cho lưu đệm
        $this->actingAs($member, 'member')->get(route('member.orders.show', $order))->assertOk()
            ->assertSeeInOrder(['data-awaiting', 'Đang chờ xác nhận thanh toán', 'href="'.route('member.orders.bill', $order).'"', 'Ảnh bill bạn đã gửi'], false)
            ->assertDontSee('data-bill-form', false);
        $response = $this->get(route('member.orders.bill', $order))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($raw, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // Báo lần nữa khi đơn đang chờ xác nhận: bị từ chối, ảnh không bị thay
        $path = $order->payment_bill;
        $this->report($member, $order, ['bill' => $this->bill('khac.jpg')])->assertSessionHas('error', 'Đơn không còn ở trạng thái chờ thanh toán.');
        $this->assertSame($path, $order->fresh()->payment_bill);
        $this->assertCount(1, Storage::disk(MemberKyc::DISK)->allFiles());

        // Người khác / khách chưa đăng nhập không xem được ảnh bill; đơn chưa có bill thì 404
        $empty = Order::factory()->create(['member_id' => $member->id]);
        $this->get(route('member.orders.bill', $empty))->assertNotFound();
        auth('member')->logout();
        $this->flushSession();
        $this->get(route('member.orders.bill', $order))->assertRedirect('/dang-nhap');
        $this->actingAs(Member::factory()->create(), 'member')->get(route('member.orders.bill', $order))->assertNotFound();
    }

    public function test_accountant_sees_the_bill_and_a_new_bill_replaces_the_old_one_after_rejection(): void
    {
        $accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $noOrderAccess = User::factory()->create(['group_id' => Group::factory()->create(['permission_data' => ['backend_access', 'member']])->id]);
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id]);

        // Đơn chưa có bill: không có thẻ ảnh bill
        $this->actingAs($accountant, 'web')->get(route('backend.orders.show', $order))->assertOk()->assertDontSee('data-card="bill"', false);
        $this->get(route('backend.orders.bill', $order))->assertNotFound();

        $file = $this->bill();
        $raw = file_get_contents($file->getRealPath());
        $this->report($member, $order, ['bill' => $file])->assertSessionHas('success');
        $order->refresh();
        $first = $order->payment_bill;

        // Kế toán: thấy ảnh bill ngay trên trang đơn, phía trên form xác nhận thanh toán
        $this->actingAs($accountant, 'web')->get(route('backend.orders.show', $order))->assertOk()
            ->assertSeeInOrder(['data-card="bill"', 'Ảnh bill chuyển khoản', '<img src="'.route('backend.orders.bill', $order), 'vẫn phải đối chiếu với sao kê ngân hàng', 'data-card="confirm-payment"'], false);
        $response = $this->get(route('backend.orders.bill', $order))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($raw, $response->getContent());
        // Không có quyền xem đơn thì không xem được ảnh bill
        $this->actingAs($noOrderAccess, 'web')->get(route('backend.orders.bill', $order))->assertForbidden();

        // Kế toán chưa thấy tiền → trả đơn về "Đơn mới"; khách tải bill khác và báo lại: ảnh cũ bị xóa
        $this->actingAs($accountant, 'web')->post(route('backend.orders.reject-payment', $order), ['reason' => 'Chưa thấy tiền về tài khoản'])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_NEW, $order->fresh()->status);
        $this->actingAs($member->fresh(), 'member')->get(route('member.orders.show', $order))->assertOk()->assertSee('data-bill-form', false);
        $this->report($member->fresh(), $order, ['bill' => $this->bill('lan-2.jpg')])->assertSessionHas('success');
        $order->refresh();
        $this->assertSame(Order::STATUS_AWAITING, $order->status);
        $this->assertNotSame($first, $order->payment_bill);
        Storage::disk(MemberKyc::DISK)->assertMissing($first);
        Storage::disk(MemberKyc::DISK)->assertExists($order->payment_bill);

        // Xác nhận thanh toán xong: ảnh bill vẫn còn để tra cứu
        $this->actingAs($accountant, 'web')->post(route('backend.orders.confirm-payment', $order), ['payment_reference' => 'FT1', 'paid_amount' => (string) $order->total_amount])->assertSessionHas('success');
        $this->get(route('backend.orders.show', $order))->assertOk()->assertSee('data-card="bill"', false);
        $this->get(route('backend.orders.bill', $order))->assertOk();
    }
}
