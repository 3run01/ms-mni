<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configurar autorização do Log Viewer
        LogViewer::auth(function ($request) {
            return $request->user() !== null;
        });

        // Configurar Pulse para coleta de métricas
        if (class_exists(Pulse::class)) {
            $this->app->make(Pulse::class)->user(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'extra' => $user->email,
            ]);
        }

        if (env('FORCE_HTTPS') === true) {
            URL::forceScheme('https');
        }

        // Configuração de URLs temporárias para arquivos locais
        // Storage::disk('local')->buildTemporaryUrlsUsing(
        //     function (string $path, \DateTime $expiration, array $options) {
        //         return URL::temporarySignedRoute(
        //             'files.download',
        //             $expiration,
        //             array_merge($options, ['path' => $path])
        //         );
        //     }
        // );
    }
}
