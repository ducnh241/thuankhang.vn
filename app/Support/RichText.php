<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Lọc HTML soạn trong trang quản trị (mô tả sản phẩm, nội dung trang) trước khi in ra website: chỉ giữ thẻ / thuộc
 * tính an toàn (không script, không on*, không javascript:), link ngoài mở tab mới.
 */
class RichText
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return (self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeMedias()
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->withMaxInputLength(500000)
        ))->sanitize($html);
    }
}
