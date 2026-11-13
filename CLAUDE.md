# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**BlessedPay** is the payments app for BLESSLUXE, meant to answer on **`pay.blessluxe.com`**. It is a separate Laravel application from the shop on purpose: its own code, database, secrets and deploys.

The shop lives next door at `../blessluxe` (Laravel 13 + Vue 3 + MySQL). Its `CLAUDE.md` describes how payments are wired there — read the "Payments — any gateway" section before designing anything here.

## Where things stand (created 13 Nov 2026)

- This is still the **blank Laravel 13 starter**. No payment code exists yet.
- Local database: MySQL `blessedpay` on `127.0.0.1:3306`, user `root` (password in `.env`, same server as the shop).
- Git is initialised on `master`; nothing committed, no remote yet.
- Not deployed. `pay.blessluxe.com` currently points at the SHOP's Laravel Cloud environment and shows the shop.

## Commands

```bash
composer install && npm install
php artisan serve --port=8001     # the shop uses 8000
npm run dev                       # Vite
php artisan migrate
php artisan test
```

## Decisions already made

- **The payment processor is StewardPay**, the gateway of TN CyberTech Bank (formerly Steward Bank). BLESSLUXE is an ordinary merchant under it; the bank holds the licence. This app must not hold or settle money for third parties, and must never see card numbers — redirect to the bank's page and store only their reference.
- **StewardPay's API is not known yet.** Before writing the integration, get from the bank: the merchant API docs (hosted page or phone prompt?), sandbox credentials, how outcomes are reported (callback and how it is signed, or polling), which methods they take, and who pays the fee (shopper or settlement).
- **Subdomain, not a path**: `pay.blessluxe.com`, so cookies and deploys stay apart from the shop. Keep `SESSION_DOMAIN=null` — never `.blessluxe.com`.

## How the shop will talk to this app

The shop already has a gateway contract (`App\Services\Payments\Contracts\Gateway` in `../blessluxe`). Adding a provider there is one driver class + one line in `Payments::gateways()` + credentials in `config/services.php`. So this app should expose a small API the shop's driver can call:

1. **Start a payment** — amount, reference, return URL, callback URL → payment id + a redirect URL (or a phone-prompt instruction).
2. **Status** — the shop polls it, as it does for VelocityAfrica.
3. **Callback** — this app posts the outcome to the shop, signed with a shared secret (HMAC), with idempotency keys so a retry never pays twice.

Everything that happens after "paid" (order, stock, Bees, receipts) stays in the shop's `PaymentOutcomes`. Never put order logic here.

## Hosting and domain

- **Laravel Cloud**, organisation `gregory-shoniwa`. The shop is the `blessluxe` app, `production` environment. This app needs its **own** application + database there (billed separately).
- **DNS is at Squarespace Domains** (blessluxe.com → DNS → DNS Settings → Custom records). An `A` record `pay → 103.133.1.1` already exists.
- To go live: create a GitHub repo and push; create the Laravel Cloud app from it; remove `pay.blessluxe.com` from the shop's environment (Settings → Network → Custom domains) and add it to this app; follow whatever DNS record the new app shows.
- No queue worker or scheduler runs in the shop's production; assume the same here unless one is set up — do work inside the request or via a command someone runs.

## House rules carried over from the shop

- Storefront-facing pages follow the BLESSLUXE look: square corners, gold accent, works from 320px wide.
- Loyalty points are called **Bees** in anything a person can read.
- Read credentials with `config()`, not `env()` (empty under `config:cache`).
