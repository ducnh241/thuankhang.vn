<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Lọc HTML soạn trong trang quản trị (mô tả sản phẩm, nội dung trang) trước khi in ra website: chỉ giữ thẻ / thuộc
 * tính an toàn (không script, không on*, không javascript:), link ngoài mở tab mới.
 * Căn lề soạn trong trình soạn thảo được giữ lại: chữ (style="text-align: …" — bộ lọc bỏ mọi style nên đổi sang
 * thuộc tính align trước khi lọc) và ảnh (data-align="center" | "right", CSS .rich-text img[data-align]).
 */
class RichText
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // <p style="text-align: center"> của trình soạn thảo → <p align="center"> (chỉ đúng dạng này; style khác vẫn bị bỏ)
        $html = (string) preg_replace(
            '/<(p|h[1-6])\b([^>]*?)\sstyle="\s*text-align:\s*(left|center|right|justify)\s*;?\s*"/i',
            '<$1$2 align="$3"',
            $html
        );

        return (self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeMedias()
                ->allowAttribute('data-align', 'img')
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->withMaxInputLength(500000)
        ))->sanitize($html);
    }
}
