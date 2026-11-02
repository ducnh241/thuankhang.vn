<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'permission_data'])]
class Group extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Số đếm từ withCount: một số driver MySQL trả về chuỗi ("0") → ép số nguyên để so sánh === 0 không sai
            'users_count' => 'integer',
            'permission_data' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permission_data ?? [], true);
    }

    /** Mọi key quyền khai báo trong config/permission.php. */
    public static function allPermissionKeys(): array
    {
        $keys = [];

        foreach (config('permission') as $module => $config) {
            $keys[] = $module;

            foreach ($config['items'] ?? [] as $action => $label) {
                $keys[] = $module.'/'.$action;
            }
        }

        return $keys;
    }

    /**
     * Chuyển input dạng permission[post][add]=1 thành mảng key phẳng,
     * chỉ giữ các key có trong config/permission.php.
     */
    public static function normalizePermissionInput(?array $input): array
    {
        $result = [];

        foreach (config('permission') as $module => $config) {
            if (empty($input[$module])) {
                continue;
            }

            $result[] = $module;

            foreach ($config['items'] ?? [] as $action => $label) {
                if (! empty($input[$module][$action])) {
                    $result[] = $module.'/'.$action;
                }
            }
        }

        return $result;
    }
}
