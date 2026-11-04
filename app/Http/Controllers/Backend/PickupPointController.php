<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\PickupPointRequest;
use App\Models\Level;
use App\Models\Member;
use App\Models\PickupPoint;
use App\Support\AffiliateSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * VP tỉnh / điểm nhận hàng (A7): danh sách (tab bật / tắt, tìm, lọc tỉnh), thêm / sửa (tỉnh, địa chỉ, SĐT, giờ mở cửa,
 * tài khoản VP sở hữu, bật / tắt), xóa mềm. Chỉ điểm đang bật hiện ở checkout.
 */
class PickupPointController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'active' => 'Đang bật', 'inactive' => 'Đang tắt'];

    public function index(Request $request): View
    {
        $tab = $this->queryString($request, 'tab');
        $province = $this->queryString($request, 'province');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : '',
            'province' => array_key_exists($province, PickupPoint::provinces()) ? $province : '',
        ];

        $base = PickupPoint::query()
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("address LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($filter['province'] !== '', fn ($q) => $q->where('province', $filter['province']));

        $byStatus = (clone $base)->selectRaw('active, COUNT(*) as cnt')->groupBy('active')->pluck('cnt', 'active');
        $counts = ['' => (int) $byStatus->sum(), 'active' => (int) ($byStatus[1] ?? 0), 'inactive' => (int) ($byStatus[0] ?? 0)];

        $points = (clone $base)
            ->when($filter['tab'] !== '', fn ($q) => $q->where('active', $filter['tab'] === 'active'))
            ->with('owner:id,full_name,phone,referral_code')
            ->orderBy('province')->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('backend.pickup-points.index', [
            'points' => $points,
            'filter' => $filter,
            'counts' => $counts,
            'max' => AffiliateSettings::get('max_vp_per_province'),
            // Số điểm đang bật mỗi tỉnh (cả hệ thống) để cảnh báo tỉnh đã đủ
            'activeByProvince' => PickupPoint::where('active', true)->selectRaw('province, COUNT(*) as cnt')->groupBy('province')->pluck('cnt', 'province'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new PickupPoint);
    }

    public function store(PickupPointRequest $request): RedirectResponse
    {
        $point = PickupPoint::create($request->pointData());

        return redirect()->route('backend.pickup-points.edit', $point)->with('success', "Đã thêm điểm nhận hàng {$point->name}.");
    }

    public function edit(PickupPoint $pickupPoint): View
    {
        return $this->form($pickupPoint->load('owner.level'));
    }

    public function update(PickupPointRequest $request, PickupPoint $pickupPoint): RedirectResponse
    {
        $pickupPoint->update($request->pointData());

        return redirect()->route('backend.pickup-points.edit', $pickupPoint)->with('success', 'Đã lưu điểm nhận hàng.');
    }

    public function destroy(PickupPoint $pickupPoint): RedirectResponse
    {
        // Gỡ chủ trước khi xóa mềm: thành viên VP đó làm chủ được điểm khác (owner_member_id duy nhất trong DB)
        DB::transaction(function () use ($pickupPoint) {
            if ($pickupPoint->owner_member_id) {
                $pickupPoint->update(['owner_member_id' => null]);
            }
            $pickupPoint->delete();
        });

        return redirect()->route('backend.pickup-points.index')->with('success', "Đã xóa điểm nhận hàng {$pickupPoint->name}.");
    }

    private function form(PickupPoint $point): View
    {
        // Thành viên chọn được làm chủ: đang ở cấp VP, chưa làm chủ điểm khác (cộng chủ hiện tại của điểm này)
        $vpLevels = Level::where('is_vp', true)->pluck('id');
        $owners = Member::whereIn('level_id', $vpLevels)
            ->where(fn ($q) => $q->whereDoesntHave('ownedPickupPoint')->when($point->owner_member_id, fn ($q) => $q->orWhere('id', $point->owner_member_id)))
            ->with('level:id,name')->orderBy('full_name')->get(['id', 'full_name', 'phone', 'referral_code', 'level_id', 'status']);
        if ($point->owner && ! $owners->contains('id', $point->owner->id)) {
            $owners->push($point->owner); // chủ hiện tại đã rời cấp VP: vẫn hiện để biết
        }

        return view('backend.pickup-points.form', [
            'point' => $point,
            'owners' => $owners,
            'max' => AffiliateSettings::get('max_vp_per_province'),
        ]);
    }
}
