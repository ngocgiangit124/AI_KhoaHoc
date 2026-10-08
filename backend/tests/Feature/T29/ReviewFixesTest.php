<?php

use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Privacy\ParentNoticeSuppression;
use App\Services\Privacy\ParentNoticeToken;
use App\Services\Privacy\ParentNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

/** Mọi href trong HTML. */
function vvT29Hrefs(string $html): array
{
    preg_match_all('/<a\s[^>]*href="([^"]*)"/i', $html, $m);

    return $m[1];
}

test('R1: ten/khoa hoc chua markdown, URL, HTML -> thu (HTML va text) khong co link nao ngoai VitaminVui', function (string $evil) {
    $student = User::factory()->student()->verified()->create(['name' => $evil.' Anh', 'parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student, 100000, $evil.' https://evil2.example/x');

    app(ParentNotifier::class)->accountCreated($student);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    $mails = vvT29Notices();
    expect($mails)->toHaveCount(2);

    foreach ($mails as $mail) {
        $html = $mail->render();
        $text = view('emails.parent-notice-text', $mail->buildViewData())->render();

        foreach (vvT29Hrefs($html) as $href) {
            expect($href)->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/');
        }

        foreach ([$html, $text, $mail->envelope()->subject] as $out) {
            expect($out)->not->toContain('://evil')->not->toContain('evil.')->not->toContain('<a href="https://evil')->not->toContain('<script')->not->toContain('](');
        }

        // thu khong con ten day du / khong chua ky tu tao link trong phan chua du lieu nguoi dung
        expect($mail->maskedStudentName)->not->toMatch('/[\[\]()<>:\/._`#!|\\\\]/');
        foreach ($mail->courseTitles as $title) {
            expect($title)->not->toMatch('/[\[\]<>:\/._`#!|\\\\@]/');
        }
    }
})->with([
    'link markdown' => ['[x](https://evil)'],
    'url tran' => ['https://evil'],
    'the a' => ['<a href="https://evil">x</a>'],
    'in dam' => ['**Bam**'],
    'gach duoi' => ['_evil_'],
    'backtick' => ['`evil`'],
    'ten mien' => ['evil.com'],
    'anh' => ['![i](https://evil/a.png)'],
]);

test('R1: ten binh thuong (co dau, dau nhay, gach noi) giu nguyen sau khi lam sach', function () {
    expect(ParentNotifier::maskName("Nguyễn Thị Bích-Ngọc O'Neil"))->toBe('Nguyễn Thị Bích-Ngọc O**')
        ->and(ParentNotifier::maskName('An'))->toBe('A**')
        ->and(ParentNotifier::maskName('[](https://x)'))->not->toContain('http')
        ->and(ParentNotifier::maskName('   '))->toBe('***');
});

test('R2: phu huynh huy nhan -> xoa roi them lai dung email do -> KHONG gui thu; resource van hien da ngung nhan', function () {
    $student = vvT29Student();
    vvT29Unsub2(vvT29Token($student));

    vvT29Put(['parent_email' => null])->assertOk();
    vvT29Put(['parent_email' => 'PhuHuynh@example.com'])->assertOk()
        ->assertJsonPath('notices_enabled', false)
        ->assertJsonPath('has_email', true);

    expect(vvT29Notices())->toHaveCount(0)
        ->and(DB::table('parent_notice_suppressions')->count())->toBe(1);

    // email khac thi van nhan duoc
    vvT29Put(['parent_email' => 'khac@example.com'])->assertOk()->assertJsonPath('notices_enabled', true);
    expect(vvT29Notices('parent_contact_added'))->toHaveCount(1);
});

test('R2: tai khoan moi dung email da huy nhan khong nhan thu; danh sach chan chi luu HMAC, khong luu email', function () {
    $old = User::factory()->student()->create(['parent_email' => 'bi.huy@example.com']);
    vvT29Unsub2(vvT29Token($old));

    $new = User::factory()->student()->verified()->create(['parent_email' => 'bi.huy@example.com']);
    app(ParentNotifier::class)->accountCreated($new);

    expect(vvT29Notices())->toHaveCount(0);

    $row = DB::table('parent_notice_suppressions')->first();
    expect($row->email_hmac)->toBe(ParentNoticeToken::addressHash('BI.HUY@example.com'))
        ->and(json_encode($row))->not->toContain('bi.huy')
        ->and($row->email_hmac)->not->toBe(hash('sha256', 'bi.huy@example.com'));
});

test('R2: huy nhan lan 2 khong tao them dong; danh sach chan khong bi go khi doi email', function () {
    $student = User::factory()->student()->create(['parent_email' => 'a@example.com']);
    $token = vvT29Token($student);
    vvT29Unsub2($token);
    vvT29Unsub2($token);

    expect(DB::table('parent_notice_suppressions')->count())->toBe(1);

    $student->forceFill(['parent_email' => 'b@example.com'])->save();
    expect(DB::table('parent_notice_suppressions')->count())->toBe(1);
});

test('R3: loi DB khi dung thu order_paid khong lam markPaid/IPN that bai, don van paid', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student);

    // Lop con ParentNotifier nem loi o truy van dau tien bang cach xoa user giua chung khong kha thi; gia lap loi DB bang listener.
    $failing = false;
    DB::listen(function ($query) use (&$failing) {
        if ($failing && str_contains($query->sql, 'COALESCE(`courses`.`title`') || str_contains($query->sql, 'COALESCE(courses.title')) {
            throw new RuntimeException('db blip');
        }
    });

    app()->bind(ParentNotifier::class, function ($app) use (&$failing) {
        $failing = true;

        return new ParentNotifier($app->make(AuditLogger::class), $app->make(ParentNoticeSuppression::class));
    });

    $paid = app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    expect($paid->status->value)->toBe('paid')->and(Order::find($order->id)->status->value)->toBe('paid')
        ->and(vvT29Notices())->toHaveCount(0);
});

test('R5: khoa tran thu va token dung HMAC co khoa (khong phai sha256 tran); verify user khong ton tai van tra null', function () {
    expect(ParentNoticeToken::addressHash('a@example.com'))->not->toBe(hash('sha256', 'a@example.com'))
        ->and(strlen(ParentNoticeToken::addressHash('a@example.com')))->toBe(64);

    config(['privacy.notice_token_key' => 'k1']);
    $h1 = ParentNoticeToken::addressHash('a@example.com');
    config(['privacy.notice_token_key' => 'k2']);
    expect(ParentNoticeToken::addressHash('a@example.com'))->not->toBe($h1);

    expect(ParentNoticeToken::verify('99999999.'.str_repeat('A', 43)))->toBeNull();
});

test('migration danh sach chan: down() xoa bang, up() tao lai', function () {
    $migration = require database_path('migrations/2026_10_20_120000_create_parent_notice_suppressions_table.php');

    try {
        $migration->down();
        expect(Schema::hasTable('parent_notice_suppressions'))->toBeFalse();
    } finally {
        if (! Schema::hasTable('parent_notice_suppressions')) {
            $migration->up();
        }
    }

    expect(Schema::hasTable('parent_notice_suppressions'))->toBeTrue();
});

function vvT29Unsub2(string $token): void
{
    test()->postJson(vvT29UnsubUrl(), ['token' => $token], ['Origin' => config('app.frontend_url')])->assertOk();
}
