<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\UpdatePasswordRequest;
use App\Http\Requests\Backend\UpdateProfileRequest;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Services\TwoFactor;
use App\Support\AvatarUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, TwoFactor $twoFactor): View
    {
        $user = $request->user();

        return view('backend.profile.edit', [
            'user' => $user->load('group'),
            // Đang ở bước quét mã (đã tạo khóa, chưa xác nhận): mã QR tạo ngay trên máy chủ
            'twoFactorQr' => $user->two_factor_secret && ! $user->hasTwoFactor() ? $twoFactor->qrSvg($user) : null,
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

    /** Xác thực 2 lớp — bước 1: xác nhận mật khẩu, tạo khóa bí mật (chưa có hiệu lực) rồi hiện mã QR để quét. */
    public function enableTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $this->confirmPassword($request);
        if (! $request->user()->hasTwoFactor()) {
            $twoFactor->begin($request->user());
        }

        return $this->backToTwoFactor();
    }

    /** Bước 2: nhập mã 6 số từ ứng dụng → bật, hiện các mã khôi phục một lần. */
    public function confirmTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validateWithBag('twofactor', ['code' => ['required', 'string', 'max:10']], ['code.required' => 'Nhập mã 6 số từ ứng dụng xác thực.']);
        $codes = $twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Mã không đúng. Kiểm tra giờ trên điện thoại rồi nhập mã mới nhất.'])->errorBag('twofactor');
        }
        AuditLog::record('updated', $request->user(), null, 'Bật xác thực 2 lớp');

        return $this->backToTwoFactor()->with('recovery_codes', $codes)->with('success', 'Đã bật xác thực 2 lớp. Lưu các mã khôi phục ở nơi an toàn.');
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $this->confirmPassword($request);
        abort_unless($request->user()->hasTwoFactor(), 404);
        $codes = $twoFactor->regenerateRecoveryCodes($request->user());
        AuditLog::record('updated', $request->user(), null, 'Tạo lại mã khôi phục xác thực 2 lớp');

        return $this->backToTwoFactor()->with('recovery_codes', $codes)->with('success', 'Đã tạo mã khôi phục mới. Các mã cũ không còn dùng được.');
    }

    /** Tắt (cần mật khẩu). Đang ở bước quét mã mà chưa xác nhận thì chỉ là hủy thiết lập — không cần mật khẩu. */
    public function disableTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $wasOn = $request->user()->hasTwoFactor();
        if ($wasOn) {
            $this->confirmPassword($request);
        }
        $twoFactor->disable($request->user());
        if ($wasOn) {
            AuditLog::record('updated', $request->user(), null, 'Tắt xác thực 2 lớp');
        }

        return $this->backToTwoFactor()->with('success', $wasOn ? 'Đã tắt xác thực 2 lớp.' : 'Đã hủy thiết lập xác thực 2 lớp.');
    }

    /** Thao tác nhạy cảm với xác thực 2 lớp: phải gõ lại mật khẩu (phiên bị chiếm cũng không tự bật / tắt được). */
    private function confirmPassword(Request $request): void
    {
        $request->validateWithBag('twofactor', ['current_password' => ['required', 'string', 'current_password']], [
            'current_password.required' => 'Nhập mật khẩu để xác nhận.', 'current_password.current_password' => 'Mật khẩu không đúng.',
        ]);
    }

    private function backToTwoFactor(): RedirectResponse
    {
        return redirect()->to(route('backend.profile.edit').'#two-factor');
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
