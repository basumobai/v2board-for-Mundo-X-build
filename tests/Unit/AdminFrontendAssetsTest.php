<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminFrontendAssetsTest extends TestCase
{
    public function testAdminShellLoadsModernEnhancementsWithoutDisablingZoom(): void
    {
        $viewPath = $this->projectPath('resources/views/admin.blade.php');

        $this->assertFileExists($viewPath);
        $view = file_get_contents($viewPath);

        $this->assertStringContainsString('<html lang="zh-CN" data-mundo-theme="{{$theme_color}}">', $view);
        $this->assertStringContainsString('viewport-fit=cover', $view);
        $this->assertStringNotContainsString('user-scalable=no', $view);
        $this->assertStringNotContainsString('maximum-scale=1', $view);
        $this->assertStringContainsString('id="mundo-admin-overrides"', $view);
        $this->assertStringContainsString('/assets/admin/custom.css?v={{$admin_ui_version}}', $view);
        $this->assertStringContainsString('/assets/admin/custom.js?v={{$admin_ui_version}}', $view);

        $routes = file_get_contents($this->projectPath('routes/web.php'));
        $this->assertStringContainsString('clearstatcache(true, $customCssPath)', $routes);
        $this->assertStringContainsString("'admin_ui_version' =>", $routes);
    }

    public function testAdminEnhancementAssetsContainAccessibilityFallbacks(): void
    {
        $cssPath = $this->projectPath('public/assets/admin/custom.css');
        $javascriptPath = $this->projectPath('public/assets/admin/custom.js');

        $this->assertFileExists($cssPath);
        $this->assertFileExists($javascriptPath);
        $css = file_get_contents($cssPath);
        $javascript = file_get_contents($javascriptPath);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringNotContainsString('transition: all', $css);
        $this->assertStringContainsString("aria-current", $javascript);
        $this->assertStringContainsString("aria-live", $javascript);
        $this->assertStringContainsString("keepOverrideStylesheetLast", $javascript);
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}
