<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Define-se a lista de emails autorizados em HORIZON_ADMIN_EMAILS (separados
     * por vírgula). Em local, liberado para qualquer usuário autenticado.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (app()->environment('local')) {
                return true;
            }

            $emails = collect(explode(',', (string) env('HORIZON_ADMIN_EMAILS', '')))
                ->map(fn ($email) => trim($email))
                ->filter()
                ->all();

            return in_array(optional($user)->email, $emails, true);
        });
    }
}
