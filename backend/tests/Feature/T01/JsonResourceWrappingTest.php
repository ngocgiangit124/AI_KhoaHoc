<?php

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

test('JsonResource don le tra JSON phang, khong boc data (api-contract §1.4)', function () {
    $resource = new class(['id' => 1, 'name' => 'Toán 6'])extends JsonResource
    {
        public function toArray($request)
        {
            return $this->resource;
        }
    };

    Route::domain(config('app.api_host'))
        ->get('/__test/flat-resource', fn () => $resource);

    $response = $this->getJson('http://'.config('app.api_host').'/__test/flat-resource');

    $response->assertOk();
    $response->assertExactJson(['id' => 1, 'name' => 'Toán 6']);
    $response->assertJsonMissing(['data' => ['id' => 1]]);
});
