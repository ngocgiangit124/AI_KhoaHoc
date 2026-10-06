<?php

use Symfony\Component\Finder\Finder;

/**
 * US-020 / ADR-005: chốt đồng ý duy nhất `App\Support\PublicTeacher`, và `TeacherProfileService` là điểm ghi duy nhất.
 */
/**
 * Mã nguồn không chú thích của các file (R1: phạm vi rộng, không chỉ `Resources/Catalog`).
 *
 * @return list<array{0: string, 1: string}> [đường dẫn tương đối app/, mã]
 */
function vvPublicSurfaceFiles(): array
{
    $files = [];
    $roots = [
        app_path('Http/Resources') => ['Admin'],
        app_path('Http/Controllers') => ['Api/V1/Admin'],
    ];

    foreach ($roots as $root => $excluded) {
        foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $dir = str_replace('\\', '/', $file->getRelativePath());

            if (collect($excluded)->contains(fn ($e) => $dir === $e || str_starts_with($dir, $e.'/') || str_ends_with(str_replace('\\', '/', $root).'/'.$dir, '/'.$e))) {
                continue;
            }

            $code = preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $file->getContents());
            $files[] = [str_replace(base_path().'/', '', str_replace('\\', '/', $file->getPathname())), (string) $code];
        }
    }

    return $files;
}

test('(i)/R1 moi Resource va Controller khong thuoc admin khong doc thang avatar_path/bio/headline/teacherProfile/data_get (chi qua PublicTeacher)', function () {
    $offenders = [];
    $files = vvPublicSurfaceFiles();

    foreach ($files as [$path, $code]) {
        if (preg_match('/teacherProfile\b|TeacherProfile\b|->\s*(bio|avatar_path|headline)\b|[\'"](bio|avatar_path|headline|avatar_url)[\'"]|\bavatar_path\b|data_get\s*\([^)]*(bio|avatar|headline|teacherProfile)/i', $code) === 1) {
            $offenders[] = $path;
        }
    }

    // Phải thật sự quét được các file công khai đã biết (phòng khi đổi cấu trúc thư mục làm test rỗng).
    $paths = array_column($files, 0);
    expect($paths)->toContain('app/Http/Resources/Catalog/CourseDetailResource.php')
        ->toContain('app/Http/Resources/Catalog/HomeTeacherResource.php')
        ->toContain('app/Http/Controllers/Api/V1/Catalog/CourseController.php');
    expect(collect($paths)->contains(fn ($p) => str_contains($p, '/Admin/')))->toBeFalse();

    expect($offenders)->toBe([], 'Resource/Controller công khai phải dùng PublicTeacher, không đọc thẳng hồ sơ giáo viên: '.implode(', ', $offenders));
});

test('R1/I2: Service cua nhom cong khai (Catalog, Learning, Cart, Quiz, Orders, Enrollment) khong xay mang chua anh/bio giao vien ngoai PublicTeacher', function () {
    $offenders = [];

    foreach (['Catalog', 'Learning', 'Cart', 'Quiz', 'Orders', 'Enrollment'] as $dir) {
        foreach ((new Finder)->files()->in(app_path('Services/'.$dir))->name('*.php') as $file) {
            $path = $dir.'/'.str_replace('\\', '/', $file->getRelativePathname());
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $file->getContents());

            // Ngoại lệ tường minh: CourseCatalog chỉ eager load hồ sơ để PublicTeacher dùng (không đọc cột).
            if ($path === 'Catalog/CourseCatalog.php') {
                $code = str_replace('teachers.teacherProfile:user_id,headline,bio,avatar_path,public_consent_at', '', $code);
            }

            if (preg_match('/avatar_path|teacherProfile\b|TeacherProfile\b|->\s*(bio|headline)\b/', $code) === 1) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

test('Resource cong khai co lien quan toi giao vien deu goi PublicTeacher', function () {
    foreach (['CourseDetailResource.php', 'HomeTeacherResource.php'] as $name) {
        expect(file_get_contents(app_path('Http/Resources/Catalog/'.$name)))->toContain('PublicTeacher::toArray');
    }
});

test('controller/resource cong khai (Catalog) khong truy van truc tiep bang teacher_profiles hay cot users.bio/avatar_path', function () {
    $offenders = [];

    foreach ([app_path('Http/Controllers/Api/V1/Catalog'), app_path('Http/Resources/Catalog')] as $dir) {
        foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
            if (preg_match('/teacher_profiles|users\.(bio|avatar_path)|TeacherProfile::/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('chi TeacherProfileService ghi vao teacher_profiles (khong cho DB::table(...)->update, TeacherProfile::create, forceFill cot hoi so o noi khac)', function () {
    $allowed = [
        'Services/Teachers/TeacherProfileService.php',
        // Chỉ dựng model trong bộ nhớ từ kết quả JOIN để đưa qua PublicTeacher (không ghi DB).
        'Services/Teachers/HomepageTeacherQuery.php',
    ];
    $offenders = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($path, $allowed, true)) {
            continue;
        }

        // Ghi trực tiếp: DB::table('teacher_profiles')->insert/update/delete, TeacherProfile::create/update/..., forceFill khoá hồ sơ.
        if (
            preg_match('/table\(\s*[\'"]teacher_profiles[\'"]\s*\)[^;]*->\s*(insert|insertOrIgnore|update|delete|upsert|truncate)\b/s', $file->getContents()) === 1
            || preg_match('/TeacherProfile::(create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|destroy)\b/', $file->getContents()) === 1
            || preg_match('/forceFill\(\s*\[[^\]]*(public_consent_at|public_consent_version|show_on_homepage|homepage_order|avatar_path|profile_updated_by)/s', $file->getContents()) === 1
        ) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Chỉ TeacherProfileService được ghi teacher_profiles: '.implode(', ', $offenders));
});
