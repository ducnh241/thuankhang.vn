<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\UpdatePasswordRequest;
use App\Http\Requests\Backend\UpdateProfileRequest;
use App\Support\AvatarUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('backend.profile.edit', ['user' => $request->user()->load('group')]);
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
}
