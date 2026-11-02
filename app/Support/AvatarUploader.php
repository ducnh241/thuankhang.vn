<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Lưu/xoá avatar trên disk public (storage/app/public/avatars). */
class AvatarUploader
{
    public const DIRECTORY = 'avatars';

    public function store(UploadedFile $file): string
    {
        return $file->store(self::DIRECTORY, 'public');
    }

    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
