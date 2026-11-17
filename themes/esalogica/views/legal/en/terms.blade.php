{{-- Esalogica: the Italian text for Italian visitors, the English courtesy translation for everyone else. --}}
@include(app()->getLocale() === 'it' ? 'legal.esa.it.terms' : 'legal.esa.en.terms')
