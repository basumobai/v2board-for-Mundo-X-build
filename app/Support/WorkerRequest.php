<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class WorkerRequest
{
    public static function capture(): Request
    {
        Request::enableHttpMethodParameterOverride();
        // AdapterMan parses the socket body into this per-request global.
        // CLI php://input is empty; rebuilding JSON would invalidate HMACs.
        return Request::createFromBase(new SymfonyRequest(
            $_GET, $_POST, [], $_COOKIE, $_FILES, $_SERVER,
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] ?? ''
        ));
    }
}
