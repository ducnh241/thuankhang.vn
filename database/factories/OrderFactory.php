<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $goods = fake()->numberBetween(1, 20) * 100000;

        return [
            'code' => Order::generateCode(),
            'member_id' => Member::factory(),
            'goods_amount' => $goods,
            'vat_amount' => intdiv($goods, 11),
            'shipping_fee' => 30000,
            'total_amount' => $goods + 30000,
            'delivery_type' => Order::DELIVERY_SHIP,
            'recipient_name' => fake()->name(),
            'recipient_phone' => '09'.fake()->numerify('########'),
            'province' => 'ha-noi',
            'address' => fake()->streetAddress(),
            'expires_at' => now()->addDay(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => array_filter([
            'status' => $status,
            'paid_at' => in_array($status, [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true) ? now() : null,
        ]));
    }
}
