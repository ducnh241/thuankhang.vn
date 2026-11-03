<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Ghi nhật ký hệ thống (audit_logs) cho mọi model quản trị đăng ký trong AppServiceProvider.
 * changes = {old: {...}, new: {...}}, chỉ gồm các cột đã đổi. Khác AuditObserver (lịch sử riêng của dự án).
 */
class SystemAuditObserver
{
    private const IGNORED = ['created_at', 'updated_at', 'remember_token'];

    /** Cột nhạy cảm: chỉ ghi "có thay đổi", không chép giá trị (mật khẩu, thông tin đăng nhập domain…). */
    private const MASKED = ['password', 'domain_note'];

    private const MASK = '••••••';

    public function created(Model $model): void
    {
        AuditLog::record('created', $model, ['old' => null, 'new' => $this->clean($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = array_diff_key($model->getChanges(), array_flip(self::IGNORED));
        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }

        AuditLog::record('updated', $model, ['old' => $this->mask($old), 'new' => $this->mask($changes)]);
    }

    public function deleted(Model $model): void
    {
        AuditLog::record('deleted', $model, ['old' => $this->clean($model->getAttributes()), 'new' => null]);
    }

    public function restored(Model $model): void
    {
        AuditLog::record('restored', $model, ['old' => null, 'new' => $this->clean($model->getAttributes())]);
    }

    private function clean(array $attributes): array
    {
        return $this->mask(array_diff_key($attributes, array_flip(self::IGNORED)));
    }

    private function mask(array $attributes): array
    {
        foreach (self::MASKED as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null && $attributes[$key] !== '') {
                $attributes[$key] = self::MASK;
            }
        }

        return $attributes;
    }
}
