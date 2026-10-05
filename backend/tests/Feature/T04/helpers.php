<?php

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;

require_once __DIR__.'/../T03/helpers.php';

/** OtpSender giả: ghi lại mã rõ để test biết mã (production không có đường nào lấy được mã rõ). */
class VvCapturingOtpSender implements OtpSender
{
    /** @var list<array{user_id: int, purpose: string, channel: string, destination: string, code: string}> */
    public array $sent = [];

    public function send(User $user, OtpPurpose $purpose, string $channel, string $destination, string $code): void
    {
        $this->sent[] = [
            'user_id' => $user->id,
            'purpose' => $purpose->value,
            'channel' => $channel,
            'destination' => $destination,
            'code' => $code,
        ];
    }

    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'];
    }
}

function vvFakeOtp(): VvCapturingOtpSender
{
    $fake = new VvCapturingOtpSender;
    app()->instance(OtpSender::class, $fake);

    return $fake;
}

/** Học sinh chưa xác thực, đã đăng nhập (actingAs) — gọi API với Origin web. */
function vvOtpStudent(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    test()->actingAs($user);

    return $user;
}

function vvOtpSend(array $payload = [])
{
    return test()->postJson(vvApiUrl('/auth/otp/send'), $payload, vvWebHeaders());
}

function vvOtpVerify(string $code)
{
    return test()->postJson(vvApiUrl('/auth/otp/verify'), ['code' => $code], vvWebHeaders());
}

function vvContactUpdate(array $payload)
{
    return test()->putJson(vvApiUrl('/auth/contact'), $payload, vvWebHeaders());
}
