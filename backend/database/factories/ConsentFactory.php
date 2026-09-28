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
            'granted_by' => 'self',
            'channel' => 'web_form',
            'destination_masked' => null,
            'granted_at' => now(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PestTestAgent/1.0',
        ];
    }
}
