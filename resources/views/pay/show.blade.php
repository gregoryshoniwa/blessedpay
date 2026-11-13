@extends('layouts.site')

@section('title', 'Pay '.$payment->company->name.' · BlessedPay')
@section('noindex', true)
@section('body', $embed ? 'bg-coal text-white' : 'bg-black text-white')

@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    [$whole, $cents] = explode('.', $money($payment->charged()));
    // Two letters for the company's tile: "Bless-Luxe" → BL, "Kopje" → KO.
    $words = preg_split('/[^\pL\pN]+/u', $payment->company->name, -1, PREG_SPLIT_NO_EMPTY);
    $initials = mb_strtoupper(count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? 'B', 0, 2));
@endphp

{{-- The checkout. Full page by default; inside a shop's pop-up when ?embed= names an allowed site. --}}
@section('content')
@if ($embed)
    <main id="checkout"></main>
@else
    <div class="pay-stage relative isolate flex min-h-screen flex-col overflow-hidden">
        {{-- The picture behind everything: the gold horizon from the BlessedPay artwork, a soft glow, a faint grid. --}}
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="gridbg pay-fade absolute inset-0"></div>
            <div class="pay-glow absolute inset-0"></div>
            <img src="/pay/backdrop.jpg" alt="" width="1536" height="300" class="pay-horizon absolute inset-x-0 bottom-0 w-full min-w-[900px] max-w-none">
        </div>

        <header class="border-b border-white/10 bg-black/60 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                @include('partials.brand')
                <p class="flex items-center gap-2 text-sm text-haze">
                    <svg aria-hidden="true" class="size-4 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" /><path d="M8 11V7a4 4 0 018 0v4" /></svg>
                    <span class="hidden sm:inline">Secure checkout ·</span> <span class="font-mono text-gold-bright">{{ request()->getHost() }}</span>
                </p>
            </div>
        </header>

        <main class="mx-auto grid w-full max-w-6xl flex-1 grid-cols-[minmax(0,1fr)] items-start gap-10 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,470px)] lg:gap-16 lg:py-14">
            {{-- Wide screens: who is being paid and how much, beside the card. Phones get the same facts inside the card. --}}
            <section class="hidden lg:block" aria-label="What you are paying for">
                <div class="flex items-center gap-4">
                    <span class="flex size-16 flex-none items-center justify-center bg-gold font-display text-2xl font-bold text-black" aria-hidden="true">{{ $initials }}</span>
                    <div class="min-w-0">
                        <p class="eyebrow text-[#a8a8a8]">YOU ARE PAYING</p>
                        <h1 class="truncate font-display text-3xl font-bold">{{ $payment->company->name }}</h1>
                    </div>
                </div>

                @if ($payment->description)
                    <p class="mt-10 text-lg text-haze">{{ $payment->description }}</p>
                @endif
                <p class="{{ $payment->description ? 'mt-2' : 'mt-10' }} flex items-baseline gap-3 font-display font-bold leading-none tabular-nums">
                    <span class="text-2xl text-haze">{{ $payment->currency }}</span>
                    <span class="text-7xl xl:text-8xl">{{ $whole }}<span class="pay-gold">.{{ $cents }}</span></span>
                </p>
                <p class="mt-4 font-mono text-sm text-[#a8a8a8]">Reference {{ $payment->reference }}</p>

                @if ((float) $payment->fee > 0)
                    <dl class="mt-8 grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-2 border-t border-white/15 pt-5 text-haze">
                        <dt>Order total</dt>
                        <dd class="tabular-nums">{{ $payment->currency }} {{ $money($payment->amount) }}</dd>
                        <dt>Processing fee</dt>
                        <dd class="tabular-nums">{{ $payment->currency }} {{ $money($payment->fee) }}</dd>
                        <dt class="font-semibold text-white">You pay</dt>
                        <dd class="font-semibold tabular-nums text-white">{{ $payment->currency }} {{ $money($payment->charged()) }}</dd>
                    </dl>
                @endif

                <ul class="mt-10 grid max-w-md gap-4 text-[15px] text-haze">
                    <li class="flex gap-3">
                        <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6l8-3z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></svg>
                        <span><strong class="font-semibold text-white">Processed by TN CyberTech Bank.</strong> The money goes straight to {{ $payment->company->name }}'s bank account.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="13" /><path d="M3 10h18M7 15h4" /></svg>
                        <span><strong class="font-semibold text-white">Your card stays with the bank.</strong> Neither {{ $payment->company->name }} nor BlessedPay ever sees your card number or PIN.</span>
                    </li>
                    <li class="flex gap-3">
                        <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                        <span><strong class="font-semibold text-white">Nothing is taken until you approve.</strong> You confirm every payment yourself, on your phone or with your bank.</span>
                    </li>
                </ul>

                <div class="mt-10 flex flex-wrap items-center gap-2" aria-label="Ways to pay">
                    <span class="mr-1 eyebrow text-[#a8a8a8]">ACCEPTED</span>
                    <span class="flex h-9 items-center bg-white px-2.5"><img src="/pay/ecocash.svg" alt="EcoCash" width="62" class="w-[62px]"></span>
                    @if ($payment->currency === 'USD')
                        <span class="flex h-9 items-center bg-white px-2.5"><img src="/pay/visa.svg" alt="Visa" width="42" class="w-[42px]"></span>
                        <span class="flex h-9 items-center bg-white px-2.5"><img src="/pay/mastercard.svg" alt="Mastercard" width="32" class="w-8"></span>
                    @endif
                    <span class="flex h-9 items-center bg-white px-2.5"><img src="/pay/tncb.png" alt="TN CyberTech Bank" width="70" class="w-[70px]"></span>
                </div>
            </section>

            <div id="checkout"></div>
        </main>

        <footer class="relative px-4 pb-6 pt-20 text-center text-sm text-haze sm:px-6 lg:pt-28">
            <p>Powered by <span class="font-semibold text-white">Blessed<span class="text-gold-bright">Pay</span></span> · Payments processed by TN CyberTech Bank</p>
        </footer>
    </div>
@endif

<script type="application/json" id="checkout-data">{!! json_encode(['state' => $state, 'embed' => $embed], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<noscript><p class="p-6 text-center">Paying needs JavaScript switched on in your browser.</p></noscript>
@endsection
