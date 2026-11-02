<?php

namespace App\Http\Requests\Backend;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    /** Lỗi để riêng một "túi" để trang biết mở lại đúng hộp thoại. */
    protected $errorBag = 'tag';

    /** Người dùng tự nhập slug (không thì slug tạo từ tên — lỗi slug báo ở ô tên). */
    private bool $manualSlug = false;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash:ascii', Rule::unique('tags', 'slug')->ignore($this->route('tag'))],
            'color' => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tên tag', 'slug' => 'slug', 'color' => 'màu'];
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'Không tạo được slug, hãy nhập slug gồm chữ không dấu, số hoặc dấu gạch ngang.',
            'slug.unique' => 'Đã có tag dùng slug này (tên trùng hoặc gần giống một tag khác).',
            'color.regex' => 'Màu phải có dạng #RRGGBB.',
        ];
    }

    /** Slug để trống thì sinh từ tên; màu để trống dùng xám. */
    protected function prepareForValidation(): void
    {
        $slug = $this->filled('slug') ? $this->input('slug') : $this->input('name');
        $this->manualSlug = $this->filled('slug');
        $this->merge([
            'slug' => is_string($slug) ? Str::slug($slug) : $slug,
            'color' => $this->filled('color') && is_string($this->input('color')) ? strtolower($this->input('color')) : '#6b7280',
        ]);
    }

    /** Quay lại kèm id tag đang sửa để mở lại đúng hộp thoại. */
    protected function failedValidation(Validator $validator): void
    {
        session()->flash('tag_dialog', $this->route('tag')?->id ?? 'new');

        // Slug tự tạo từ tên (người dùng để trống ô slug) bị trùng / không tạo được → báo ở ô tên, chỗ họ đã gõ
        $errors = $validator->errors();
        if (! $this->manualSlug && $errors->has('slug') && ! $errors->has('name')) {
            $errors->add('name', $errors->first('slug') === $this->messages()['slug.unique']
                ? 'Đã có tag tên này (hoặc gần giống, cùng slug "'.$this->input('slug').'").'
                : 'Tên này không tạo được slug, hãy nhập slug gồm chữ không dấu, số hoặc dấu gạch ngang.');
            $errors->forget('slug');
        }

        parent::failedValidation($validator);
    }
}
