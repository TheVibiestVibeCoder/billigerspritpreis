@php
    $fuel = strtoupper((string) ($fuel ?? 'DIE'));
    $compareScope = strtolower((string) ($compareScope ?? 'viewport')) === 'austria' ? 'austria' : 'viewport';

    $fuelOptions = [
        'DIE' => 'Diesel',
        'SUP' => 'Super 95',
    ];

    $scopeLabel = $compareScope === 'austria'
        ? 'Vergleich: Ganz AT'
        : 'Vergleich: Ansicht';
@endphp

<div class="filter-toolbar">
    <div id="fuel-filter" class="fuel-filter" role="group" aria-label="Kraftstofftyp auswählen">
        @foreach ($fuelOptions as $code => $label)
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

