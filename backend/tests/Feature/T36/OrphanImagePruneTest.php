<?php

use App\Models\Course;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

/** Tạo file `{uuid}.webp` với mtime cho trước. */
function vvT36PutImage(int $ageHours, ?string $name = null): string
{
    $name ??= (string) Str::uuid().'.webp';
    $disk = Storage::disk('uploads');
    $disk->put($name, 'x');
    touch($disk->path($name), time() - $ageHours * 3600);

    return $name;
}

test('images:prune-orphans: xoa file > 24h khong tham chieu; giu file moi, file duoc tham chieu (thumbnail ke ca khoa xoa mem, avatar GV, users.avatar_path)', function () {
    Storage::fake('uploads');
    $orphan = vvT36PutImage(30);
    $fresh = vvT36PutImage(1);
    $thumb = vvT36PutImage(48);
    $thumbTrashed = vvT36PutImage(48);
    $avatar = vvT36PutImage(48);
    $legacy = vvT36PutImage(48);

    Course::factory()->create(['thumbnail_path' => $thumb]);
    Course::factory()->create(['thumbnail_path' => $thumbTrashed])->delete();
    $t = User::factory()->teacher()->create();
    DB::table('teacher_profiles')->insert(['user_id' => $t->id, 'avatar_path' => $avatar, 'created_at' => now(), 'updated_at' => now()]);
    $old = User::factory()->teacher()->create();
    DB::table('users')->where('id', $old->id)->update(['avatar_path' => $legacy]);

    expect(Artisan::call('images:prune-orphans'))->toBe(0);

    Storage::disk('uploads')->assertMissing($orphan);
    foreach ([$fresh, $thumb, $thumbTrashed, $avatar, $legacy] as $kept) {
        Storage::disk('uploads')->assertExists($kept);
    }
    expect(Artisan::output())->toContain('mồ côi: 1')->toContain('đã xoá: 1');
});

test('images:prune-orphans --dry-run chi dem, khong xoa', function () {
    Storage::fake('uploads');
    $orphan = vvT36PutImage(30);

    Artisan::call('images:prune-orphans', ['--dry-run' => true]);

    Storage::disk('uploads')->assertExists($orphan);
    expect(Artisan::output())->toContain('mồ côi: 1')->toContain('đã xoá: 0')->toContain('dry-run');
});

test('images:prune-orphans: khong dung toi file ten la / khong phai webp UUID (vd .htaccess, index.html, ten tuy y)', function () {
    Storage::fake('uploads');
    foreach (['.htaccess', 'index.html', 'readme.txt', 'abc.webp', 'not-a-uuid-at-all-xxxxxxxxxxxxxxxxxxxxxx.webp'] as $name) {
        vvT36PutImage(500, $name);
    }

    Artisan::call('images:prune-orphans');

    expect(Storage::disk('uploads')->allFiles())->toHaveCount(5);
});

test('images:prune-orphans: dang ky lich hang ngay 04:10 withoutOverlapping + onOneServer', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'images:prune-orphans'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('10 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
