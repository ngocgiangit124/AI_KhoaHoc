<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * `AuthorizesRequests` cho phép `$this->authorize()` trong mọi controller
 * (api-contract §1.3: "Mọi action còn gọi $this->authorize(); middleware
 * `role` chỉ là lớp chặn thô"). Thêm ở T06 vì là controller quản trị đầu
 * tiên cần Policy — dùng chung cho mọi task sau.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
