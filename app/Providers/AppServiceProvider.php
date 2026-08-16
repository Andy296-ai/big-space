<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->configureRateLimiters();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Единая точка правды для минимальной длины пароля (9 символов) —
        // раньше здесь было отдельное, более строгое правило только для
        // прода, которое нигде не подключалось: Admin\UserController валидировал
        // напрямую строкой 'min:9', так что реальная политика молча
        // расходилась с тем, что было тут написано. root — исключение,
        // задаётся отдельно в create_root_user-миграции, под это правило не подпадает.
        Password::defaults(fn (): Password => Password::min(9));
    }

    /**
     * Троттлинг для эндпоинтов, у которых раньше не было вообще никакого
     * ограничения — только то, что уже покрыто RateLimiter-логикой прямо в
     * AuthController (вход, 2FA), сюда не дублируется.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('export', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('import', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('admin-users', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // Комментарии к узлам сегодня вообще без лимита — для чата это
        // не годится, у него принципиально больше объём сообщений в минуту.
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Публичные ссылки — аноним в принципе, только IP: тот же фолбэк,
        // что и у остальных лимитеров, здесь используется всегда.
        RateLimiter::for('shared', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
