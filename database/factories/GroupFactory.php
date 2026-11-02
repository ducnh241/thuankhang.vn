<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Group> */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'permission_data' => ['backend_access'],
        ];
    }

    /** Group có thêm các quyền chỉ định, ví dụ ->withPermissions('user', 'user/edit'). */
    public function withPermissions(string ...$keys): static
    {
        return $this->state(fn (array $attributes) => [
            'permission_data' => array_values(array_unique(array_merge($attributes['permission_data'] ?? [], $keys))),
        ]);
    }
}
