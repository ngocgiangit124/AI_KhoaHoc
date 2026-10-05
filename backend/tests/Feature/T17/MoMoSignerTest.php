<?php

use App\Services\Payments\Gateways\Momo\MoMoSigner;

/**
 * Vector dùng thông số sandbox công khai trong tài liệu MoMo (partnerCode MOMO, accessKey F8BBA842ECF85,
 * secretKey K951B6PE1waDMi640xX08PD3vg6EkVlz). Digest đối chiếu bằng HMAC-SHA256 độc lập (python hmac)
 * vì không truy cập được tài liệu offline — cần đối chiếu lại với digest trong tài liệu/sandbox.
 */
function momoCreateSample(): array
{
    return [
        'accessKey' => 'F8BBA842ECF85',
        'amount' => '50000',
        'extraData' => '',
        'ipnUrl' => 'https://webhook.site/b3088a6a-2d17-4f8d-a383-71389a6c600b',
        'orderId' => 'MM1540456472575',
        'orderInfo' => 'pay with MoMo',
        'partnerCode' => 'MOMO',
        'redirectUrl' => 'https://webhook.site/b3088a6a-2d17-4f8d-a383-71389a6c600b',
        'requestId' => 'MM1540456472575',
        'requestType' => 'captureWallet',
    ];
}

const MOMO_SAMPLE_SECRET = 'K951B6PE1waDMi640xX08PD3vg6EkVlz';

test('rawSignature theo thu tu alphabet cua tai lieu', function () {
    $raw = (new MoMoSigner)->rawSignature(momoCreateSample(), MoMoSigner::CREATE_FIELDS);

    expect($raw)->toBe('accessKey=F8BBA842ECF85&amount=50000&extraData=&ipnUrl=https://webhook.site/b3088a6a-2d17-4f8d-a383-71389a6c600b&orderId=MM1540456472575&orderInfo=pay with MoMo&partnerCode=MOMO&redirectUrl=https://webhook.site/b3088a6a-2d17-4f8d-a383-71389a6c600b&requestId=MM1540456472575&requestType=captureWallet');
});

test('chu ky create khop vector mau', function () {
    $sig = (new MoMoSigner)->sign(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET);

    expect($sig)->toBe('e4e7c6d8bfc40abc362e39afe1e2ca815034f350aed6225e78ef1745fff0e617');
});

test('danh sach truong da sap alphabet', function () {
    foreach ([MoMoSigner::CREATE_FIELDS, MoMoSigner::IPN_FIELDS, MoMoSigner::QUERY_REQUEST_FIELDS] as $fields) {
        $sorted = $fields;
        sort($sorted, SORT_STRING);
        expect($fields)->toBe($sorted);
    }
});

test('verify dung/sai chu ky va khong phan biet hoa thuong hex', function () {
    $signer = new MoMoSigner;
    $sig = $signer->sign(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET);

    expect($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, $sig))->toBeTrue()
        ->and($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, strtoupper($sig)))->toBeTrue()
        ->and($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, 'sai-khoa', $sig))->toBeFalse()
        ->and($signer->verify(['amount' => '60000'] + momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, $sig))->toBeFalse()
        ->and($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, null))->toBeFalse()
        ->and($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, ''))->toBeFalse()
        ->and($signer->verify(momoCreateSample(), MoMoSigner::CREATE_FIELDS, '', $sig))->toBeFalse();
});

test('thieu truong ky thi verify false, sign nem loi', function () {
    $data = momoCreateSample();
    unset($data['orderId']);
    $signer = new MoMoSigner;

    expect($signer->verify($data, MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET, 'abc'))->toBeFalse();
    expect(fn () => $signer->sign($data, MoMoSigner::CREATE_FIELDS, MOMO_SAMPLE_SECRET))->toThrow(InvalidArgumentException::class);
});
