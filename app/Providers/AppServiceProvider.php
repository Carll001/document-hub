<?php

namespace App\Providers;

use App\Contracts\Services\DocumentBatchItemGenerationService as DocumentBatchItemGenerationServiceContract;
use App\Contracts\Repositories\AfsFilingItemRepository as AfsFilingItemRepositoryContract;
use App\Contracts\Repositories\CompanyRepository as CompanyRepositoryContract;
use App\Contracts\Repositories\FilingRepository as FilingRepositoryContract;
use App\Repositories\Eloquent\AfsFilingItemRepository;
use App\Repositories\Eloquent\CompanyRepository;
use App\Repositories\Eloquent\FilingRepository;
use App\Services\DocumentBatchItemGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            DocumentBatchItemGenerationServiceContract::class,
            DocumentBatchItemGenerationService::class,
        );
        $this->app->bind(
            AfsFilingItemRepositoryContract::class,
            AfsFilingItemRepository::class,
        );
        $this->app->bind(
            CompanyRepositoryContract::class,
            CompanyRepository::class,
        );
        $this->app->bind(
            FilingRepositoryContract::class,
            FilingRepository::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
