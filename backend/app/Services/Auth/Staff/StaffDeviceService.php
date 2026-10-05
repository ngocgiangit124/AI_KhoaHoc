<?php

namespace App\Services\Auth\Staff;

use App\Mail\StaffNewDeviceMail;
use App\Models\StaffDevice;
use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nhận diện thiết bị đăng nhập của giáo viên và gửi email cảnh báo khi là thiết bị mới
 * (S15, US-016 AC10). Không bao giờ chặn đăng nhập: mọi lỗi chỉ được ghi log.
 */
class StaffDeviceService
{
    public function recordAndWarnIfNew(User $user, Request $request): void
    {
        try {
            $userAgent = Str::limit((string) $request->userAgent(), 255, '');
            // X-Device-Id (UUID do frontend sinh) nếu có, nếu không thì dùng User-Agent làm dấu vết thô.
            $deviceId = StudentSessionService::deviceIdFromRequest($request, allowBody: true);
            $hash = hash('sha256', $deviceId !== null ? 'id:'.$deviceId : 'ua:'.$userAgent);
            $now = now();

            $existing = StaffDevice::query()->where('user_id', $user->getKey())->where('device_hash', $hash)->first();

            if ($existing === null) {
                try {
                    $device = StaffDevice::query()->create([
                        'user_id' => $user->getKey(),
                        'device_hash' => $hash,
                        'last_ip' => $request->ip(),
                        'user_agent' => $userAgent,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Hai lần đăng nhập song song từ cùng thiết bị: bên kia đã ghi/cảnh báo.
                    return;
                }

                try {
                    Mail::to($user->email)->send(new StaffNewDeviceMail(
                        $user->name,
                        $now->timezone((string) config('app.timezone'))->format('H:i d/m/Y'),
                        $request->ip(),
                        $userAgent,
                    ));
                } catch (Throwable $e) {
                    // Cảnh báo chưa tới giáo viên: xoá bản ghi để lần đăng nhập sau vẫn coi là thiết bị mới.
                    $device->delete();

                    throw $e;
                }

                return;
            }

            $existing->forceFill(['last_seen_at' => $now, 'last_ip' => $request->ip(), 'user_agent' => $userAgent])->save();
        } catch (Throwable $e) {
            Log::warning('staff_device.warn_failed', ['exception' => $e::class]);
        }
    }
}
