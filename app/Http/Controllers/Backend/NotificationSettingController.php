<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\StaffNotifier;
use App\Support\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Cài đặt → tab "Thông báo" (/backend/setting/notification): báo khi có đơn mới / khách báo đã chuyển khoản / hồ sơ KYC mới,
 * qua Telegram (bot token, chat ID) và email (địa chỉ nhận, máy chủ SMTP, người gửi); mỗi kênh có nút gửi thử.
 * Xem: quyền setting. Sửa / gửi thử: quyền setting/edit VÀ là Root admin — trang này giữ bí mật (bot token, mật khẩu
 * SMTP) và quyết định thư / tin của hệ thống đi qua đâu, như mã theo dõi ở tab Tracking.
 * Bí mật không hiện lại trên form (để trống = giữ nguyên) và không vào nhật ký (ghi một dòng tóm tắt).
 */
class NotificationSettingController extends Controller
{
    public function edit(Request $request): View
    {
        $config = NotificationSettings::all();

        return view('backend.settings.notification', [
            'config' => $config,
            'masked' => [
                'telegram_token' => NotificationSettings::mask('telegram_token', $config['telegram_token']),
                'mail_password' => NotificationSettings::mask('mail_password', $config['mail_password']),
            ],
            'canEdit' => $this->canEdit($request),
            'serverCanSend' => NotificationSettings::serverCanSend(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $current = NotificationSettings::all();
        $trim = fn (string $key, bool $noSpaces = false) => is_string($v = $request->input($key)) ? ($noSpaces ? preg_replace('/\s+/', '', $v) : trim($v)) : $v;
        $request->merge([
            'telegram_token' => $trim('telegram_token', true),
            'telegram_chat_id' => $trim('telegram_chat_id', true),
            'mail_host' => is_string($trim('mail_host', true)) ? mb_strtolower($trim('mail_host', true)) : $trim('mail_host'),
            'mail_username' => $trim('mail_username'),
            'mail_from_address' => is_string($trim('mail_from_address', true)) ? mb_strtolower($trim('mail_from_address', true)) : $trim('mail_from_address'),
            'mail_from_name' => $trim('mail_from_name'),
        ]);
        $telegramOn = $request->boolean('telegram_enabled');
        $mailOn = $request->boolean('mail_enabled');
        $hasHost = is_string($request->input('mail_host')) && $request->input('mail_host') !== '';

        $validator = ValidatorFacade::make($request->all(), [
            'events' => ['nullable', 'array'],
            'events.*' => ['nullable', 'boolean'],
            'telegram_enabled' => ['nullable', 'boolean'],
            // Bật thì phải có token (mới nhập hoặc đã lưu) và chat ID
            'telegram_token' => [Rule::requiredIf($telegramOn && ! $current['telegram_token']), 'nullable', 'string', 'regex:'.NotificationSettings::TOKEN_PATTERN],
            'telegram_chat_id' => [Rule::requiredIf($telegramOn), 'nullable', 'string', 'regex:'.NotificationSettings::CHAT_PATTERN],
            'mail_enabled' => ['nullable', 'boolean'],
            'mail_recipients' => [Rule::requiredIf($mailOn), 'nullable', 'string', 'max:500'],
            // Chỉ nhận tên miền (không nhận địa chỉ IP) và các cổng SMTP quen thuộc
            'mail_host' => ['nullable', 'string', 'max:150', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'],
            'mail_port' => ['nullable', 'integer', Rule::in(NotificationSettings::SMTP_PORTS)],
            'mail_encryption' => ['nullable', 'string', Rule::in(array_keys(NotificationSettings::ENCRYPTIONS))],
            'mail_username' => ['nullable', 'string', 'max:150'],
            'mail_password' => ['nullable', 'string', 'max:200'],
            'mail_from_address' => [Rule::requiredIf($hasHost), 'nullable', 'string', 'email', 'max:150'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
        ], [
            'telegram_token.required' => 'Nhập bot token để bật thông báo Telegram.',
            'telegram_token.regex' => 'Bot token không đúng dạng (VD: 1234567890:AAH…). Chép nguyên dòng token BotFather gửi.',
            'telegram_chat_id.required' => 'Nhập chat ID để bật thông báo Telegram.',
            'telegram_chat_id.regex' => 'Chat ID là một số (nhóm thường là số âm, VD: -1001234567890) hoặc @tên_kênh.',
            'mail_recipients.required' => 'Nhập ít nhất một địa chỉ email nhận thông báo.',
            'mail_host.regex' => 'Máy chủ SMTP là tên miền, VD: smtp.gmail.com.',
            'mail_port.in' => 'Cổng SMTP là một trong: '.implode(', ', NotificationSettings::SMTP_PORTS).'.',
            'mail_from_address.required' => 'Nhập địa chỉ gửi (email của tài khoản SMTP).',
        ], [
            'telegram_token' => 'bot token', 'telegram_chat_id' => 'chat ID', 'mail_recipients' => 'email nhận thông báo', 'mail_host' => 'máy chủ SMTP',
            'mail_port' => 'cổng', 'mail_encryption' => 'mã hóa', 'mail_username' => 'tên đăng nhập SMTP', 'mail_password' => 'mật khẩu SMTP',
            'mail_from_address' => 'địa chỉ gửi', 'mail_from_name' => 'tên người gửi',
        ]);
        $recipients = NotificationSettings::recipients(is_string($request->input('mail_recipients')) ? $request->input('mail_recipients') : null);
        $validator->after(function (Validator $validator) use ($recipients, $mailOn, $hasHost) {
            $errors = $validator->errors();
            if (! $errors->has('mail_recipients')) {
                $bad = array_filter($recipients, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150);
                if ($bad !== []) {
                    $errors->add('mail_recipients', 'Địa chỉ email không hợp lệ: '.implode(', ', $bad).'.');
                } elseif (count($recipients) > NotificationSettings::MAX_RECIPIENTS) {
                    $errors->add('mail_recipients', 'Tối đa '.NotificationSettings::MAX_RECIPIENTS.' địa chỉ email.');
                }
            }
            if ($mailOn && ! $hasHost && ! $errors->has('mail_host') && ! NotificationSettings::serverCanSend()) {
                $errors->add('mail_host', 'Nhập máy chủ SMTP để gửi được email (máy chủ website chưa cấu hình gửi thư).');
            }
        });
        $data = $validator->validate();

        $encryption = $hasHost ? ($data['mail_encryption'] ?? 'tls') : null;
        $values = [
            'events' => collect(NotificationSettings::EVENTS)->keys()->mapWithKeys(fn ($event) => [$event => $request->boolean("events.$event")])->all(),
            'telegram_enabled' => $telegramOn,
            'telegram_token' => $data['telegram_token'] ?? null,
            'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
            'mail_enabled' => $mailOn,
            'mail_recipients' => $recipients,
            'mail_host' => $data['mail_host'] ?? null,
            'mail_port' => $hasHost ? (int) ($data['mail_port'] ?? NotificationSettings::ENCRYPTIONS[$encryption][1]) : null,
            'mail_encryption' => $encryption,
            'mail_username' => $hasHost ? ($data['mail_username'] ?? null) : null,
            'mail_password' => $data['mail_password'] ?? null,
            'mail_from_address' => $data['mail_from_address'] ?? null,
            'mail_from_name' => $data['mail_from_name'] ?? null,
        ];
        NotificationSettings::save($values);
        $this->audit($current, NotificationSettings::all(), $values);

        return redirect()->route('backend.settings.notification.edit')->with('success', 'Đã lưu cấu hình thông báo.');
    }

    /** Gửi thử một tin (channel = telegram | mail) theo cấu hình ĐÃ LƯU, kể cả khi kênh đang tắt. */
    public function test(Request $request, string $channel): RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $config = NotificationSettings::all();
        $site = StaffNotifier::siteName();
        $lines = ['Kết nối hoạt động. Nơi này sẽ nhận thông báo đơn hàng và hồ sơ KYC từ website.', 'Gửi bởi: '.$request->user()->name.' · '.now()->format('H:i d/m/Y')];

        [$error, $done] = $channel === 'telegram'
            ? [StaffNotifier::sendTelegram('✅ Tin thử từ '.$site, $lines, $config), 'Đã gửi tin thử. Kiểm tra nhóm / chat Telegram.']
            : [StaffNotifier::sendMail('Email thử', $lines, $config), 'Đã gửi email thử tới '.implode(', ', $config['mail_recipients']).'. Kiểm tra hộp thư (cả mục Spam).'];

        return redirect()->route('backend.settings.notification.edit')
            ->with($error ? 'error' : 'success', $error ? 'Chưa gửi thử được. '.$error : $done);
    }

    private function canEdit(Request $request): bool
    {
        return $request->user()->can('setting/edit') && $request->user()->isSuperAdmin();
    }

    /** Nhật ký: một dòng tóm tắt các ô không bí mật đã đổi; bí mật chỉ ghi "đã đổi". */
    private function audit(array $before, array $after, array $submitted): void
    {
        $flat = fn (array $config) => collect($config)->except(['events', 'telegram_token', 'mail_password'])
            ->map(fn ($value) => is_array($value) ? implode(', ', $value) : $value)->merge($config['events'])->all();
        $old = $flat($before);
        $new = $flat($after);
        $changed = array_keys(array_filter($new, fn ($value, $key) => $value !== ($old[$key] ?? null), ARRAY_FILTER_USE_BOTH));
        $old = array_intersect_key($old, array_flip($changed));
        $new = array_intersect_key($new, array_flip($changed));
        foreach (['telegram_token', 'mail_password'] as $secret) {
            if (($submitted[$secret] ?? null) !== null && $submitted[$secret] !== $before[$secret]) {
                $old[$secret] = $before[$secret] ? '••••••' : null;
                $new[$secret] = '•••••• (đã đổi)';
            }
        }
        if ($new !== []) {
            AuditLog::record('updated', Setting::class, ['old' => $old, 'new' => $new], 'Cập nhật cấu hình thông báo (Telegram / email)');
        }
    }
}
