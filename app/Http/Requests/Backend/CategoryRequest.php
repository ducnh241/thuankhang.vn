<?php

namespace App\Http\Requests\Backend;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Dùng cho mọi loại danh mục; loại (type) lấy từ tham số {kind} của route. Thứ tự (priority) không nhận từ form. */
class CategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $current = $this->route('category');
        $type = $this->type();

        return [
            'name' => ['required', 'string', 'max:255'],
            // Slug chỉ cần duy nhất trong cùng loại: dự án và tin tức được trùng slug
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii',
                Rule::unique('categories', 'slug')->where('type', $type)->ignore($current)],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('type', $type)],
            'status' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tên danh mục', 'parent_id' => 'danh mục cha', 'status' => 'trạng thái', 'description' => 'mô tả'];
    }

    /** Slug để trống thì sinh từ tên. */
    protected function prepareForValidation(): void
    {
        $source = $this->filled('slug') ? $this->input('slug') : $this->input('name');
        // Gửi sai kiểu (mảng) thì để nguyên cho rule string báo lỗi, đừng ép kiểu (lỗi 500)
        $this->merge(['slug' => is_string($source) || $source === null ? Str::slug((string) $source) : $source]);
    }

    /** Không chọn chính nó hoặc con cháu của nó làm cha (tạo vòng lặp). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $current = $this->route('category');
            if (! $current || ! $this->filled('parent_id')) {
                return;
            }

            if (in_array((int) $this->input('parent_id'), Category::descendantIds($current->id, $this->type()), true)) {
                $validator->errors()->add('parent_id', 'Danh mục cha không thể là chính nó hoặc danh mục con của nó.');
            }
        }];
    }

    /** Dữ liệu lưu (thứ tự do controller tính: thêm mới / đổi cha thì xuống cuối, kéo thả để sắp xếp). */
    public function categoryData(): array
    {
        $data = $this->validated();
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['status'] = (bool) $data['status'];

        return $data;
    }

    private function type(): int
    {
        return Category::KINDS[$this->route('kind')]['type'];
    }
}
