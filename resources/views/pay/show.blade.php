@extends('layouts.site')

@section('title', 'Pay '.$payment->company->name.' · BlessedPay')
@section('noindex', true)
@section('body', 'bg-white text-ink')

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
    {{-- Wide screens: the picture and the amount on the left, the form on white on the right. Sized to fit a laptop screen without scrolling. --}}
    <div class="lg:grid lg:min-h-screen lg:grid-cols-[minmax(0,11fr)_minmax(0,10fr)]">
        <section class="pay-stage relative isolate hidden overflow-hidden text-white lg:flex lg:flex-col" aria-label="What you are paying for">
            <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                <div class="pay-glow absolute inset-0"></div>
                <img src="/pay/backdrop.jpg" alt="" width="1536" height="300" class="pay-horizon absolute bottom-0 w-[140%] max-w-none">
            </div>

            <div class="mx-auto flex w-full max-w-xl flex-1 flex-col px-10 py-7 xl:px-12">
                <div>@include('partials.brand')</div>

                <div class="flex flex-1 flex-col justify-center pb-24 pt-6">
                    <div class="flex items-center gap-4">
                        <span class="flex size-14 flex-none items-center justify-center bg-gold font-display text-xl font-bold text-black" aria-hidden="true">{{ $initials }}</span>
                        <div class="min-w-0">
                            <p class="eyebrow text-[#a8a8a8]">YOU ARE PAYING</p>
                            <h1 class="truncate font-display text-2xl font-bold">{{ $payment->company->name }}</h1>
                        </div>
                    </div>

                    @if ($payment->description)
                        <p class="mt-8 text-lg text-haze">{{ $payment->description }}</p>
                    @endif
                    <p class="{{ $payment->description ? 'mt-1' : 'mt-8' }} flex items-baseline gap-3 font-display font-bold leading-none tabular-nums">
                        <span class="text-2xl text-haze">{{ $payment->currency }}</span>
                        <span class="text-7xl">{{ $whole }}<span class="pay-gold">.{{ $cents }}</span></span>
                    </p>
                    <p class="mt-3 font-mono text-sm text-[#a8a8a8]">Reference {{ $payment->reference }}</p>

                    @if ((float) $payment->fee > 0)
                        <dl class="mt-6 grid max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 border-t border-white/15 pt-4 text-[15px] text-haze">
                            <dt>Order total</dt>
                            <dd class="tabular-nums">{{ $payment->currency }} {{ $money($payment->amount) }}</dd>
                            <dt>Processing fee</dt>
                            <dd class="tabular-nums">{{ $payment->currency }} {{ $money($payment->fee) }}</dd>
                        </dl>
                    @endif

                    <ul class="mt-7 grid max-w-md gap-2.5 text-[15px] text-haze">
                        <li class="flex gap-3">
                            <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6l8-3z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></svg>
                            <span>Processed by <strong class="font-semibold text-white">TN CyberTech Bank</strong>, straight to {{ $payment->company->name }}'s account.</span>
                        </li>
                        <li class="flex gap-3">
                            <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="6" width="18" height="13" /><path d="M3 10h18M7 15h4" /></svg>
                            <span>Your card number and PIN <strong class="font-semibold text-white">stay with the bank</strong>.</span>
                        </li>
                        <li class="flex gap-3">
                            <svg aria-hidden="true" class="mt-0.5 size-5 flex-none text-gold-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                            <span>Nothing is taken <strong class="font-semibold text-white">until you approve</strong> it yourself.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <div class="flex min-h-screen flex-col bg-paper lg:bg-white">
            <header class="bg-black px-4 py-2 lg:hidden">@include('partials.brand')</header>

            <main class="flex flex-1 flex-col justify-center px-3 py-3 sm:px-6 sm:py-5 lg:px-12 xl:px-16">
                <div id="checkout" class="mx-auto w-full max-w-[460px]"></div>
            </main>

            <footer class="px-4 pb-4 text-center text-[13px] text-mute sm:px-6">
                <p>Check the address bar says <span class="font-mono font-medium text-ink">{{ request()->getHost() }}</span> · Powered by <span class="font-semibold text-ink">BlessedPay</span></p>
            </footer>
        </div>
    </div>
@endif

<script type="application/json" id="checkout-data">{!! json_encode(['state' => $state, 'embed' => $embed], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<noscript><p class="p-6 text-center">Paying needs JavaScript switched on in your browser.</p></noscript>
@endsection
