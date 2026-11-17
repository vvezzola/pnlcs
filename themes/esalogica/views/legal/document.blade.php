{{-- One Esalogica legal document, with its own title and effective date. --}}
@extends('legal.layout')

@php
    $esaLegal = require base_path('themes/esalogica/legal.php');
    $lang = app()->getLocale() === 'it' ? 'it' : 'en';
    $ui = $esaLegal['ui'][$lang];
    $own = $esaLegal['documents'][$document] ?? null;
    $covered = $esaLegal['covered'][$document] ?? null;
    $title = $own[$lang][0] ?? ($covered[$lang] ?? $meta['en'][0]);
    $summary = $own[$lang][1] ?? '';
    $effective = $own[1] ?? null;
@endphp

@section('legal-title', $title)
@section('legal-description', $summary ?: $title)

@section('legal-content')
    <div class="legal-crumb">
        <a href="{{ route('legal.index') }}">{{ $ui['hub'] }}</a>
        &rsaquo; {{ $title }}
    </div>

    <div class="legal-head">
        <h1>{{ $title }}</h1>
        @if($summary)
        <p>{{ $summary }}</p>
        @endif
    </div>

    <div class="legal-grid">
        <nav class="legal-side">
            <p class="legal-side-title">{{ $ui['side'] }}</p>
            @foreach($esaLegal['documents'] as $slug => $doc)
                <a href="{{ route('legal.show', $slug) }}" class="{{ $slug === $document ? 'is-active' : '' }}">{{ $doc[$lang][0] }}</a>
            @endforeach
        </nav>

        <article class="legal-body">
            @include($body, ['esaCompany' => $esaLegal['company']])

            <p class="legal-meta">
                @if($effective)
                {{ $ui['effective'] }} {{ \Carbon\Carbon::parse($effective)->locale($lang)->translatedFormat('j F Y') }} &middot;
                @endif
                {{ $esaLegal['company']['legal_name'] }} &middot;
                <a href="mailto:{{ $esaLegal['company']['email'] }}">{{ $esaLegal['company']['email'] }}</a>
            </p>
        </article>
    </div>
@endsection
