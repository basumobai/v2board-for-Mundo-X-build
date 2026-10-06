<?php

namespace Tests\Unit;

use Tests\TestCase;

class XiaoAdminViewTest extends TestCase
{
    public function testXiaoViewRendersWithOnlyTheOriginalWebRouteVariables(): void
    {
        config(['v2board.frontend_theme_header' => 'dark']);
        $view = app('view')->file(base_path('variants/xiao-admin/admin.blade.php'), [
            'title' => 'Xiao test',
            'theme_sidebar' => 'light',
            'theme_header' => 'dark',
            'theme_color' => 'default',
            'background_url' => '',
            'version' => 'xiao-test',
            'logo' => '',
            'secure_path' => 'custom-admin',
        ])->render();
        $cssHash = hash_file('sha256', public_path('assets/admin/custom.css'));
        $jsHash = hash_file('sha256', public_path('assets/admin/custom.js'));
        $uiVersion = substr(hash('sha256', $cssHash . ':' . $jsHash), 0, 20);
        $bundleVersion = substr(hash_file('sha256', public_path('assets/admin/umi.js')), 0, 20);

        $this->assertStringContainsString('custom.css?v=' . $uiVersion, $view);
        $this->assertStringContainsString('custom.js?v=' . $uiVersion, $view);
        $this->assertStringContainsString('umi.js?v=' . $bundleVersion, $view);
        $this->assertStringContainsString("header: 'dark'", $view);
        $this->assertStringContainsString("secure_path: 'custom-admin'", $view);
        $this->assertStringNotContainsString('@php', $view);
        $this->assertStringNotContainsString('{{$admin_', $view);
    }
}
