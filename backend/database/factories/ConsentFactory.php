<?php

namespace Database\Factories;

use App\Enums\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ConsentType::PrivacyPolicy,
            'policy_version' => config('privacy.policy_version'),
            'granted_by' => Consent::GRANTED_BY_SELF,
            'channel' => Consent::CHANNEL_WEB_FORM,
            'granted_at' => now(),
            'ip' => fake()->ipv4(),
            'user_agent' => 'Pest',
        ];
    }
}
