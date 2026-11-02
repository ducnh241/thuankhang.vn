<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Domain alias của dự án (cùng website với domain chính). site_key tự chuẩn hoá theo domain. */
class ProjectDomain extends Model
{
    protected $fillable = ['project_id', 'domain', 'note', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (ProjectDomain $domain) {
            $domain->site_key = Project::normalizeSiteKey($domain->domain);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** Link mở website, chỉ khi là tên miền thật. */
    public function url(): ?string
    {
        return Project::hostUrl($this->site_key);
    }
}
