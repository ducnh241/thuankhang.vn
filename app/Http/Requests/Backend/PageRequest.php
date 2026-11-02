<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Thêm / sửa trang. Đường dẫn để trống thì lấy theo tiêu đề (Str::slug: bỏ dấu, đ → d); ảnh đại diện gửi cùng form
 * (image, remove_image); trạng thái là công tắc (ô ẩn status=0 đứng trước checkbox).
 */
class PageRequest extends FormRequest
{
    public function rules(): array
    {
        $page = $this->route('page');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('pages', 'slug')->ignore($page)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:500000'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
            'status' => ['required', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'tiêu đề', 'slug' => 'đường dẫn', 'excerpt' => 'mô tả ngắn', 'content' => 'nội dung', 'image' => 'ảnh đại diện',
            'status' => 'hiển thị', 'meta_title' => 'tiêu đề SEO', 'meta_description' => 'mô tả SEO',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'Nhập đường dẫn (hoặc tiêu đề có chữ / số để tự tạo).',
            'slug.unique' => 'Đường dẫn này đã có trang khác dùng.',
            'image.max' => 'Ảnh tối đa 2 MB.',
            'image.image' => 'Chọn ảnh PNG, JPG, WEBP hoặc GIF.',
            'image.mimes' => 'Chọn ảnh PNG, JPG, WEBP hoặc GIF.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $source = $this->filled('slug') ? $this->input('slug') : $this->input('title');
        $this->merge(['slug' => is_string($source) || $source === null ? Str::slug((string) $source) : $source]);
    }

    /** Dữ liệu lưu vào bảng pages (ảnh xử lý riêng ở controller). */
    public function pageData(): array
    {
        $data = $this->safe()->only(['title', 'slug', 'excerpt', 'content', 'meta_title', 'meta_description']);
        $trim = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'title' => trim($data['title']),
            'slug' => $data['slug'],
            'excerpt' => $trim($data['excerpt'] ?? null),
            // Trình soạn thảo trống vẫn gửi "<p></p>" → coi như không có nội dung
            'content' => trim(strip_tags((string) ($data['content'] ?? ''), '<img><iframe><video><hr>')) === '' ? null : $data['content'],
            'status' => $this->boolean('status'),
            'meta_title' => $trim($data['meta_title'] ?? null),
            'meta_description' => $trim($data['meta_description'] ?? null),
        ];
    }
}
