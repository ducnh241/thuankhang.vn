<?php

namespace Tests\Unit;

use App\Models\Group;
use App\Models\Menu;
use PHPUnit\Framework\TestCase;

/**
 * Một số máy chủ (pdo_mysql dựng với libmysqlclient) trả số đếm withCount dạng chuỗi "0" → view so `=== 0` sai,
 * nút xóa bị khóa dù không còn gì liên kết. Các model ép số nguyên cho cột đếm.
 */
class CountCastsTest extends TestCase
{
    public function test_withcount_attributes_are_integers_even_when_driver_returns_strings(): void
    {
        foreach ([
            [new Group, 'users_count'],
            [new Menu, 'items_count'],
        ] as [$model, $attribute]) {
            $model->setRawAttributes([$attribute => '0']);
            $this->assertSame(0, $model->{$attribute}, $model::class);
            $model->setRawAttributes([$attribute => '3']);
            $this->assertSame(3, $model->{$attribute}, $model::class);
        }
    }
}
