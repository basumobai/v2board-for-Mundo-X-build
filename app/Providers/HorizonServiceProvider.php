<?php

namespace App\Providers;

use App\Services\AuthService;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        // Horizon::night();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewHorizon', function ($user = null) {
            $authorization = request()->input('auth_data')
                ?? request()->header('authorization');

            if (!$authorization) {
                return false;
            }

            $v2boardUser = AuthService::decryptAuthData($authorization);

            return $v2boardUser && !empty($v2boardUser['is_admin']);
        });
    }
}
