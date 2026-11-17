{{-- Esalogica: this subject is covered by the terms of service. --}}
@php
    [$esaTopic, $esaDoc, $esaArticle] = (require base_path('themes/esalogica/legal.php'))['covered'][$document];
@endphp
<p>Questo argomento è regolato dalle <a href="{{ route('legal.show', $esaDoc) }}">Condizioni generali di servizio</a>, {{ $esaArticle }}.</p>
