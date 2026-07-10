<?php

namespace App\Providers;

use App\Models\AttendanceRecord;
use App\Models\Authorization;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\PayrollPeriod;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\AuthorizationPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\IncidentPolicy;
use App\Policies\PayrollPeriodPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Employee::class => EmployeePolicy::class,
        AttendanceRecord::class => AttendanceRecordPolicy::class,
        Incident::class => IncidentPolicy::class,
        PayrollPeriod::class => PayrollPeriodPolicy::class,
        Authorization::class => AuthorizationPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PAC de timbrado CFDI: driver intercambiable por config. 'fake' es un
        // singleton para que los tests puedan inspeccionar las llamadas.
        $this->app->singleton(\App\Services\Cfdi\FakePacDriver::class);
        $this->app->bind(\App\Services\Cfdi\PacProviderInterface::class, function ($app) {
            return config('services.cfdi.driver') === 'facturama'
                ? $app->make(\App\Services\Cfdi\FacturamaDriver::class)
                : $app->make(\App\Services\Cfdi\FakePacDriver::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Register policies
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
