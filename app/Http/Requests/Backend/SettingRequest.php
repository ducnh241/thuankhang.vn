<?php

namespace App\Http\Requests\Backend;

use App\Support\SiteSettings;
use Illuminate\Foundation\Http\FormRequest;

class SettingRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'site_name' => ['required', 'string', 'max:80'],
            // Không nhận SVG: ảnh nằm cùng tên miền, SVG có thể chứa mã chạy khi mở trực tiếp
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
            'site_hotline' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]+$/'],
            'site_email' => ['nullable', 'string', 'email', 'max:150'],
            'site_address' => ['nullable', 'string', 'max:300'],
            'site_footer' => ['nullable', 'string', 'max:10000'],
            'site_map' => ['bail', 'nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                if (SiteSettings::mapEmbedUrl($value) === null) {
                    $fail('Dán mã nhúng (iframe) hoặc link Google Maps.');
                }
            }],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
            'seo_keywords' => ['nullable', 'array', 'max:30'],
            'seo_keywords.*' => ['string', 'max:60', 'not_regex:/,/'],
            'code_head' => ['nullable', 'string', 'max:20000'],
            'code_body' => ['nullable', 'string', 'max:20000'],
            'code_bottom' => ['nullable', 'string', 'max:20000'],
        ];
        foreach (SiteSettings::socialKeys() as $key) {
            $rules[$key] = ['nullable', 'string', 'max:300', 'url:http,https'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $names = [
            'site_name' => 'tên website', 'logo' => 'logo', 'favicon' => 'favicon', 'site_hotline' => 'hotline',
            'site_email' => 'email', 'site_address' => 'địa chỉ', 'site_footer' => 'nội dung chân trang',
            'site_map' => 'bản đồ', 'seo_title' => 'tiêu đề', 'seo_description' => 'mô tả', 'seo_keywords' => 'từ khóa',
            'seo_keywords.*' => 'từ khóa', 'code_head' => 'mã trong head', 'code_body' => 'mã đầu body',
            'code_bottom' => 'mã cuối body',
        ];
        foreach (SiteSettings::SOCIALS as $key => [$label]) {
            $names['social_'.$key] = 'link '.$label;
        }

        return $names;
    }

    public function messages(): array
    {
        $messages = [
            'logo.mimes' => 'Logo phải là ảnh PNG, JPG hoặc WEBP.',
            'favicon.mimes' => 'Favicon phải là ảnh PNG, ICO hoặc WEBP.',
            'site_hotline.regex' => 'Hotline chỉ gồm số, dấu cách và các dấu + - . ( ).',
            'seo_keywords.max' => 'Tối đa 30 từ khóa.',
            'seo_keywords.*.not_regex' => 'Từ khóa không được chứa dấu phẩy.',
        ];
        foreach (SiteSettings::socialKeys() as $key) {
            $messages[$key.'.url'] = 'Nhập link đầy đủ, bắt đầu bằng https://';
        }

        return $messages;
    }

    /** Từ khóa trống (ô tag gửi "" → null sau ConvertEmptyStringsToNull) thì bỏ, không báo lỗi. */
    protected function prepareForValidation(): void
    {
        if (is_array($keywords = $this->input('seo_keywords'))) {
            $this->merge(['seo_keywords' => array_values(array_filter($keywords, fn ($k) => $k !== null && $k !== ''))]);
        }
    }

    /** Giá trị lưu vào bảng settings (logo / favicon xử lý riêng ở controller). Ô trống → null. */
    public function settings(): array
    {
        $data = [];
        foreach ([...SiteSettings::TEXT_KEYS, ...SiteSettings::socialKeys()] as $key) {
            $value = $this->validated($key);
            $data[$key] = is_string($value) && $value !== '' ? $value : null;
        }
        $keywords = array_unique(SiteSettings::keywords(implode(',', (array) $this->validated('seo_keywords', []))));
        $data['seo_keywords'] = $keywords ? implode(', ', $keywords) : null;

        return $data;
    }
}
