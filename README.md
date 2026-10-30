# Tech Connect: ISP billing for a small WISP

A PHP application for a small wireless ISP in Kenya. It sells bandwidth packages and vouchers, takes payment through M-Pesa, provisions the customer onto a MikroTik router, and gives the operator a console and the subscriber a login.

## Why I built it

Small WISPs here often run on three things that do not talk to each other: a spreadsheet, the router admin page, and a phone full of M-Pesa messages. When they disagree, the customer pays and stays offline, or goes online without paying, and someone loses an evening to it.

The problem worth solving is not billing. It is making the router, the payment record and the customer's session describe the same reality.

## How it is laid out

```
index.php                    front controller
  /frontend/                 static HTML, served as-is
  /backend/*.php             executed directly
  /health.php                direct entry point

backend/api.php              one action name in, one JSON response out
backend/mikrotik_manager.php RouterOS calls: add, read, test, sync
backend/client_manager.php   subscriber records and sessions
backend/fup_engine.php       usage tracking and speed drops
backend/sms_service.php      voucher delivery
backend/callback.php         M-Pesa callback
```

`index.php` does three things: sends the root to `/frontend/index.html`, serves anything under `frontend/` as a static file, and executes PHP under `backend/` or at the root. A directory path resolves to its `index.html` or `index.php` rather than being handed to `readfile()`.

## The part worth showing

Routing every operation through one action name keeps a single place to read, log and audit:

```php
switch ($action) {
  case 'activate':         ...
  case 'initiate_payment': ...
  case 'check_payment':    ...
  case 'use_voucher':      ...
}
```

The decision I would point at is inside `checkPayment`. A naive implementation activates the session as soon as the browser says the payment succeeded, which is trivially spoofed. This one refuses:

```php
// PRODUCTION SECURITY: Only activate sessions via the M-Pesa callback.
if (!empty($checkout_request_id)) {
    $pendingSession = $this->userManager->getSessionByCheckoutId($checkout_request_id);
    if ($pendingSession && $pendingSession['status'] === 'pending') {
        $age = time() - strtotime($pendingSession['created_at']);
        // too old to still be pending, report it rather than silently activating
    }
}
```

Someone claiming "I paid" gets a status check, not access. Access is granted in two places only: the M-Pesa callback, and redeeming a voucher. `check_payment` exists so the operator can ask about a pending session instead of guessing when the callback is late.

## Where it is still weak

The callback checks the caller against Safaricom's published IP range, and this is what that check actually does today:

```php
if (!in_array($clientIP, $allowedIPs)) {
    error_log("M-Pesa callback from non-whitelisted IP: $clientIP");
    // Continue processing but log the concern
}
```

Logging instead of rejecting is the wrong default. Worse, `getClientIP()` reads `X-Forwarded-For` before `REMOTE_ADDR`, so the header can be set by the caller. Together that means someone who guesses a live `CheckoutRequestID` and sends a forged success could activate a session without paying. I am disclosing it rather than quietly fixing it, because rejecting on IP needs a trusted-proxy setting this deployment never had, and a half-applied fix would lock out real callbacks.

## Running it

```bash
composer install
cp .env.example .env
php backend/seed_postgresql.php
php -S localhost:8000 index.php
```

The seeding script reads `DATABASE_URL` from `.env`, so the copy step is enough on its own.

What I ran to check this README, on PHP 8.1: every file passes `php -l`, the server starts, `/` redirects, `/frontend/` serves the portal, `/health.php` answers OK, and `/backend/api.php` returns the API envelope.

`/provision/{client}/{router}` and `/templates/{client}/{name}` reach their scripts, but they read from the database. Without a reachable PostgreSQL instance and a completed seed they return a 500, which is the honest behaviour rather than a routing fault.

`Dockerfile` is the deployment path. The other deployment files that came from trying different hosts have been removed.

## What is rough

- **Several development backdoors were removed before publishing, and it is worth naming them.** The original build exposed `debug_env` (which returned the database URL), `test_mpesa`, `test_callback`, `verify_payment`, an `activate_test_session` action that activated a KSH 1 session without any payment, and a local admin API whose auth check was skipped when no key was supplied. All six are gone from this mirror. They were convenient while wiring M-Pesa up and unsafe the moment anything is reachable from the internet.
- **The admin panel went through several rewrites.** I removed the older variants and kept one.
- **The callback IP whitelist logs but does not block, and `X-Forwarded-For` is trusted before `REMOTE_ADDR`.** See "Where it is still weak" above. This is the most serious open issue in the codebase.
- **Reconciliation is partly manual.** If M-Pesa confirms but the callback never arrives, someone has to check and activate.
- **Router calls are synchronous.** Fine for one router and a few hundred customers, not beyond that.
- **No unit tests.** Everything was verified by hand against a sandbox account.
- **The client portal was removed from this mirror.** It required a `config.php` that was never committed and duplicated `backend/client_manager.php`. Shipping something that cannot run is worse than not shipping it.

## Stack

PHP, PostgreSQL, MikroTik RouterOS API, M-Pesa Daraja, Docker.
