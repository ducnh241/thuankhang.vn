<?php

namespace App\Services;

use App\Models\Product;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\UriInterface;

/**
 * Nhận ảnh sản phẩm cho API: từ link (http/https công khai) hoặc base64. Chỉ PNG / JPG / WEBP, tối đa 4 MB, kiểm tra nội
 * dung thật là ảnh. Trả về đường dẫn trên disk public (products/…).
 *
 * Chống SSRF khi tải từ link:
 *  - tên miền chỉ được trỏ tới địa chỉ IPv4 công khai (chặn mạng riêng, loopback, link-local / metadata cloud, CGNAT…);
 *  - kết nối bị GHIM vào đúng IP đã kiểm tra (CURLOPT_RESOLVE) nên không đổi DNS giữa lúc kiểm tra và lúc tải được
 *    (DNS rebinding), và không đi đường IPv6;
 *  - chuyển hướng tự xử lý từng bước, bước nào cũng kiểm tra lại như trên;
 *  - dừng tải ngay khi vượt 4 MB (không đọc hết file lớn vào bộ nhớ).
 */
class ProductImageImporter
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

    public function fromUrl(string $url, string $field): string
    {
        $uri = $this->parse($url, $field);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->publicIp($uri, $field);
            $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
            try {
                $response = Http::timeout(20)->connectTimeout(5)->withOptions([
                    'allow_redirects' => false,
                    'force_ip_resolve' => 'v4',
                    'curl' => [CURLOPT_RESOLVE => [$uri->getHost().':'.$port.':'.$ip]],
                    // Trả về true → Guzzle hủy tải: file báo trước / đã tải vượt 4 MB
                    'progress' => fn ($total, $downloaded) => $total > self::MAX_BYTES || $downloaded > self::MAX_BYTES,
                ])->withHeaders(['Accept' => 'image/*'])->get((string) $uri);
            } catch (\Throwable) {
                $this->fail($field, 'Không tải được ảnh từ link này (lỗi kết nối hoặc ảnh quá 4 MB).');
            }

            if ($response->redirect() && $response->header('Location') !== '') {
                $uri = UriResolver::resolve($uri, $this->parse($response->header('Location'), $field));

                continue;
            }
            if (! $response->successful()) {
                $this->fail($field, 'Không tải được ảnh (HTTP '.$response->status().').');
            }

            return $this->store($response->body(), $field);
        }

        $this->fail($field, 'Link ảnh chuyển hướng quá nhiều lần.');
    }

    public function fromBase64(string $data, string $field): string
    {
        $data = preg_replace('/^data:[^;]+;base64,/', '', trim($data));
        $binary = base64_decode((string) $data, true);
        if ($binary === false) {
            $this->fail($field, 'Dữ liệu base64 không hợp lệ.');
        }

        return $this->store($binary, $field);
    }

    private function store(string $binary, string $field): string
    {
        if (strlen($binary) > self::MAX_BYTES) {
            $this->fail($field, 'Ảnh tối đa 4 MB.');
        }
        $info = @getimagesizefromstring($binary);
        $ext = $info ? (self::TYPES[$info[2]] ?? null) : null;
        if (! $ext) {
            $this->fail($field, 'Chỉ nhận ảnh PNG, JPG hoặc WEBP.');
        }
        $path = Product::IMAGE_DIRECTORY.'/'.Str::random(40).'.'.$ext;
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function parse(string $url, string $field): UriInterface
    {
        try {
            return new Uri(trim($url));
        } catch (\Throwable) {
            $this->fail($field, 'Link ảnh không hợp lệ.');
        }
    }

    /** IPv4 công khai mà tên miền của link trỏ tới (để ghim kết nối). Bất kỳ bản ghi nào trỏ vào mạng nội bộ → từ chối. */
    private function publicIp(UriInterface $uri, string $field): string
    {
        if (! function_exists('curl_exec')) {
            $this->fail($field, 'Máy chủ chưa bật cURL nên không tải ảnh từ link được — hãy gửi ảnh dạng base64.');
        }
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            $this->fail($field, 'Link ảnh phải là http hoặc https.');
        }
        $host = $uri->getHost();
        if (str_contains($host, ':') || str_starts_with($host, '[')) {
            $this->fail($field, 'Link ảnh dùng địa chỉ IPv6 — không được phép.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            $this->fail($field, 'Không phân giải được tên miền của link ảnh.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIpv4($ip)) {
                $this->fail($field, 'Link ảnh trỏ tới địa chỉ nội bộ — không được phép.');
            }
        }

        return $ips[0];
    }

    /** IPv4 định tuyến công khai: loại mạng riêng, loopback, link-local (169.254 — metadata cloud), CGNAT 100.64/10, multicast… */
    public static function isPublicIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false
            && (ip2long($ip) >> 28 & 0xF) < 0xE; // 224.0.0.0/3: multicast + dành riêng
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
