<?php

namespace App\Http\Middleware;

use App\Services\RuntimeConfigService;
use Closure;

class ReloadRuntimeConfiguration
{
    private $runtimeConfig;

    public function __construct(RuntimeConfigService $runtimeConfig)
    {
        $this->runtimeConfig = $runtimeConfig;
    }

    public function handle($request, Closure $next)
    {
        $this->runtimeConfig->refreshV2boardConfig();

        return $next($request);
    }
}
