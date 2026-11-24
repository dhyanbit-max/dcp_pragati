<?php

namespace App\Providers;

use App\Domain\Access\Role;
use App\Domain\Auth\LogOtpSender;
use App\Domain\Auth\OtpSender;
use App\Domain\Rules\RuleEngine;
use App\Models\RuleDefinition;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, fn () => match (config('dcp.otp.driver')) {
            'log' => new LogOtpSender,
            default => throw new InvalidArgumentException('Unknown OTP driver: '.config('dcp.otp.driver')),
        });
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // System admins pass every permission check.
        Gate::before(fn ($user) => $user->hasRole(Role::SYSTEM_ADMIN) ? true : null);

        RateLimiter::for('otp', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('field', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('photos', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));

        RuleDefinition::saved(fn () => RuleEngine::flushCache());
        RuleDefinition::deleted(fn () => RuleEngine::flushCache());
    }
}
