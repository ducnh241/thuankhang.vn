<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Cấu hình thông báo cho kế toán (Cài đặt → tab "Thông báo", /backend/setting/notification): sự kiện nào thì báo, kênh
 * Telegram (bot token, chat ID) và kênh email (địa chỉ nhận, máy chủ SMTP, người gửi). Lưu trong bảng settings (khóa
 * notify_*). Bot token và mật khẩu SMTP là bí mật: lưu mã hóa bằng APP_KEY, không hiện lại trên form, không ghi vào nhật
 * ký (NotificationSettingController tự ghi một dòng tóm tắt).
 */
class NotificationSettings
{
    public const PREFIX = 'notify_';

    /** Sự kiện gửi thông báo (chung cho cả Telegram và email): khóa => [nhãn, gợi ý]. */
    public const EVENTS = [
        'order_created' => ['Có đơn hàng mới', 'Ngay khi khách bấm Thanh toán (đơn ở trạng thái Đơn mới, chờ khách chuyển khoản).'],
        'order_transferred' => ['Khách báo đã chuyển khoản', 'Khi khách bấm "Tôi đã chuyển khoản" — lúc kế toán cần vào đối chiếu và xác nhận thanh toán.'],
    ];

    /** Token của BotFather: "<id bot>:<chuỗi bí mật>". */
    public const TOKEN_PATTERN = '/^\d{6,12}:[A-Za-z0-9_-]{30,60}$/';

    /** Chat ID: số (nhóm là số âm, vd -1001234567890) hoặc @tên_kênh công khai. */
    public const CHAT_PATTERN = '/^(-?\d{5,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/';

    /** Kiểu mã hóa SMTP => [nhãn, cổng gợi ý]. */
    public const ENCRYPTIONS = ['tls' => ['STARTTLS (cổng 587)', 587], 'ssl' => ['SSL / TLS (cổng 465)', 465]];

    /** Cổng SMTP được phép (không cho gõ cổng tùy ý — tránh dùng form này để dò cổng máy chủ nội bộ). */
    public const SMTP_PORTS = [25, 465, 587, 2525];

    public const MAX_RECIPIENTS = 5;

    /** Các khóa chữ thường (không bí mật). */
    private const PLAIN = ['telegram_chat_id', 'mail_recipients', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_from_address', 'mail_from_name'];

    private const SECRETS = ['telegram_token', 'mail_password'];

    /**
     * Toàn bộ cấu hình, bí mật đã giải mã (null nếu chưa có / không giải mã được sau khi đổi APP_KEY):
     * events[khóa] => bool, telegram_enabled, telegram_token, telegram_chat_id, mail_enabled, mail_recipients (mảng),
     * mail_host, mail_port (int), mail_encryption, mail_username, mail_password, mail_from_address, mail_from_name.
     */
    public static function all(): array
    {
        $keys = ['telegram_enabled', 'mail_enabled', ...self::PLAIN, ...self::SECRETS, ...array_keys(self::EVENTS)];
        $stored = Setting::whereIn('key', array_map(fn ($key) => self::PREFIX.$key, $keys))->pluck('value', 'key');
        $get = fn (string $key) => $stored[self::PREFIX.$key] ?? null;

        $config = [
            // Chưa lưu lần nào: mặc định báo cả hai sự kiện
            'events' => collect(self::EVENTS)->keys()->mapWithKeys(fn ($event) => [$event => ($get($event) ?? '1') === '1'])->all(),
            'telegram_enabled' => $get('telegram_enabled') === '1',
            'mail_enabled' => $get('mail_enabled') === '1',
        ];
        foreach (self::PLAIN as $key) {
            $config[$key] = ($get($key) ?? '') !== '' ? $get($key) : null;
        }
        foreach (self::SECRETS as $key) {
            $config[$key] = null;
            if ($get($key)) {
                try {
                    $config[$key] = Crypt::decryptString($get($key));
                } catch (Throwable) {
                    // đổi APP_KEY: coi như chưa cấu hình, nhập lại
                }
            }
        }
        $config['mail_recipients'] = self::recipients($config['mail_recipients']);
        $config['mail_port'] = $config['mail_port'] !== null ? (int) $config['mail_port'] : null;

        return $config;
    }

    /**
     * Lưu cấu hình. Bí mật (telegram_token, mail_password): null = giữ giá trị cũ. Không bắn event của Setting để bí mật
     * không vào nhật ký.
     */
    public static function save(array $values): void
    {
        Setting::withoutEvents(function () use ($values) {
            foreach (['telegram_enabled', 'mail_enabled'] as $key) {
                Setting::put(self::PREFIX.$key, ! empty($values[$key]) ? '1' : '0');
            }
            foreach (array_keys(self::EVENTS) as $event) {
                Setting::put(self::PREFIX.$event, ! empty($values['events'][$event]) ? '1' : '0');
            }
            foreach (self::PLAIN as $key) {
                $value = $values[$key] ?? null;
                Setting::put(self::PREFIX.$key, is_array($value) ? implode(', ', $value) : ($value === null ? null : (string) $value));
            }
            foreach (self::SECRETS as $key) {
                if (($values[$key] ?? null) !== null) {
                    Setting::put(self::PREFIX.$key, Crypt::encryptString($values[$key]));
                }
            }
        });
    }

    /** Telegram gửi được: đã bật, có token và chat ID. */
    public static function telegramReady(array $config): bool
    {
        return $config['telegram_enabled'] && self::telegramConfigured($config);
    }

    public static function telegramConfigured(array $config): bool
    {
        return (bool) $config['telegram_token'] && (bool) $config['telegram_chat_id'];
    }

    /** Email gửi được: đã bật, có người nhận và có đường gửi (SMTP nhập ở đây hoặc mailer thật của máy chủ). */
    public static function mailReady(array $config): bool
    {
        return $config['mail_enabled'] && self::mailConfigured($config);
    }

    public static function mailConfigured(array $config): bool
    {
        return $config['mail_recipients'] !== [] && (self::hasSmtp($config) || self::serverCanSend());
    }

    /** Đã nhập máy chủ SMTP riêng (khi đó cần cả địa chỉ gửi). */
    public static function hasSmtp(array $config): bool
    {
        return (bool) $config['mail_host'] && (bool) $config['mail_from_address'];
    }

    /** Mailer mặc định của máy chủ (.env) gửi thư thật — không phải "log" / "array" chỉ ghi ra file. */
    public static function serverCanSend(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /** "a@x.vn, b@y.vn" (cách nhau dấu phẩy / chấm phẩy / xuống dòng) → mảng địa chỉ chữ thường, không trùng. */
    public static function recipients(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($email) => mb_strtolower(trim($email)), preg_split('/[\s,;]+/', (string) $value)))));
    }

    /** Bí mật che bớt để hiện trên form: token "1234567890:••••••••wxyz", mật khẩu "••••••••". */
    public static function mask(string $key, ?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $key === 'telegram_token' ? strtok($value, ':').':••••••••'.substr($value, -4) : '••••••••';
    }
}
