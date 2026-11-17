{{-- Esalogica's legal hub: its own documents, in Italian or in the English courtesy translation. --}}
@extends('legal.layout')

@php
    $esaLegal = require base_path('themes/esalogica/legal.php');
    $lang = app()->getLocale() === 'it' ? 'it' : 'en';
    $ui = $esaLegal['ui'][$lang];
    $mailto = fn (string $address) => '<a href="mailto:'.e($address).'">'.e($address).'</a>';
@endphp

@section('legal-title', $ui['hub'])
@section('legal-description', $ui['hub_description'])

@section('legal-content')
    <div class="legal-head">
        <h1>{{ $ui['hub'] }}</h1>
        <p>{{ $ui['hub_intro'] }}</p>
    </div>

    <div class="legal-cards">
        @foreach($esaLegal['documents'] as $slug => $doc)
            <a href="{{ route('legal.show', $slug) }}" class="legal-card">
                <i class="{{ $doc[0] }}"></i>
                <b>{{ $doc[$lang][0] }}</b>
                <span>{{ $doc[$lang][1] }}</span>
            </a>
        @endforeach
    </div>

    <div class="legal-note" style="margin-top:32px;">
        <p>
            <strong>{{ $ui['questions'] }}</strong> —
            {!! str_replace([':email', ':pec'], [$mailto($esaLegal['company']['email']), $mailto($esaLegal['company']['pec'])], e($ui['questions_text'])) !!}
        </p>
    </div>
@endsection
