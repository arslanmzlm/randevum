<?php

namespace App\Modules\Verticals\Podiatry;

use Illuminate\Support\ServiceProvider;

/**
 * A vertical is a self-contained folder: its config, lang and migrations all ship
 * here. Adding a vertical = copy this folder, fill config/lang/migrations, add a
 * `verticals` row + morph-map slug, and register the provider.
 */
class PodiatryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/podiatry.php', 'podiatry');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/lang', 'verticals.podiatry');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
