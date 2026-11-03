<?php

namespace App\Http\Controllers\Backend\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Affiliate\AffiliateSettingRequest;
use App\Support\AffiliateSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** A5 – Tham số chung của affiliate. */
class AffiliateSettingController extends Controller
{
    public function edit(Request $request): View
    {
        return view('backend.affiliate.settings', [
            'settings' => AffiliateSettings::all(),
            'keys' => AffiliateSettingRequest::fieldKeys(),
            'canEdit' => $request->user()->can('affiliate_setting/edit'),
        ]);
    }

    public function update(AffiliateSettingRequest $request): RedirectResponse
    {
        DB::transaction(fn () => AffiliateSettings::save($request->settings()));

        return redirect()->route('backend.affiliate.settings.edit')->with('success', 'Đã lưu tham số chung.');
    }
}
