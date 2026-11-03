<?php

namespace App\Http\Controllers\Backend\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Affiliate\CommissionRateRequest;
use App\Models\AuditLog;
use App\Models\CommissionRate;
use App\Models\Level;
use App\Support\AffiliateSettings;
use App\Support\Percent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A3 – Ma trận hoa hồng: form nạp sẵn phiên bản mới nhất; lưu = thêm phiên bản mới với giờ hiệu lực (mặc định bây
 * giờ). Bên dưới là lịch sử các phiên bản (đang áp dụng / sắp áp dụng / đã thay thế).
 */
class CommissionRateController extends Controller
{
    public function index(Request $request): View
    {
        $versions = CommissionRate::versions();
        $now = now();

        return view('backend.affiliate.commission', [
            'levels' => Level::earning(),
            'allLevels' => Level::ordered()->get()->keyBy('id'),
            'versions' => $versions,
            'latest' => $versions->first(),
            // Phiên bản đang áp dụng = mới nhất đã tới giờ hiệu lực
            'currentKey' => $versions->search(fn ($v) => $v['effective_from']->lte($now)),
            'warning' => AffiliateSettings::get('commission_warning_percent'),
            'canEdit' => $request->user()->can('affiliate_commission/edit'),
        ]);
    }

    public function store(CommissionRateRequest $request): RedirectResponse
    {
        $at = $request->effectiveFrom();
        $rates = $request->rates();
        $names = Level::pluck('name', 'id');
        $named = fn (array $matrix) => collect($matrix)->mapWithKeys(fn ($tiers, $levelId) => [
            $names[$levelId] ?? "#{$levelId}" => collect($tiers)->map(fn ($rate) => Percent::format($rate).'%')->all(),
        ])->all();

        DB::transaction(function () use ($request, $at, $rates, $named) {
            $previous = CommissionRate::versions()->first();
            foreach ($rates as $levelId => $tiers) {
                foreach ($tiers as $tier => $rate) {
                    CommissionRate::create([
                        'level_id' => $levelId, 'tier' => $tier, 'rate_percent' => $rate,
                        'effective_from' => $at, 'created_by' => $request->user()->id,
                    ]);
                }
            }
            // Một dòng nhật ký cho cả phiên bản (CommissionRate không gắn observer)
            AuditLog::record('created', CommissionRate::class,
                ['old' => $previous ? $named($previous['rates']) : null, 'new' => $named($rates)],
                'Phiên bản tỉ lệ hoa hồng hiệu lực từ '.$at->format('d/m/Y H:i'));
        });

        $when = $at->lte(now()) ? 'áp dụng ngay' : 'áp dụng từ '.$at->format('d/m/Y H:i');

        return redirect()->route('backend.affiliate.commission.index')->with('success', "Đã lưu phiên bản tỉ lệ mới, {$when}.");
    }
}
