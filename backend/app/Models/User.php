<?php

namespace App\Models;

use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Larastan không tự suy ra được kiểu cast enum từ phương thức `casts()` (chỉ
 * đọc được `protected $casts` khai báo tĩnh) — khai @property tường minh để
 * phpstan hiểu đúng kiểu, tránh báo sai "always false" ở mọi so sánh enum.
 *
 * @property UserRole $role
 * @property UserStatus $status
 * @property ParentConsentStatus $parent_consent_status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * S17 — mass assignment: CHỈ các trường này được gán qua create()/fill().
     * Cột trạng thái/quyền (role, status, *_verified_at, current_session_id,
     * current_device_id, parent_consent_status, must_change_password,
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
        'bio',
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
        ];
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
}
