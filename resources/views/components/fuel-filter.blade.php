@props([
    'fuel' => 'DIE',
    'compareScope' => 'viewport',
])

@php
    $fuel = strtoupper((string) $fuel);
    $compareScope = strtolower((string) $compareScope) === 'austria' ? 'austria' : 'viewport';

    $options = [
        'DIE' => 'Diesel',
        'SUP' => 'Super 95',
    ];

    $scopeLabel = $compareScope === 'austria'
        ? 'Vergleich: Ganz AT'
        : 'Vergleich: Ansicht';
@endphp

<div class="filter-toolbar">
    <div id="fuel-filter" class="fuel-filter" role="group" aria-label="Kraftstofftyp ausw&#228;hlen">
        @foreach ($options as $code => $label)
            <button
                type="button"
                class="fuel-button {{ $fuel === $code ? 'is-active' : '' }}"
                data-fuel="{{ $code }}"
                aria-pressed="{{ $fuel === $code ? 'true' : 'false' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <button
        type="button"
        class="scope-toggle {{ $compareScope === 'austria' ? 'is-active' : '' }}"
        data-compare-scope-toggle
        data-compare-scope="{{ $compareScope }}"
        aria-pressed="{{ $compareScope === 'austria' ? 'true' : 'false' }}"
    >
        {{ $scopeLabel }}
    </button>
</div>

