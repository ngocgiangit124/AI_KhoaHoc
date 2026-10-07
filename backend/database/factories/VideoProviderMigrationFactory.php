<?php

namespace Database\Factories;

use App\Models\VideoAsset;
use App\Models\VideoProviderMigration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoProviderMigration>
 */
class VideoProviderMigrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_asset_id' => VideoAsset::factory()->ready(),
            'from_provider' => 'internal',
            'from_library_id' => 'default',
            'from_video_id' => (string) Str::uuid(),
            'to_provider' => 'bunny',
            'to_library_id' => '777',
            'to_video_id' => (string) Str::uuid(),
            'status' => VideoProviderMigration::PENDING,
            'attempts' => 0,
        ];
    }
}
