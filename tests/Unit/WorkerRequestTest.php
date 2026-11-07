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
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/healthz', 'REMOTE_ADDR' => '203.0.113.1'];
            $_FILES = ['previous_upload' => ['name' => 'private.txt', 'type' => 'text/plain',
                'tmp_name' => '/not-a-current-upload', 'error' => 0, 'size' => 123]];
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
            $next = WorkerRequest::capture(['REMOTE_ADDR' => '198.51.100.17', 'REMOTE_PORT' => 55000]);
            $this->assertSame('', $next->getContent());
            $this->assertNull($next->input('event'));
            $this->assertNull($next->header('X-Signature'));
            $this->assertSame('198.51.100.17', $next->ip());
            $this->assertSame(55000, $next->server('REMOTE_PORT'));
            $this->assertSame([], $next->allFiles());
        } finally {
            [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $raw] = $saved;
            if ($raw === null) unset($GLOBALS['HTTP_RAW_REQUEST_DATA']);
            else $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $raw;
        }
    }
}
