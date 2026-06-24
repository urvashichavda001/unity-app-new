<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Support/helpers.php');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        config([
            'mail.mailers.pravin' => [
                'transport' => 'smtp',
                'host' => env('MAIL_HOST_PRAVIN', 'smtppro.zoho.in'),
                'port' => env('MAIL_PORT_PRAVIN', 587),
                'encryption' => env('MAIL_ENCRYPTION_PRAVIN', 'tls'),
                'username' => env('MAIL_USERNAME_PRAVIN', 'pravin@peersglobal.com'),
                'password' => env('MAIL_PASSWORD_PRAVIN'),
                'timeout' => null,
            ]
        ]);
    }
}
