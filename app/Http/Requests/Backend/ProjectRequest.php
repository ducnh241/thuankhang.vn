<?php

namespace App\Http\Requests\Backend;

use App\Models\Category;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProjectRequest extends FormRequest
{
    public const MAX_DOMAINS = 50;

    public function rules(): array
    {
        return [
            // Khách hàng còn hoạt động, hoặc đúng khách hàng dự án đang gắn (kể cả đã xóa)
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $this->route('project')?->customer_id))],
            'cat_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('type', Category::TYPE_PROJECT)],
            'name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['nullable', 'string', 'max:255'],
            // Domain alias: cùng website, chung source code + database (chi tiết kiểm tra ở after())
            'domains' => ['nullable', 'array', 'max:'.self::MAX_DOMAINS],
            'domains.*' => ['array'],
            'domains.*.domain' => ['bail', 'nullable', 'string', 'max:255'],
            'domains.*.note' => ['nullable', 'string', 'max:255'],
            'domain_note' => ['nullable', 'string'],
            'tech_stack' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(array_keys(Project::STATUSES))],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            'deliver_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            'next_renewal_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'currency' => ['nullable', 'string', 'max:10'],
            'renewal_hosting_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'renewal_domain_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'note' => ['nullable', 'string'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'hosting_ids' => ['nullable', 'array'],
            'hosting_ids.*' => ['integer', 'exists:hostings,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'khách hàng',
            'cat_id' => 'danh mục',
            'name' => 'tên dự án',
            'primary_domain' => 'domain chính',
            'domains' => 'domain alias',
            'domains.*.domain' => 'domain alias',
            'domains.*.note' => 'ghi chú domain',
            'price' => 'giá hợp đồng',
            'renewal_hosting_amount' => 'gia hạn hosting',
            'renewal_domain_amount' => 'gia hạn domain',
        ];
    }

    /**
     * Số tiền nhập kiểu "2.499.999,99" / "12,500,000" → "2499999.99".
     * Chuỗi không phải số giữ nguyên để rule numeric báo lỗi (không âm thầm thành 0).
     */
    protected function prepareForValidation(): void
    {
        $money = [];
        foreach (['price', 'renewal_hosting_amount', 'renewal_domain_amount'] as $field) {
            if ($this->has($field)) {
                $raw = $this->input($field);
                $money[$field] = is_string($raw) || $raw === null ? (Money::parse($raw) ?? $raw) : $raw;
            }
        }
        $this->merge($money);

    }

    /**
     * Domain đã chuẩn hoá (site_key) là duy nhất giữa mọi dự án — cả domain chính lẫn domain alias, tính cả dự án đã xóa
     * (bản ghi đó vẫn giữ domain trong DB) — báo lỗi rõ thay vì lỗi SQL. Domain alias phải là tên miền thật, không trùng
     * nhau / trùng domain chính trong cùng dự án.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $current = $this->route('project');
            $domain = $this->input('primary_domain');
            $primaryKey = is_string($domain) ? Project::normalizeSiteKey($domain) : null; // mảng: rule string đã báo lỗi
            if ($primaryKey !== null && ($owner = $this->owner($primaryKey, $current))) {
                $validator->errors()->add('primary_domain', "Domain \"{$primaryKey}\" đã gắn với {$owner}");
            }

            $seen = $primaryKey !== null ? [$primaryKey => 'domain chính'] : [];
            foreach ((array) $this->input('domains', []) as $i => $row) {
                $field = "domains.{$i}.domain";
                if (! is_array($row) || $validator->errors()->has($field) || $validator->errors()->has("domains.{$i}")) {
                    continue;
                }
                if (self::blankRow($row)) {
                    continue; // dòng trống (vừa bấm "Thêm" chưa nhập): bỏ qua
                }
                $key = Project::normalizeSiteKey(is_string($row['domain'] ?? null) ? $row['domain'] : null);
                if ($key === null) {
                    $validator->errors()->add($field, 'Nhập domain (hoặc xóa dòng này).');
                } elseif (! Project::hostUrl($key)) {
                    $validator->errors()->add($field, "\"{$key}\" không phải tên miền (vd shop.com, en.shop.com).");
                } elseif (isset($seen[$key])) {
                    $validator->errors()->add($field, "Domain \"{$key}\" bị trùng với {$seen[$key]}.");
                } elseif ($owner = $this->owner($key, $current)) {
                    $validator->errors()->add($field, "Domain \"{$key}\" đã gắn với {$owner}");
                }
                if ($key !== null) {
                    $seen[$key] ??= 'dòng '.($i + 1);
                }
            }
        }];
    }

    /** Dự án khác (kể cả đã xóa) đang dùng domain này làm domain chính hoặc alias: "dự án #3 — Tên", null nếu không. */
    private function owner(string $key, ?Project $current): ?string
    {
        $project = Project::withTrashed()
            ->where(fn ($q) => $q->where('site_key', $key)->orWhereHas('domains', fn ($d) => $d->where('site_key', $key)))
            ->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->first();

        return $project ? "dự án #{$project->id} — {$project->name}".($project->trashed() ? ' (đã xóa)' : '') : null;
    }

    /** Domain alias đã làm sạch, theo thứ tự nhập, bỏ dòng trống: [['domain' => …, 'note' => …], …]. */
    public function domains(): array
    {
        $rows = array_filter($this->validated('domains') ?? [], fn ($row) => ! self::blankRow($row));

        return array_values(array_map(fn ($row) => [
            'domain' => trim($row['domain']),
            'note' => is_string($row['note'] ?? null) && trim($row['note']) !== '' ? trim($row['note']) : null,
        ], $rows));
    }

    /** Dòng domain alias để trống cả domain lẫn ghi chú (giữ nguyên chỉ số dòng để lỗi trỏ đúng dòng đang hiện). */
    private static function blankRow(array $row): bool
    {
        $blank = fn ($value) => $value === null || (is_string($value) && trim($value) === '');

        return $blank($row['domain'] ?? null) && $blank($row['note'] ?? null);
    }
}
