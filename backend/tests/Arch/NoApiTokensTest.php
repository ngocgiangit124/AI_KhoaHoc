<?php

use App\Models\User;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\Finder\Finder;

/**
 * L6 (review bảo mật T01/T02) — dự án chỉ xác thực SPA bằng cookie phiên,
 * không phát hành personal access token (S24). `User` không được dùng
 * `HasApiTokens`, và không nơi nào trong `app/` được GỌI THẬT `createToken(`.
 *
 * Quét bằng `token_get_all()` rồi bỏ token T_COMMENT/T_DOC_COMMENT trước khi
 * tìm chuỗi `createToken(` — tránh bắt nhầm chính chuỗi này khi nó chỉ xuất
 * hiện trong docblock/comment giải thích quy tắc (ví dụ docblock của
 * `App\Models\User` nhắc tới `$user->createToken()` để giải thích LÝ DO cấm),
 * mà vẫn bắt được lời gọi PHP thật trong mã nguồn (xem test thứ 3 bên dưới —
 * chứng minh không bị làm yếu bằng cách bỏ qua comment).
 */
function arch_no_api_tokens_strip_comments(string $sourceCode): string
{
    $tokens = token_get_all($sourceCode);

    $withoutComments = '';

    foreach ($tokens as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $withoutComments .= $token[1];
        } else {
            $withoutComments .= $token;
        }
    }

    return $withoutComments;
}

/**
 * Có lời gọi `createToken(` trong mã (đã bỏ comment) không. Cho phép khoảng trắng
 * trước dấu ngoặc và không phân biệt hoa/thường vì tên method PHP không phân biệt.
 */
function arch_no_api_tokens_calls_create_token(string $codeWithoutComments): bool
{
    return preg_match('/createToken\s*\(/i', $codeWithoutComments) === 1;
}

test('User model khong dung trait HasApiTokens', function () {
    expect(in_array(HasApiTokens::class, class_uses_recursive(User::class), true))->toBeFalse();
});

test('khong co noi nao trong app/ goi that createToken( (bo qua comment/docblock)', function () {
    $finder = (new Finder)->files()->in(app_path())->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        $codeWithoutComments = arch_no_api_tokens_strip_comments($file->getContents());

        if (arch_no_api_tokens_calls_create_token($codeWithoutComments)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Không được phát hành personal access token (S24): '.implode(', ', $offenders));
});

test('bo loc comment van bat duoc loi goi createToken( that su trong ma nguon', function () {
    $onlyCommentReference = <<<'PHP'
    <?php

    /**
     * Chỉ nhắc tới createToken( trong docblock để giải thích, không gọi thật.
     */
    class SampleOnlyCommentMentionsCreateToken
    {
        // dòng comment đơn cũng nhắc tới createToken( nhưng không được tính
        public function noop(): void
        {
            // no-op
        }
    }
    PHP;

    expect(arch_no_api_tokens_calls_create_token(arch_no_api_tokens_strip_comments($onlyCommentReference)))
        ->toBeFalse();

    $withRealCall = <<<'PHP'
    <?php

    /**
     * Chỉ nhắc tới createToken( trong docblock để giải thích, không gọi thật.
     */
    class SampleWithRealCreateTokenCall
    {
        public function issueToken(User $user): mixed
        {
            // Lời gọi thật bên dưới PHẢI bị bắt dù có comment ở trên.
            return $user->createToken('name');
        }
    }
    PHP;

    expect(arch_no_api_tokens_calls_create_token(arch_no_api_tokens_strip_comments($withRealCall)))
        ->toBeTrue();

    // Biến thể né chuỗi cứng: khoảng trắng trước ngoặc, khác hoa/thường.
    expect(arch_no_api_tokens_calls_create_token('<?php $user->createToken (\'x\');'))->toBeTrue()
        ->and(arch_no_api_tokens_calls_create_token('<?php $user->CreateToken(\'x\');'))->toBeTrue();
});
