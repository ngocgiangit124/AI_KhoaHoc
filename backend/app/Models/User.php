<?php

namespace App\Models;

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Larastan không tự suy ra được kiểu cast enum từ phương thức `casts()` (chỉ
 * đọc được `protected $casts` khai báo tĩnh) — khai @property tường minh để
 * phpstan hiểu đúng kiểu, tránh báo sai "always false" ở mọi so sánh enum.
 *
 * L6 (review bảo mật T01/T02) — KHÔNG dùng `Laravel\Sanctum\HasApiTokens`:
 * dự án chỉ xác thực bằng session cookie SPA (`auth:sanctum` + cookie, không
 * phát hành personal access token — S24). Có trait này mà không dùng khiến
 * guard `sanctum` chấp nhận cả Bearer token (bỏ qua CSRF) nếu sau này có ai
 * vô tình gọi `$user->createToken` . Không cần trait cho xác thực SPA qua
 * cookie (guard `sanctum` của Sanctum dùng session guard trước, trait chỉ
 * cần khi thật sự phát hành/kiểm token). Xem test kiến trúc
 * `tests/Arch/NoApiTokensTest.php`.
 *
 * @property UserRole $role
 * @property UserStatus $status
 * @property ParentConsentStatus $parent_consent_status
 * @property string|null $parent_email
 * @property string|null $parent_phone
 * @property Carbon|null $parent_notice_opt_out_at
 * @property Carbon|null $anonymized_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * S17 — mass assignment: CHỈ các trường này được gán qua create()/fill().
     * Cột trạng thái/quyền (role, status, *_verified_at, current_session_id,
     * current_device_id, parent_consent_status, parent_notice_opt_out_at, must_change_password,
     * anonymized_at...) KHÔNG được liệt kê ở đây — chỉ đổi qua Service chuyên
     * trách (StudentSessionService, OtpService, staff:lock/unlock...).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'grade_level',
        'date_of_birth',
        'parent_phone',
        'parent_email',
        'referral_code_used',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'current_session_id',
        'parent_phone',
        'parent_email',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'parent_consent_status' => ParentConsentStatus::class,
            'grade_level' => 'integer',
            'date_of_birth' => 'date',
            // Bên thứ ba (phụ huynh) — ciphertext trong DB, không tìm kiếm được (S7).
            'parent_phone' => 'encrypted',
            'parent_email' => 'encrypted',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'parent_notice_opt_out_at' => 'datetime',
        ];
    }

    /**
     * `is_verified` (api-contract §2.2, US-001 AC8): đã xác thực OTP ít nhất một kênh liên hệ
     * (email hoặc SĐT). Đổi email/SĐT sẽ reset cột tương ứng (S9). Production MVP chỉ có kênh email.
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Staff = admin | quản lý trang (ADR-004 §3).
     */
    public function isStaff(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::PageManager], true);
    }

    public function isTeacher(): bool
    {
        return $this->role === UserRole::Teacher;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    /**
     * Hồ sơ công khai của giáo viên (US-020, ADR-005). `users.bio`/`users.avatar_path` ngừng dùng từ T36.
     *
     * @return HasOne<TeacherProfile, $this>
     */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class, 'user_id');
    }

    /**
     * Các khóa giáo viên này phụ trách (`course_teacher`).
     *
     * @return BelongsToMany<Course, $this>
     */
    public function taughtCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher');
    }
}
