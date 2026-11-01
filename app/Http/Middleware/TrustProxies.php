<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    public function handle($request, Closure $next)
    {
        $this->proxies = config('security.trusted_proxies', ['127.0.0.1', '::1']);
        // The isolated deployment trusts its own gateway, including after a
        // container recreation changes that gateway's address.
        $host = config('security.trusted_proxy_host');
        if (is_string($host) && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $address = gethostbyname($host);
            if (filter_var($address, FILTER_VALIDATE_IP)) $this->proxies[] = $address;
        }
        return parent::handle($request, $next);
    }
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies = ['127.0.0.1', '::1'];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;
}
