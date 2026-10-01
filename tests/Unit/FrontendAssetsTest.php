<?php

namespace Tests\Unit;

use App\Support\FrontendAssets;
use PHPUnit\Framework\TestCase;

class FrontendAssetsTest extends TestCase
{
    public function testSameSizeSameTimestampEditsAndRemovalChangeTheVersion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mundo-assets-');
        try {
            file_put_contents($path, 'old-css');
            touch($path, 1700000000);
            $first = FrontendAssets::version([$path], 'fallback');
            file_put_contents($path, 'new-css');
            touch($path, 1700000000);
            $second = FrontendAssets::version([$path], 'fallback');
            $this->assertNotSame($first, $second);
            $this->assertSame($second, FrontendAssets::version([$path], 'fallback'));
            unlink($path);
            $this->assertNotSame($second, FrontendAssets::version([$path], 'fallback'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
