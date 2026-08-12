<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            return rtrim((string) config('mangroscan.web_url'), '/')
                .'/reset-password?token='.rawurlencode($token)
                .'&email='.rawurlencode((string) $notifiable->getEmailForPasswordReset());
        });

        RateLimiter::for('auth.login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(max(
                1,
                (int) config('mangroscan.auth.login_attempts_per_minute'),
            ))
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth.authenticated', function (Request $request): Limit {
            return Limit::perMinute(max(
                1,
                (int) config('mangroscan.auth.authenticated_requests_per_minute'),
            ))
                ->by(($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip());
        });
    }
}
