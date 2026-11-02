<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    /** Phát sinh: chi phí thêm ngoài giá hợp đồng (vd làm thêm tính năng sau bàn giao) — không trừ vào nợ hợp đồng. */
    public const TYPES = ['new' => 'Thu mới', 'renewal' => 'Gia hạn', 'extra' => 'Phát sinh'];

    /** Màu tag loại khoản thu trong giao diện admin. */
    public const TYPE_TAGS = ['new' => 'ant-tag-green', 'renewal' => 'ant-tag-cyan', 'extra' => 'ant-tag-orange'];

    /** unpaid: đã ghi nhưng khách chưa trả — tính công nợ, chưa tính doanh thu (paid_at khi đó là ngày phát sinh). */
    public const STATUSES = ['paid' => 'Đã thu', 'unpaid' => 'Chưa thu'];

    /** Loại được để "chưa thu". Thu mới chưa thu đã nằm sẵn trong nợ hợp đồng (giá − đã thu) nên không cần. */
    public const UNPAID_TYPES = ['extra'];

    public const METHODS = [
        'bank_transfer' => 'Chuyển khoản',
        'cash' => 'Tiền mặt',
        'momo' => 'Momo',
        'paypal' => 'PayPal',
        'other' => 'Khác',
    ];

    protected $attributes = ['status' => 'paid'];

    protected static function booted(): void
    {
        // Chỉ loại Phát sinh được để chưa thu; khoản chưa thu chưa có phương thức thanh toán
        static::saving(function (Payment $payment) {
            if (! in_array($payment->type, self::UNPAID_TYPES, true) || $payment->status !== 'unpaid') {
                $payment->status = 'paid';
            } else {
                $payment->method = null;
            }
        });
        // Thêm / sửa / xóa khoản thu → cập nhật trạng thái thanh toán của dự án (giống nhánh main).
        // Sửa đổi dự án của khoản thu thì tính lại cả dự án cũ.
        // Tính cả dự án đã xóa mềm, để khi khôi phục dự án thì trạng thái vẫn đúng.
        static::saved(function (Payment $payment) {
            Project::withTrashed()->find($payment->project_id)?->recalculatePaymentStatus();
            if ($payment->wasChanged('project_id') && ($old = $payment->getOriginal('project_id'))) {
                Project::withTrashed()->find($old)?->recalculatePaymentStatus();
            }
        });
        static::deleted(fn (Payment $payment) => Project::withTrashed()->find($payment->project_id)?->recalculatePaymentStatus());
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'Thu mới';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isUnpaid(): bool
    {
        return $this->status === 'unpaid';
    }

    /** Tiền đã nhận — mọi chỗ tính doanh thu / đã thu dùng scope này. */
    public function scopeReceived(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), 'paid');
    }

    /** Phát sinh chưa thu — cộng vào công nợ. */
    public function scopeUnpaid(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), 'unpaid');
    }

    /** Thu mới (gồm dữ liệu cũ không ghi loại) — so với giá hợp đồng. */
    public function scopeContract(Builder $query): void
    {
        $query->where(fn ($q) => $q->where($q->qualifyColumn('type'), 'new')->orWhereNull($q->qualifyColumn('type')));
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? (string) $this->method;
    }

    protected $fillable = ['project_id', 'amount', 'type', 'status', 'paid_at', 'method', 'note', 'invoice_file_path'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
