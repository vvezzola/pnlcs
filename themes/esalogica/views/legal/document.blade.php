{{-- One Esalogica legal document, with its own title and effective date. --}}
@extends('legal.layout')

@php
    $esaLegal = require base_path('themes/esalogica/legal.php');
    $own = $esaLegal['documents'][$document] ?? null;
    $covered = $esaLegal['covered'][$document] ?? null;
    $title = $own[0] ?? ($covered[0] ?? $meta['en'][0]);
    $summary = $own[1] ?? '';
    $effective = $own[3] ?? null;
@endphp

@section('legal-title', $title)
@section('legal-description', $summary ?: $title)

@section('legal-content')
    <div class="legal-crumb">
        <a href="{{ route('legal.index') }}">Documenti legali</a>
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
            <p class="legal-side-title">DOCUMENTI</p>
            @foreach($esaLegal['documents'] as $slug => [$docTitle])
                <a href="{{ route('legal.show', $slug) }}" class="{{ $slug === $document ? 'is-active' : '' }}">{{ $docTitle }}</a>
            @endforeach
        </nav>

        <article class="legal-body">
            @include($body, ['esaCompany' => $esaLegal['company']])

            <p class="legal-meta">
                @if($effective)
                In vigore dal {{ \Carbon\Carbon::parse($effective)->locale('it')->translatedFormat('j F Y') }} &middot;
                @endif
                {{ $esaLegal['company']['legal_name'] }} &middot;
                <a href="mailto:{{ $esaLegal['company']['email'] }}">{{ $esaLegal['company']['email'] }}</a>
            </p>
        </article>
    </div>
@endsection
