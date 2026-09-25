<?php

test('host la bi tu choi (TrustHosts)', function () {
    $response = $this->getJson('http://evil.test/api/v1/health');

    expect($response->getStatusCode())->toBeIn([400, 404]);
});
