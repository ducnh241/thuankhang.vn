<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Ghi lịch sử thay đổi (project_audit_logs) cho Project, Payment, Customer, Hosting.
 * changes = {old: {...}, new: {...}}, chỉ gồm các cột đã đổi.
 */
class AuditObserver
{
    private const IGNORED = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'site_key', 'slug'];

    /** Cột chứa thông tin nhạy cảm (tài khoản đăng nhập domain…): lịch sử chỉ ghi "có thay đổi", không chép giá trị. */
    private const MASKED = ['domain_note'];

    private const MASK = '••••••';

    public function created(Model $model): void
    {
        $this->log($model, 'created', ['old' => null, 'new' => $this->clean($model->getAttributes())]);
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
        $old = $this->mask($old);
        $changes = $this->mask($changes);

        $this->log($model, 'updated', ['old' => $old, 'new' => $changes]);

        // Khoản thu bị chuyển sang dự án khác: dự án cũ cũng cần thấy trong lịch sử
        if ($model instanceof Payment && array_key_exists('project_id', $changes) && $old['project_id']) {
            $this->log($model, 'updated', ['old' => $old, 'new' => $changes], (int) $old['project_id']);
        }
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', ['old' => $this->clean($model->getAttributes()), 'new' => null]);
    }

    public function restored(Model $model): void
    {
        $this->log($model, 'restored', ['old' => null, 'new' => $this->clean($model->getAttributes())]);
    }

    private function log(Model $model, string $action, array $changes, ?int $projectId = null): void
    {
        $user = Auth::user();

        ProjectAuditLog::create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'project_id' => $projectId ?? match (true) {
                $model instanceof Project => $model->getKey(),
                $model instanceof Payment => $model->project_id,
                default => null,
            },
            'action' => $action,
            'changes' => $changes,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'system',
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'created_at' => now(),
        ]);
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
