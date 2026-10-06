<?php

use App\Rules\PlainText;
use App\Support\VisibleText;

function vvPlainFails(PlainText $rule, string $value): bool
{
    $failed = false;
    $rule->validate('bio', $value, function () use (&$failed): void {
        $failed = true;
    });

    return $failed;
}

test('PlainText mac dinh chan xuong dong (hanh vi cu giu nguyen)', function () {
    expect(vvPlainFails(new PlainText, "a\nb"))->toBeTrue();
    expect(vvPlainFails(new PlainText, 'ab'))->toBeFalse();
});

test('PlainText(allowNewlines): cho \\n nhung van chan \\r, tab, NUL, HTML, <>, bidi, zero-width, BOM, ZWJ lac', function () {
    $rule = new PlainText(allowNewlines: true);

    expect(vvPlainFails($rule, "dòng 1\ndòng 2\n\ndòng 4"))->toBeFalse();
    expect(vvPlainFails($rule, "a\u{200D}\n"))->toBeTrue();

    foreach (["a\rb", "a\tb", "a\0b", '<b>x</b>', 'a < b', 'a > b', "a\u{202E}b", "a\u{200B}b", "a\u{200C}b", "a\u{FEFF}b", "a\u{200D}b", "a\x0Bb", "a\x0Cb", "a\u{0085}b", "a\u{2028}b"] as $bad) {
        expect(vvPlainFails($rule, $bad))->toBeTrue(bin2hex($bad));
    }
});

test('PlainText(allowNewlines): xuong dong khong tao ra ZWJ hop le gia (emoji tach boi \\n van bi chan)', function () {
    $rule = new PlainText(allowNewlines: true);

    expect(vvPlainFails($rule, "\u{1F468}\n\u{200D}\u{1F469}"))->toBeTrue();
    expect(vvPlainFails($rule, "\u{1F468}\u{200D}\u{1F469}"))->toBeFalse();
});

// ---------------------------------------------------------------- L2 (security T36): chặn theo lớp ký tự

dataset('vvPlainBlocked', [
    'LRM' => ["a\u{200E}b"], 'RLM' => ["a\u{200F}b"], 'ALM' => ["a\u{061C}b"],
    'word joiner U+2060' => ["a\u{2060}b"], 'U+2061' => ["a\u{2061}b"], 'invisible times U+2062' => ["a\u{2062}b"],
    'invisible separator U+2063' => ["a\u{2063}b"], 'invisible plus U+2064' => ["a\u{2064}b"],
    'soft hyphen' => ["a\u{00AD}b"], 'mongolian vowel sep U+180E' => ["a\u{180E}b"], 'CGJ U+034F' => ["a\u{034F}b"],
    'tag A U+E0041' => ["a\u{E0041}b"], 'tag begin U+E0001' => ["a\u{E0001}b"], 'tag cancel U+E007F' => ["a\u{E007F}b"],
    'Hangul filler U+3164' => ["a\u{3164}b"], 'U+115F' => ["a\u{115F}b"], 'U+1160' => ["a\u{1160}b"], 'halfwidth filler U+FFA0' => ["a\u{FFA0}b"],
    'braille blank U+2800' => ["a\u{2800}b"], 'khmer inherent vowel U+17B4' => ["a\u{17B4}b"],
    'FE0F le (sau chu)' => ["a\u{FE0F}b"], 'FE0F dau chuoi' => ["\u{FE0F}x"], 'FE0E le' => ["a\u{FE0E}"],
    'selector supplement le' => ["a\u{E0100}b"],
    'Zalgo 4 dau' => ["a\u{0300}\u{0301}\u{0302}\u{0303}"], 'Zalgo 100 dau' => ['a'.str_repeat("\u{0301}", 100)],
    'LS U+2028' => ["a\u{2028}b"], 'PS U+2029' => ["a\u{2029}b"], 'BOM' => ["a\u{FEFF}b"], 'ZWSP' => ["a\u{200B}b"],
    'bidi override' => ["a\u{202E}b"], 'isolate' => ["a\u{2066}b"], 'ZWJ lac' => ["a\u{200D}b"],
    'tab' => ["a\tb"], 'html' => ['<b>x</b>'],
]);

test('L2: PlainText chan ky tu an, dinh dang, filler, selector lac, Zalgo (ca che do nhieu dong)', function (string $bad) {
    expect(vvPlainFails(new PlainText, $bad))->toBeTrue(bin2hex($bad));
    expect(vvPlainFails(new PlainText(allowNewlines: true), "dòng 1\n".$bad))->toBeTrue(bin2hex($bad));
})->with('vvPlainBlocked');

dataset('vvPlainAllowed', [
    'tieng Viet NFC' => ['Nguyễn Thị Lan dạy Toán lớp 9, luyện thi vào 10 chuyên'],
    'tieng Viet NFD (tach dau)' => [Normalizer::normalize('Tiếng Việt: ế ộ ử ữ Ặ ằ', Normalizer::FORM_D)],
    'NFD dau chong 2' => ["e\u{0302}\u{0301}"],
    'toi da 3 dau ket hop' => ["a\u{0300}\u{0301}\u{0323}"],
    'emoji don' => ['Vui 😀 hoc 🎓'],
    'emoji FE0F sau pictographic' => ["❤\u{FE0F} và ☺\u{FE0F}"],
    'emoji gia dinh ZWJ' => ["👨\u{200D}👩\u{200D}👧"],
    'emoji tim ZWJ + FE0F' => ["👨\u{200D}❤\u{FE0F}\u{200D}👨"],
    'tong da' => ["👍\u{1F3FD}"],
    'keycap' => ["1\u{FE0F}\u{20E3} 2\u{FE0F}\u{20E3} #\u{FE0F}\u{20E3}"],
    'co quoc gia' => ["\u{1F1FB}\u{1F1F3}"],
    'dau cau thong thuong' => ['"Giỏi" - (toán/lý) & hóa; 100% [tốt] {ok} @vv #1 $5 ~ ^ _ = + | \\ '."'x'"],
    'khoang trang NBSP' => ["a\u{00A0}b"],
]);

test('L2: PlainText khong chan oan tieng Viet NFC/NFD, emoji (ZWJ, FE0F, tong da, keycap, co)', function (string $good) {
    expect(vvPlainFails(new PlainText, $good))->toBeFalse(bin2hex($good));
    expect(vvPlainFails(new PlainText(allowNewlines: true), "dòng 1\n\n".$good."\ndòng 3"))->toBeFalse(bin2hex($good));
})->with('vvPlainAllowed');

test('BUG-1: co vung co tag sequence hop le qua (ca che do nhieu dong); tag le hoac thieu ky tu ket thuc bi chan; VisibleText khong coi co la trong', function () {
    $england = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
    $scotland = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";

    foreach ([new PlainText, new PlainText(allowNewlines: true)] as $rule) {
        expect(vvPlainFails($rule, "Học {$england} và {$scotland}"))->toBeFalse();
        expect(vvPlainFails($rule, "Học \u{E0067}"))->toBeTrue();
        expect(vvPlainFails($rule, "Học \u{1F3F4}\u{E0067}\u{E0062}"))->toBeTrue();
        expect(vvPlainFails($rule, "Học {$england}\u{E0041}"))->toBeTrue();
    }

    expect(VisibleText::isBlank($england))->toBeFalse();
    expect(VisibleText::isBlank("\u{E0067}\u{E007F}"))->toBeTrue();
});
