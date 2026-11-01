<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\TrustedOrigins;

class CORS
{
    public function handle($request, Closure $next)
    {
        $origin = $request->header('origin');
        $allowed = TrustedOrigins::allows($origin);
        $preflight = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method');
        $response = $preflight ? response('', $allowed ? 204 : 403) : $next($request);
        $response->setVary('Origin', false);
        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS,HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Origin,Content-Type,Accept,Authorization,X-Request-With,X-Requested-With');
            $response->headers->set('Access-Control-Expose-Headers', 'subscription-userinfo,Content-Disposition');
            $response->headers->set('Access-Control-Max-Age', '600');
            if (config('cors.supports_credentials', false)) $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
