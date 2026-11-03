<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Sản phẩm '.fake()->unique()->numberBetween(1, 999999);

        return [
            'category_id' => Category::factory()->state(['type' => Category::TYPE_PRODUCT]),
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 1_100_000,
            'vat_rate' => '10.00',
        ];
    }
}
