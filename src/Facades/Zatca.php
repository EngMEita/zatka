<?php

namespace Zatca\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Zatca
 *
 * Provides a facade for the ZatcaClient to enable expressive static
 * method calls within a Laravel application. Under the hood the
 * ZatcaServiceProvider binds the client to the service container.
 */
class Zatca extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'zatca';
    }
}