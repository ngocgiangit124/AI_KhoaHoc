<?php

use App\Services\Content\HtmlSanitizer;

/**
 * `HtmlSanitizer` — profile "course_description" (api-contract §4, S8).
 * Test bắt buộc theo tasks.md T08: "XSS payload trong description bị lọc".
 */
function sanitize(?string $html): ?string
{
    return app(HtmlSanitizer::class)->sanitize($html);
}

test('null va chuoi rong duoc giu nguyen dang', function () {
    expect(sanitize(null))->toBeNull();
    expect(sanitize(''))->toBe('');
    expect(sanitize('   '))->toBe('');
});

test('the duoc allowlist giu nguyen, khong copy thuoc tinh', function () {
    $html = '<p class="lead" style="color:red" onclick="steal()">Xin chào <strong>Toán 9</strong></p>';

    expect(sanitize($html))->toBe('<p>Xin chào <strong>Toán 9</strong></p>');
});

test('script bi xoa ca the lan noi dung', function () {
    $html = 'Trước<script>alert(document.cookie)</script>Sau';

    expect(sanitize($html))->toBe('TrướcSau');
});

test('img bi xoa hoan toan, ke ca payload onerror', function () {
    $html = '<p>Ảnh<img src="x" onerror="alert(1)"></p>';

    expect(sanitize($html))->toBe('<p>Ảnh</p>');
});

test('iframe bi xoa ca the lan noi dung', function () {
    $html = '<iframe src="https://evil.example/steal"></iframe>Nội dung thật';

    expect(sanitize($html))->toBe('Nội dung thật');
});

test('svg voi script long ben trong bi xoa hoan toan', function () {
    $html = '<svg onload="alert(1)"><script>alert(2)</script></svg>An toàn';

    expect(sanitize($html))->toBe('An toàn');
});

test('the khong nam trong allowlist bi go bo nhung giu lai noi dung (unwrap)', function () {
    $html = '<div class="wrap"><span>Nội dung</span></div>';

    expect(sanitize($html))->toBe('Nội dung');
});

test('h1 khong nam trong allowlist (chi h2-h4) bi unwrap', function () {
    expect(sanitize('<h1>Tiêu đề lớn</h1>'))->toBe('Tiêu đề lớn');
    expect(sanitize('<h2>Tiêu đề vừa</h2>'))->toBe('<h2>Tiêu đề vừa</h2>');
});

test('link javascript scheme bi tu choi, giu lai van ban', function () {
    $html = '<a href="javascript:alert(1)">Bấm vào đây</a>';

    expect(sanitize($html))->toBe('Bấm vào đây');
});

test('link data scheme bi tu choi', function () {
    $html = '<a href="data:text/html,<script>alert(1)</script>">Link</a>';

    expect(sanitize($html))->toBe('Link');
});

test('link http/https/mailto hop le duoc giu, ep target blank va rel nofollow', function () {
    expect(sanitize('<a href="https://vitaminvui.vn">Trang chủ</a>'))
        ->toBe('<a href="https://vitaminvui.vn" target="_blank" rel="nofollow noopener noreferrer">Trang chủ</a>');

    expect(sanitize('<a href="mailto:ho-tro@vitaminvui.vn">Liên hệ</a>'))
        ->toBe('<a href="mailto:ho-tro@vitaminvui.vn" target="_blank" rel="nofollow noopener noreferrer">Liên hệ</a>');
});

test('link tuong doi cung goc duoc coi la hop le', function () {
    expect(sanitize('<a href="/khoa-hoc/toan-9">Xem khóa học</a>'))
        ->toBe('<a href="/khoa-hoc/toan-9" target="_blank" rel="nofollow noopener noreferrer">Xem khóa học</a>');
});

test('link dang protocol-relative bi tu choi (scheme mo ho)', function () {
    expect(sanitize('<a href="//evil.example/phish">Link</a>'))->toBe('Link');
});

test('rel/target goc cua nguoi dung khong duoc giu, luon bi ep lai', function () {
    $html = '<a href="https://vitaminvui.vn" target="_self" rel="opener">Trang chủ</a>';

    expect(sanitize($html))->toBe('<a href="https://vitaminvui.vn" target="_blank" rel="nofollow noopener noreferrer">Trang chủ</a>');
});

test('danh sach ul/ol/li va blockquote duoc giu dung cau truc', function () {
    $html = '<ul><li>Một</li><li>Hai</li></ul><blockquote>Trích dẫn</blockquote>';

    expect(sanitize($html))->toBe($html);
});

test('van ban thuan co ky tu < > duoc escape lai an toan', function () {
    expect(sanitize('<p>1 &lt; 2 va 3 &gt; 2</p>'))->toBe('<p>1 &lt; 2 va 3 &gt; 2</p>');
});

test('html hong nang van tra ve chuoi rong thay vi loi 500', function () {
    // libxml rất khoan dung với HTML lỗi — vẫn kỳ vọng không có ngoại lệ nào
    // ném ra (an toàn hơn để lộ 500).
    expect(fn () => sanitize('<p><strong>chưa đóng thẻ'))->not->toThrow(Throwable::class);
});

// --- R3/R4 (review-T08): khoá hành vi với payload XSS/mXSS thực tế ---------

test('payload XSS/mXSS khong de lai vector nao trong output', function (string $payload) {
    $out = (string) sanitize($payload);

    expect(strtolower($out))
        ->not->toContain('javascript')
        ->not->toContain('vbscript')
        ->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('<svg')
        ->not->toContain('<math')
        ->not->toContain('style=');

    // Idempotent: ghi rồi đọc lại không đổi.
    expect(sanitize($out))->toBe($out);
})->with([
    'tab trong scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
    'newline trong scheme' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
    'entity so' => ['<a href="&#106;avascript:alert(1)">x</a>'],
    'entity tab' => ['<a href="jav&#x09;ascript:alert(1)">x</a>'],
    'entity colon' => ['<a href="javascript&#x3A;alert(1)">x</a>'],
    'ky tu dieu khien dau' => ["<a href=\"\x01javascript:alert(1)\">x</a>"],
    'khoang trang dau' => ['<a href=" javascript:alert(1)">x</a>'],
    'viet hoa' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
    'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'svg onload' => ['<svg/onload=alert(1)>'],
    'math mglyph mxss' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
    'noscript mxss' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
    'style tag' => ['<style>@import "javascript:alert(1)"</style>x'],
    'comment dieu kien' => ['<!--[if IE]><script>alert(1)</script><![endif]-->x'],
    'script bi phan manh' => ["<scr\0ipt>alert(1)</scr\0ipt>"],
    'nested anchor' => ['<a href="https://a.com"><a href="javascript:alert(1)" onclick="x()">y</a></a>'],
    'textarea/xmp' => ['<textarea></textarea><img src=x onerror=alert(1)><xmp><img src=x onerror=alert(1)></xmp>'],
]);

test('anchor long nhau khong sinh the a long nhau nguy hiem', function () {
    $out = sanitize('<a href="https://a.com"><a href="javascript:alert(1)">y</a></a>');

    expect($out)->not->toContain('javascript');
});

test('tieng Viet, emoji va ky tu dac biet duoc giu nguyen', function () {
    $html = '<p>Đại số & Hình học — học vui 😀 “ngoặc”</p>';

    expect(sanitize($html))->toBe('<p>Đại số &amp; Hình học — học vui 😀 “ngoặc”</p>');
    expect(sanitize(sanitize($html)))->toBe(sanitize($html));
});

test('href bat dau bang backslash hoac /\\ bi tu choi nhu protocol-relative (R4)', function (string $href) {
    $out = (string) sanitize('<a href="'.$href.'">x</a>');

    expect($out)->toBe('x');
})->with([
    'hai backslash' => ['\\\\evil.example'],
    'slash backslash' => ['/\\evil.example'],
    'backslash slash' => ['\\/evil.example'],
    'hai slash' => ['//evil.example'],
]);

test('byte UTF-8 sai khong lam mat ca text node (R7)', function () {
    $out = (string) sanitize("<p>Xin chào \xC3\x28 bạn</p>");

    expect($out)->toContain('Xin chào')->toContain('bạn');
});

test('chen dau nhay + onmouseover vao href bi escape, khong thanh thuoc tinh (R3)', function () {
    $out = (string) sanitize('<a href="https://a.com&quot; onmouseover=&quot;alert(1)">x</a>');

    expect($out)->toContain('&quot;')->not->toContain('" onmouseover');
    expect(sanitize($out))->toBe($out);
});
