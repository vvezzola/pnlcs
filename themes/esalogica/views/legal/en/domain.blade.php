{{-- Esalogica: this subject is covered by the terms of service. --}}
@php
    $esaLegal = require base_path('themes/esalogica/legal.php');
    $ui = $esaLegal['ui'][app()->getLocale() === 'it' ? 'it' : 'en'];
    [$esaDoc, $esaArticle] = $esaLegal['covered'][$document];
@endphp
<p>{!! str_replace([':terms', ':article'], ['<a href="'.e(route('legal.show', $esaDoc)).'">'.e($ui['terms_link']).'</a>', e(is_array($esaArticle) ? $esaArticle[app()->getLocale() === 'it' ? 'it' : 'en'] : $esaArticle)], e($ui['covered'])) !!}</p>
