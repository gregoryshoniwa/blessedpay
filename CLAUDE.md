# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**BlessedPay** is the payments app for BLESSLUXE, meant to answer on **`pay.blessluxe.com`**. It is a separate Laravel application from the shop on purpose: its own code, database, secrets and deploys.

The shop lives next door at `../blessluxe` (Laravel 13 + Vue 3 + MySQL). Its `CLAUDE.md` describes how payments are wired there — read the "Payments — any gateway" section before designing anything here.

## Where things stand (created 13 Nov 2026)

- The gateway console, the company-facing API, the checkout (full page and pop-up), a sandbox bank and the real bank integration (StewardPay / CyberPay) are built — see "What is built" and "The bank and the checkout". The shop's driver for this API (in `../blessluxe`) is not written yet.
- Local database: MySQL `blessedpay` on `127.0.0.1:3306`, user `root` (password in `.env`, same server as the shop).
- Git is on `master`, pushed to `git@github.com:gregoryshoniwa/blessedpay.git` (use SSH — the HTTPS login saved on this Mac is a different GitHub account and gets a 403).
- Deployed on Laravel Cloud: `pay.blessluxe.com` serves this app (the Laravel starter page) as of 13 Nov 2026.

## What is built (13 Nov 2026, branch `feature/gateway-console`)

**Scope decided by the owner: "option A".** BlessedPay exists to keep the bank integration and the payment logs out of the shop, and it is shaped like a gateway (companies, keys, logs). BLESSLUXE is the merchant; this app must not settle money for businesses the owner does not own — that needs RBZ authorisation through a sponsoring bank.

**Self sign-up is allowed (owner's request, 13 Nov 2026), with a gate.** Anyone can create an account and their own company at `/console/register`; they become its `owner`. That company starts **`pending`**: its people can sign in and use TEST keys (sandbox, no money), but live keys can't be created or used until staff approve it (`companies.manage` sets status `active`; an owner can never approve their own). Approving a company for live payments is the regulated step — don't weaken that gate. There is no email verification yet (mail isn't configured); sign-up is throttled to 6 an hour per address.

**The first admin** is the owner in `config/blessedpay.php` (email + name; overridable by env). `php artisan blessedpay:admin --ensure` creates that account only if it is missing, from `BLESSEDPAY_ADMIN_PASSWORD`, and never touches an existing one — put it in the deploy commands, set the variable once, sign in, remove the variable.

- **Three surfaces.** `/` landing page and `/p/{id}` pay page are server-rendered Blade (fast, readable by search engines, no JS needed). `/console/*` is a Vue 3 + vue-router app (`resources/js/console/`) talking to `/console-api/*` with the session cookie + CSRF. `/api/v1/*` is the stateless API a company's system calls with `Authorization: Bearer sk_test_…|sk_live_…`.
- **Company API**: `POST /api/v1/payments` (amount, currency USD|ZWG, reference, return_url, optional callback_url/description/metadata; optional `Idempotency-Key` — same key returns the same payment, same key with different money is a 409) → `id` + `redirect_url`. `GET /api/v1/payments/{id}` → status. The key decides company AND mode.
- **Gateways** ([app/Services/Gateways](app/Services/Gateways/)): `BankGateway` contract (`methods` / `begin` / `check`), wired in `Gateways::all()`. Test keys always use `SandboxGateway` (the checkout shows Approve / Decline buttons); live keys use `StewardPayGateway`.
- **Outcomes** go through `PaymentService::settle()` only: idempotent, once paid stays paid, writes the payment log (`payment_events`), then `CallbackSender` POSTs the company a JSON body signed `X-BlessedPay-Signature: t=<unix>,v1=<hmac-sha256 of "t.body">` with the company's callback secret. Sent inside the request (no queue); `php artisan payments:callbacks` retries failures and re-checks pending payments. Callback addresses that resolve to private/local IPs are refused outside local/testing.
- **People**: `users.company_id` null = BlessedPay staff (reach every company), set = a member of that company (reach only it; another company's rows are a 404). Roles are fixed in [Roles](app/Support/Roles.php) — staff: super_admin, operations, support, auditor; company: owner, admin, finance, developer, viewer. Check with `$this->need($request, 'ability')` and `$this->company($request, $id)` in controllers. People join an existing company (or staff) by invitation only (single-use link, 7 days, also returned to the inviter to pass on because mail may not be configured). Lost super-admin password: `php artisan blessedpay:admin` (asks for a new one).
- **Secrets**: API keys are stored only as SHA-256 hashes and shown once. The callback secret is encrypted at rest; viewing it is written to the activity log.
- **Not built yet**: two-step sign-in (the design shows it), password reset by email, ownership transfer, refunds. The shop's driver for this API (in `../blessluxe`) is also not written.
- **Look**: black + gold from the logo, square corners, Sora headings / Inter body / JetBrains Mono labels (tokens in `resources/css/app.css`). Gold is a fill or sits on black — never gold text on white (use `gold-ink`). `public/mark.png` has a black background: use it on black only. Hidden labels (`sr-only`) inside a scrolling table need the scroll box to be `relative`, or they widen the page on phones.

## The bank and the checkout (built 13 Nov 2026, branch `feature/stewardpay-checkout`)

**The bank's API** — [StewardPay\Client](app/Services/Gateways/StewardPay/Client.php). The bank calls the product CyberPay; the host is still stewardpay.co.zw. The PDF guide is thin and partly wrong; the contract was read from the bank's own gateway code (`~/Documents/TNC Bank Dev/Java/biller-and-merchant-gateway`, branch release/0.1.28) and confirmed with real no-charge calls:
- **Live base is `https://api.stewardpay.co.zw/v1`** (`/merchant/client/token`, `/merchant/sdk/action`, …). `/lab/v2` is the bank's test environment and rejects live credentials. Credentials are `STEWARDPAY_CLIENT_ID / _CLIENT_SECRET / _ENCRYPTION_KEY` in the environment — never in the repo. Tokens last 300 s (cached a little less).
- `hash = sha256(clientId + encryptionKey + phone + amount)` with phone and amount exactly as sent; send `amount` as a string ("48.00"). Never send `encryptionKey`. `phone` is required for every mode. `sandbox` in the body does nothing. `responseUrl` must always be sent. The bank echoes `clientSecret` back and its `targetUrl` can carry our bearer token: `Client::scrub()` everything stored, and never log `targetUrl`.
- The bank answers HTTP 200 with `status: FAILED` when it could not start. Trust `status`, not `responseCode` (which is "00" even for `INVALID_REQUEST`). `SAF` = shopper debited, merchant credit still in flight: treated as paid.
- **card** = `WEB_EMBEDDED` + `MPGS`, USD only. Returns a Mastercard `sessionId`; the checkout loads Mastercard's `checkout.min.js` from the same host as `targetUrl` and calls `Checkout.showEmbeddedPage`. We never see the card. **Cards never trigger the bank's callback: `GET /merchant/sdk/mpgs/poll/{uid}` is what completes (and settles) a card payment**, so the checkout polls it every 4 s.
- **ecocash** = `REMOTE` + `ECOCASH`. The call blocks until the shopper answers the prompt on their phone (up to ~2 min), so `PaymentService::startAttempt` answers the browser first and makes the call in `app()->terminating()`. If that process dies, `check()` finds the try by our reference (`/merchant/sdk/reference/{billerReference}`).
- **bank** = `WEB` + `TNCB`. Returns the bank's own sign-in page; the shopper's phone must match the one sent. The checkout opens it in a new tab and polls.
- **The bank's callback** (`POST /hooks/stewardpay`) is signed `hash = sha256(encryptionKey + amount + trace + reference)`, sent once, only after a debit, never for cards. It is a nudge: we ask the bank, and only fall back to the notice itself when the bank is unreachable AND the hash is valid.

**Attempts.** A payment is tried through `payment_attempts` (one per method choice, `reference` = our `billerReference`, unique per try). A failed try leaves the payment pending so the shopper can pick another way; a payment is paid at most once; an abandoned try is still watched for 2 h, and if it ALSO turns out paid the log gets `attempt.paid_twice` (refund by hand). A paid result is refused if the bank's amount differs from the one asked. Payments expire after `BLESSEDPAY_PAYMENT_MINUTES` (30).

**The checkout** — `/p/{id}` (Blade shell + [Checkout.vue](resources/js/checkout/Checkout.vue)), modelled on the owner's CyberPay v2 checkout (`~/Documents/TNC Bank Dev/raw folders/omni-open-bank-laravel`: `public/js/cyberpay.js`, `Pages/Gateway/Checkout.vue`).
- **No cookies, no CSRF on `/p/*`**: inside a pop-up on another site the browser withholds them. The long random payment id in the address is the credential (`Referrer-Policy: no-referrer` keeps it from leaking).
- **Pop-up**: [public/js/blessedpay.js](public/js/blessedpay.js) — `BlessedPay.open({url: redirect_url, onSuccess, onCancel, onClose, onError, redirect, history})`. It is an overlay + iframe (never `window.open`, so pop-up blockers don't apply), talking by `postMessage` (`blessedpay-host` ⇄ `blessedpay-checkout`: ready, resize, success, cancel, failed, redirect, close; both sides check origin). `onSuccess` is a hint — the shop must confirm server-side.
- **Who may frame it**: [EmbedOrigin](app/Support/EmbedOrigin.php) — the payment's return site, the company's website, plus `companies.embed_origins`. Sent as `Content-Security-Policy: frame-ancestors`; never add `X-Frame-Options` beside it. `/p/{id}/card-return` deliberately has NO frame restriction: Mastercard loads it inside its own frame inside ours, and all it does is tell the checkout to look again.
- **Try it** (`/console/try`, [TryIt.vue](resources/js/console/pages/TryIt.vue)): a demo shop in the console that calls the real `/api/v1` from the browser with a TEST key the user pastes (kept in that tab only; live keys are refused), opens the pop-up or full page, and logs every request, response, pop-up callback, the signed callback and the payment's own log. It also gives copy-ready cURL (Postman: Import ▸ paste) and a downloadable Postman collection. Its `callback_url` is `/try/hook/{token}` ([TryHook](app/Services/TryHook.php)), a stand-in for a company's server that keeps what it is sent for 2 h; `CallbackSender` hands callbacks for that address over in-process rather than calling ourselves over HTTP.
- Tests fake the bank (`StewardPayTest`); `Tests\TestCase` calls `Http::preventStrayRequests()` and `phpunit.xml` blanks the bank credentials so a test can never reach the real bank.

## Transactions and the audit trail (built 13 Nov 2026, branch `feature/audit-trail-checkout`)

- **Every step of a payment is a line in `payment_events`**, written with `Payment::log(type, summary, data, actor:, level:, attempt:, ms:)`. `actor` is whose step it was (`merchant` = the company's system, `shopper`, `bank`, `system` = BlessedPay, `staff`); `level` is `info | ok | warn | error`. **An `error` line's actor is where the payment was stopped** — set it honestly when adding a step. [Diagnosis](app/Support/Diagnosis.php) reads the trail and gives the one-line finding shown at the top of a transaction ("Stopped at the bank", "Stopped inside BlessedPay", …).
- **Every exchange with the bank is recorded** (what was sent, HTTP status, what came back, how long): `StewardPay\Client::observe()` reports each call and `StewardPayGateway::record()` writes it as `bank.token` / `bank.request` / `bank.check`. Anything stored goes through `Client::forLog()` — it removes the secret, key and hash and blanks the access token, card session and bank page address. The checkout asks the bank every few seconds, so an identical answer is counted on the line it repeats (`data.repeats`), not written again. Our own exceptions become `system.error` (and the shopper sees "Something went wrong on our side"), so a bug here is never blamed on the bank.
- Callbacks record what was sent and the first 1000 characters of what the company's server answered.
- **Console** — the menu says "Transactions" (route still `/payments`). The list has status tabs, search (reference, payment id, bank reference, a try's reference, the bank's id, shopper phone), company (staff), dates, way of paying (matches payments where it was *tried*, not only paid), currency, amount range, callback state, order, 10/25/50/100 per page, numbered pages, and totals for the whole filtered set. The detail page shows the finding, the tries, and every step with "Problems only" / "Bank only" views and "Copy all steps" (plain text for an email to the bank).
- **Checkout look (owner's direction, 13 Nov 2026)**: the form is WHITE so the payment marks sit on it as their owners draw them (no chips, never recoloured; files in `public/pay/`), and there is NO grid background. Full page ([pay/show.blade.php](resources/views/pay/show.blade.php)) is split on wide screens: black picture side (company, amount, three reassurances, gold horizon `public/pay/backdrop.jpg`) and white form side; on phones and in the pop-up the form carries a short black header with the same facts. **It must fit without scrolling** at 1280×720 (full page and pop-up, with the phone field open) and 390×844: keep the choose view short when adding to it.
- **`$payment->attempts()` is sorted oldest-first, so `->latest('id')` on it is silently ignored — use `->reorder()->latest('id')`.** This once made the checkout read the first try for ever, so after one failed try every later try bounced back with the old error (live, 13 Nov 2026).

## API log, per-company figures, Try it in live (built 13 Nov 2026, branch `feature/api-log-per-company`)

- **API log** (`api_requests`, [LogApiRequest](app/Http/Middleware/LogApiRequest.php), console page `/console/api-log`): every call to `/api/v1` is kept with the request (headers + body) and our answer in full — including the ones that never became a payment (401, 422), which is where integrations usually go wrong. Only the first 12 characters of the key are kept. A company sees its own calls; staff see every company's and the calls made with an unrecognised key. When a call is about a payment it is also a step in that payment's trail (`api.request` / `api.status`); repeated identical status reads are counted (`repeats`), not stored again. Nothing prunes this table yet.
- **Staff overview is per company**: a company picker narrows every figure (`?company_id=`), and "By company" lists each company's paid today / this month, pending, failed and failed callbacks. The Transactions and API log pages have the same company filter.
- **Try it follows the Test / Live switch** (owner's request). With "Live keys" it takes a live key and makes REAL payments through the bank: it shows a warning, needs an "I understand" tick, defaults to 1.00, and never stores the live key (test keys stay in sessionStorage). Don't remove the tick or start remembering live keys.
- Callbacks record the headers sent and up to 20,000 characters of the company's answer.

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
- **The bank's fee** (told to BLESSLUXE by the bank, Nov 2026): cards 1% with a US$0.20 minimum, EcoCash and bank account 1%; money is credited straight to BLESSLUXE's corporate account and the bank keeps its fee from that. Each company chooses to absorb it or pass it to the shopper (`companies.fee_bearer`, [Fees](app/Services/Fees.php)).
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
