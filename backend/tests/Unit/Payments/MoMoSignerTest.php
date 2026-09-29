<?php

use App\Services\Payments\Gateways\MoMo\MoMoSigner;

/**
 * Vector chữ ký cho `MoMoSigner` (ADR-001 §2, T17).
 *
 * QUAN TRỌNG — chưa kiểm chứng với sandbox MoMo thật: mạng tới
 * test-payment.momo.vn bị chặn ở môi trường chạy agent này (chính sách mạng),
 * nên KHÔNG thể lấy vector chính thức từ tài liệu/sandbox MoMo. Các giá trị
 * `accessKey`/`secretKey`/`orderId`... dưới đây là TỰ TẠO (không phải bí mật
 * thật, không phải ví dụ của MoMo). Chữ ký mong đợi được tính ĐỘC LẬP bằng
 * `openssl dgst -sha256 -hmac` (không dùng lại code đang test) để xác nhận
 * đúng 2 điều ADR-001 §2 yêu cầu: (1) thứ tự trường sắp theo alphabet,
 * (2) công thức `HMAC-SHA256(secretKey, "key1=value1&key2=value2...")`.
 *
 * Việc còn lại cho máy local (có mạng ra sandbox MoMo thật):
 * - Đối chiếu vector này với ví dụ chính thức trong tài liệu MoMo hiện hành.
 * - Xác nhận danh sách trường ký của API `create`/IPN/`query` (request +
 *   response) chưa đổi so với ADR-001 §2.
 */
it('ký đúng thứ tự alphabet + công thức HMAC-SHA256 cho trường kiểu "create" (vector tự tạo, xác nhận độc lập bằng openssl)', function () {
    $signer = new MoMoSigner;

    $fields = [
        'partnerCode' => 'TESTPARTNER',
        'accessKey' => 'testAccessKey',
        'requestId' => 'REQUEST123',
        'amount' => '50000',
        'orderId' => 'ORDER123-1',
        'orderInfo' => 'Thanh toan don hang ORDER123',
        'redirectUrl' => 'https://vitaminvui.test/checkout/ket-qua?order=ORDER123',
        'ipnUrl' => 'https://api.vitaminvui.test/webhooks/payments/momo',
        'extraData' => '',
        'requestType' => 'captureWallet',
    ];

    $expectedRaw = 'accessKey=testAccessKey&amount=50000&extraData=&ipnUrl=https://api.vitaminvui.test/webhooks/payments/momo'
        .'&orderId=ORDER123-1&orderInfo=Thanh toan don hang ORDER123&partnerCode=TESTPARTNER'
        .'&redirectUrl=https://vitaminvui.test/checkout/ket-qua?order=ORDER123&requestId=REQUEST123&requestType=captureWallet';

    // openssl dgst -sha256 -hmac "testSecretKey" <<< "$expectedRaw"
    $expectedSignature = '6921fa129b25d2f51e9a46831ed235e04a705a4d893b81747276915f32a57711';

    expect($signer->buildRawSignature($fields))->toBe($expectedRaw)
        ->and($signer->sign($fields, 'testSecretKey'))->toBe($expectedSignature)
        ->and($signer->verify($fields, 'testSecretKey', $expectedSignature))->toBeTrue();
});

it('ký đúng cho trường kiểu "query request" (vector tự tạo, xác nhận độc lập bằng openssl)', function () {
    $signer = new MoMoSigner;

    $fields = [
        'requestId' => 'QUERYREQ1',
        'orderId' => 'ORDER123-1',
        'partnerCode' => 'TESTPARTNER',
        'accessKey' => 'testAccessKey',
    ];

    $expectedRaw = 'accessKey=testAccessKey&orderId=ORDER123-1&partnerCode=TESTPARTNER&requestId=QUERYREQ1';

    // openssl dgst -sha256 -hmac "testSecretKey" <<< "$expectedRaw"
    $expectedSignature = '9f77a8f56d86b676dbf5fc7554c619f75f58d2ccce875928385ad34b1adca50b';

    expect($signer->buildRawSignature($fields))->toBe($expectedRaw)
        ->and($signer->sign($fields, 'testSecretKey'))->toBe($expectedSignature);
});

it('từ chối chữ ký sai và chữ ký rỗng', function () {
    $signer = new MoMoSigner;

    $fields = ['a' => '1', 'b' => '2'];

    expect($signer->verify($fields, 'secret', 'sai-chu-ky'))->toBeFalse()
        ->and($signer->verify($fields, 'secret', ''))->toBeFalse();
});

/**
 * M2 (review bảo mật T17) — `secretKey = ''` không được tạo ra một chữ ký
 * "hợp lệ" mà ai cũng tính lại được (`hash_hmac` với khoá rỗng vẫn ra kết
 * quả xác định). `sign()` phải ném lỗi; `verify()` (đường xử lý dữ liệu
 * KHÔNG ĐÁNG TIN — IPN/phản hồi query) phải luôn trả `false`, không ném lỗi.
 */
it('sign() ném InvalidArgumentException khi secretKey rỗng (M2)', function () {
    (new MoMoSigner)->sign(['a' => '1'], '');
})->throws(InvalidArgumentException::class);

it('verify() trả false khi secretKey rỗng, kể cả khi chữ ký được tính bằng chính khoá rỗng đó (M2)', function () {
    $signer = new MoMoSigner;
    $fields = ['a' => '1', 'b' => '2'];

    // Chữ ký "tự ký" bằng khoá rỗng — ai cũng tính lại được vì không cần biết
    // bí mật thật. `verify()` phải từ chối ngay từ điều kiện `secretKey === ''`,
    // không được gọi `sign()` (vốn giờ đã ném lỗi) rồi để lộ exception ra ngoài.
    $signatureWithEmptyKey = hash_hmac('sha256', $signer->buildRawSignature($fields), '');

    expect($signer->verify($fields, '', $signatureWithEmptyKey))->toBeFalse();
});

/**
 * L4 (review bảo mật T17) — `$secretKey` phải có `#[\SensitiveParameter]` để
 * không lộ (dù chỉ 1 phần) vào stack trace nếu có exception phát sinh trong
 * khung gọi `sign()`/`verify()` (image `php` không có `php.ini`, mặc định
 * `zend.exception_ignore_args=Off`).
 */
it('sign() và verify() đánh dấu tham số secretKey bằng #[SensitiveParameter] (L4)', function (string $method) {
    $reflection = new ReflectionMethod(MoMoSigner::class, $method);
    $secretKeyParam = collect($reflection->getParameters())->firstWhere('name', 'secretKey');

    expect($secretKeyParam)->not->toBeNull();

    $attributes = $secretKeyParam->getAttributes(SensitiveParameter::class);

    expect($attributes)->toHaveCount(1);
})->with(['sign', 'verify']);
