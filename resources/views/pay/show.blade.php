@extends('layouts.site')

@section('title', 'Pay '.$payment->company->name.' · BlessedPay')
@section('noindex', true)

@php
    $states = [
        'paid' => ['Payment received', 'Thank you. '.$payment->company->name.' has been told.'],
        'failed' => ['Payment did not go through', 'No money was taken. You can go back and try again.'],
        'cancelled' => ['Payment cancelled', 'No money was taken.'],
    ];
@endphp

@section('content')
<div class="gridbg flex min-h-screen flex-col">
    <header class="border-b border-edge bg-black">
        <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-3">
            @include('partials.brand')
            @if ($payment->mode === 'test')
                <span class="bg-gold px-2 py-1 font-mono text-xs font-medium text-black">TEST MODE</span>
            @endif
        </div>
    </header>

    <main class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-4 py-10">
        <div class="border border-gold bg-coal p-6 shadow-[0_0_60px_rgba(224,176,58,0.18)] sm:p-8">
            <div class="flex items-center justify-between font-mono text-xs text-[#a8a8a8]">
                <span>{{ $payment->isFinal() ? strtoupper($payment->status) : 'PAYING' }}</span>
                <span>REF {{ $payment->reference }}</span>
            </div>

            <h1 class="mt-4 font-display text-2xl font-bold">{{ $payment->company->name }}</h1>
            @if ($payment->description)
                <p class="mt-1 text-haze">{{ $payment->description }}</p>
            @endif

            <p class="mt-6 flex items-baseline gap-2">
                <span class="text-[#a8a8a8]">{{ $payment->currency }}</span>
                <span class="font-display text-5xl font-bold leading-none">{{ number_format((float) $payment->amount, 2) }}</span>
            </p>

            @if (session('problem'))
                <p role="alert" class="mt-6 border border-gold px-4 py-3 text-sm">{{ session('problem') }}</p>
            @endif

            @if ($payment->isFinal())
                <div class="mt-8 border-t border-edge pt-6">
                    <h2 class="text-lg font-semibold">{{ $states[$payment->status][0] }}</h2>
                    <p class="mt-1 text-haze">{{ $states[$payment->status][1] }}</p>
                    <a href="{{ $returnUrl }}" class="btn btn-gold mt-6 w-full">Return to {{ $payment->company->name }}</a>
                </div>
            @else
                <form method="POST" action="{{ route('pay.continue', $payment->publicId()) }}" class="mt-8">
                    @csrf
                    <button type="submit" class="btn btn-gold w-full py-4 text-base">Continue to the bank's secure page</button>
                </form>
                <p class="mt-4 flex gap-2 text-sm text-[#a8a8a8]">
                    <svg aria-hidden="true" class="mt-0.5 size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                    You will enter your payment details on the bank's page, never on this one.
                </p>
                <p class="mt-6 text-sm"><a href="{{ $returnUrl }}" class="text-haze underline underline-offset-2">Cancel and return to {{ $payment->company->name }}</a></p>
            @endif
        </div>

        <p class="mt-6 text-center text-sm text-[#a8a8a8]">Check the address bar says <span class="font-mono text-gold-bright">{{ request()->getHost() }}</span>.</p>
    </main>
</div>
@endsection
