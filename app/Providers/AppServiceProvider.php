<?php

namespace App\Providers;

use App\Telegram\QueueWorkerHeartbeat;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /* Claude behind the AI flows; tests bind a fake. */
        $this->app->singleton(
            \App\Application\Telegram\Ai\AiModel::class,
            \App\Application\Telegram\Ai\AnthropicAiModel::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        /*
         * The queue page reads these to tell whether a worker is running
         * at all - see QueueWorkerHeartbeat.
         */
        Event::listen(Looping::class, [QueueWorkerHeartbeat::class, 'looping']);
        Event::listen(JobProcessed::class, [QueueWorkerHeartbeat::class, 'processed']);
        Event::listen(JobFailed::class, [QueueWorkerHeartbeat::class, 'failed']);

        View::composer('layouts.app', function ($view) {
            $user = Auth::user();

            if (! $user) {
                // anonim foydalanuvchi uchun oddiy include
                $view->with('cachedHeader', view('partials.header')->render());
                return;
            }

            $cacheKey = 'header_html_user_' . $user->id . '_' . app()->getLocale();

            $html = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($user) {
                // ensure relations already loaded for consistent rendering
                $user->loadMissing(['avatar', 'role']);
                return view('layouts.app-header', ['user' => $user])->render();
            });

            $view->with('cachedHeader', $html);
        });
    }
}
