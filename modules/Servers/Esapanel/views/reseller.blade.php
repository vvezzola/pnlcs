{{-- A reseller plan's limits and use, in the customer's and staff's
     Esapanel section. Included with $t and $limits defined. --}}
@php
    $amount = function ($value, ?string $unit) {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_numeric($value) && $unit === 'MB') {
            return number_format($value / 1024, 1, ',', '.').' GB';
        }

        return (is_numeric($value) ? number_format((float) $value, 0, ',', '.') : $value).($unit && $unit !== 'MB' ? ' '.$unit : '');
    };
@endphp
<table style="width:100%;font-size:13px;border-collapse:collapse;margin-top:8px;">
    <tr>
        <th style="text-align:left;padding:5px 8px 5px 0;color:var(--muted, var(--pn-muted));">{{ $t('reseller.limit') }}</th>
        <th style="text-align:left;padding:5px 8px 5px 0;color:var(--muted, var(--pn-muted));">{{ $t('reseller.value') }}</th>
        <th style="text-align:left;padding:5px 0;color:var(--muted, var(--pn-muted));">{{ $t('reseller.usage') }}</th>
    </tr>
    @foreach($limits as $limit)
    <tr>
        <td style="padding:4px 8px 4px 0;">{{ $limit['code'] }}</td>
        <td style="padding:4px 8px 4px 0;">{{ $limit['unlimited'] ? $t('reseller.unlimited') : $amount($limit['value'], $limit['unit']) }}</td>
        <td style="padding:4px 0;">{{ $amount($limit['usage'], $limit['unit']) }}</td>
    </tr>
    @endforeach
</table>
