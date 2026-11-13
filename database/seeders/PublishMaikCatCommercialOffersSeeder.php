<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Publish the seeded commercial catalog automatically, but only in Stripe test mode.
 * Reuse the guarded, idempotent publisher instead of fabricating Stripe IDs.
 */
class PublishMaikCatCommercialOffersSeeder extends Seeder
{
    public function run(): void
    {
        $key = config('stripe_billing.secret_key');
        if (! is_string($key) || ! str_starts_with($key, 'sk_test_')
            || config('stripe_billing.environment') !== 'sandbox') {
            $this->command?->warn('Maik Cat offers are local drafts only. Automatic Stripe publication requires STRIPE_SECRET_KEY=sk_test_... and sandbox billing; live Stripe publication is never automatic.');

            return;
        }

        // DatabaseSeeder created this privileged actor before commercial offers.
        // Never bypass the permission checks of the existing Stripe publisher.
        $actor = User::query()->where('email', 'admin@example.com')->first();
        if (! $actor?->is_active || ! $actor->can('manage_subscription_products')) {
            throw new RuntimeException('Cannot auto-publish Maik Cat offers: seeded administrator is missing, inactive, or lacks manage_subscription_products.');
        }

        $code = Artisan::call('stripe:publish-commercial-test-offers', [
            '--actor' => $actor->getKey(),
            '--execute' => true,
        ]);
        if ($code !== Command::SUCCESS) {
            throw new RuntimeException('Maik Cat Stripe test offer publication failed: '.trim(Artisan::output()));
        }

        $this->command?->info('Maik Cat commercial test offers published and verified through Stripe (or already published).');
    }
}
