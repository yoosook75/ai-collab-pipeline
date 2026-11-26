<?php

namespace App\Http\Controllers;

use App\Security\IpBlocker;
use Illuminate\View\View;

class OpsUnblockController extends Controller
{
    public function __invoke(string $ip, IpBlocker $blocker): View
    {
        $blocker->unblock($ip);

        return view('ops.unblocked', ['ip' => $ip]);
    }
}
