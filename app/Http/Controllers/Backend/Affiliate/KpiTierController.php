<?php

namespace App\Http\Controllers\Backend\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Affiliate\KpiTierRequest;
use App\Models\KpiTier;
use App\Support\AffiliateSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** A4 – Cấu hình KPI tháng (chỉ lưu, chưa áp dụng – Phase 2): bảng bậc + ngày trả thưởng. */
class KpiTierController extends Controller
{
    public function index(Request $request): View
    {
        return view('backend.affiliate.kpi', [
            'tiers' => KpiTier::orderBy('min_amount')->get(),
            'payDay' => AffiliateSettings::get('kpi_pay_day'),
            'canEdit' => $request->user()->can('affiliate_kpi/edit'),
        ]);
    }

    public function update(KpiTierRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            // Ghép theo vị trí (đã xếp theo "từ"): sửa dòng cũ, thêm dòng thiếu, xóa dòng thừa — nhật ký chỉ ghi chỗ đổi
            $existing = KpiTier::lockForUpdate()->orderBy('min_amount')->get()->values();
            foreach ($request->tiers() as $i => $data) {
                isset($existing[$i]) ? $existing[$i]->update($data) : KpiTier::create($data);
            }
            $existing->slice(count($request->tiers()))->each->delete();

            AffiliateSettings::save(['kpi_pay_day' => (int) $request->validated('kpi_pay_day')]);
        });

        return redirect()->route('backend.affiliate.kpi.index')->with('success', 'Đã lưu cấu hình KPI.');
    }
}
