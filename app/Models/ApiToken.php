<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Token API (Bearer) của tài khoản quản trị. Chỉ lưu SHA-256; bản gốc trả về một lần lúc tạo. */
class ApiToken extends Model
{
    public const PREFIX = 'tk_';

    protected $fillable = ['user_id', 'name', 'token_hash', 'prefix', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} [token, chuỗi token gốc — chỉ có lúc này] */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);
        $token = self::create([
            'user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10), 'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return str_starts_with($plain, self::PREFIX) ? self::with('user.group')->where('token_hash', hash('sha256', $plain))->first() : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
