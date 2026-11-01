<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

readonly class GrantTerms
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public function __construct(CarbonImmutable $startsAt, CarbonImmutable $endsAt, public string $reason)
    {
        if ($endsAt->lessThanOrEqualTo($startsAt) || trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['grant' => 'A reason and an increasing validity interval are required.']);
        }
        $this->startsAt = $startsAt->utc();
        $this->endsAt = $endsAt->utc();
    }

    public static function forPlan(SubscriptionPlan $plan, CarbonImmutable $startsAt, string $reason): self
    {
        if (! in_array($plan->duration_months, [1, 3, 6, 12], true)) {
            throw ValidationException::withMessages(['plan' => 'Unsupported calendar duration.']);
        }
        $start = $startsAt->utc();

        return new self($start, $start->addMonthsNoOverflow($plan->duration_months), $reason);
    }
}
