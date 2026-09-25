<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('X-Forwarded-For gia tu IP khong tin cay khong doi request ip (S10)', function () {
    Route::domain(config('app.api_host'))
        ->get('/__test/ip', fn (Request $request) => response()->json(['ip' => $request->ip()]));

    $baseline = $this->getJson('http://'.config('app.api_host').'/__test/ip')->json('ip');

    $spoofed = $this->withHeaders([
        'X-Forwarded-For' => '203.0.113.99',
    ])->getJson('http://'.config('app.api_host').'/__test/ip')->json('ip');

    expect($spoofed)->toBe($baseline);
});
