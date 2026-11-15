<?php

namespace Database\Factories;

use App\Models\FileUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FileUpload> */
class FileUploadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->word().'.jpg',
            'path' => FileUpload::DIRECTORY.'/2026/11/'.Str::random(40).'.jpg',
            'extension' => 'jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(1_000, 900_000),
        ];
    }

    public function document(string $extension = 'pdf', string $mime = 'application/pdf'): static
    {
        return $this->state(fn () => [
            'name' => fake()->word().'.'.$extension,
            'path' => FileUpload::DIRECTORY.'/2026/11/'.Str::random(40).'.'.$extension,
            'extension' => $extension,
            'mime' => $mime,
        ]);
    }
}
