<?php

require_once __DIR__.'/helpers.php';

test('QA minor-fixes: register - message validation tieng Viet (rong, email sai, mat khau ngan, khong khop)', function () {
    $r = vvRegister(['name' => '', 'email' => 'khong-phai-email', 'password' => '123', 'password_confirmation' => '456'])
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    $all = collect($r->json('errors'))->flatten()->implode(' | ');

    expect($all)->not->toMatch('/must be|is required|field is|The |at least|does not match/')
        ->and($all)->not->toContain('validation.')
        ->and($all)->toMatch('/[ăâêôơưđáàảãạéèíóòúùý]/u');
    expect($r->json('errors'))->toHaveKeys(['name', 'email']);
});

test('QA minor-fixes: register - ten truong (attribute) tieng Viet trong message', function () {
    $r = vvRegister(['grade_level' => null, 'phone' => ''])->assertStatus(422);
    $all = collect($r->json('errors'))->flatten()->implode(' | ');
    expect($all)->not->toMatch('/grade level|grade_level|phone /i');
});
