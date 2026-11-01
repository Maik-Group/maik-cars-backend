<?php

namespace App\Providers;

use App\Models\ItemImageSubmission;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemApiSettingsService;
use App\Services\Mobile\ItemPriceService;
use App\Services\Mobile\ItemPriceSettingsService;
use App\Services\Mobile\MetalsSpotService;
use App\Services\Subscriptions\EntitlementResolver;
use App\Support\MediaLibrary\ImageSubmissionPathGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetalsSpotService::class);
        $this->app->scoped(ItemPriceSettingsService::class);
        $this->app->scoped(ItemPriceService::class);
        $this->app->scoped(ItemApiSettingsService::class);
        $this->app->scoped(ItemApiResponseService::class);
        $this->app->scoped(EntitlementResolver::class);

        $this->app->singleton(Messaging::class, function (): Messaging {
            $factory = new Factory;
            $credentials = config('services.firebase.credentials');
            $projectId = config('services.firebase.project_id');

            if (is_string($credentials) && $credentials !== '') {
                $factory = $factory->withServiceAccount($credentials);
            }

            if (is_string($projectId) && $projectId !== '') {
                $factory = $factory->withProjectId($projectId);
            }

            return $factory->createMessaging();
        });

        $this->app->singleton(FcmChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['subscription-reveals' => 60, 'subscription-purchases' => 10] as $name => $limit) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($limit)
                ->by((string) $request->user()?->getKey())
                ->response(fn (Request $request, array $headers) => response()->json(['code' => 'RATE_LIMITED', 'message' => 'RATE_LIMITED'], 429, $headers)));
        }
        PathGeneratorFactory::setCustomPathGenerators(ItemImageSubmission::class, ImageSubmissionPathGenerator::class);

        $this->app->make(ChannelManager::class)->extend('fcm', function ($app): FcmChannel {
            return $app->make(FcmChannel::class);
        });

        Gate::before(static function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
