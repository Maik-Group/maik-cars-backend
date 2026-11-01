<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Maik Cat payment status</title>
</head>
<body style="font:16px system-ui,sans-serif;max-width:38rem;padding:2rem;margin:4rem auto">
    <h1>Return to Maik Cat</h1>
    <p>Payment status is verified separately. Return to the app to check your
       wallet or subscription; a successful redirect does not confirm fulfillment.</p>
    @php
        // This handoff is navigation only, with no token, entitlement mutation
        // or inference from the Checkout success/cancel query parameters.
        $orderId = (string) request()->query('orderId', '');
        $canOpen = (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $orderId);
        $appLink = $canOpen ? 'maikcat://subscriptions?orderId='.rawurlencode($orderId) : 'maikcat://subscriptions';
    @endphp
        <p>
            <a href="{{ $appLink }}"
               style="display:inline-block;background:#111;color:white;padding:.8rem 1.2rem;border-radius:8px;text-decoration:none">
                Open Maik Cat to check payment status
            </a>
        </p>
    <p>If credits have not appeared, use the refresh action in the app or contact support.</p>
    <script>
        // Best-effort automatic deep-link back into the installed application.
        // Browsers may require a user gesture; the explicit button above stays
        // usable. Neither success nor cancel query values grant entitlement.
        window.setTimeout(function () {
            if (document.visibilityState !== 'hidden') {
                window.location.assign(@json($appLink));
            }
        }, 650);
    </script>
</body>
</html>
