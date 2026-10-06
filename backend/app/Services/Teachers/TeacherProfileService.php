<?php

namespace App\Services\Teachers;

use App\Enums\ConsentType;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Mail\TeacherProfileEditedByStaffMail;
use App\Models\Consent;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ImageUploadService;
use App\Support\VisibleText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * ĐIỂM GHI DUY NHẤT vào `teacher_profiles` (US-020, ADR-005). Các cột ngoài `headline`/`bio` chỉ đổi bằng forceFill ở đây (S17).
 *
 * Thứ tự khoá của miền này (data-model §4): `users` (S, hoặc X ở `erase`) → `teacher_profiles` (theo `user_id` tăng dần
 * khi nhiều dòng) → `consents`. Không luồng nào khoá `teacher_profiles` rồi mới khoá `users`.
 * Ghi `profile_updated_by` làm InnoDB lấy khoá S trên dòng `users` của người sửa (kiểm FK). Vì vậy luồng ghi nội dung/ảnh khoá S
 * dòng người sửa TRƯỚC dòng giáo viên rồi mới tới `teacher_profiles` (cùng chiều với `StaffAccountService`: khoá các admin rồi mới
 * tới dòng đích). Nếu để FK tự khoá sau khi đã giữ dòng hồ sơ thì sinh chu trình với `changeRole` (race test T36 đã bắt được).
 * - Bật hiển thị trang chủ: khoá MỌI dòng `teacher_profiles` theo PK tăng dần rồi mới đếm (mutex, đúng ở READ COMMITTED).
 * - Sửa nội dung/ảnh/đồng ý/tắt hiển thị/đổi thứ tự: chỉ khoá 1 dòng.
 * Dòng hồ sơ tạo lười bằng `INSERT IGNORE` NGOÀI transaction (không giữ khoá lâu, không gap lock).
 *
 * Gửi lại đúng giá trị cũ (double submit) thì không ghi gì và không audit. Audit không chứa nội dung bio/ảnh.
 */
class TeacherProfileService
{
    /** Số lần thử lại transaction khi InnoDB chọn làm nạn nhân deadlock (data-model §4). */
    private const TRANSACTION_ATTEMPTS = 3;

    /** Nhãn tiếng Việt của trường nội dung, dùng trong email báo giáo viên khi staff sửa hộ. */
    private const FIELD_LABELS = ['headline' => 'dòng chuyên môn', 'bio' => 'phần giới thiệu', 'avatar' => 'ảnh đại diện'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImageUploadService $images,
    ) {}

    /**
     * Chuẩn hoá văn bản hồ sơ: `\r\n`/`\r` → `\n`; cắt khoảng trắng Unicode ở hai đầu và cuối mỗi dòng; tối đa 2 dòng trống
     * liên tiếp (`\n{4,}` → `\n\n\n`); rỗng (chỉ còn khoảng trắng) → null. (Ký tự ẩn không bị lược mà bị rule `PlainText` từ chối.)
     */
    public static function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = (string) preg_replace('/\r\n?/', "\n", $value);
        $text = (string) preg_replace('/\p{Zs}+(?=\n)/u', '', $text);
        $text = (string) preg_replace('/\n{4,}/', "\n\n\n", $text);
        $text = VisibleText::trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Sửa từng phần headline/bio (AC21: trường không gửi giữ nguyên, nên hai người sửa hai trường khác nhau không đè nhau).
     *
     * @param  array{headline?: mixed, bio?: mixed}  $data
     *
     * @throws DomainException NOT_TEACHER
     */
    public function updateContent(User $teacher, array $data, User $actor): TeacherProfile
    {
        $this->assertTeacher($teacher);

        $values = [];
        foreach (['headline', 'bio'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = self::normalizeText($data[$field]);
            }
        }

        $this->ensureRow($teacher);

        return DB::transaction(function () use ($teacher, $values, $actor): TeacherProfile {
            $this->lockUsers($teacher, $actor, requireTeacher: true);
            $profile = $this->lockProfile($teacher);

            $fields = [];
            $bioFrom = $profile->bio;
            foreach ($values as $field => $value) {
                if ($profile->{$field} !== $value) {
                    $fields[] = $field;
                }
            }

            if ($fields === []) {
                return $profile;
            }

            $profile->fill(array_intersect_key($values, array_flip($fields)));
            $this->markEdited($profile, $actor);
            $profile->save();

            $changes = ['fields' => $fields, 'on_behalf' => $this->onBehalf($teacher, $actor)];
            if (in_array('bio', $fields, true)) {
                $changes['bio_length'] = ['from' => $bioFrom === null ? 0 : mb_strlen($bioFrom), 'to' => $profile->bio === null ? 0 : mb_strlen($profile->bio)];
            }
            $this->audit->log('teacher_profile.update', $teacher, $changes);
            $this->notifyEditedByStaff($teacher, $actor, $profile, $fields);

            return $profile;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Thay ảnh đại diện: file mới ghi TRƯỚC (ngoài transaction), commit rồi mới xoá file cũ; lỗi trong transaction thì xoá
     * file mới (mẫu `CourseService::update`). File mồ côi do tiến trình chết giữa hai bước: `images:prune-orphans` dọn.
     *
     * @throws DomainException NOT_TEACHER
     */
    public function replaceAvatar(User $teacher, UploadedFile $file, User $actor): TeacherProfile
    {
        $this->assertTeacher($teacher);

        $newPath = $this->images->storeWebp($file, 'avatar', (int) config('teacher_profile.avatar_max_edge'));
        $oldPath = null;

        try {
            $this->ensureRow($teacher);

            $profile = DB::transaction(function () use ($teacher, $newPath, $actor, &$oldPath): TeacherProfile {
                $oldPath = null; // đặt lại mỗi lần thử (transaction có thể chạy lại sau deadlock)
                $this->lockUsers($teacher, $actor, requireTeacher: true);
                $profile = $this->lockProfile($teacher);

                $oldPath = $profile->avatar_path;
                $profile->forceFill(['avatar_path' => $newPath]);
                $this->markEdited($profile, $actor);
                $profile->save();

                $this->audit->log('teacher_profile.update', $teacher, [
                    'fields' => ['avatar'],
                    'on_behalf' => $this->onBehalf($teacher, $actor),
                    'avatar' => 'changed',
                ]);
                $this->notifyEditedByStaff($teacher, $actor, $profile, ['avatar']);

                return $profile;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (Throwable $e) {
            $this->images->delete($newPath);

            throw $e;
        }

        $this->images->delete($oldPath);

        return $profile;
    }

    /**
     * Xoá ảnh đại diện. Không yêu cầu còn là giáo viên (admin gỡ ảnh của người đã đổi vai trò vẫn được); không có ảnh
     * thì không làm gì và không audit.
     */
    public function removeAvatar(User $teacher, User $actor): ?TeacherProfile
    {
        $oldPath = null;

        $profile = DB::transaction(function () use ($teacher, $actor, &$oldPath): ?TeacherProfile {
            $oldPath = null; // đặt lại mỗi lần thử (transaction có thể chạy lại sau deadlock)
            $this->lockUsers($teacher, $actor, requireTeacher: false);
            $profile = $this->findLockedProfile($teacher);

            if ($profile === null || $profile->avatar_path === null) {
                return $profile;
            }

            $oldPath = $profile->avatar_path;
            $profile->forceFill(['avatar_path' => null]);
            $this->markEdited($profile, $actor);
            $profile->save();

            $this->audit->log('teacher_profile.update', $teacher, [
                'fields' => ['avatar'],
                'on_behalf' => $this->onBehalf($teacher, $actor),
                'avatar' => 'removed',
            ]);
            $this->notifyEditedByStaff($teacher, $actor, $profile, ['avatar']);

            return $profile;
        }, self::TRANSACTION_ATTEMPTS);

        $this->images->delete($oldPath);

        return $profile;
    }

    /**
     * Giáo viên tự đồng ý công khai (BR4). Không có route/tham số cho phép người khác đồng ý thay.
     *
     * @throws DomainException CONSENT_VERSION_CHANGED (409), NOT_TEACHER
     */
    public function giveConsent(User $teacher, string $version): TeacherProfile
    {
        $current = (string) config('teacher_profile.consent_version');

        if ($version !== $current) {
            throw new DomainException(
                'CONSENT_VERSION_CHANGED',
                'Nội dung đồng ý đã được cập nhật. Vui lòng đọc lại nội dung mới rồi đồng ý.',
                409,
                ['current_version' => $current],
            );
        }

        $this->assertTeacher($teacher);
        $this->ensureRow($teacher);

        return DB::transaction(function () use ($teacher, $current): TeacherProfile {
            $this->lockUsers($teacher, null, requireTeacher: true);
            $profile = $this->lockProfile($teacher);

            if ($profile->public_consent_at !== null && $profile->public_consent_version === $current) {
                return $profile;
            }

            $now = now();
            $profile->forceFill([
                'public_consent_at' => $now,
                'public_consent_version' => $current,
            ])->save();

            $userAgent = mb_substr((string) Request::userAgent(), 0, 255);
            Consent::create([
                'user_id' => $teacher->getKey(),
                'type' => ConsentType::TeacherPublicProfile,
                'policy_version' => $current,
                'granted_by' => Consent::GRANTED_BY_SELF,
                'channel' => Consent::CHANNEL_WEB_FORM,
                'granted_at' => $now,
                'ip' => Request::ip(),
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ]);

            $this->audit->log('teacher_profile.consent', $teacher, ['version' => $current]);

            return $profile;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Rút đồng ý: API công khai trả null NGAY (không cache ở Laravel). Nội dung hồ sơ và cờ trang chủ giữ nguyên (BR9).
     *
     * M1 (security T36): URL ảnh đã từng công khai không được sống tiếp. Rút đồng ý thì sao chép ảnh sang UUID mới, cập nhật
     * `avatar_path`, và xoá file cũ SAU commit (`DB::afterCommit`; lỗi xoá chỉ ghi log, `images:prune-orphans` dọn lại).
     * Không sao chép được ảnh thì vẫn rút đồng ý (ưu tiên quyền của giáo viên), ghi log lỗi và giữ nguyên đường dẫn.
     */
    public function withdrawConsent(User $teacher): ?TeacherProfile
    {
        $rotated = null;

        try {
            return DB::transaction(function () use ($teacher, &$rotated): ?TeacherProfile {
                if ($rotated !== null) { // lần thử trước (deadlock) đã sao chép: bỏ bản đó
                    $this->images->delete($rotated);
                    $rotated = null;
                }

                $this->lockUsers($teacher, null, requireTeacher: false);
                $profile = $this->findLockedProfile($teacher);

                if ($profile === null || $profile->public_consent_at === null) {
                    return $profile;
                }

                $version = $profile->public_consent_version;
                $now = now();
                $oldPath = null;

                $profile->forceFill([
                    'public_consent_at' => null,
                    'public_consent_version' => null,
                    'public_consent_withdrawn_at' => $now,
                ]);

                if ($profile->avatar_path !== null) {
                    try {
                        $rotated = $this->images->rotate($profile->avatar_path);
                        $oldPath = $profile->avatar_path;
                        $profile->forceFill(['avatar_path' => $rotated]);
                    } catch (Throwable $e) {
                        $rotated = null;
                        Log::error('Rút đồng ý nhưng không đổi được tên ảnh đại diện: URL cũ vẫn còn hiệu lực.', [
                            'user_id' => $teacher->getKey(), 'error' => $e->getMessage(),
                        ]);
                    }
                }

                $profile->save();

                Consent::query()
                    ->where('user_id', $teacher->getKey())
                    ->where('type', ConsentType::TeacherPublicProfile->value)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);

                $this->audit->log('teacher_profile.consent_withdraw', $teacher, ['version' => $version]);

                if ($oldPath !== null) {
                    DB::afterCommit(fn () => $this->images->delete($oldPath));
                }

                return $profile;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (Throwable $e) {
            $this->images->delete($rotated);

            throw $e;
        }
    }

    /**
     * Bật/tắt hiển thị trang chủ và/hoặc đặt thứ tự (chỉ Admin/QLT, Gate ở route). Bật hiển thị khi chưa đủ điều kiện vẫn
     * lưu được (AC9). Giới hạn `homepage_max` đếm MỌI dòng đang bật, kể cả người bị khoá/đã đổi vai trò (PO 2026-10-06).
     *
     * @param  bool|null  $show  null = không đổi
     * @param  bool  $orderGiven  có gửi `homepage_order` (kể cả null để xoá thứ tự)
     *
     * @throws DomainException TEACHER_HOMEPAGE_LIMIT (409), NOT_TEACHER (khi bật cho người không còn là giáo viên)
     */
    public function setHomepage(User $teacher, ?bool $show, bool $orderGiven, ?int $order): ?TeacherProfile
    {
        if ($show === true) {
            $this->assertTeacher($teacher);
        }

        // Chỉ tạo dòng khi thật sự có gì để ghi (bật, hoặc đặt/xoá thứ tự); tắt cờ cho người chưa có hồ sơ là no-op.
        if ($teacher->role === UserRole::Teacher && ($show === true || $orderGiven)) {
            $this->ensureRow($teacher);
        }

        return DB::transaction(function () use ($teacher, $show, $orderGiven, $order): ?TeacherProfile {
            // Không ghi `profile_updated_by` ở đây nên không cần khoá dòng người thao tác (actor lấy từ Auth khi audit).
            $this->lockUsers($teacher, null, requireTeacher: $show === true);

            if ($show === true) {
                // Mutex: khoá mọi dòng theo PK tăng dần, đọc/đếm sau khi đã giữ khoá. Hai admin bật cùng lúc: người thứ hai
                // chờ ở dòng đầu tiên, rồi đọc bản đã commit của người thứ nhất.
                DB::table('teacher_profiles')->orderBy('user_id')->lockForUpdate()->pluck('user_id');
                $profile = TeacherProfile::query()->whereKey($teacher->getKey())->first();
            } else {
                $profile = $this->findLockedProfile($teacher);
            }

            if ($profile === null) {
                if ($show === true) {
                    throw new DomainException('NOT_FOUND', 'Không tìm thấy hồ sơ giáo viên.', 404);
                }

                return null;
            }

            $toggleChanged = $show !== null && $show !== $profile->show_on_homepage;
            $orderChanged = $orderGiven && $order !== $profile->homepage_order;

            if ($toggleChanged && $show === true) {
                $max = (int) config('teacher_profile.homepage_max');
                $others = TeacherProfile::query()
                    ->where('show_on_homepage', true)
                    ->where('user_id', '<>', $teacher->getKey())
                    ->count();

                if ($others >= $max) {
                    throw new DomainException(
                        'TEACHER_HOMEPAGE_LIMIT',
                        "Trang chủ chỉ hiển thị tối đa {$max} giáo viên. Hãy tắt bớt một người trước.",
                        409,
                        ['max' => $max],
                    );
                }
            }

            if (! $toggleChanged && ! $orderChanged) {
                return $profile;
            }

            $fromOrder = $profile->homepage_order;

            if ($toggleChanged) {
                $profile->forceFill(['show_on_homepage' => $show]);
            }

            if ($orderChanged) {
                $profile->forceFill(['homepage_order' => $order]);
            }

            $profile->save();

            if ($toggleChanged) {
                $this->audit->log('teacher_profile.homepage_toggle', $teacher, [
                    'show_on_homepage' => ['from' => ! $show, 'to' => $show],
                ]);
            }

            if ($orderChanged) {
                $this->audit->log('teacher_profile.homepage_order', $teacher, [
                    'homepage_order' => ['from' => $fromOrder, 'to' => $order],
                ]);
            }

            return $profile;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Ẩn danh hoá/xoá tài khoản (T34, US-018): xoá dòng hồ sơ (ảnh, bio, headline, đồng ý, cờ trang chủ), thu hồi mọi
     * bằng chứng đồng ý còn hiệu lực, xoá file ảnh sau commit. Khoá `users` (X) trước rồi mới tới `teacher_profiles`.
     */
    public function erase(User $user): void
    {
        $oldPath = null;

        DB::transaction(function () use ($user, &$oldPath): void {
            $oldPath = null;
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $profile = $this->findLockedProfile($user);

            $revoked = Consent::query()
                ->where('user_id', $user->getKey())
                ->where('type', ConsentType::TeacherPublicProfile->value)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($profile === null && $revoked === 0) {
                return;
            }

            if ($profile !== null) {
                $oldPath = $profile->avatar_path;
                $profile->delete();
            }

            $this->audit->log('teacher_profile.erase', $user);
        }, self::TRANSACTION_ATTEMPTS);

        $this->images->delete($oldPath);
    }

    /**
     * Admin/QLT sửa hộ nội dung của giáo viên ĐANG đồng ý công khai: nội dung có hiệu lực ngay, nên báo email cho giáo viên
     * (không chứa toàn văn bio) để họ biết và rút đồng ý nếu không đồng ý. Gửi sau commit; lỗi gửi không làm hỏng thao tác.
     *
     * @param  list<string>  $fields  headline|bio|avatar
     */
    private function notifyEditedByStaff(User $teacher, User $actor, TeacherProfile $profile, array $fields): void
    {
        if (! $this->onBehalf($teacher, $actor) || $profile->public_consent_at === null || $teacher->email === null) {
            return;
        }

        $labels = array_map(fn (string $f): string => self::FIELD_LABELS[$f], $fields);
        $mail = new TeacherProfileEditedByStaffMail(
            $teacher->name,
            $actor->name,
            now()->timezone((string) config('app.timezone'))->format('H:i d/m/Y'),
            $labels,
        );
        $email = $teacher->email;

        DB::afterCommit(function () use ($email, $mail, $teacher): void {
            try {
                Mail::to($email)->send($mail);
            } catch (Throwable $e) {
                Log::warning('Không gửi được email báo giáo viên về chỉnh sửa hồ sơ.', ['user_id' => $teacher->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }

    private function assertTeacher(User $user): void
    {
        if ($user->role !== UserRole::Teacher) {
            throw new DomainException('NOT_TEACHER', 'Tài khoản này không phải giáo viên.', 422);
        }
    }

    /**
     * Tạo dòng hồ sơ nếu chưa có. NGOÀI transaction: đọc thường trước (không khoá) để không chờ khoá của mutex; chỉ
     * khi thiếu mới `INSERT IGNORE` (trùng khoá do hai request đồng thời thì bỏ qua).
     */
    private function ensureRow(User $teacher): void
    {
        if (DB::table('teacher_profiles')->where('user_id', $teacher->getKey())->exists()) {
            return;
        }

        DB::table('teacher_profiles')->insertOrIgnore([
            'user_id' => $teacher->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Khoá chia sẻ hàng `users`: trước hết dòng người thao tác (nếu khác giáo viên và sẽ ghi `profile_updated_by`), rồi dòng
     * giáo viên (chặn `changeRole` chen ngang giữa lúc kiểm vai trò và lúc ghi). Cùng chiều khoá `users` →
     * `teacher_profiles` ở mọi luồng nên không có chu trình.
     *
     * @throws DomainException NOT_TEACHER
     */
    private function lockUsers(User $teacher, ?User $actor, bool $requireTeacher): void
    {
        if ($actor !== null && $actor->getKey() !== $teacher->getKey()) {
            User::query()->whereKey($actor->getKey())->sharedLock()->first(['id']);
        }

        $this->lockUser($teacher, $requireTeacher);
    }

    /**
     * @throws DomainException NOT_TEACHER
     */
    private function lockUser(User $user, bool $requireTeacher): void
    {
        $locked = User::query()->whereKey($user->getKey())->sharedLock()->first();

        if ($locked === null) {
            throw new DomainException('NOT_FOUND', 'Không tìm thấy giáo viên.', 404);
        }

        if ($requireTeacher) {
            $this->assertTeacher($locked);
        }
    }

    private function lockProfile(User $teacher): TeacherProfile
    {
        return $this->findLockedProfile($teacher)
            ?? throw new DomainException('NOT_FOUND', 'Không tìm thấy hồ sơ giáo viên.', 404);
    }

    private function findLockedProfile(User $teacher): ?TeacherProfile
    {
        return TeacherProfile::query()->whereKey($teacher->getKey())->lockForUpdate()->first();
    }

    private function markEdited(TeacherProfile $profile, User $actor): void
    {
        $profile->forceFill(['profile_updated_by' => $actor->getKey(), 'profile_updated_at' => now()]);
    }

    private function onBehalf(User $teacher, User $actor): bool
    {
        return $teacher->getKey() !== $actor->getKey();
    }
}
