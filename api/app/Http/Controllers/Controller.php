<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as ControllerBase;

abstract class Controller extends ControllerBase
{
    use AuthorizesRequests;
}