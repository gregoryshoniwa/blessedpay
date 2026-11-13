@extends('layouts.site')

@section('title', 'Pay '.$payment->company->name.' · BlessedPay')
@section('noindex', true)
@section('body', $embed ? 'bg-coal text-white' : 'bg-black text-white')

{{-- The checkout. Full page by default; inside a shop's pop-up when ?embed= names an allowed site. --}}
@section('content')
@if ($embed)
    <main id="checkout"></main>
@else
    <div class="gridbg flex min-h-screen flex-col">
        <header class="border-b border-edge bg-black">
            <div class="mx-auto flex max-w-md items-center px-4 py-3">@include('partials.brand')</div>
        </header>
        <main class="flex flex-1 flex-col justify-center px-4 py-8">
            <div id="checkout"></div>
            <p class="mt-6 text-center text-sm text-[#a8a8a8]">Check the address bar says <span class="font-mono text-gold-bright">{{ request()->getHost() }}</span>.</p>
        </main>
    </div>
@endif

<script type="application/json" id="checkout-data">{!! json_encode(['state' => $state, 'embed' => $embed], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<noscript><p class="p-6 text-center">Paying needs JavaScript switched on in your browser.</p></noscript>
@endsection
