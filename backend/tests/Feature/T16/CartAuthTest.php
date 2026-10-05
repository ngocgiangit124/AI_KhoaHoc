<?php

require_once __DIR__.'/../T04/helpers.php';

test('chua dang nhap -> 401 UNAUTHENTICATED o moi route gio hang', function () {
    $h = vvWebHeaders();

    test()->getJson(vvApiUrl('/cart'), $h)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    test()->postJson(vvApiUrl('/cart/items'), ['course_id' => 1], $h)->assertUnauthorized();
    test()->deleteJson(vvApiUrl('/cart/items/1'), [], $h)->assertUnauthorized();
    test()->putJson(vvApiUrl('/cart/coupon'), ['code' => 'ABCD'], $h)->assertUnauthorized();
    test()->deleteJson(vvApiUrl('/cart/coupon'), [], $h)->assertUnauthorized();
});
