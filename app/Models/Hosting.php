<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hosting extends Model
{
    use HasFactory;

    public const OWNERSHIPS = ['mine' => 'Của mình', 'customer' => 'Của khách'];

    protected $fillable = ['name', 'provider', 'plan_name', 'ownership', 'ip', 'specs', 'register_date', 'expire_date', 'alert_days', 'is_active', 'note'];

    protected function casts(): array
    {
        return [
            // Số đếm từ withCount: một số driver MySQL trả về chuỗi ("0") → ép số nguyên để so sánh === 0 không sai
            'projects_count' => 'integer',
            'specs' => 'array',
            'register_date' => 'date',
            'expire_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('note');
    }

    /** "6 CPU · 12 GB RAM · 200 GB" — bỏ các thông số bằng 0 / chưa nhập. */
    public function specLabel(): string
    {
        $specs = (array) $this->specs;
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',');

        return collect([
            ! empty($specs['cpu']) ? (int) $specs['cpu'].' CPU' : null,
            ! empty($specs['ram_gb']) ? $num($specs['ram_gb']).' GB RAM' : null,
            ! empty($specs['disk_gb']) ? $num($specs['disk_gb']).' GB' : null,
        ])->filter()->implode(' · ');
    }

    /** Số ngày còn lại tới ngày hết hạn (âm = đã quá hạn), null nếu không có ngày. */
    public function daysLeft(): ?int
    {
        return $this->expire_date ? (int) now()->startOfDay()->diffInDays($this->expire_date, false) : null;
    }
}
