<?php

namespace App\Providers;

use App\Models\Message;
use App\Observers\MessageObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

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
        // Register model observers
        Message::observe(MessageObserver::class);

        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/api.php'));
    }
    
}
