<?php

namespace Tests;

use App\Models\MemberKyc;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Dữ liệu form "Tôi đã chuyển khoản": ảnh bill chuyển khoản (bắt buộc). Dùng disk giả để test không ghi file thật —
     * mỗi lần gọi là một disk giả mới (xóa file của lần gọi trước).
     */
    protected function billUpload(string $name = 'bill.jpg'): array
    {
        Storage::fake(MemberKyc::DISK);

        return ['bill' => UploadedFile::fake()->image($name, 600, 900)];
    }
}
