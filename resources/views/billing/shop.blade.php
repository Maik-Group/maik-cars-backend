<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Maik Cat | Plans and credits</title>
    <style>
        body{font:16px system-ui,sans-serif;background:#faf8f6;color:#2a2521;margin:0}
        main{max-width:850px;margin:auto;padding:2rem 1rem}
        h1{margin-bottom:.4rem}p{line-height:1.5}.offers{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem}
        article{background:white;padding:1.4rem;border:1px solid #ecded1;border-radius:14px}
        article.selected{border:2px solid #c84f00;background:#fff7f0}
        button{background:#c84f00;color:white;border:0;border-radius:9px;padding:.8rem 1rem;cursor:pointer;font:inherit}
        button:disabled{opacity:.5;cursor:not-allowed}small{color:#52665b}
    </style>
</head>
<body><main>
    <h1>Maik Cat</h1>
    <p>Choose a subscription or add credits. Your account: {{ auth()->user()->email }}.</p>
    @foreach (['subscriptions' => 'Subscriptions', 'topUps' => 'Extra credits'] as $category => $heading)
        @continue($category === 'topUps' && empty($catalog[$category]))
        <h2>{{ $heading }}</h2>
        <div class="offers">
            @forelse ($catalog[$category] as $offer)
                <article class="{{ ($selectedKind ?? '') === ($category === 'subscriptions' ? 'subscription' : 'topup') && ($selectedPrice ?? 0) === $offer['priceVersionId'] ? 'selected' : '' }}">
                    <h3>{{ $offer['name'] }}</h3>
                    @if (($selectedKind ?? '') === ($category === 'subscriptions' ? 'subscription' : 'topup') && ($selectedPrice ?? 0) === $offer['priceVersionId'])
                        <small>Your selected offer</small>
                    @endif
                    <p><strong>{{ number_format($offer['amountMinor'] / 100, 2) }} {{ $offer['currency'] }}</strong></p>
                    <p>{{ number_format($offer['credits']) }} credits
                        @if ($offer['durationMonths']) · {{ $offer['durationMonths'] }} month(s) @endif
                    </p>
                    <form method="POST" action="{{ route('billing.web.checkout') }}">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $category === 'subscriptions' ? 'subscription' : 'topup' }}">
                        <input type="hidden" name="priceVersionId" value="{{ $offer['priceVersionId'] }}">
                        <button type="submit" @disabled(! $offer['checkoutEligible'])>Continue to secure checkout</button>
                    </form>
                </article>
            @empty
                <p>No offers currently available.</p>
            @endforelse
        </div>
    @endforeach
    <p><small>Purchases are confirmed after payment verification. Returning to the app does not automatically add credits.</small></p>
    <p><a href="maikcat://subscriptions">Return to Maik Cat</a></p>
</main></body></html>
