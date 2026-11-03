<?php

namespace App\Http\Controllers\Backend\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Affiliate\LevelRequest;
use App\Models\Level;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** A2 – Cấu hình cấp bậc: một bảng sửa tại chỗ, thêm dòng mới, lưu cả bảng một lần. */
class LevelController extends Controller
{
    public function index(Request $request): View
    {
        return view('backend.affiliate.levels', [
            'levels' => Level::ordered()->get(),
            'canEdit' => $request->user()->can('affiliate_level/edit'),
        ]);
    }

    public function update(LevelRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $existing = Level::lockForUpdate()->get()->keyBy('id');
            foreach ($request->levels() as $row) {
                $data = collect($row)->except('id')->all();
                $row['id'] ? $existing[$row['id']]->update($data) : Level::create($data);
            }
        });

        return redirect()->route('backend.affiliate.levels.index')->with('success', 'Đã lưu cấp bậc.');
    }
}
