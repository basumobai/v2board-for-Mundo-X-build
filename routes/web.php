<?php

use App\Services\ThemeService;
use App\Services\RuntimeConfigService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => config('v2board.frontend_theme', 'default'),
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => config('v2board.logo')
    ];

    $runtimeConfig = app(RuntimeConfigService::class);
    $themeConfig = $runtimeConfig->loadThemeConfig($renderParams['theme']);
    if (!$themeConfig) {
        $themeService = new ThemeService($renderParams['theme'], $runtimeConfig);
        $themeService->init();
        $themeConfig = $runtimeConfig->loadThemeConfig($renderParams['theme']);
    }

    $renderParams['theme_config'] = $themeConfig;
    return view('theme::' . config('v2board.frontend_theme', 'default') . '.dashboard', $renderParams);
});

//TODO:: 兼容
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    $customCssPath = public_path('assets/admin/custom.css');
    $customJavascriptPath = public_path('assets/admin/custom.js');
    clearstatcache(true, $customCssPath);
    clearstatcache(true, $customJavascriptPath);
    $adminUiVersion = max(
        is_file($customCssPath) ? filemtime($customCssPath) : 0,
        is_file($customJavascriptPath) ? filemtime($customJavascriptPath) : 0
    );

    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'admin_ui_version' => $adminUiVersion ?: config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware('client');
}
