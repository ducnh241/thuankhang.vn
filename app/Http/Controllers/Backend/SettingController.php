<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\SettingRequest;
use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class SettingController extends Controller
{
    /** Tab của trang (?tab=…): khóa => [tên, icon, các ô thuộc tab — để mở đúng tab có lỗi]. */
    public const TABS = [
        'general' => ['Chung', 'setting', ['site_', 'logo', 'favicon']],
        'social' => ['Mạng xã hội', 'share-alt', ['social_']],
        'seo' => ['SEO', 'global', ['seo_']],
        'tracking' => ['Tracking', 'code', ['code_']],
    ];

    public function edit(Request $request): View
    {
        $tab = $this->tabOf($request->query('tab'));
        // Server báo lỗi: mở tab có ô lỗi đầu tiên (lấy túi lỗi đã chia sẻ cho view — session lưu dạng JSON nên giá
        // trị thô trong session có thể chỉ là mảng)
        $errors = view()->shared('errors');
        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $field = $errors->getBag('default')->keys()[0];
            $tab = collect(self::TABS)->search(fn ($t) => collect($t[2])->contains(fn ($prefix) => str_starts_with($field, $prefix))) ?: $tab;
        }

        return view('backend.settings.edit', [
            'settings' => SiteSettings::all(),
            'tab' => $tab,
            'canEdit' => $request->user()->can('setting/edit'),
        ]);
    }

    public function update(SettingRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $current = SiteSettings::all();

        // Ảnh: chọn ảnh mới thì thay ảnh cũ; bỏ ảnh thì xóa. Ảnh cũ chỉ xóa sau khi lưu xong.
        $oldImages = [];
        foreach (['logo' => 'site_logo', 'favicon' => 'site_favicon'] as $field => $key) {
            if ($request->hasFile($field)) {
                $values[$key] = $request->file($field)->store(SiteSettings::IMAGE_DIRECTORY, 'public');
                $oldImages[] = $current[$key];
            } elseif ($request->boolean('remove_'.$field)) {
                $values[$key] = null;
                $oldImages[] = $current[$key];
            }
        }

        DB::transaction(function () use ($values, $current) {
            foreach ($values as $key => $value) {
                // Ô trống chưa từng lưu thì không tạo dòng mới
                if ((string) $value === $current[$key]) {
                    continue;
                }
                Setting::put($key, $value);
            }
        });

        foreach (array_filter($oldImages) as $path) {
            if (str_starts_with($path, SiteSettings::IMAGE_DIRECTORY.'/')) {
                Storage::disk('public')->delete($path);
            }
        }

        return redirect()->route('backend.settings.edit', ['tab' => $this->tabOf($request->input('_tab'))])
            ->with('success', 'Đã lưu cài đặt.');
    }

    private function tabOf(mixed $tab): string
    {
        return is_string($tab) && isset(self::TABS[$tab]) ? $tab : 'general';
    }
}
