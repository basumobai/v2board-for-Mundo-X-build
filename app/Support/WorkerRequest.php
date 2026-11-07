<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class WorkerRequest
{
    public static function capture(array $connectionServer = []): Request
    {
        Request::enableHttpMethodParameterOverride();
        // AdapterMan's GET cache includes $_SERVER from the first connection.
        // Socket metadata must always come from the current connection; cached
        // GET requests must not inherit files from an earlier upload either.
        $server = array_replace($_SERVER, $connectionServer);
        $files = ($server['REQUEST_METHOD'] ?? '') === 'GET' ? [] : $_FILES;
        // AdapterMan parses the socket body into this per-request global.
        // CLI php://input is empty; rebuilding JSON would invalidate HMACs.
        return Request::createFromBase(new SymfonyRequest(
            $_GET, $_POST, [], $_COOKIE, $files, $server,
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] ?? ''
        ));
    }
}
