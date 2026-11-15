<?php

namespace App\Services;

use App\Models\Product;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Nhận ảnh sản phẩm cho API: từ link (http/https công khai) hoặc base64. Chỉ PNG / JPG / WEBP, tối đa 4 MB, kiểm tra nội
 * dung thật là ảnh. Link tới địa chỉ nội bộ / mạng riêng bị chặn (kể cả qua chuyển hướng) để tránh SSRF.
 * Trả về đường dẫn trên disk public (products/…).
 */
class ProductImageImporter
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

    public function fromUrl(string $url, string $field): string
    {
        $this->assertPublicUrl(new Uri($url), $field);
        try {
            $response = Http::timeout(20)->withOptions([
                'allow_redirects' => [
                    'max' => 3, 'protocols' => ['http', 'https'],
                    'on_redirect' => fn (RequestInterface $request, $response, UriInterface $uri) => $this->assertPublicUrl($uri, $field),
                ],
            ])->withHeaders(['Accept' => 'image/*'])->get($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->fail($field, 'Không tải được ảnh từ link này.');
        }
        if (! $response->successful()) {
            $this->fail($field, 'Không tải được ảnh (HTTP '.$response->status().').');
        }

        return $this->store($response->body(), $field);
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

    private function assertPublicUrl(UriInterface $uri, string $field): void
    {
        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            $this->fail($field, 'Link ảnh phải là http hoặc https.');
        }
        $host = trim($uri->getHost(), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            $this->fail($field, 'Không phân giải được tên miền của link ảnh.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->fail($field, 'Link ảnh trỏ tới địa chỉ nội bộ — không được phép.');
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
