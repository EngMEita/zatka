<?php

namespace Zatca;

use Illuminate\Support\ServiceProvider;
use Zatca\Support\ZatcaConfig;
use Zatca\ZatcaClient;

/**
 * Class ZatcaServiceProvider
 *
 * Registers the ZATCA services with a Laravel application. It publishes the
 * configuration file, merges default configuration, binds the
 * ZatcaConfig and ZatcaClient classes into the IoC container and sets up
 * a facade alias for easy access.
 */
class ZatcaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration file to the application's config directory
        $this->publishes([
            __DIR__ . '/config/zatca.php' => config_path('zatca.php'),
        ], 'zatca-config');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge default configuration from the package
        $this->mergeConfigFrom(
            __DIR__ . '/config/zatca.php',
            'zatca'
        );

        // Bind the ZatcaConfig class as a singleton so all consumers share
        // the same configuration instance per request
        $this->app->singleton(ZatcaConfig::class, function ($app) {
            return new ZatcaConfig();
        });

        // Bind ZatcaClient and inject ZatcaConfig automatically
        $this->app->bind(ZatcaClient::class, function ($app) {
            return new ZatcaClient($app->make(ZatcaConfig::class));
        });

        // Alias the client into the container for facade usage
        $this->app->alias(ZatcaClient::class, 'zatca');
    }
}