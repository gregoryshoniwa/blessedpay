@extends('layouts.site')

@section('title', 'Sandbox bank · BlessedPay')
@section('noindex', true)
@section('body', 'bg-paper text-ink')

{{-- Deliberately plain and unlike the real pages: nobody should mistake this for a bank. --}}
@section('content')
<main class="mx-auto max-w-lg px-4 py-10">
    <p class="inline-block bg-ink px-2 py-1 font-mono text-xs text-white">SANDBOX BANK · NO REAL MONEY</p>
    <h1 class="mt-4 font-display text-2xl font-bold">Pretend bank page</h1>
    <p class="mt-2 text-mute">This stands in for the bank while testing. Choose what the bank should say happened.</p>

    <dl class="mt-6 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 border border-line bg-white p-5">
        <dt class="text-mute">Paying</dt><dd class="font-medium">{{ $payment->company->name }}</dd>
        <dt class="text-mute">Amount</dt><dd class="font-medium">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</dd>
        <dt class="text-mute">Reference</dt><dd class="font-mono text-sm">{{ $payment->reference }}</dd>
        <dt class="text-mute">Bank ref</dt><dd class="font-mono text-sm">{{ $payment->gateway_reference ?? '—' }}</dd>
    </dl>

    <form method="POST" action="{{ route('sandbox.decide', $payment->publicId()) }}" class="mt-6 grid gap-3">
        @csrf
        <button type="submit" name="outcome" value="paid" class="btn btn-dark">Approve: payment succeeds</button>
        <button type="submit" name="outcome" value="failed" class="btn btn-line">Decline: payment fails</button>
        <button type="submit" name="outcome" value="cancelled" class="btn btn-line">Cancel: shopper gives up</button>
    </form>
</main>
@endsection
