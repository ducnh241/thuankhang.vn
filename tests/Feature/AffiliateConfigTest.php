<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommissionRate;
use App\Models\Group;
use App\Models\KpiTier;
use App\Models\Level;
use App\Models\Setting;
use App\Models\User;
use App\Support\AffiliateSettings;
use App\Support\Percent;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * Step 1 – Cấu hình affiliate (A2–A5). Nghiệm thu: sửa tỉ lệ ghi lịch sử theo effective_from; mốc cấp phải tăng dần
 * theo thứ tự, nhập sai bị chặn; seed mặc định theo đặc tả mục 2.
 */
class AffiliateConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function level(string $name): Level
    {
        return Level::where('name', $name)->sole();
    }

    /** Dữ liệu form cấp bậc từ DB, đổi vài ô: $changes[tên cấp] = [...]. */
    private function levelForm(array $changes = [], array $extra = []): array
    {
        $rows = Level::ordered()->get()->map(fn (Level $l) => [
            'id' => $l->id, 'name' => $l->name, 'sort_order' => (string) $l->sort_order,
            'threshold_amount' => number_format($l->threshold_amount, 0, ',', '.'),
            'is_vp' => $l->is_vp ? '1' : null, 'active' => $l->active ? '1' : null,
            ...($changes[$l->name] ?? []),
        ])->values()->all();

        return ['levels' => [...$rows, ...$extra]];
    }

    /** Dữ liệu form ma trận: tỉ lệ của phiên bản mới nhất, đổi vài ô: $changes[tên cấp][tier] = '12,5'. */
    private function rateForm(array $changes = [], ?string $at = null, array $extra = []): array
    {
        $latest = CommissionRate::versions()->first();
        $rates = [];
        foreach (Level::earning() as $level) {
            foreach (['F1', 'F2'] as $tier) {
                $rates[$level->id][$tier] = $changes[$level->name][$tier] ?? Percent::format($latest['rates'][$level->id][$tier] ?? 0);
            }
        }

        return ['rates' => $rates, 'effective_from' => $at ?? now()->format('Y-m-d\TH:i'), ...$extra];
    }

    // ---- Seed ----

    public function test_seeds_defaults_from_spec_and_is_idempotent(): void
    {
        $this->assertSame(
            ['Chưa kích hoạt', 'Nhân viên', 'Cộng tác viên', 'Cửa hàng', 'Đại lý', 'VP tỉnh cấp 1', 'VP tỉnh cấp 2'],
            Level::ordered()->pluck('name')->all());
        $this->assertSame([0, 1, 3_000_000, 10_000_000, 15_000_000, 150_000_000, 300_000_000], Level::ordered()->pluck('threshold_amount')->all());
        $this->assertSame(['VP tỉnh cấp 1', 'VP tỉnh cấp 2'], Level::where('is_vp', true)->ordered()->pluck('name')->all());

        // F1: CTV 10%, Đại lý 20%; cấp gốc không có tỉ lệ
        $now = now();
        $this->assertSame('10.00', CommissionRate::rateFor($this->level('Cộng tác viên')->id, 'F1', $now));
        $this->assertSame('20.00', CommissionRate::rateFor($this->level('Đại lý')->id, 'F1', $now));
        $this->assertSame('0.00', CommissionRate::rateFor($this->level('Đại lý')->id, 'F2', $now));
        $this->assertNull(CommissionRate::rateFor($this->level('Chưa kích hoạt')->id, 'F1', $now));

        $this->assertSame([[10_000_000, 100_000_000, '3.00', 5], [1_000_000_000, null, '9.00', 5]],
            collect([0, 3])->map(fn ($i) => KpiTier::orderBy('min_amount')->get()[$i])->map(fn ($t) => [$t->min_amount, $t->max_amount, $t->rate_percent, $t->min_f1])->all());

        // Tham số chung: mặc định theo đặc tả 6.5
        $this->assertSame(['5.00', 500000, 24, 30, 10, null], [
            AffiliateSettings::get('business_fee_percent'), AffiliateSettings::get('min_withdraw'), AffiliateSettings::get('payment_expiry_hours'),
            AffiliateSettings::get('ref_cookie_days'), AffiliateSettings::get('kpi_pay_day'), AffiliateSettings::get('pit_percent'),
        ]);

        $this->level('Đại lý')->update(['threshold_amount' => 20_000_000]);
        $this->seed(AffiliateConfigSeeder::class);
        $this->assertSame([7, 12, 4], [Level::count(), CommissionRate::count(), KpiTier::count()]);
        $this->assertSame(20_000_000, $this->level('Đại lý')->threshold_amount);
    }

    // ---- Phân quyền ----

    public function test_pages_follow_permissions(): void
    {
        $pages = ['/backend/affiliate/levels', '/backend/affiliate/commission', '/backend/affiliate/kpi', '/backend/affiliate/settings'];
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'page')->create()->id]);
        foreach ($pages as $url) {
            $this->actingAs($noAccess)->get($url)->assertForbidden();
        }
        $this->actingAs($noAccess)->get('/backend')->assertDontSee(route('backend.affiliate.levels.index'), false);

        // Chỉ xem: trang mở, form khóa, không lưu được
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions(
            'backend_access', 'affiliate_level', 'affiliate_commission', 'affiliate_kpi', 'affiliate_setting')->create()->id]);
        foreach ($pages as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()->assertSee('Bạn chỉ có quyền xem mục này.')->assertDontSee('id="save-bar"', false);
        }
        $this->actingAs($viewer)->get('/backend')->assertSee(route('backend.affiliate.commission.index'), false);
        $this->actingAs($viewer)->put('/backend/affiliate/levels', $this->levelForm())->assertForbidden();
        $this->actingAs($viewer)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '25']]))->assertForbidden();
        $this->actingAs($viewer)->put('/backend/affiliate/kpi', ['tiers' => [], 'kpi_pay_day' => '5'])->assertForbidden();
        $this->actingAs($viewer)->put('/backend/affiliate/settings', ['business_fee_percent' => '6'])->assertForbidden();
        $this->assertSame(4, KpiTier::count());

        // Super admin theo RoleSeeder có mọi quyền cấu hình
        $this->actingAs($this->root())->get('/backend/affiliate/levels')->assertOk()->assertSee('id="save-bar"', false)
            ->assertSee('Cộng tác viên')->assertSee('value="3.000.000"', false)->assertSee('Cấp gốc');
    }

    // ---- A2 Cấp bậc ----

    public function test_levels_save_edits_reorder_and_new_level(): void
    {
        $root = $this->root();
        $form = $this->levelForm(
            ['Đại lý' => ['threshold_amount' => '16.000.000'], 'VP tỉnh cấp 2' => ['name' => 'VP vùng', 'active' => null]],
            [['name' => 'Tổng đại lý', 'sort_order' => '7', 'threshold_amount' => '500.000.000', 'is_vp' => '1', 'active' => '1']],
        );

        $this->actingAs($root)->put('/backend/affiliate/levels', $form)
            ->assertRedirect(route('backend.affiliate.levels.index'))->assertSessionHas('success', 'Đã lưu cấp bậc.');

        $this->assertSame(16_000_000, $this->level('Đại lý')->threshold_amount);
        $this->assertFalse($this->level('VP vùng')->active);
        $this->assertSame([500_000_000, true, 7], [$this->level('Tổng đại lý')->threshold_amount, $this->level('Tổng đại lý')->is_vp, $this->level('Tổng đại lý')->sort_order]);
        $this->assertSame(8, Level::count());
        // Mọi thay đổi ghi nhật ký
        $this->assertTrue(AuditLog::where('auditable_type', Level::class)->where('auditable_id', $this->level('Đại lý')->id)->where('action', 'updated')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', Level::class)->where('action', 'created')->where('user_id', $root->id)->exists());
    }

    public function test_levels_must_be_increasing_by_order(): void
    {
        $root = $this->root();
        $before = Level::ordered()->pluck('threshold_amount', 'name')->all();

        // Mốc cấp sau ≤ cấp trước → lỗi đúng dòng
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Cửa hàng' => ['threshold_amount' => '3.000.000']]))
            ->assertSessionHasErrors(['levels.3.threshold_amount' => 'Mốc phải lớn hơn mốc của cấp trước “Cộng tác viên” (3.000.000 đ).']);
        // Đổi thứ tự làm mốc không còn tăng dần
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Đại lý' => ['sort_order' => '1'], 'Nhân viên' => ['sort_order' => '4']]))
            ->assertSessionHasErrors('levels.1.threshold_amount');
        // Cấp đầu tiên phải mốc 0, luôn bật, không là VP
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Chưa kích hoạt' => ['threshold_amount' => '1']]))
            ->assertSessionHasErrors('levels.0.threshold_amount');
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Chưa kích hoạt' => ['active' => null, 'is_vp' => '1']]))
            ->assertSessionHasErrors(['levels.0.active', 'levels.0.is_vp']);
        // Tên / thứ tự trùng, mốc không phải số tiền
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Cửa hàng' => ['name' => 'đại lý', 'sort_order' => '4']]))
            ->assertSessionHasErrors(['levels.3.name', 'levels.3.sort_order']);
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Cửa hàng' => ['threshold_amount' => '10,5']]))
            ->assertSessionHasErrors('levels.3.threshold_amount');
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm(['Cửa hàng' => ['threshold_amount' => '']]))
            ->assertSessionHasErrors('levels.3.threshold_amount');
        // Bỏ dòng cấp đã có = xóa cấp → chặn
        $form = $this->levelForm();
        array_pop($form['levels']);
        $this->actingAs($root)->put('/backend/affiliate/levels', $form)->assertSessionHasErrors('levels');

        $this->assertSame($before, Level::ordered()->pluck('threshold_amount', 'name')->all());

        // Đổi chỗ hai cấp kèm đổi mốc cho hợp lệ: lưu được (thứ tự không vướng ràng buộc duy nhất giữa chừng)
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm([
            'Cửa hàng' => ['sort_order' => '4', 'threshold_amount' => '15.000.000'], 'Đại lý' => ['sort_order' => '3', 'threshold_amount' => '10.000.000'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(['Cộng tác viên', 'Đại lý', 'Cửa hàng'], Level::ordered()->pluck('name')->slice(2, 3)->values()->all());
    }

    public function test_levels_page_redisplays_input_after_error(): void
    {
        $root = $this->root();
        $html = $this->actingAs($root)->from('/backend/affiliate/levels')->followingRedirects()
            ->put('/backend/affiliate/levels', $this->levelForm(['Cửa hàng' => ['threshold_amount' => '2.000.000']], [['name' => 'Mới', 'sort_order' => '9', 'threshold_amount' => '900.000.000', 'active' => '1']]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="2.000.000"', $html);
        $this->assertStringContainsString('value="Mới"', $html);
        $this->assertStringContainsString('Mốc phải lớn hơn mốc của cấp trước', $html);
        $this->assertStringContainsString('data-dirty', $html);
    }

    // ---- A3 Ma trận hoa hồng ----

    public function test_saving_rates_creates_a_new_version_and_keeps_history(): void
    {
        Carbon::setTestNow('2026-11-04 09:00:00');
        $root = $this->root();
        $agent = $this->level('Đại lý');
        $old = CommissionRate::versions()->first()['effective_from'];

        Carbon::setTestNow('2026-11-05 10:30:00');
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '22,5', 'F2' => '5']]))
            ->assertRedirect(route('backend.affiliate.commission.index'))->assertSessionHas('success', 'Đã lưu phiên bản tỉ lệ mới, áp dụng ngay.');

        $this->assertCount(2, CommissionRate::versions());
        $this->assertSame('22.50', CommissionRate::rateFor($agent->id, 'F1', now()));
        $this->assertSame('5.00', CommissionRate::rateFor($agent->id, 'F2', now()));
        // Đơn thanh toán trước giờ hiệu lực vẫn dùng tỉ lệ cũ
        $this->assertSame('20.00', CommissionRate::rateFor($agent->id, 'F1', $old->copy()->addHour()));
        $this->assertSame($root->id, CommissionRate::where('rate_percent', '22.50')->value('created_by'));

        // Một dòng nhật ký cho cả phiên bản, có trước / sau
        $log = AuditLog::where('auditable_type', CommissionRate::class)->sole();
        $this->assertSame(['20%', '0%'], array_values($log->changes['old']['Đại lý']));
        $this->assertSame(['22,5%', '5%'], array_values($log->changes['new']['Đại lý']));

        // Phiên bản tương lai: chưa áp dụng tới giờ đó
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '25']], '2026-12-01T00:00'))
            ->assertSessionHas('success', 'Đã lưu phiên bản tỉ lệ mới, áp dụng từ 01/12/2026 00:00.');
        $this->assertSame('22.50', CommissionRate::rateFor($agent->id, 'F1', now()));
        $this->assertSame('25.00', CommissionRate::rateFor($agent->id, 'F1', Carbon::parse('2026-12-01 00:00')));

        $this->actingAs($root)->get('/backend/affiliate/commission')->assertOk()
            ->assertSeeInOrder(['01/12/2026 00:00', 'Sắp áp dụng', '05/11/2026 10:30', 'Đang áp dụng', 'Đã thay thế'])
            ->assertSee('value="25"', false); // form điền sẵn phiên bản mới nhất

        // Lịch sử không sửa / xóa được
        $this->assertThrows(fn () => CommissionRate::first()->update(['rate_percent' => '1']), LogicException::class);
        $this->assertThrows(fn () => CommissionRate::first()->delete(), LogicException::class);
    }

    public function test_rate_validation(): void
    {
        Carbon::setTestNow('2026-11-05 10:30:00');
        $root = $this->root();
        $agent = $this->level('Đại lý');
        $count = CommissionRate::count();

        foreach (['101', '-1', 'abc', '10,555', ''] as $bad) {
            $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => $bad]]))
                ->assertSessionHasErrors("rates.{$agent->id}.F1");
        }
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '21']], '2026-11-01T00:00'))
            ->assertSessionHasErrors('effective_from');
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '21']], 'hôm nay'))
            ->assertSessionHasErrors('effective_from');
        // Không đổi gì so với phiên bản mới nhất
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm())->assertSessionHasErrors('rates');

        // F1 + F2 vượt ngưỡng (mặc định 30%): phải xác nhận
        $over = $this->rateForm(['Đại lý' => ['F1' => '25', 'F2' => '10']]);
        $this->actingAs($root)->post('/backend/affiliate/commission', $over)
            ->assertSessionHasErrors(['confirm_over' => 'F1 + F2 vượt ngưỡng 30% ở: Đại lý (35%). Tích xác nhận để lưu.']);
        $this->assertSame($count, CommissionRate::count());
        $this->actingAs($root)->post('/backend/affiliate/commission', [...$over, 'confirm_over' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('25.00', CommissionRate::rateFor($agent->id, 'F1', now()));

        // Trùng đúng giờ hiệu lực của phiên bản đã có
        $this->actingAs($root)->post('/backend/affiliate/commission', [...$this->rateForm(['Đại lý' => ['F1' => '24']]), 'confirm_over' => '1'])
            ->assertSessionHasErrors('effective_from');
    }

    public function test_new_level_appears_in_matrix(): void
    {
        $root = $this->root();
        $this->actingAs($root)->put('/backend/affiliate/levels', $this->levelForm([], [['name' => 'Tổng đại lý', 'sort_order' => '7', 'threshold_amount' => '500.000.000', 'active' => '1']]));
        $new = $this->level('Tổng đại lý');

        $this->assertNull(CommissionRate::rateFor($new->id, 'F1', now())); // chưa cấu hình = 0
        $this->actingAs($root)->get('/backend/affiliate/commission')->assertOk()->assertSee('name="rates['.$new->id.'][F1]"', false);
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Tổng đại lý' => ['F1' => '15']], now()->addMinute()->format('Y-m-d\TH:i')))
            ->assertSessionHasNoErrors();
        $this->assertSame('15.00', CommissionRate::rateFor($new->id, 'F1', now()->addMinutes(2)));
    }

    // ---- A4 KPI ----

    public function test_kpi_tiers_save_and_validate(): void
    {
        $root = $this->root();
        $this->actingAs($root)->get('/backend/affiliate/kpi')->assertOk()->assertSee('Chưa áp dụng – Phase 2')->assertSee('value="1.000.000.000"', false);

        $tiers = [
            ['min_amount' => '200.000.000', 'max_amount' => '', 'rate_percent' => '6', 'min_f1' => '3'],
            ['min_amount' => '20.000.000', 'max_amount' => '200.000.000', 'rate_percent' => '2,5', 'min_f1' => '5'],
        ];
        $this->actingAs($root)->put('/backend/affiliate/kpi', ['tiers' => $tiers, 'kpi_pay_day' => '15'])
            ->assertRedirect(route('backend.affiliate.kpi.index'))->assertSessionHas('success');
        $this->assertSame([[20_000_000, 200_000_000, '2.50', 5], [200_000_000, null, '6.00', 3]],
            KpiTier::orderBy('min_amount')->get()->map(fn ($t) => [$t->min_amount, $t->max_amount, $t->rate_percent, $t->min_f1])->all());
        $this->assertSame(15, AffiliateSettings::get('kpi_pay_day'));

        $bad = fn (array $tiers, string $field, string $day = '10') => $this->actingAs($root)->put('/backend/affiliate/kpi', ['tiers' => $tiers, 'kpi_pay_day' => $day])->assertSessionHasErrors($field);
        $bad([['min_amount' => '10', 'max_amount' => '5', 'rate_percent' => '1', 'min_f1' => '0']], 'tiers.0.max_amount');
        $bad([['min_amount' => '0', 'max_amount' => '', 'rate_percent' => '1', 'min_f1' => '0'], ['min_amount' => '10', 'max_amount' => '', 'rate_percent' => '2', 'min_f1' => '0']], 'tiers.0.max_amount');
        $bad([['min_amount' => '0', 'max_amount' => '100', 'rate_percent' => '1', 'min_f1' => '0'], ['min_amount' => '50', 'max_amount' => '', 'rate_percent' => '2', 'min_f1' => '0']], 'tiers.1.min_amount');
        $bad([['min_amount' => '0', 'max_amount' => '', 'rate_percent' => '120', 'min_f1' => '0']], 'tiers.0.rate_percent');
        $bad([], 'kpi_pay_day', '31');
        $this->assertSame(2, KpiTier::count());

        // Bỏ hết bậc được
        $this->actingAs($root)->put('/backend/affiliate/kpi', ['kpi_pay_day' => '10'])->assertSessionHasNoErrors();
        $this->assertSame(0, KpiTier::count());
    }

    // ---- A5 Tham số chung ----

    public function test_settings_save_normalize_and_validate(): void
    {
        $root = $this->root();
        $this->actingAs($root)->get('/backend/affiliate/settings')->assertOk()
            ->assertSee('value="500.000"', false)->assertSee('value="5"', false)->assertSee('Chưa cấu hình');

        $valid = [
            'business_fee_percent' => '5,5', 'commission_warning_percent' => '30', 'min_withdraw' => '1.000.000', 'pit_percent' => '10',
            'default_vat_percent' => '8', 'payment_expiry_hours' => '12', 'ref_cookie_days' => '60',
            'bank_code' => ' vcb ', 'bank_account' => '0123456789', 'bank_account_name' => 'cong ty  thuan khang',
            'root_referral_code' => 'goc01', 'max_vp_per_province' => '',
        ];
        $this->actingAs($root)->put('/backend/affiliate/settings', $valid)
            ->assertRedirect(route('backend.affiliate.settings.edit'))->assertSessionHas('success');

        $this->assertSame(['5.50', 1_000_000, '10.00', 12, 'VCB', 'CONG TY THUAN KHANG', 'GOC01', null], [
            AffiliateSettings::get('business_fee_percent'), AffiliateSettings::get('min_withdraw'), AffiliateSettings::get('pit_percent'),
            AffiliateSettings::get('payment_expiry_hours'), AffiliateSettings::get('bank_code'), AffiliateSettings::get('bank_account_name'),
            AffiliateSettings::get('root_referral_code'), AffiliateSettings::get('max_vp_per_province'),
        ]);
        $this->assertSame('5.50', Setting::get('aff_business_fee_percent'));
        // Không đổi thì không ghi lại (nhật ký chỉ có chỗ thay đổi)
        $logs = AuditLog::where('auditable_type', Setting::class)->count();
        $this->actingAs($root)->put('/backend/affiliate/settings', $valid)->assertSessionHasNoErrors();
        $this->assertSame($logs, AuditLog::where('auditable_type', Setting::class)->count());

        foreach ([
            'business_fee_percent' => '101', 'min_withdraw' => '500.000,5', 'payment_expiry_hours' => '0', 'ref_cookie_days' => 'abc',
            'bank_account' => '0123-456', 'root_referral_code' => 'mã gốc!', 'max_vp_per_province' => '0',
        ] as $field => $value) {
            $this->actingAs($root)->put('/backend/affiliate/settings', [...$valid, $field => $value])->assertSessionHasErrors($field);
        }
        $this->actingAs($root)->put('/backend/affiliate/settings', [...$valid, 'business_fee_percent' => ''])->assertSessionHasErrors('business_fee_percent');
        $this->assertSame('5.50', AffiliateSettings::get('business_fee_percent'));
    }

    public function test_commission_warning_threshold_comes_from_settings(): void
    {
        $root = $this->root();
        AffiliateSettings::save(['commission_warning_percent' => '40.00']);

        // Giờ hiệu lực phút sau: không phụ thuộc giây lúc chạy test (bản seed hiệu lực đầu phút hiện tại)
        $this->actingAs($root)->post('/backend/affiliate/commission', $this->rateForm(['Đại lý' => ['F1' => '25', 'F2' => '10']], now()->addMinute()->format('Y-m-d H:i')))->assertSessionHasNoErrors();
        $this->actingAs($root)->get('/backend/affiliate/commission')->assertSee('<strong>40%</strong>', false);
    }

    public function test_percent_helper(): void
    {
        $this->assertSame(['10.00', '10.50', '10.55', '0.00', '100.00'], array_map([Percent::class, 'parse'], ['10', '10,5', '10.55', '0', '100']));
        foreach (['100.01', '-1', '1,234', 'x', '', null, ['1']] as $bad) {
            $this->assertNull(Percent::parse($bad));
        }
        $this->assertSame(['10', '10,5', '0', '100'], array_map([Percent::class, 'format'], ['10.00', '10.50', '0.00', '100.00']));
    }
}
