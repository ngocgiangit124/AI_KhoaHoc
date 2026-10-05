<?php

use Illuminate\Support\Facades\DB;

function vvApiUrl(string $path): string
{
    return 'http://'.config('app.api_host').'/api/v1'.$path;
}

/** @return array<string, string> */
function vvWebHeaders(array $extra = []): array
{
    return array_merge(['Origin' => config('app.frontend_url')], $extra);
}

/** @return array<string, mixed> */
function vvRegisterPayload(array $override = []): array
{
    return array_merge([
        'name' => 'Nguyễn Văn An',
        'date_of_birth' => now('Asia/Ho_Chi_Minh')->subYears(20)->toDateString(),
        'email' => 'an@example.com',
        'phone' => '0912345678',
        'grade_level' => 9,
        'password' => 'matkhau-123',
        'password_confirmation' => 'matkhau-123',
        'accept_terms' => true,
        'accept_privacy' => true,
        'captcha_token' => 'ok',
    ], $override);
}

function vvRegister(array $override = [], array $headers = [])
{
    return test()->postJson(vvApiUrl('/auth/register'), vvRegisterPayload($override), vvWebHeaders($headers));
}

function vvWipeUsers(): void
{
    DB::table('consents')->delete();
    DB::table('users')->delete();
}

/** Mô phỏng trình duyệt mới (mỗi request thật là 1 process: guard/session không dính sang request sau). */
function vvResetClient(): void
{
    app('auth')->forgetGuards();
    test()->flushSession();
}
