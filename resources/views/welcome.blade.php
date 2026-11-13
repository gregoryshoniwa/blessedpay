@extends('layouts.site')

@section('title', 'BlessedPay · Secure payments for BLESSLUXE')
@section('description', 'BlessedPay is the secure payment service of BLESSLUXE. You pay on the bank\'s own page, so your card details never touch us.')

@section('head')
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:title" content="BlessedPay · Secure payments for BLESSLUXE">
    <meta property="og:description" content="You pay on the bank's own page, so your card details never touch us.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    <script type="application/ld+json">
    {!! json_encode([
        '@'.'context' => 'https://schema.org',
        '@'.'type' => 'FAQPage',
        'mainEntity' => collect([
            ['Is BlessedPay a bank?', 'No. BlessedPay is the payment service of BLESSLUXE. Payments are processed by TN CyberTech Bank through its StewardPay gateway.'],
            ['Does BlessedPay see my card number?', 'No. You enter your payment details on the bank\'s own secure page. BlessedPay only receives the result and the bank\'s reference.'],
            ['How do I know this page is real?', 'The address is always pay.blessluxe.com, and you only arrive here from a BLESSLUXE checkout. We never ask for a password or PIN by phone, email or WhatsApp.'],
            ['What if my payment fails?', 'No money is taken for a failed or cancelled payment. Go back to your order and try again, or contact BLESSLUXE with your order reference.'],
        ])->map(fn ($qa) => ['@'.'type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@'.'type' => 'Answer', 'text' => $qa[1]]])->all(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection

@section('content')
<header class="border-b border-edge bg-black">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-3">
        @include('partials.brand')
        <nav aria-label="Main" class="flex flex-wrap items-center gap-x-6 text-[15px] text-[#e6e6e6]">
            <a href="#how" class="py-3 hover:text-gold-bright">How it works</a>
            <a href="#security" class="py-3 hover:text-gold-bright">Security</a>
            <a href="#developers" class="py-3 hover:text-gold-bright">Developers</a>
            <a href="#questions" class="py-3 hover:text-gold-bright">Questions</a>
        </nav>
        <div class="flex items-center gap-2">
            <a href="/console/login" class="px-3 py-3 text-[15px] font-medium text-white hover:text-gold-bright">Sign in</a>
            <a href="/console/register" class="btn btn-gold">Create account</a>
        </div>
    </div>
</header>

<main>
    {{-- Hero --}}
    <section class="gridbg overflow-hidden bg-black">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-12 px-4 pb-10 pt-16 sm:pt-20">
            <div class="min-w-0 flex-[1_1_480px]">
                <p class="eyebrow flex items-center gap-2.5 text-gold-bright"><span class="inline-block size-2 bg-gold-bright"></span>PAY.BLESSLUXE.COM / A BLESSLUXE COMPANY</p>
                <h1 class="mt-6 font-display text-[clamp(2.25rem,5.4vw,4rem)] font-bold leading-[1.04] tracking-tight">Pay BLESSLUXE with confidence. <span class="text-gold-bright">The bank does the rest.</span></h1>
                <p class="mt-6 max-w-xl text-lg text-haze">BlessedPay is the payment service behind every BLESSLUXE checkout. You confirm the amount here, then pay on the bank's own secure page, so your card details never touch us.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="https://blessluxe.com" class="btn btn-gold px-7 py-4 text-base">Shop at BLESSLUXE</a>
                    <a href="#how" class="btn border-[#6b6b6b] px-7 py-4 font-mono text-[15px] font-medium text-white hover:border-white">&gt; see how it works</a>
                </div>
                <ul class="mt-8 flex flex-wrap gap-x-7 gap-y-2.5 text-sm text-haze">
                    <li class="flex items-center gap-2"><svg aria-hidden="true" class="size-[18px] text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>Processed by TN CyberTech Bank</li>
                    <li class="flex items-center gap-2"><svg aria-hidden="true" class="size-[18px] text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>We never see or store card numbers</li>
                </ul>
            </div>

            {{-- A picture of the pay page, not a working form. --}}
            <div class="flex min-w-0 flex-[1_1_360px] justify-center" aria-hidden="true">
                <div class="grid w-full max-w-md gap-3">
                    <div class="border border-gold bg-coal p-7 shadow-[0_0_60px_rgba(224,176,58,0.22)]">
                        <div class="flex justify-between font-mono text-xs text-[#a8a8a8]"><span>PAYING</span><span>REF BLX-10482</span></div>
                        <div class="mt-4 flex items-center justify-between gap-3">
                            <span class="font-display text-xl font-bold">BLESSLUXE</span>
                            <svg width="44" height="34" viewBox="0 0 44 34" fill="none" stroke="#E9BF4A" stroke-width="1.6"><rect x="1" y="1" width="42" height="32"/><path d="M1 12h13M1 22h13M30 12h13M30 22h13M14 1v32M30 1v32M14 17h16"/></svg>
                        </div>
                        <p class="mt-5 flex items-baseline gap-2"><span class="text-[#a8a8a8]">US$</span><span class="font-display text-5xl font-bold leading-none">48.00</span></p>
                        <div class="mt-5 bg-gold py-3.5 text-center font-semibold text-black">Continue to the bank's secure page</div>
                    </div>
                    <div class="border border-edge bg-coal px-4 py-3.5 font-mono text-[12.5px] leading-7 text-haze">
                        <div><span class="text-gold-bright">POST</span> /api/v1/payments <span class="text-[#8fd3a8]">201</span></div>
                        <div><span class="text-gold-bright">HOOK</span> payment.paid signature ok <span class="text-[#8fd3a8]">200</span></div>
                        <div><span class="text-gold-bright">GET&nbsp;</span> /api/v1/payments/pay_… <span class="text-[#8fd3a8]">paid</span></div>
                    </div>
                </div>
            </div>
        </div>
        {{-- Only the bottom strip of the artwork (the globe) is shown. --}}
        <div class="aspect-[1536/300] w-full">
            <img src="/globe.png" alt="" width="1536" height="1024" class="size-full object-cover object-bottom" fetchpriority="low">
        </div>
    </section>

    {{-- How it works --}}
    <section id="how" class="bg-white text-ink">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:py-24">
            <p class="eyebrow text-gold-ink">01 / HOW IT WORKS</p>
            <h2 class="mt-3 max-w-3xl font-display text-[clamp(1.75rem,3.6vw,2.6rem)] font-bold leading-tight tracking-tight">Three hops. The money part happens entirely at the bank.</h2>
            <ol class="mt-12 grid gap-px border border-line bg-line sm:grid-cols-3">
                @foreach ([
                    ['HOP 1 / THE SHOP', 'You check out', 'BLESSLUXE sends you here with your order reference and the amount to pay.'],
                    ['HOP 2 / THE BANK', 'You pay at the bank', 'We hand you to TN CyberTech Bank\'s secure page. You enter your details there, never with us.'],
                    ['HOP 3 / BACK AGAIN', 'Your order is confirmed', 'The bank tells us the result, we tell the shop with a signed message, and you are returned to your order.'],
                ] as [$tag, $title, $text])
                    <li class="bg-white p-7">
                        <p class="eyebrow text-gold-ink">{{ $tag }}</p>
                        <h3 class="mt-3 text-xl font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-[#454545]">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Security --}}
    <section id="security" class="bg-[#0a0a0a]">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-12 px-4 py-20 sm:py-24">
            <div class="flex min-w-0 flex-[1_1_320px] justify-center">
                <img src="/mark.png" alt="BlessedPay mark: a gold B with a card chip" width="1034" height="630" loading="lazy" class="h-auto w-full max-w-md">
            </div>
            <div class="min-w-0 flex-[1_1_500px]">
                <p class="eyebrow text-gold-bright">02 / SECURITY</p>
                <h2 class="mt-3 font-display text-[clamp(1.75rem,3.6vw,2.6rem)] font-bold leading-tight tracking-tight">The safest card number is one we never receive.</h2>
                <div class="mt-9 grid gap-x-8 gap-y-7 sm:grid-cols-2">
                    @foreach ([
                        ['The bank takes the payment', 'Your details are entered on TN CyberTech Bank\'s page. We keep only the bank\'s reference.'],
                        ['Signed confirmations', 'Every result we pass to the shop carries a signature, so a forged "paid" message is rejected.'],
                        ['Every step is logged', 'Each payment has a full record of what happened and when, kept apart from the shop.'],
                        ['One address', 'This service only ever lives at pay.blessluxe.com. If the address is different, close the page.'],
                    ] as [$title, $text])
                        <div class="border-t-2 border-gold pt-4">
                            <h3 class="text-lg font-semibold">{{ $title }}</h3>
                            <p class="mt-2 text-haze">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Developers --}}
    <section id="developers" class="bg-white text-ink">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-12 px-4 py-20 sm:py-24">
            <div class="min-w-0 flex-[1_1_360px]">
                <p class="eyebrow text-gold-ink">03 / DEVELOPERS</p>
                <h2 class="mt-3 font-display text-[clamp(1.75rem,3.6vw,2.6rem)] font-bold leading-tight tracking-tight">Start, check, hear back.</h2>
                <p class="mt-4 text-lg text-[#454545]">One call starts a payment and returns the page to send the shopper to. A second reads its status. The result also arrives as a signed callback, and a repeated request never creates a second payment.</p>
                <p class="mt-5"><a href="/console/register" class="link">Create an account for test keys</a></p>
            </div>
            <div class="min-w-0 flex-[1_1_440px] border border-edge bg-coal">
                <div class="border-b border-edge px-4 py-3 font-mono text-xs text-gold-bright">start-payment.http</div>
<pre class="overflow-x-auto p-5 font-mono text-[13.5px] leading-relaxed text-[#ededed]">POST /api/v1/payments
Authorization: Bearer sk_test_...
Idempotency-Key: order-10482

{
  "amount": "48.00",
  "currency": "USD",
  "reference": "BLX-10482",
  "return_url": "https://blessluxe.com/checkout/return",
  "callback_url": "https://blessluxe.com/api/pay/callback"
}

201 Created
{ "id": "pay_...", "status": "pending",
  "redirect_url": "{{ url('/p/pay_...') }}" }</pre>
            </div>
        </div>
    </section>

    {{-- Questions --}}
    <section id="questions" class="bg-paper text-ink">
        <div class="mx-auto max-w-3xl px-4 py-20 sm:py-24">
            <p class="eyebrow text-gold-ink">04 / QUESTIONS</p>
            <h2 class="mt-3 font-display text-[clamp(1.75rem,3.6vw,2.6rem)] font-bold leading-tight tracking-tight">What people ask first.</h2>
            <div class="mt-8 grid gap-px border border-line bg-line">
                @foreach ([
                    ['Is BlessedPay a bank?', 'No. BlessedPay is the payment service of BLESSLUXE. Payments are processed by TN CyberTech Bank through its StewardPay gateway.'],
                    ['Does BlessedPay see my card number?', 'No. You enter your payment details on the bank\'s own secure page. BlessedPay only receives the result and the bank\'s reference.'],
                    ['How do I know this page is real?', 'The address is always pay.blessluxe.com, and you only arrive here from a BLESSLUXE checkout. We never ask for a password or PIN by phone, email or WhatsApp.'],
                    ['What if my payment fails?', 'No money is taken for a failed or cancelled payment. Go back to your order and try again, or contact BLESSLUXE with your order reference.'],
                ] as $i => [$q, $a])
                    <details class="bg-white px-6 py-5" @if ($i === 0) open @endif>
                        <summary class="min-h-7 text-[17px] font-semibold">{{ $q }}</summary>
                        <p class="mt-3 text-[#454545]">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
</main>

<footer class="border-t border-edge bg-black text-sm text-haze">
    <div class="mx-auto flex max-w-6xl flex-wrap items-start justify-between gap-8 px-4 py-12">
        <div>
            <p class="font-display text-xl font-bold text-white">Blessed<span class="text-gold-bright">Pay</span></p>
            <p class="mt-2">A subsidiary of BlessLuxe.com</p>
        </div>
        <nav aria-label="Footer" class="flex flex-wrap gap-x-8 gap-y-2">
            <a href="https://blessluxe.com" class="py-2 underline underline-offset-2 hover:text-white">BLESSLUXE shop</a>
            <a href="#security" class="py-2 underline underline-offset-2 hover:text-white">Security</a>
            <a href="#questions" class="py-2 underline underline-offset-2 hover:text-white">Questions</a>
            <a href="/console" class="py-2 underline underline-offset-2 hover:text-white">Staff sign in</a>
        </nav>
    </div>
    <p class="mx-auto max-w-6xl border-t border-edge px-4 py-6 text-[#a8a8a8]">Payments are processed by TN CyberTech Bank. &copy; {{ date('Y') }} BLESSLUXE.</p>
</footer>
@endsection
