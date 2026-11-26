<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Staging-only, admin-only error source for demonstrating the incident pipeline.
 */
class OpsDemoController extends Controller
{
    public function __invoke(Request $request): never
    {
        abort_unless(config('ops.app_env_label') === 'staging', 404);
        abort_unless($request->user()?->is_admin, 403);

        throw new RuntimeException('Demo error for incident pipeline');
    }
}
