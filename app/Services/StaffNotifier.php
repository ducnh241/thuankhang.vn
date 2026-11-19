<?php

namespace App\Services;

use App\Models\MemberKyc;
use App\Models\Order;
use App\Support\Money;
use App\Support\NotificationSettings;
use App\Support\SiteSettings;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Thông báo cho người vận hành / kế toán qua Telegram và email (cấu hình: App\Support\NotificationSettings, Cài đặt →
 * Thông báo):
 *   - có đơn hàng mới (khách bấm Thanh toán);
 *   - khách báo đã chuyển khoản → kế toán vào xác nhận thanh toán;
 *   - thành viên gửi hồ sơ KYC → vào duyệt.
 * Tin gửi SAU khi đã trả trang cho khách (defer) nên Telegram / máy chủ thư chậm hay lỗi không làm hỏng thao tác của khách;
 * lỗi chỉ ghi log (đã che bot token / mật khẩu). Nội dung chỉ gồm thông tin tối thiểu (mã đơn, tên, số tiền, hình thức
 * nhận; với KYC: tên + mã thành viên) và link mở trang tương ứng trong quản trị.
 */
class StaffNotifier
{
    public static function orderCreated(Order $order): void
    {
        self::notify('order_created', fn () => [
            'subject' => 'Đơn hàng mới '.$order->code.' — '.Money::format($order->total_amount).' đ',
            'icon' => '🛒',
            'lines' => array_values(array_filter([
                'Khách: '.$order->recipient_name,
                'Tổng thanh toán: '.Money::format($order->total_amount).' đ',
                'Nhận hàng: '.self::delivery($order),
                $order->expires_at ? 'Hạn thanh toán: '.$order->expires_at->format('H:i d/m/Y') : null,
                'Xem đơn: '.route('backend.orders.show', $order),
            ])),
        ]);
    }

    public static function orderTransferred(Order $order): void
    {
        self::notify('order_transferred', fn () => [
            'subject' => 'Khách báo đã chuyển khoản — đơn '.$order->code,
            'icon' => '💸',
            'lines' => [
                'Số tiền: '.Money::format($order->total_amount).' đ · Nội dung chuyển khoản: '.$order->code,
                'Khách: '.$order->recipient_name,
                ...($order->hasBill() ? ['Khách đã gửi ảnh bill chuyển khoản (xem trong trang đơn).'] : []),
                'Xác nhận thanh toán: '.route('backend.orders.show', $order),
            ],
        ]);
    }

    /** Thành viên gửi / gửi lại hồ sơ KYC → vào duyệt. Tin không chứa số CCCD, số tài khoản hay ảnh. */
    public static function kycSubmitted(MemberKyc $kyc, bool $resubmit = false): void
    {
        self::notify('kyc_submitted', function () use ($kyc, $resubmit) {
            $member = $kyc->member;

            return [
                'subject' => ($resubmit ? 'Hồ sơ KYC gửi lại' : 'Hồ sơ KYC mới').' — '.$member->full_name,
                'icon' => '🪪',
                'lines' => [
                    'Thành viên: '.$member->full_name.' (mã '.$member->referral_code.')',
                    'Gửi lúc: '.$kyc->submitted_at->format('H:i d/m/Y'),
                    'Duyệt hồ sơ: '.route('backend.kyc.show', $kyc),
                ],
            ];
        });
    }

    /**
     * Gửi một tin Telegram tới chat trong $config. Trả null nếu gửi được, ngược lại trả lý do để hiện cho người cấu hình.
     * Không ném lỗi. $title in đậm, các dòng sau là chữ thường.
     */
    public static function sendTelegram(string $title, array $lines, array $config): ?string
    {
        if (! NotificationSettings::telegramConfigured($config)) {
            return 'Chưa nhập đủ bot token và chat ID.';
        }
        $token = $config['telegram_token'];

        try {
            $response = Http::timeout(8)->connectTimeout(4)->asForm()->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $config['telegram_chat_id'],
                'text' => '<b>'.e($title).'</b>'."\n".implode("\n", array_map('e', $lines)),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ]);
        } catch (Throwable $e) {
            // Thông báo lỗi kết nối có chứa URL kèm token → che đi trước khi ghi log
            Log::warning('Thông báo Telegram: không gửi được', ['error' => str_replace($token, '***', $e->getMessage())]);

            return 'Không kết nối được tới Telegram. Thử lại sau.';
        }
        if ($response->successful() && $response->json('ok') === true) {
            return null;
        }
        $reason = (string) ($response->json('description') ?: 'HTTP '.$response->status());
        Log::warning('Thông báo Telegram: bị từ chối', ['status' => $response->status(), 'reason' => $reason]);

        return match (true) {
            in_array($response->status(), [401, 404], true) => 'Bot token không đúng (Telegram trả lời: '.$reason.').',
            str_contains($reason, 'chat not found') => 'Không tìm thấy chat: kiểm tra chat ID, và nhớ thêm bot vào nhóm (hoặc bấm Start với bot) trước.',
            str_contains($reason, 'bot was kicked') || str_contains($reason, 'bot is not a member') || str_contains($reason, 'blocked') => 'Bot chưa ở trong nhóm / đã bị chặn: thêm bot vào nhóm rồi thử lại.',
            default => 'Telegram từ chối: '.$reason,
        };
    }

    /** Gửi một email chữ thường tới các địa chỉ nhận trong $config. Trả null nếu gửi được, ngược lại trả lý do. */
    public static function sendMail(string $subject, array $lines, array $config): ?string
    {
        if ($config['mail_recipients'] === []) {
            return 'Chưa nhập địa chỉ email nhận thông báo.';
        }
        if (! NotificationSettings::hasSmtp($config) && ! NotificationSettings::serverCanSend()) {
            return 'Chưa có máy chủ gửi thư: nhập máy chủ SMTP và địa chỉ gửi.';
        }
        $site = self::siteName();

        try {
            self::mailer($config)->raw(implode("\n", $lines)."\n\n— ".$site, function (Message $message) use ($config, $subject, $site) {
                $message->to($config['mail_recipients'])->subject('['.$site.'] '.$subject);
                if (NotificationSettings::hasSmtp($config)) {
                    $message->from($config['mail_from_address'], $config['mail_from_name'] ?: $site);
                }
            });
        } catch (Throwable $e) {
            $reason = (string) $config['mail_password'] !== '' ? str_replace($config['mail_password'], '***', $e->getMessage()) : $e->getMessage();
            Log::warning('Thông báo email: không gửi được', ['error' => $reason]);

            return 'Máy chủ thư báo lỗi: '.mb_strimwidth(preg_replace('/\s+/', ' ', $reason), 0, 200, '…');
        }

        return null;
    }

    /** Mailer theo SMTP nhập ở trang Thông báo; không nhập thì dùng mailer mặc định của máy chủ (.env). */
    private static function mailer(array $config): Mailer
    {
        if (! NotificationSettings::hasSmtp($config)) {
            return Mail::mailer();
        }

        return Mail::build([
            'transport' => 'smtp',
            'scheme' => $config['mail_encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $config['mail_host'],
            'port' => $config['mail_port'] ?: NotificationSettings::ENCRYPTIONS[$config['mail_encryption'] ?? 'tls'][1] ?? 587,
            'username' => $config['mail_username'],
            'password' => $config['mail_password'],
            'timeout' => 10,
        ]);
    }

    /** Sự kiện đang bật thì dựng nội dung và gửi sau khi trả trang; mọi lỗi (kể cả lúc dựng nội dung) chỉ ghi log. */
    private static function notify(string $event, callable $content): void
    {
        try {
            $config = NotificationSettings::all();
            $telegram = NotificationSettings::telegramReady($config);
            $mail = NotificationSettings::mailReady($config);
            if (empty($config['events'][$event]) || (! $telegram && ! $mail)) {
                return;
            }
            $message = $content();
            defer(function () use ($message, $config, $telegram, $mail) {
                if ($telegram) {
                    self::sendTelegram($message['icon'].' '.$message['subject'], $message['lines'], $config);
                }
                if ($mail) {
                    self::sendMail($message['subject'], $message['lines'], $config);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Thông báo: không chuẩn bị được nội dung', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private static function delivery(Order $order): string
    {
        $label = Order::DELIVERIES[$order->delivery_type] ?? $order->delivery_type;

        return $order->isPickup() && $order->pickupPoint ? $label.' — '.$order->pickupPoint->name : $label;
    }

    public static function siteName(): string
    {
        return SiteSettings::all()['site_name'] ?: (string) config('app.name');
    }
}
