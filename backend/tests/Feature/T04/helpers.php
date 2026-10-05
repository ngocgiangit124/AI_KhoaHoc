<?php

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\Otp\OtpSender;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

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
    return vvActAsStudent(User::factory()->create($attrs));
}

/**
 * actingAs + gắn phiên hiện hành (T05): mọi request tiếp theo của test gửi cookie `vv_session` khớp
 * `users.current_session_id`, nên qua được middleware `student.single_session`.
 */
function vvActAsStudent(User $user): User
{
    $sessionId = Str::random(40);
    User::query()->whereKey($user->getKey())->update(['current_session_id' => $sessionId]);
    $user = $user->fresh();

    test()->actingAs($user)->withCredentials()->withCookie((string) config('session.cookie'), $sessionId);

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

/**
 * Mô phỏng trình duyệt giữ cookie phiên từ response đăng ký/đăng nhập cho các request sau (T05:
 * `student.single_session` so session id của request với `users.current_session_id`).
 */
function vvFollowSession(TestResponse $response): void
{
    $cookie = $response->getCookie((string) config('session.cookie'));

    test()->withCredentials()->withCookie((string) config('session.cookie'), $cookie->getValue());
}
