<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * S15 — KHÔNG BAO GIỜ tạo admin ở production, kể cả với mật khẩu ngẫu
     * nhiên: chỉ chạy ở `local`. Tạo tài khoản staff thật dùng `staff:create`.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        User::factory()->admin()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@vitaminvui.test',
        ]);

        User::factory()->teacher()->create([
            'name' => 'Demo Giáo viên',
            'email' => 'teacher@vitaminvui.test',
        ]);

        User::factory()->verified()->create([
            'name' => 'Demo Học sinh',
            'email' => 'student@vitaminvui.test',
        ]);
    }
}
