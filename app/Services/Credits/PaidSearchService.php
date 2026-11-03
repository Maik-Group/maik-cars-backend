<?php

namespace App\Services\Credits;

use App\Models\CreditConsumption;
use App\Models\Item;
use App\Models\PaidSearchSession;
use App\Models\User;
use App\Services\Mobile\ItemApiResponseService;
use App\Services\Mobile\ItemPriceService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A credit pays for one explicit non-empty search, not for item opens.
 *
 * A paid search session only authorizes matching catalog items and expires
 * after one day; it cannot be replayed for unrelated items or other accounts.
 */
class PaidSearchService
{
    public function __construct(
        private readonly CreditWalletService $wallet,
        private readonly ItemApiResponseService $responses,
    ) {}

    public function start(User $user, string $text, string $idempotencyKey): PaidSearchSession
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 255
            || str_contains($text, '%') || str_contains($text, '_')
            || preg_match('/[\pL\pN]/u', $text) !== 1) {
            throw new HttpException(422, 'SEARCH_TEXT_REQUIRED');
        }
        if (strlen($idempotencyKey) < 12 || strlen($idempotencyKey) > 200) {
            throw new HttpException(422, 'IDEMPOTENCY_KEY_REQUIRED');
        }
        $environment = (string) config('stripe_billing.environment');
        $hash = hash('sha256', $idempotencyKey);
        $prior = PaidSearchSession::query()->where('user_id', $user->getKey())
            ->where('environment', $environment)->where('idempotency_hash', $hash)->first();
        if ($prior) {
            if ($prior->search_text !== $text) {
                throw new HttpException(409, 'SEARCH_IDEMPOTENCY_CONFLICT');
            }

            return $this->authorized($user, (string) $prior->getKey());
        }
        $firstMatch = $this->query($text)->first();
        if ($firstMatch) {
            // Pricing may depend on external metals feeds; never keep a user
            // wallet lock or database transaction open during that lookup.
            $price = app(ItemPriceService::class)->priceFor($firstMatch, 'USD');
            if (! is_finite($price) || $price <= 0) {
                throw new HttpException(503, 'VALUATION_UNAVAILABLE');
            }
        }

        return DB::transaction(function () use ($user, $text, $environment, $hash, $idempotencyKey, $firstMatch): PaidSearchSession {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $lockedUser->is_active) {
                throw new HttpException(403, 'ACCOUNT_INACTIVE');
            }
            $existing = PaidSearchSession::query()
                ->where('user_id', $user->getKey())->where('environment', $environment)
                ->where('idempotency_hash', $hash)->first();
            if ($existing) {
                if ($existing->search_text !== $text) {
                    throw new HttpException(409, 'SEARCH_IDEMPOTENCY_CONFLICT');
                }
                if ($existing->expires_at->isPast()) {
                    throw new HttpException(410, 'SEARCH_EXPIRED');
                }

                return $existing;
            }

            // Only actual, visible catalog matches are chargeable; autocomplete,
            // empty queries, filters, and empty result sets use no credits.
            $receiptId = null;
            if ($firstMatch) {
                $receipt = $this->wallet->spend($lockedUser, $firstMatch, $environment, false, $idempotencyKey);
                $receiptId = $receipt['receipt_id'];
            }

            return PaidSearchSession::query()->create([
                'user_id' => $user->getKey(),
                'environment' => $environment,
                'idempotency_hash' => $hash,
                'search_text' => $text,
                'charged' => (bool) $firstMatch,
                'credit_consumption_id' => $receiptId,
                'expires_at' => now('UTC')->addDay(),
            ]);
        }, 3);
    }

    public function authorized(User $user, string $searchId): PaidSearchSession
    {
        if (! $user->is_active) {
            throw new HttpException(403, 'ACCOUNT_INACTIVE');
        }
        $session = PaidSearchSession::query()
            ->whereKey($searchId)->where('user_id', $user->getKey())
            ->where('environment', config('stripe_billing.environment'))
            ->firstOrFail();

        if ($session->expires_at->isPast()) {
            throw new HttpException(410, 'SEARCH_EXPIRED');
        }
        if ($session->charged) {
            $receipt = CreditConsumption::query()->whereKey($session->credit_consumption_id)->first();
            // Receipt identity belongs to the search that was actually debited.
            if (! $receipt || $receipt->user_id !== $user->getKey()
                || $receipt->environment !== $session->environment
                || ! DB::table('credit_grants')->where('id', $receipt->credit_grant_id)
                    ->whereNull('revoked_at')->whereNull('held_at')->exists()) {
                throw new HttpException(403, 'SEARCH_ACCESS_REVOKED');
            }
        }

        return $session;
    }

    public function allowsItem(PaidSearchSession $session, Item $item): bool
    {
        return $session->charged && $this->query($session->search_text)
            ->whereKey($item->getKey())->exists();
    }

    public function page(PaidSearchSession $session, int $page, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        if (! $this->responses->uniqueModeEnabled()) {
            return $this->query($session->search_text, true)->paginate($perPage, ['*'], 'page', $page);
        }

        $rows = $this->query($session->search_text)->get(['items.id', 'items.normalized_serial', 'items.serial_code']);
        $ids = $this->responses->representativeIds($rows);
        $pageIds = $ids->forPage($page, $perPage)->values();
        $found = Item::query()->with(['carGroup', 'media'])->whereKey($pageIds->all())
            ->get()->keyBy(fn (Item $item): string => (string) $item->getKey());
        $items = $pageIds->map(fn (mixed $id): ?Item => $found->get((string) $id))
            ->filter(fn (mixed $item): bool => $item instanceof Item)->values();

        return new LengthAwarePaginator(
            $this->responses->applyAverages($items), $ids->count(), $perPage, $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    private function query(string $text, bool $withRelations = false): QueryBuilder
    {
        $request = Request::create('/', 'GET', ['filter' => ['text' => $text]]);

        return Item::getQuery($request, $withRelations);
    }
}
