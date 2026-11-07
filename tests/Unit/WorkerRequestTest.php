<?php

namespace Tests\Unit;

use App\Support\WorkerRequest;
use PHPUnit\Framework\TestCase;

class WorkerRequestTest extends TestCase
{
    public function testCapturePreservesSignedBytesAndClearsBodyBetweenRequests(): void
    {
        $saved = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $GLOBALS['HTTP_RAW_REQUEST_DATA'] ?? null];
        try {
            $body = " \n{\"event\": {\"type\": \"charge:confirmed\"}, \"amount\": 5.00}\n";
            $_GET = $_COOKIE = $_FILES = [];
            $_POST = json_decode($body, true);
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/notify', 'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'secret')];
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $body;
            $request = WorkerRequest::capture();
            $this->assertSame($body, $request->getContent());
            $this->assertSame('charge:confirmed', $request->input('event.type'));
            $this->assertSame($request->header('X-Signature'), hash_hmac('sha256', $request->getContent(), 'secret'));

            $_POST = [];
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/healthz'];
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
            $next = WorkerRequest::capture();
            $this->assertSame('', $next->getContent());
            $this->assertNull($next->input('event'));
            $this->assertNull($next->header('X-Signature'));
        } finally {
            [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $raw] = $saved;
            if ($raw === null) unset($GLOBALS['HTTP_RAW_REQUEST_DATA']);
            else $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $raw;
        }
    }
}
