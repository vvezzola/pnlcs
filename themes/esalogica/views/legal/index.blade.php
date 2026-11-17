{{-- Esalogica's legal hub: its own documents, in Italian. --}}
@extends('legal.layout')

@php
    $esaLegal = require base_path('themes/esalogica/legal.php');
@endphp

@section('legal-title', 'Documenti legali')
@section('legal-description', 'Condizioni generali di servizio, informativa privacy, cookie policy e accordo sul trattamento dei dati di Esalogica.')

@section('legal-content')
    <div class="legal-head">
        <h1>Documenti legali</h1>
        <p>I contratti e le informative che si applicano quando usi i nostri servizi. Ogni documento riporta la data da cui è in vigore: a un ordine si applica il testo in vigore il giorno in cui è stato fatto.</p>
    </div>

    <div class="legal-cards">
        @foreach($esaLegal['documents'] as $slug => [$title, $summary, $icon])
            <a href="{{ route('legal.show', $slug) }}" class="legal-card">
                <i class="{{ $icon }}"></i>
                <b>{{ $title }}</b>
                <span>{{ $summary }}</span>
            </a>
        @endforeach
    </div>

    <div class="legal-note" style="margin-top:32px;">
        <p>
            <strong>Domande</strong> — per qualsiasi domanda su questi documenti scrivi a
            <a href="mailto:{{ $esaLegal['company']['email'] }}">{{ $esaLegal['company']['email'] }}</a>
            o alla PEC <a href="mailto:{{ $esaLegal['company']['pec'] }}">{{ $esaLegal['company']['pec'] }}</a>.
        </p>
    </div>
@endsection
