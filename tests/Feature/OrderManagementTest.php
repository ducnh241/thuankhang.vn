<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Step 5 – Admin Đơn hàng (A8): danh sách + lọc, chi tiết, chuyển trạng thái, hủy đơn chưa thanh toán, phân quyền. */
class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function role(string $name): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $name)->value('id')]);
    }

    public function test_list_tabs_and_filters(): void
    {
        $member = Member::factory()->create(['full_name' => 'Lê Văn Mua', 'phone' => '0911222333']);
        $point = PickupPoint::factory()->create(['name' => 'VP Huế']);
        $pending = Order::factory()->create(['member_id' => $member->id]);
        $overdue = Order::factory()->create(['expires_at' => now()->subHour()]);
        $paid = Order::factory()->status(Order::STATUS_PAID)->create(['delivery_type' => Order::DELIVERY_PICKUP, 'pickup_point_id' => $point->id, 'province' => null, 'address' => null]);
        $old = Order::factory()->status(Order::STATUS_COMPLETED)->create(['created_at' => now()->subDays(40)]);
        $root = User::factory()->superAdmin()->create();

        $this->actingAs($root)->get('/backend')->assertSee(route('backend.orders.index'), false);
        $this->get('/backend/order')->assertOk()->assertSee($pending->code)->assertSee($overdue->code)->assertSee($paid->code)->assertSee('VP Huế');
        $this->get('/backend/order?tab=new')->assertSee($pending->code)->assertDontSee($overdue->code)->assertDontSee($paid->code);
        $this->get('/backend/order?tab=cancelled')->assertSee($overdue->code)->assertDontSee($pending->code);
        $this->get('/backend/order?q=lê văn')->assertSee($pending->code)->assertDontSee($paid->code);
        $this->get('/backend/order?q=0911 222 333')->assertSee($pending->code)->assertDontSee($paid->code);
        $this->get('/backend/order?q='.strtolower($paid->code))->assertSee($paid->code)->assertDontSee($pending->code);
        $this->get("/backend/order?pickup_point_id={$point->id}")->assertSee($paid->code)->assertDontSee($pending->code)->assertSee('Điểm: VP Huế');
        $this->get('/backend/order?delivery=pickup')->assertSee($paid->code)->assertDontSee($pending->code);
        $this->get('/backend/order?period=30d')->assertSee($paid->code)->assertDontSee($old->code);
        $this->get('/backend/order?tab=x&period=y&pickup_point_id=abc&q[]=1')->assertOk();

        $this->get(route('backend.members.show', $member))->assertSee($pending->code);
    }

    public function test_status_transitions_and_cancel(): void
    {
        $ops = $this->role('Vận hành');
        $paid = Order::factory()->status(Order::STATUS_PAID)->create();
        $pending = Order::factory()->create();

        $this->actingAs($ops)->get(route('backend.orders.show', $paid))->assertOk()->assertSee('Chuyển sang “Đang giao”')->assertDontSee('cancel-form');
        $this->post(route('backend.orders.status', $paid), ['to' => Order::STATUS_PROCESSING])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_PROCESSING, $paid->refresh()->status);
        $this->assertNotNull($paid->processing_at);
        // Bấm lại form cũ: không nhảy bước
        $this->post(route('backend.orders.status', $paid), ['to' => Order::STATUS_PROCESSING])->assertSessionHas('error');
        $this->post(route('backend.orders.status', $paid), ['to' => Order::STATUS_COMPLETED])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_COMPLETED, $paid->refresh()->status);
        $this->assertTrue(AuditLog::where('auditable_type', Order::class)->where('auditable_id', $paid->id)->where('user_id', $ops->id)->count() === 2);

        // Đơn chưa thanh toán: không chuyển tay sang đã thanh toán / xử lý được
        $this->post(route('backend.orders.status', $pending), ['to' => Order::STATUS_PROCESSING])->assertSessionHas('error');
        $this->post(route('backend.orders.status', $pending), ['to' => Order::STATUS_PAID])->assertSessionHas('error');
        $this->assertSame(Order::STATUS_NEW, $pending->refresh()->status);

        $this->post(route('backend.orders.cancel', $pending), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('backend.orders.cancel', $pending), ['reason' => 'Khách yêu cầu hủy'])->assertSessionHas('success');
        $pending->refresh();
        $this->assertSame([Order::STATUS_CANCELLED, 'Khách yêu cầu hủy', Order::CANCEL_ADMIN], [$pending->status, $pending->cancel_reason, $pending->cancel_source]);
        $this->assertTrue(AuditLog::where('auditable_id', $pending->id)->where('auditable_type', Order::class)->where('description', 'like', '%Khách yêu cầu hủy%')->exists());
        $this->get(route('backend.orders.show', $pending))->assertSee('Đã hủy')->assertSee('Khách yêu cầu hủy');
        // Đơn đã thanh toán không hủy được
        $this->post(route('backend.orders.cancel', $paid), ['reason' => 'Thử hủy đơn đã trả'])->assertSessionHas('error');

        // Thành viên thấy lý do hủy
        $this->actingAs($pending->member, 'member')->get(route('member.orders.show', $pending))->assertSee('Đã hủy')->assertSee('Khách yêu cầu hủy');
    }

    public function test_confirm_and_reject_payment(): void
    {
        $accountant = $this->role('Kế toán');
        $awaiting = Order::factory()->create(['status' => Order::STATUS_AWAITING, 'confirm_requested_at' => now(), 'total_amount' => 1500000]);
        $other = Order::factory()->create(['total_amount' => 200000]);
        $cancelled = Order::factory()->create(['status' => Order::STATUS_CANCELLED, 'cancel_source' => Order::CANCEL_EXPIRED, 'total_amount' => 300000]);

        $this->actingAs($accountant)->get('/backend/order?tab=awaiting_confirmation')->assertSee($awaiting->code)->assertDontSee($other->code);
        $this->get(route('backend.orders.show', $awaiting))->assertOk()->assertSee('Xác nhận đã thanh toán')->assertSee('Chưa nhận được tiền');

        $url = route('backend.orders.confirm-payment', $awaiting);
        $this->post($url, ['_form' => 'confirm', 'payment_reference' => '', 'paid_amount' => '1.500.000'])->assertSessionHasErrors('payment_reference');
        $this->post($url, ['payment_reference' => 'FT001', 'paid_amount' => '1.400.000'])->assertSessionHasErrors('paid_amount');
        $this->post($url, ['payment_reference' => 'FT001', 'paid_amount' => 'abc'])->assertSessionHasErrors('paid_amount');
        $this->assertSame(Order::STATUS_AWAITING, $awaiting->refresh()->status);

        $this->post($url, ['payment_reference' => ' ft001 ', 'paid_amount' => '1.550.000', 'payment_note' => 'Khách chuyển dư'])->assertSessionHas('success');
        $awaiting->refresh();
        $this->assertSame([Order::STATUS_PAID, 'FT001', 1550000, $accountant->id], [$awaiting->status, $awaiting->payment_reference, $awaiting->paid_amount, $awaiting->confirmed_by]);
        $this->assertNotNull($awaiting->paid_at);
        $this->assertTrue(AuditLog::where('auditable_type', Order::class)->where('auditable_id', $awaiting->id)->where('description', 'like', '%GD FT001%')->exists());
        $this->get(route('backend.orders.show', $awaiting))->assertSee('FT001')->assertSee('Thừa 50.000 đ')->assertDontSee('confirm-payment-form');
        // Bấm lại: không xác nhận lần hai; mã giao dịch không dùng lại cho đơn khác
        $this->post($url, ['payment_reference' => 'FT002', 'paid_amount' => '1.500.000'])->assertSessionHas('error');
        $this->post(route('backend.orders.confirm-payment', $other), ['payment_reference' => 'ft001', 'paid_amount' => '200000'])
            ->assertSessionHasErrors(['payment_reference' => 'Mã giao dịch này đã dùng để xác nhận đơn khác.']);

        // Đơn đã hủy (tiền về muộn): bắt buộc ghi chú, khôi phục thành Đã thanh toán
        $this->post(route('backend.orders.confirm-payment', $cancelled), ['payment_reference' => 'FT003', 'paid_amount' => '300.000'])->assertSessionHasErrors('payment_note');
        $this->post(route('backend.orders.confirm-payment', $cancelled), ['payment_reference' => 'FT003', 'paid_amount' => '300.000', 'payment_note' => 'Tiền về sau hạn'])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_PAID, $cancelled->refresh()->status);

        // Chưa nhận được tiền: trả về Đơn mới, hạn tính lại
        $claimed = Order::factory()->create(['status' => Order::STATUS_AWAITING, 'confirm_requested_at' => now(), 'expires_at' => now()->subDay()]);
        $this->post(route('backend.orders.reject-payment', $claimed), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('backend.orders.reject-payment', $claimed), ['reason' => 'Sao kê chưa có giao dịch'])->assertSessionHas('success');
        $claimed->refresh();
        $this->assertSame([Order::STATUS_NEW, null], [$claimed->status, $claimed->confirm_requested_at]);
        $this->assertTrue($claimed->expires_at->isFuture());
        $this->post(route('backend.orders.reject-payment', $other), ['reason' => 'Không phải đơn chờ'])->assertSessionHas('error');
    }

    public function test_pickup_order_label(): void
    {
        $order = Order::factory()->status(Order::STATUS_PROCESSING)->create(['delivery_type' => Order::DELIVERY_PICKUP, 'pickup_point_id' => PickupPoint::factory()->create()->id, 'province' => null, 'address' => null]);
        $this->assertSame('Chờ nhận tại VP', $order->statusLabel());
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('backend.orders.show', $order))->assertSee('Chờ nhận tại VP');
    }

    public function test_permissions(): void
    {
        $order = Order::factory()->status(Order::STATUS_PAID)->create();
        $pending = Order::factory()->create();

        $accountant = $this->role('Kế toán');
        $this->actingAs($accountant)->get('/backend/order')->assertOk();
        $this->get(route('backend.orders.show', $pending))->assertSee('confirm-payment-form')->assertDontSee('cancel-form');
        $this->get(route('backend.orders.show', $order))->assertOk()->assertDontSee('status-form');
        $this->post(route('backend.orders.status', $order), ['to' => Order::STATUS_PROCESSING])->assertForbidden();
        $this->post(route('backend.orders.cancel', $pending), ['reason' => 'Kế toán hủy'])->assertForbidden();

        $cskh = $this->role('CSKH');
        $this->actingAs($cskh)->get(route('backend.orders.show', $pending))->assertOk()->assertSee('cancel-form')->assertDontSee('confirm-payment-form');
        $this->post(route('backend.orders.confirm-payment', $pending), ['payment_reference' => 'FT1', 'paid_amount' => $pending->total_amount])->assertForbidden();
        $this->post(route('backend.orders.reject-payment', $pending), ['reason' => 'Chưa thấy tiền'])->assertForbidden();
        $this->post(route('backend.orders.status', $order), ['to' => Order::STATUS_PROCESSING])->assertSessionHas('success');

        $nobody = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access')->create()->id]);
        $this->actingAs($nobody)->get('/backend/order')->assertForbidden();
        $this->get(route('backend.orders.show', $order))->assertForbidden();
    }
}
