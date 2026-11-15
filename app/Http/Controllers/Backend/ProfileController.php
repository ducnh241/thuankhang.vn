<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\UpdatePasswordRequest;
use App\Http\Requests\Backend\UpdateProfileRequest;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Support\AvatarUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('backend.profile.edit', [
            'user' => $request->user()->load('group'),
            'tokens' => ApiToken::where('user_id', $request->user()->id)->latest('id')->get(),
        ]);
    }

    public function update(UpdateProfileRequest $request, AvatarUploader $avatars): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($request->boolean('remove_avatar')) {
            $avatars->delete($user->avatar);
            $data['avatar'] = null;
        }

        if ($request->hasFile('avatar')) {
            $avatars->delete($user->avatar);
            $data['avatar'] = $avatars->store($request->file('avatar'));
        }

        $user->update($data);

        return redirect()->route('backend.profile.edit')->with('success', 'Đã cập nhật tài khoản.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->validated('password')]);
        // Phiên này giữ nguyên, các thiết bị khác đang đăng nhập bằng mật khẩu cũ bị đăng xuất
        Auth::logoutOtherDevices($request->validated('password'));

        return redirect()->route('backend.profile.edit')->with('success', 'Đã đổi mật khẩu. Các thiết bị khác đã được đăng xuất.');
    }

    /** Tạo token API (hiện chuỗi token một lần qua flash). Token mang quyền của chính tài khoản này. */
    public function createToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['token_name' => ['required', 'string', 'max:100']], ['token_name.required' => 'Đặt tên cho token (VD: Claude đăng sản phẩm).']);
        [$token, $plain] = ApiToken::issue($request->user(), trim($data['token_name']));
        AuditLog::record('created', $token, null, "Tạo token API “{$token->name}”");

        return redirect()->route('backend.profile.edit')->with('new_api_token', $plain)->with('success', 'Đã tạo token API. Sao chép ngay — token chỉ hiện một lần.');
    }

    public function revokeToken(Request $request, int $token): RedirectResponse
    {
        $model = ApiToken::where('user_id', $request->user()->id)->findOrFail($token);
        $model->delete();
        AuditLog::record('deleted', $model, null, "Thu hồi token API “{$model->name}”");

        return redirect()->route('backend.profile.edit')->with('success', "Đã thu hồi token “{$model->name}”.");
    }
}
