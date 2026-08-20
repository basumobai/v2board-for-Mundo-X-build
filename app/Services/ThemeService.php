<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;
    private $runtimeConfig;

    public function __construct($theme, RuntimeConfigService $runtimeConfig = null)
    {
        $this->theme = $theme;
        $this->path = public_path('theme/');
        $this->runtimeConfig = $runtimeConfig ?: app(RuntimeConfigService::class);
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, "{$this->theme}主题不存在");
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) abort(500, "{$this->theme}主题配置文件有误");
        $configs = $themeConfig['configs'];
        $data = [];
        foreach ($configs as $config) {
            $data[$config['field_name']] = isset($config['default_value']) ? $config['default_value'] : '';
        }

        try {
            $this->runtimeConfig->saveThemeConfig($this->theme, $data);
        } catch (\Throwable $exception) {
            report($exception);
            abort(500, '请检查V2Board目录权限');
        }
    }
}
