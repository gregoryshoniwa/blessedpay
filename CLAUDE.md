# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**BlessedPay** is the payments app for BLESSLUXE, meant to answer on **`pay.blessluxe.com`**. It is a separate Laravel application from the shop on purpose: its own code, database, secrets and deploys.

The shop lives next door at `../blessluxe` (Laravel 13 + Vue 3 + MySQL). Its `CLAUDE.md` describes how payments are wired there — read the "Payments — any gateway" section before designing anything here.

## Where things stand (created 13 Nov 2026)

- The gateway console, the company-facing API and a sandbox bank are built (see "What is built"). The real bank integration is NOT: `StewardPayGateway` is a stub and live keys are refused with a 503.
- Local database: MySQL `blessedpay` on `127.0.0.1:3306`, user `root` (password in `.env`, same server as the shop).
- Git is on `master`, pushed to `git@github.com:gregoryshoniwa/blessedpay.git` (use SSH — the HTTPS login saved on this Mac is a different GitHub account and gets a 403).
- Deployed on Laravel Cloud: `pay.blessluxe.com` serves this app (the Laravel starter page) as of 13 Nov 2026.

## What is built (13 Nov 2026, branch `feature/gateway-console`)

**Scope decided by the owner: "option A".** BlessedPay exists to keep the bank integration and the payment logs out of the shop, and it is shaped like a gateway (companies, keys, logs). BLESSLUXE is the merchant; this app must not settle money for businesses the owner does not own — that needs RBZ authorisation through a sponsoring bank.

**Self sign-up is allowed (owner's request, 13 Nov 2026), with a gate.** Anyone can create an account and their own company at `/console/register`; they become its `owner`. That company starts **`pending`**: its people can sign in and use TEST keys (sandbox, no money), but live keys can't be created or used until staff approve it (`companies.manage` sets status `active`; an owner can never approve their own). Approving a company for live payments is the regulated step — don't weaken that gate. There is no email verification yet (mail isn't configured); sign-up is throttled to 6 an hour per address.

**The first admin** is the owner in `config/blessedpay.php` (email + name; overridable by env). `php artisan blessedpay:admin --ensure` creates that account only if it is missing, from `BLESSEDPAY_ADMIN_PASSWORD`, and never touches an existing one — put it in the deploy commands, set the variable once, sign in, remove the variable.

- **Three surfaces.** `/` landing page and `/p/{id}` pay page are server-rendered Blade (fast, readable by search engines, no JS needed). `/console/*` is a Vue 3 + vue-router app (`resources/js/console/`) talking to `/console-api/*` with the session cookie + CSRF. `/api/v1/*` is the stateless API a company's system calls with `Authorization: Bearer sk_test_…|sk_live_…`.
- **Company API**: `POST /api/v1/payments` (amount, currency USD|ZWG, reference, return_url, optional callback_url/description/metadata; optional `Idempotency-Key` — same key returns the same payment, same key with different money is a 409) → `id` + `redirect_url`. `GET /api/v1/payments/{id}` → status. The key decides company AND mode.
- **Gateways** ([app/Services/Gateways](app/Services/Gateways/)): `BankGateway` contract, wired in `Gateways::all()`. Test keys always use `SandboxGateway` (our own `/sandbox/{id}` page with Approve/Decline/Cancel); live keys use `StewardPayGateway`. Adding the bank = fill in that one class.
- **Outcomes** go through `PaymentService::settle()` only: idempotent, once paid stays paid, writes the payment log (`payment_events`), then `CallbackSender` POSTs the company a JSON body signed `X-BlessedPay-Signature: t=<unix>,v1=<hmac-sha256 of "t.body">` with the company's callback secret. Sent inside the request (no queue); `php artisan payments:callbacks` retries failures and re-checks pending payments. Callback addresses that resolve to private/local IPs are refused outside local/testing.
- **People**: `users.company_id` null = BlessedPay staff (reach every company), set = a member of that company (reach only it; another company's rows are a 404). Roles are fixed in [Roles](app/Support/Roles.php) — staff: super_admin, operations, support, auditor; company: owner, admin, finance, developer, viewer. Check with `$this->need($request, 'ability')` and `$this->company($request, $id)` in controllers. People join an existing company (or staff) by invitation only (single-use link, 7 days, also returned to the inviter to pass on because mail may not be configured). Lost super-admin password: `php artisan blessedpay:admin` (asks for a new one).
- **Secrets**: API keys are stored only as SHA-256 hashes and shown once. The callback secret is encrypted at rest; viewing it is written to the activity log.
- **Not built yet**: two-step sign-in (the design shows it), password reset by email, ownership transfer, refunds, and the StewardPay integration itself. The shop's driver for this API (in `../blessluxe`) is also not written.
- **Look**: black + gold from the logo, square corners, Sora headings / Inter body / JetBrains Mono labels (tokens in `resources/css/app.css`). Gold is a fill or sits on black — never gold text on white (use `gold-ink`). `public/mark.png` has a black background: use it on black only. Hidden labels (`sr-only`) inside a scrolling table need the scroll box to be `relative`, or they widen the page on phones.

## Commands

```bash
composer install && npm install
php artisan serve --port=8001     # the shop uses 8000
npm run dev                       # Vite
php artisan migrate
php artisan test
php artisan blessedpay:admin                   # the owner admin: create, or reset the password (asks for it)
php artisan blessedpay:admin --ensure          # deploy step: create the owner admin once from BLESSEDPAY_ADMIN_PASSWORD
php artisan payments:callbacks                # retry failed callbacks, re-check pending
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

- **Laravel Cloud**, organisation `gregory-shoniwa`. The shop is the `blessluxe` app, `production` environment. This app is the `blessedpay` app, `production` environment, with its **own** MySQL 8.4 database (cluster `blessedpay`, US East), billed separately. Starter migrations are applied; run others from the Commands tab console with `php artisan migrate --force`.
- **DNS is at Squarespace Domains** (blessluxe.com → DNS → DNS Settings → Custom records). An `A` record `pay → 103.133.1.1` already exists.
- Go-live is done: the GitHub repo exists, the Laravel Cloud app is created from it, and `pay.blessluxe.com` is attached to this app instead of the shop.
- No queue worker or scheduler runs in the shop's production; assume the same here unless one is set up — do work inside the request or via a command someone runs.

## House rules carried over from the shop

- Storefront-facing pages follow the BLESSLUXE look: square corners, gold accent, works from 320px wide.
- Loyalty points are called **Bees** in anything a person can read.
- Read credentials with `config()`, not `env()` (empty under `config:cache`).
