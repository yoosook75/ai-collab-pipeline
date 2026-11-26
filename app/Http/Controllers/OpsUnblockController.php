<?php

namespace App\Http\Controllers;

use App\Security\IpBlocker;
use Illuminate\Http\Response;

class OpsUnblockController extends Controller
{
    public function __invoke(string $ip, IpBlocker $blocker): Response
    {
        $blocker->unblock($ip);

        return response("{$ip} 차단을 해제했습니다.");
    }
}
