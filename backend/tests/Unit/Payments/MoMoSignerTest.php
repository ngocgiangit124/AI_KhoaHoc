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
