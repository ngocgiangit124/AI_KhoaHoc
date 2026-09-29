<?php

namespace App\Services\Auth;

use App\Mail\StaffNewDeviceMail;
use App\Models\StaffKnownDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * "Email cảnh báo thiết bị mới cho GV" (tasks.md T28, api-contract §2.5) — chỉ
 * áp dụng cho giáo viên (admin/quản lý trang xác thực bằng MFA email mỗi lần
 * đăng nhập, đã đủ mạnh — README §3.2, §8.1).
 */
class StaffDeviceService
{
    /**
     * Ghi nhận thiết bị của lần đăng nhập hiện tại; gửi cảnh báo nếu là thiết
     * bị CHƯA từng thấy của user này. Không có `deviceId` (client cũ/không
     * gửi `X-Device-Id`) → bỏ qua hoàn toàn (không thể xác định "mới hay cũ",
     * không chặn đăng nhập vì đây chỉ là cảnh báo, không phải kiểm soát truy
     * cập — S15 mức Thấp).
     */
    public function recordLoginAndWarnIfNew(User $user, ?string $deviceId): void
    {
        if ($deviceId === null || $deviceId === '') {
            return;
        }

        $isNew = DB::transaction(function () use ($user, $deviceId): bool {
            $existing = StaffKnownDevice::query()
                ->where('user_id', $user->getKey())
                ->where('device_id', $deviceId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['last_seen_at' => now()])->save();

                return false;
            }

            StaffKnownDevice::query()->create([
                'user_id' => $user->getKey(),
                'device_id' => $deviceId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            return true;
        });

        // Thiết bị ĐẦU TIÊN của 1 tài khoản (chưa từng có bản ghi nào) không
        // tính là "mới" theo nghĩa đáng cảnh báo — đó là lần dùng bình thường
        // đầu tiên. Chỉ cảnh báo từ thiết bị thứ 2 trở đi.
        if ($isNew && $this->hasOtherKnownDevice($user, $deviceId)) {
            Mail::to($user->email)->queue(new StaffNewDeviceMail($user->name));
        }
    }

    private function hasOtherKnownDevice(User $user, string $currentDeviceId): bool
    {
        return StaffKnownDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_id', '!=', $currentDeviceId)
            ->exists();
    }
}
