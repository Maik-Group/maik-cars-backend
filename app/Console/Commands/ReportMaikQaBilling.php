<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Credits\CreditWalletService;
use Database\Seeders\MaikCatQaBillingSeeder;
use Illuminate\Console\Command;

/** A read-only, live QA snapshot; no stale duplicate JSON file is persisted. */
class ReportMaikQaBilling extends Command
{
    protected $signature = 'maik:qa-billing-report';

    protected $description = 'Return current sandbox QA balances from Laravel database';

    public function handle(CreditWalletService $wallet): int
    {
        $users = User::query()->whereIn('email', array_values(MaikCatQaBillingSeeder::ACCOUNTS))
            ->get()->keyBy('email');
        $accounts = [];
        foreach (MaikCatQaBillingSeeder::ACCOUNTS as $scenario => $email) {
            $user = $users->get($email);
            $accounts[$scenario] = $user ? [
                'userId' => $user->getKey(), 'walletBalance' => $wallet->balance($user, 'sandbox'),
            ] : null;
        }

        $this->line(json_encode(['billingEnvironment' => 'sandbox', 'accounts' => $accounts], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
