<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\HostingRequest;
use App\Models\Hosting;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HostingController extends Controller
{
    /** Thanh thời hạn: còn 365 ngày trở lên = 100%, giảm dần theo ngày, hết hạn = 0%. */
    public const EXPIRY_FULL_DAYS = 365;

    public function index(): View|RedirectResponse
    {
        // Không lọc: chỉ vài hosting, xem hết một lượt; sắp theo tên A–Z
        $hostings = Hosting::query()->withCount('projects')->orderBy('name')->orderBy('id')->paginate(30);

        // Trang vượt quá số trang (vd vừa xóa hosting cuối của trang cuối) → về trang cuối
        if ($hostings->isEmpty() && $hostings->currentPage() > 1) {
            return redirect($hostings->url($hostings->lastPage()));
        }

        return view('backend.hostings.index', compact('hostings'));
    }

    public function create(Request $request): View
    {
        // Mở từ trang dự án: chọn sẵn dự án đó
        $preselect = $request->integer('project_id');

        return view('backend.hostings.form', $this->formData(new Hosting(['ownership' => 'mine', 'is_active' => true, 'alert_days' => '30,15,7,1']), $preselect ? [$preselect] : []));
    }

    public function store(HostingRequest $request): RedirectResponse
    {
        $hosting = DB::transaction(function () use ($request) {
            $hosting = Hosting::create($request->hostingData());
            $hosting->projects()->sync($request->validated('project_ids') ?? []);

            return $hosting;
        });

        return ($request->user()->can('hosting/edit')
            ? redirect()->route('backend.hostings.edit', $hosting)
            : redirect()->route('backend.hostings.index'))->with('success', "Đã tạo hosting {$hosting->name}.");
    }

    public function edit(Hosting $hosting): View
    {
        return view('backend.hostings.form', $this->formData($hosting, $hosting->projects()->pluck('projects.id')->all()));
    }

    public function update(HostingRequest $request, Hosting $hosting): RedirectResponse
    {
        DB::transaction(function () use ($request, $hosting) {
            $hosting->update($request->hostingData());
            // Dự án đã xóa (thùng rác) không hiện trong form → giữ nguyên liên kết của chúng
            $trashed = $hosting->projects()->onlyTrashed()->pluck('projects.id')->all();
            $hosting->projects()->sync([...($request->validated('project_ids') ?? []), ...$trashed]);
        });

        return redirect()->route('backend.hostings.edit', $hosting)->with('success', 'Đã cập nhật hosting.');
    }

    public function destroy(Hosting $hosting): RedirectResponse
    {
        if ($count = $hosting->projects()->count()) {
            return back()->with('error', "Không xóa được \"{$hosting->name}\": đang gắn {$count} dự án. Hãy bỏ gắn dự án trước (trong trang sửa hosting).");
        }

        $hosting->delete();

        // Xóa từ trang sửa của chính hosting này: trang đó không còn → về danh sách; xóa từ danh sách thì giữ trang
        $back = url()->previous();
        $toList = $back === route('backend.hostings.edit', $hosting) || ! str_starts_with($back, route('backend.hostings.index'));

        return ($toList ? redirect()->route('backend.hostings.index') : back())->with('success', "Đã xóa hosting {$hosting->name}.");
    }

    private function formData(Hosting $hosting, array $selected): array
    {
        return [
            'hosting' => $hosting,
            'selected' => $selected,
            'projects' => Project::with(['customer:id,name', 'domains:id,project_id,domain'])->orderBy('name')->get(['id', 'name', 'primary_domain', 'customer_id', 'status']),
        ];
    }
}
