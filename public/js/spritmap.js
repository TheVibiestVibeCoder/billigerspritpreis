const mapElement = document.getElementById('map');
const globalErrorElement = document.getElementById('map-error');

if (mapElement && typeof window.L === 'undefined') {
    if (globalErrorElement) {
        globalErrorElement.classList.remove('hidden');
        globalErrorElement.style.display = 'flex';
        globalErrorElement.querySelector('.state-card').innerHTML = `
            Kartenbibliothek konnte nicht geladen werden.
            <br>
            Bitte Internetverbindung und Content-Blocker pr\u00fcfen.
        `;
    }
}

if (mapElement && typeof window.L !== 'undefined') {
    const documentElement = document.documentElement;
    const austriaBounds = window.L.latLngBounds(
        [46.32, 9.35],
        [49.10, 17.25],
    );

    const defaultCenter = [
        Number.parseFloat(mapElement.dataset.centerLat ?? '47.8') || 47.8,
        Number.parseFloat(mapElement.dataset.centerLng ?? '13.3') || 13.3,
    ];
    const defaultZoom = Number.parseInt(mapElement.dataset.zoom ?? '8', 10) || 8;
    const apiUrl = mapElement.dataset.apiUrl ?? '/api/stations';
    const boundaryUrl = mapElement.dataset.boundaryUrl ?? '/api/austria-boundary';

    const normalizeFuel = (value) => {
        const normalized = String(value ?? '').toUpperCase();

        return ['DIE', 'SUP'].includes(normalized) ? normalized : 'DIE';
    };

    const normalizeCompareScope = (value) => {
        const normalized = String(value ?? '').toLowerCase();

        return ['austria', 'at', 'all_at'].includes(normalized) ? 'austria' : 'viewport';
    };

    const compareScopeLabels = {
        viewport: 'Vergleich: Ansicht',
        austria: 'Vergleich: Ganz AT',
    };

    const compareScopeMetaLabels = {
        viewport: 'Ausschnitt',
        austria: 'Ganz AT',
    };

    let currentFuel = normalizeFuel(mapElement.dataset.initialFuel ?? 'DIE');
    let currentCompareScope = normalizeCompareScope(mapElement.dataset.initialCompareScope ?? 'viewport');
    let latestStations = [];
    let currentAbortController = null;
    let moveDebounceHandle = null;
    let activePopupStationId = null;
    let isRefreshingMarkers = false;
    let bottomOverlaySyncFrame = null;
    let bottomOverlaySyncTimeout = null;
    let tierFilterAnimationTimeout = null;
    let latestComparisonRange = {
        scope: currentCompareScope,
        min: null,
        max: null,
    };

    const loadingElement = document.getElementById('map-loading');
    const emptyElement = document.getElementById('map-empty');
    const errorElement = globalErrorElement;
    const brandUpdatedElement = document.getElementById('brand-updated');
    const metaCardElement = document.getElementById('map-meta');
    const metaCountElement = document.getElementById('map-meta-count');
    const metaScopeElement = document.getElementById('map-meta-scope');
    const legendCardElement = document.querySelector('[data-legend]');
    const legendToggleElement = document.querySelector('[data-legend-toggle]');
    const legendContentElement = document.querySelector('[data-legend-content]');
    const legendTierToggleElements = document.querySelectorAll('[data-tier-toggle]');
    const legendTierRowElements = document.querySelectorAll('[data-tier-row]');
    const mobileFilterCardElement = document.querySelector('[data-mobile-filter-card]');
    const mobileFilterToggleElement = document.querySelector('[data-mobile-filter-toggle]');
    const mobileFilterPanelElement = document.querySelector('[data-mobile-filter-panel]');
    const mobileFilterSummaryElement = document.querySelector('[data-mobile-filter-summary]');
    const rangeCardElement = document.querySelector('[data-price-range]');
    const rangeScopeElement = document.querySelector('[data-range-scope]');
    const rangeMinElement = document.querySelector('[data-range-min]');
    const rangeMaxElement = document.querySelector('[data-range-max]');
    const rangeDotsElement = document.querySelector('[data-range-dots]');
    const rangeEmptyElement = document.querySelector('[data-range-empty]');
    const stationCountFormatter = new Intl.NumberFormat('de-AT');
    const priceValueFormatter = new Intl.NumberFormat('de-AT', {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
    const rangeScopeLabels = {
        viewport: 'Ansicht-Vergleich',
        austria: 'AT-Vergleich',
    };
    const tierFilterAnimationMs = 180;
    const maxAnimatedTierFilterStations = 120;
    const maxAnimatedTierFilterElements = 280;
    let mobileFilterCollapsed = true;
    const tierFilterState = {
        1: true,
        2: true,
        3: true,
        4: true,
        5: true,
    };

    const map = window.L.map(mapElement, {
        zoomControl: false,
        minZoom: 4,
        maxZoom: 17,
        worldCopyJump: false,
    }).setView(defaultCenter, defaultZoom);

    window.L.control.zoom({ position: 'topright' }).addTo(map);

    window.L.tileLayer('https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png', {
        subdomains: 'abcd',
        maxZoom: 20,
        noWrap: true,
        attribution: '&copy; OpenStreetMap-Mitwirkende &copy; CARTO',
    }).addTo(map);

    map.createPane('labelsPane');
    map.getPane('labelsPane').style.pointerEvents = 'none';
    map.getPane('labelsPane').style.opacity = '0.72';

    window.L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png', {
        subdomains: 'abcd',
        maxZoom: 20,
        noWrap: true,
        pane: 'labelsPane',
        attribution: '&copy; OpenStreetMap-Mitwirkende &copy; CARTO',
    }).addTo(map);

    map.fitBounds(austriaBounds, { padding: [28, 28], animate: false });

    const markerLayer = window.L.layerGroup().addTo(map);
    const priceLabelLayer = window.L.layerGroup().addTo(map);
    const markerByStationId = new Map();

    map.createPane('austriaBorderHaloPane');
    map.getPane('austriaBorderHaloPane').style.pointerEvents = 'none';
    map.getPane('austriaBorderHaloPane').style.zIndex = '450';

    map.createPane('austriaBorderPane');
    map.getPane('austriaBorderPane').style.pointerEvents = 'none';
    map.getPane('austriaBorderPane').style.zIndex = '451';

    const drawAustriaBoundary = (geojson) => {
        window.L.geoJSON(geojson, {
            pane: 'austriaBorderHaloPane',
            style: {
                color: '#FFFFFF',
                weight: 5,
                opacity: 0.9,
                fill: false,
                lineJoin: 'round',
                lineCap: 'round',
            },
        }).addTo(map);

        window.L.geoJSON(geojson, {
            pane: 'austriaBorderPane',
            style: {
                color: '#0f766e',
                weight: 3,
                opacity: 0.95,
                fill: false,
                lineJoin: 'round',
                lineCap: 'round',
            },
        }).addTo(map);
    };

    const drawFallbackBoundsBorder = () => {
        window.L.rectangle(austriaBounds, {
            pane: 'austriaBorderPane',
            color: '#0f766e',
            weight: 3,
            opacity: 0.95,
            fill: false,
        }).addTo(map);
    };

    const loadAustriaBoundary = async () => {
        try {
            const response = await fetch(boundaryUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`Boundary request failed with status ${response.status}`);
            }

            const payload = await response.json();
            const features = Array.isArray(payload.features) ? payload.features : [];

            if (features.length === 0) {
                throw new Error('Boundary payload has no features.');
            }

            drawAustriaBoundary({
                type: 'FeatureCollection',
                features: [features[0]],
            });
        } catch (error) {
            console.warn('Using fallback boundary style.', error);
            drawFallbackBoundsBorder();
        }
    };

    loadAustriaBoundary();

    const showElement = (element, display = 'block') => {
        if (!element) {
            return;
        }

        element.classList.remove('hidden');
        element.style.display = display;
    };

    const hideElement = (element) => {
        if (!element) {
            return;
        }

        element.classList.add('hidden');
        element.style.display = 'none';
    };

    const normalizeTier = (value, fallback = null) => {
        const parsed = Number.parseInt(value, 10);

        if (Number.isFinite(parsed) && parsed >= 1 && parsed <= 5) {
            return parsed;
        }

        return fallback;
    };

    const isTierEnabled = (tier) => tierFilterState[tier] !== false;

    const filterStationsByTier = (stations = []) => {
        if (!Array.isArray(stations)) {
            return [];
        }

        return stations.filter((station) => {
            const tier = normalizeTier(station?.price_tier, 3);

            return isTierEnabled(tier);
        });
    };

    const updateLegendTierUi = () => {
        legendTierToggleElements.forEach((toggle) => {
            const tier = normalizeTier(toggle.dataset.tierToggle, null);

            if (tier === null) {
                return;
            }

            const enabled = isTierEnabled(tier);
            toggle.checked = enabled;
            toggle.setAttribute('aria-checked', String(enabled));
        });

        legendTierRowElements.forEach((row) => {
            const tier = normalizeTier(row.dataset.tierRow, null);

            if (tier === null) {
                return;
            }

            row.classList.toggle('is-disabled', !isTierEnabled(tier));
        });
    };

    const updateVisibleStationCount = (stations = null) => {
        if (!metaCountElement) {
            return;
        }

        const filtered = Array.isArray(stations) ? stations : filterStationsByTier(latestStations);
        metaCountElement.textContent = stationCountFormatter.format(filtered.length);
    };

    const setMetaLoadingState = (isLoading) => {
        if (!metaCardElement) {
            return;
        }

        metaCardElement.classList.toggle('is-loading', Boolean(isLoading));
    };

    const syncBottomOverlayMeasurements = () => {
        if (!rangeCardElement) {
            return;
        }

        const nextHeight = Math.max(72, Math.ceil(rangeCardElement.offsetHeight));
        documentElement.style.setProperty('--range-card-height', `${nextHeight}px`);

        const nextSpace = Math.max(96, Math.ceil(rangeCardElement.offsetHeight) + 18);
        documentElement.style.setProperty('--range-card-space', `${nextSpace}px`);

        if (!legendCardElement) {
            return;
        }

        const isDesktopViewport = window.innerWidth > 820;
        const legendIsCollapsed = legendCardElement.classList.contains('is-collapsed');

        if (!isDesktopViewport || !legendIsCollapsed) {
            legendCardElement.style.removeProperty('bottom');

            return;
        }

        const rangeBottom = Number.parseFloat(window.getComputedStyle(rangeCardElement).bottom) || 0;
        const alignedLegendBottom = rangeBottom + Math.max(0, rangeCardElement.offsetHeight - legendCardElement.offsetHeight);

        legendCardElement.style.bottom = `${Math.round(alignedLegendBottom)}px`;
    };

    const queueBottomOverlayMeasurements = () => {
        if (!rangeCardElement) {
            return;
        }

        if (bottomOverlaySyncFrame !== null) {
            window.cancelAnimationFrame(bottomOverlaySyncFrame);
            bottomOverlaySyncFrame = null;
        }

        if (bottomOverlaySyncTimeout !== null) {
            window.clearTimeout(bottomOverlaySyncTimeout);
            bottomOverlaySyncTimeout = null;
        }

        bottomOverlaySyncFrame = window.requestAnimationFrame(() => {
            bottomOverlaySyncFrame = window.requestAnimationFrame(() => {
                bottomOverlaySyncFrame = null;
                syncBottomOverlayMeasurements();
            });
        });

        bottomOverlaySyncTimeout = window.setTimeout(() => {
            bottomOverlaySyncTimeout = null;
            syncBottomOverlayMeasurements();
        }, 220);
    };

    const setRangeLoadingState = (isLoading) => {
        if (!rangeCardElement) {
            return;
        }

        rangeCardElement.classList.toggle('is-loading', Boolean(isLoading));
    };

    const setLegendCollapsed = (isCollapsed) => {
        if (!legendCardElement) {
            return;
        }

        const collapsed = Boolean(isCollapsed);

        legendCardElement.classList.toggle('is-collapsed', collapsed);

        if (legendToggleElement) {
            legendToggleElement.setAttribute('aria-expanded', String(!collapsed));
        }

        if (legendContentElement) {
            legendContentElement.setAttribute('aria-hidden', String(collapsed));
        }

        syncBottomOverlayMeasurements();
        queueBottomOverlayMeasurements();
    };

    const setMobileFilterCollapsed = (isCollapsed) => {
        if (!mobileFilterCardElement) {
            return;
        }

        mobileFilterCollapsed = Boolean(isCollapsed);

        const isCollapsibleViewport = window.innerWidth <= 820;
        const collapsed = isCollapsibleViewport ? mobileFilterCollapsed : false;

        mobileFilterCardElement.classList.toggle('is-collapsed', collapsed);

        if (mobileFilterToggleElement) {
            mobileFilterToggleElement.setAttribute('aria-expanded', String(!collapsed));
        }

        if (mobileFilterPanelElement) {
            mobileFilterPanelElement.setAttribute('aria-hidden', String(collapsed));
        }
    };

    const updateMobileFilterSummary = () => {
        if (!mobileFilterSummaryElement) {
            return;
        }

        const fuelLabel = currentFuel === 'SUP' ? 'Super 95' : 'Diesel';
        const scopeLabel = currentCompareScope === 'austria' ? 'Ganz AT' : 'Ansicht';

        mobileFilterSummaryElement.textContent = `${fuelLabel} · ${scopeLabel}`;
    };

    const updateRangeCardCaption = () => {
        if (!rangeScopeElement) {
            return;
        }

        rangeScopeElement.textContent = rangeScopeLabels[currentCompareScope] ?? rangeScopeLabels.viewport;
        syncBottomOverlayMeasurements();
        queueBottomOverlayMeasurements();
    };

    const updateMetaBar = (payload = null) => {
        const rawCount = Number.parseInt(payload?.count, 10);
        const count = Number.isFinite(rawCount) ? rawCount : latestStations.length;
        const scope = normalizeCompareScope(payload?.meta?.comparison_scope ?? payload?.comparison_scope ?? currentCompareScope);

        if (metaCountElement) {
            metaCountElement.textContent = stationCountFormatter.format(Math.max(0, Number(count) || 0));
        }

        if (metaScopeElement) {
            metaScopeElement.textContent = compareScopeMetaLabels[scope] ?? compareScopeMetaLabels.viewport;
        }

        if (brandUpdatedElement) {
            const raw = payload?.meta?.generated_at ?? null;
            if (raw) {
                const date = new Date(raw);
                if (!Number.isNaN(date.getTime())) {
                    const hhmm = date.toLocaleTimeString('de-AT', { hour: '2-digit', minute: '2-digit' });
                    brandUpdatedElement.textContent = `aktualisiert ${hhmm}`;
                    brandUpdatedElement.classList.add('is-visible');
                }
            }
        }
    };

    const updateLegendTierCounts = (stations = []) => {
        const countsByTier = {
            1: 0,
            2: 0,
            3: 0,
            4: 0,
            5: 0,
        };

        if (Array.isArray(stations)) {
            stations.forEach((station) => {
                const tier = Number.parseInt(station?.price_tier, 10);

                if (countsByTier[tier] !== undefined) {
                    countsByTier[tier] += 1;
                }
            });
        }

        document.querySelectorAll('[data-tier-count]').forEach((element) => {
            const tier = Number.parseInt(element.dataset.tierCount, 10);
            const count = countsByTier[tier] ?? 0;

            element.textContent = stationCountFormatter.format(count);
        });
    };

    const updateComparisonRange = (payload = null) => {
        const scope = normalizeCompareScope(payload?.meta?.comparison_scope ?? payload?.comparison_scope ?? currentCompareScope);
        const min = Number.parseFloat(payload?.meta?.comparison_min_price);
        const max = Number.parseFloat(payload?.meta?.comparison_max_price);

        latestComparisonRange = {
            scope,
            min: Number.isFinite(min) ? min : null,
            max: Number.isFinite(max) ? max : null,
        };
    };

    const shouldReduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const collectMarkerPaths = () => {
        return markerLayer.getLayers()
            .map((layer) => layer?._path)
            .filter(Boolean);
    };

    const collectLabelElements = () => {
        return priceLabelLayer.getLayers()
            .map((layer) => layer?.getElement?.() ?? layer?._icon)
            .filter(Boolean);
    };

    const collectRangeDotElements = () => {
        if (!rangeDotsElement) {
            return [];
        }

        return Array.from(rangeDotsElement.querySelectorAll('.range-card-dot'));
    };

    const animateElementsIn = (elements, className, duration = tierFilterAnimationMs + 60) => {
        if (shouldReduceMotion() || elements.length === 0) {
            return;
        }

        elements.forEach((element) => {
            element.classList.remove('marker-exiting', 'label-exiting', 'dot-exiting');
            element.classList.add(className);
        });

        window.setTimeout(() => {
            elements.forEach((element) => {
                element.classList.remove(className);
            });
        }, duration);
    };

    const shouldAnimateTierFilterTransition = () => {
        if (shouldReduceMotion()) {
            return false;
        }

        const nextVisibleCount = filterStationsByTier(latestStations).length;
        const currentElementCount = collectMarkerPaths().length
            + collectLabelElements().length
            + collectRangeDotElements().length;
        const nextElementEstimate = nextVisibleCount * (map.getZoom() >= 13 ? 3 : 2);

        return nextVisibleCount <= maxAnimatedTierFilterStations
            && Math.max(currentElementCount, nextElementEstimate) <= maxAnimatedTierFilterElements;
    };

    const animateTierFilterChange = () => {
        if (tierFilterAnimationTimeout !== null) {
            window.clearTimeout(tierFilterAnimationTimeout);
            tierFilterAnimationTimeout = null;
        }

        if (!shouldAnimateTierFilterTransition()) {
            renderStations(latestStations, { restorePopup: true, animateTransition: false });

            return;
        }

        const markerElements = collectMarkerPaths();
        const labelElements = collectLabelElements();
        const rangeDotElements = collectRangeDotElements();
        const hasElements = markerElements.length > 0 || labelElements.length > 0 || rangeDotElements.length > 0;

        markerElements.forEach((element) => {
            element.classList.remove('marker-entering');
            element.classList.add('marker-exiting', 'marker-filterable');
        });

        labelElements.forEach((element) => {
            element.classList.remove('label-entering');
            element.classList.add('label-exiting');
        });

        rangeDotElements.forEach((element) => {
            element.classList.remove('dot-entering');
            element.classList.add('dot-exiting');
        });

        tierFilterAnimationTimeout = window.setTimeout(() => {
            tierFilterAnimationTimeout = null;
            renderStations(latestStations, { restorePopup: true, animateTransition: true });
        }, hasElements ? tierFilterAnimationMs : 0);
    };

    const sanitizeHtml = (value) => {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const resolveSelectedPrice = (station) => {
        const diesel = Number.parseFloat(station.price_diesel);
        const superPrice = Number.parseFloat(station.price_super);

        if (currentFuel === 'SUP') {
            return Number.isFinite(superPrice) ? superPrice : null;
        }

        return Number.isFinite(diesel) ? diesel : null;
    };

    const getRangeDotLayoutConfig = (count) => {
        if (count < 25) {
            return {
                size: '1.05rem',
                border: '3px',
                minGapPx: 14,
            };
        }

        if (count < 50) {
            return {
                size: '0.88rem',
                border: '2px',
                minGapPx: 10,
            };
        }

        if (count < 100) {
            return {
                size: '0.74rem',
                border: '2px',
                minGapPx: 7,
            };
        }

        return {
            size: '0.74rem',
            border: '2px',
            minGapPx: 0,
        };
    };

    const distributeRangeDotPositions = (idealPositions, trackWidth, minGapPx) => {
        if (idealPositions.length <= 1 || minGapPx <= 0 || !Number.isFinite(trackWidth) || trackWidth <= 0) {
            return idealPositions.map((position) => Math.max(0, Math.min(trackWidth, position)));
        }

        const positions = idealPositions.map((position) => Math.max(0, Math.min(trackWidth, position)));

        for (let index = 1; index < positions.length; index += 1) {
            positions[index] = Math.max(positions[index], positions[index - 1] + minGapPx);
        }

        if (positions[positions.length - 1] > trackWidth) {
            positions[positions.length - 1] = trackWidth;

            for (let index = positions.length - 2; index >= 0; index -= 1) {
                positions[index] = Math.min(positions[index], positions[index + 1] - minGapPx);
            }

            if (positions[0] < 0) {
                const shift = Math.abs(positions[0]);

                for (let index = 0; index < positions.length; index += 1) {
                    positions[index] += shift;
                }

                const overflow = positions[positions.length - 1] - trackWidth;

                if (overflow > 0) {
                    for (let index = 0; index < positions.length; index += 1) {
                        positions[index] -= overflow;
                    }
                }
            }
        }

        return positions.map((position) => Math.max(0, Math.min(trackWidth, position)));
    };

    const getFilteredPricedStations = (stations = latestStations) => {
        return filterStationsByTier(Array.isArray(stations) ? stations : [])
            .map((station) => ({
                station,
                price: resolveSelectedPrice(station),
            }))
            .filter(({ price }) => Number.isFinite(price));
    };

    const focusStationFromRangeDot = (station) => {
        const lat = Number.parseFloat(station?.latitude);
        const lng = Number.parseFloat(station?.longitude);
        const rawStationId = Number.parseInt(station?.id, 10);
        const stationId = Number.isFinite(rawStationId) ? rawStationId : null;

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        if (stationId !== null) {
            activePopupStationId = stationId;
        }

        const marker = stationId !== null ? markerByStationId.get(stationId) : null;
        const target = window.L.latLng(lat, lng);
        const distanceToCenter = map.distance(map.getCenter(), target);

        if (marker) {
            marker.openPopup();
        }

        if (distanceToCenter > 30) {
            map.flyTo(target, map.getZoom(), {
                animate: true,
                duration: 0.45,
            });
        }
    };

    const updatePriceRange = (stations = latestStations, { animateTransition = false } = {}) => {
        if (!rangeCardElement || !rangeMinElement || !rangeMaxElement || !rangeDotsElement) {
            return;
        }

        const filteredStations = filterStationsByTier(Array.isArray(stations) ? stations : []);
        const pricedStations = getFilteredPricedStations(stations);

        rangeDotsElement.replaceChildren();

        if (pricedStations.length === 0) {
            rangeCardElement.classList.add('is-empty');
            rangeCardElement.classList.remove('is-dense');
            rangeMinElement.textContent = '-';
            rangeMaxElement.textContent = '-';
            rangeMinElement.style.color = '#5a7a90';
            rangeMaxElement.style.color = '#5a7a90';
            rangeCardElement.style.setProperty('--range-dot-size', '0.92rem');
            rangeCardElement.style.setProperty('--range-dot-border', '2px');

            if (rangeEmptyElement) {
                rangeEmptyElement.textContent = filteredStations.length > 0
                    ? 'Für den aktuellen Filter sind keine Preiswerte im Ausschnitt verfügbar.'
                    : 'Keine Preise im Ausschnitt.';
            }

            syncBottomOverlayMeasurements();
            queueBottomOverlayMeasurements();

            return;
        }

        rangeCardElement.classList.remove('is-empty');
        rangeCardElement.classList.toggle('is-dense', pricedStations.length >= 50);

        const localMin = Math.min(...pricedStations.map(({ price }) => price));
        const localMax = Math.max(...pricedStations.map(({ price }) => price));
        const useComparisonRange = currentCompareScope === 'austria'
            && latestComparisonRange.scope === 'austria'
            && Number.isFinite(latestComparisonRange.min)
            && Number.isFinite(latestComparisonRange.max)
            && latestComparisonRange.max >= latestComparisonRange.min;
        const min = useComparisonRange ? latestComparisonRange.min : localMin;
        const max = useComparisonRange ? latestComparisonRange.max : localMax;
        const spread = max - min;
        const minStation = pricedStations.find(({ price }) => price === localMin)?.station;
        const maxStation = pricedStations.find(({ price }) => price === localMax)?.station;

        rangeMinElement.textContent = priceValueFormatter.format(min);
        rangeMaxElement.textContent = priceValueFormatter.format(max);
        rangeMinElement.style.color = useComparisonRange ? '#22C55E' : (minStation?.price_color || '#22C55E');
        rangeMaxElement.style.color = useComparisonRange ? '#DC2626' : (maxStation?.price_color || '#DC2626');

        const dotLayoutConfig = getRangeDotLayoutConfig(pricedStations.length);
        const trackWidth = rangeDotsElement.getBoundingClientRect().width
            || rangeDotsElement.parentElement?.getBoundingClientRect().width
            || 0;

        rangeCardElement.style.setProperty('--range-dot-size', dotLayoutConfig.size);
        rangeCardElement.style.setProperty('--range-dot-border', dotLayoutConfig.border);

        const fragment = document.createDocumentFragment();
        const sortedStations = pricedStations
            .slice()
            .sort((left, right) => left.price - right.price);
        const idealPositions = sortedStations.map(({ price }) => {
            if (!(trackWidth > 0)) {
                return spread > 0 ? ((price - min) / spread) * 100 : 50;
            }

            return spread > 0 ? ((price - min) / spread) * trackWidth : trackWidth / 2;
        });
        const renderedPositions = pricedStations.length < 100
            ? distributeRangeDotPositions(idealPositions, trackWidth, dotLayoutConfig.minGapPx)
            : idealPositions;

        sortedStations.forEach(({ station, price }, index) => {
            const dot = document.createElement('button');
            const rawStationId = Number.parseInt(station?.id, 10);
            const stationId = Number.isFinite(rawStationId) ? rawStationId : null;
            const exactPosition = spread > 0 ? ((price - min) / spread) * 100 : 50;
            const renderedPosition = trackWidth > 0
                ? (renderedPositions[index] / trackWidth) * 100
                : renderedPositions[index];
            const stationName = String(station?.name || 'Tankstelle').trim();
            const stationLabel = `${stationName} · ${priceValueFormatter.format(price)} EUR/L`;

            dot.type = 'button';
            dot.className = 'range-card-dot';
            dot.style.setProperty('--dot-position', `${renderedPosition.toFixed(2)}%`);
            dot.style.setProperty('--dot-color', station?.price_color || '#EAB308');
            dot.style.zIndex = String(index + 1);
            dot.dataset.exactPosition = exactPosition.toFixed(2);
            dot.setAttribute('aria-label', stationLabel);
            dot.title = stationLabel;

            if (stationId !== null) {
                dot.dataset.stationId = String(stationId);
            }

            dot.addEventListener('click', () => {
                focusStationFromRangeDot(station);
            });

            fragment.appendChild(dot);
        });

        rangeDotsElement.appendChild(fragment);

        if (animateTransition) {
            animateElementsIn(Array.from(rangeDotsElement.querySelectorAll('.range-card-dot')), 'dot-entering');
        }

        syncBottomOverlayMeasurements();
        queueBottomOverlayMeasurements();
    };

    const getBottomOverlayPadding = () => {
        const rangeHeight = rangeCardElement?.offsetHeight ?? 0;
        const viewportPadding = window.innerWidth <= 820 ? 24 : 20;

        return Math.max(56, rangeHeight + viewportPadding);
    };

    const buildPopupHtml = (station) => {
        const rawStationName = String(station.name || 'Unbekannte Tankstelle').trim();
        const nameParts = rawStationName.split(/\s+/).filter((part) => part.length > 0);
        const stationBrand = sanitizeHtml((nameParts[0] || 'Tankstelle').toUpperCase());
        const stationTitle = sanitizeHtml(nameParts.length > 1 ? nameParts.slice(1).join(' ') : rawStationName);
        const street = sanitizeHtml(station.street || '');
        const cityLine = sanitizeHtml(`${station.postal_code || ''} ${station.city || ''}`.trim());
        const diesel = Number.parseFloat(station.price_diesel);
        const superPrice = Number.parseFloat(station.price_super);
        const selected = resolveSelectedPrice(station);
        const selectedPriceValue = Number.isFinite(selected) ? Number(selected).toFixed(3) : 'n/a';
        const color = sanitizeHtml(station.price_color || '#EAB308');
        const statusOpen = Boolean(station.is_open);
        const statusLabel = statusOpen ? 'Offen' : 'Geschlossen';
        const selectedFuelLabel = currentFuel === 'SUP' ? 'Super 95' : 'Diesel';
        const addressText = [street, cityLine].filter((segment) => segment.length > 0).join(', ')
            || 'Adresse nicht verf\u00fcgbar';
        const routeTarget = encodeURIComponent(`${station.latitude},${station.longitude}`);
        const routeUrl = `https://www.google.com/maps/dir/?api=1&destination=${routeTarget}`;

        const dieselText = Number.isFinite(diesel) ? `D ${Number(diesel).toFixed(3)}` : null;
        const superText = Number.isFinite(superPrice) ? `S ${Number(superPrice).toFixed(3)}` : null;
        const secondaryPrices = [dieselText, superText].filter(Boolean).join(' &middot; ') || 'n/a';

        return `
            <article class="popup-card" style="--popup-accent:${color};">
                <div class="popup-left">
                    <p class="popup-name">${stationBrand}${stationTitle !== stationBrand ? `<span class="popup-name-rest"> ${stationTitle}</span>` : ''}</p>
                    <p class="popup-address">${addressText}</p>
                    <p class="popup-bottom-row">
                        <span class="popup-status ${statusOpen ? 'open' : 'closed'}"><span class="popup-status-dot" aria-hidden="true"></span>${statusLabel}</span>
                        <span class="popup-sub-prices">${secondaryPrices}</span>
                    </p>
                </div>
                <div class="popup-right">
                    <p class="popup-price-big" style="color:${color};">${sanitizeHtml(selectedPriceValue)}</p>
                    <p class="popup-price-meta">${sanitizeHtml(selectedFuelLabel)} &middot; EUR/L</p>
                    <a class="popup-route" href="${routeUrl}" target="_blank" rel="noopener noreferrer">&#8599; Route</a>
                </div>
            </article>
        `;
    };

    const renderLabels = ({ animateTransition = false } = {}) => {
        priceLabelLayer.clearLayers();

        if (map.getZoom() < 13) {
            return;
        }

        const newLabelElements = [];

        filterStationsByTier(latestStations).forEach((station) => {
            const lat = Number.parseFloat(station.latitude);
            const lng = Number.parseFloat(station.longitude);
            const price = resolveSelectedPrice(station);

            if (!Number.isFinite(lat) || !Number.isFinite(lng) || !Number.isFinite(price)) {
                return;
            }

            const color = station.price_color || '#EAB308';
            const icon = window.L.divIcon({
                className: 'price-label-wrap',
                html: `<span class="price-label" style="--label-color:${color};">${Number(price).toFixed(3)}</span>`,
                iconAnchor: [-12, 2],
            });

            const labelMarker = window.L.marker([lat, lng], { icon, interactive: false }).addTo(priceLabelLayer);
            const labelElement = labelMarker.getElement?.() ?? labelMarker._icon;

            if (labelElement) {
                newLabelElements.push(labelElement);
            }
        });

        if (animateTransition) {
            animateElementsIn(newLabelElements, 'label-entering');
        }
    };

    const renderStations = (stations, { restorePopup = false, animateTransition = false } = {}) => {
        if (!restorePopup) {
            activePopupStationId = null;
        }

        isRefreshingMarkers = true;

        try {
            markerLayer.clearLayers();
            markerByStationId.clear();
            const safeStations = Array.isArray(stations) ? stations : [];
            latestStations = safeStations;
            updateLegendTierCounts(safeStations);
            updateLegendTierUi();
            const visibleStations = filterStationsByTier(safeStations);
            updateVisibleStationCount(visibleStations);
            updatePriceRange(safeStations, { animateTransition });

            hideElement(emptyElement);

            if (safeStations.length === 0) {
                activePopupStationId = null;
                showElement(emptyElement, 'flex');
                renderLabels({ animateTransition });

                return;
            }

            const isMobileViewport = window.innerWidth <= 820;
            const popupMaxWidth = Math.min(280, window.innerWidth - 48);
            // On mobile the control banner is at the top; keep extra top padding while
            // letting the bottom stay mostly free so popups can use vertical space.
            const autoPanTop = isMobileViewport ? 170 : 88;
            const autoPanBottom = getBottomOverlayPadding();
            let markerToReopen = null;
            const newMarkerElements = [];

            visibleStations.forEach((station) => {
                const lat = Number.parseFloat(station.latitude);
                const lng = Number.parseFloat(station.longitude);

                if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                    return;
                }

                const color = station.price_color || '#EAB308';
                const rawStationId = Number.parseInt(station?.id, 10);
                const stationId = Number.isFinite(rawStationId) ? rawStationId : null;
                const markerOptions = {
                    radius: 8,
                    color: '#FFFFFF',
                    weight: 2,
                    fillColor: color,
                    fillOpacity: 0.95,
                };

                if (stationId !== null) {
                    markerOptions.stationId = stationId;
                }

                const marker = window.L.circleMarker([lat, lng], markerOptions);

                marker.on('mouseover', () => marker.setStyle({ radius: 11 }));
                marker.on('mouseout', () => marker.setStyle({ radius: 8 }));
                marker.bindPopup(buildPopupHtml(station), {
                    maxWidth: popupMaxWidth,
                    className: 'spritmap-popup',
                    autoPan: true,
                    autoPanPaddingTopLeft: window.L.point(16, autoPanTop),
                    autoPanPaddingBottomRight: window.L.point(64, autoPanBottom),
                    closeButton: true,
                    closeOnClick: true,
                    closeOnMove: false,
                    autoClose: true,
                    offset: window.L.point(0, -8),
                });
                marker.addTo(markerLayer);
                marker._path?.classList.add('marker-filterable');

                if (marker._path) {
                    newMarkerElements.push(marker._path);
                }

                if (stationId !== null) {
                    markerByStationId.set(stationId, marker);
                }

                if (restorePopup && stationId !== null && stationId === activePopupStationId) {
                    markerToReopen = marker;
                }
            });

            if (restorePopup && markerToReopen) {
                markerToReopen.openPopup();
            } else if (restorePopup) {
                activePopupStationId = null;
            }

            if (animateTransition) {
                animateElementsIn(newMarkerElements, 'marker-entering');
            }

            renderLabels({ animateTransition });
        } finally {
            isRefreshingMarkers = false;
        }
    };

    const updateFuelButtons = () => {
        document.querySelectorAll('[data-fuel]').forEach((button) => {
            const isActive = (button.dataset.fuel || '').toUpperCase() === currentFuel;

            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });

        updateMobileFilterSummary();
    };

    const updateCompareScopeToggle = () => {
        const toggleButton = document.querySelector('[data-compare-scope-toggle]');

        if (!toggleButton) {
            updateMobileFilterSummary();
            updateRangeCardCaption();
            return;
        }

        toggleButton.dataset.compareScope = currentCompareScope;
        toggleButton.classList.toggle('is-active', currentCompareScope === 'austria');
        toggleButton.setAttribute('aria-pressed', String(currentCompareScope === 'austria'));
        toggleButton.textContent = compareScopeLabels[currentCompareScope] ?? compareScopeLabels.viewport;
        updateMobileFilterSummary();
        updateRangeCardCaption();
    };

    const getBoundsQuery = () => {
        const bounds = map.getBounds();

        return [
            bounds.getSouth().toFixed(6),
            bounds.getWest().toFixed(6),
            bounds.getNorth().toFixed(6),
            bounds.getEast().toFixed(6),
        ].join(',');
    };

    const loadStations = async ({ preservePopup = false } = {}) => {
        const restorePopupAfterLoad = Boolean(preservePopup);

        if (!restorePopupAfterLoad) {
            activePopupStationId = null;
        }

        if (currentAbortController) {
            currentAbortController.abort();
        }

        currentAbortController = new AbortController();

        showElement(loadingElement, 'flex');
        hideElement(errorElement);
        hideElement(emptyElement);
        setMetaLoadingState(true);
        setRangeLoadingState(true);

        const endpoint = new URL(apiUrl, window.location.origin);
        endpoint.searchParams.set('fuel', currentFuel);
        endpoint.searchParams.set('bounds', getBoundsQuery());
        endpoint.searchParams.set('compareScope', currentCompareScope);
        endpoint.searchParams.set('includeClosed', 'false');

        try {
            const response = await fetch(endpoint.toString(), {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                },
                signal: currentAbortController.signal,
            });

            if (!response.ok) {
                throw new Error(`API request failed with status ${response.status}`);
            }

            const payload = await response.json();
            const payloadScope = normalizeCompareScope(payload?.meta?.comparison_scope ?? currentCompareScope);

            if (payloadScope !== currentCompareScope) {
                currentCompareScope = payloadScope;
                updateCompareScopeToggle();
            }

            updateComparisonRange(payload);
            renderStations(Array.isArray(payload.stations) ? payload.stations : [], {
                restorePopup: restorePopupAfterLoad,
            });
            updateMetaBar(payload);
            updateVisibleStationCount();
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
                showElement(errorElement, 'flex');
            }
        } finally {
            hideElement(loadingElement);
            setMetaLoadingState(false);
            setRangeLoadingState(false);
        }
    };

    const debounceReload = () => {
        if (moveDebounceHandle) {
            window.clearTimeout(moveDebounceHandle);
        }

        moveDebounceHandle = window.setTimeout(() => {
            loadStations({ preservePopup: true });
        }, 250);
    };

    map.on('moveend', debounceReload);
    map.on('zoomend', renderLabels);
    map.on('popupopen', (event) => {
        const popupElement = event?.popup?.getElement?.();

        if (popupElement) {
            popupElement.classList.add('dir-top');
            popupElement.classList.remove('is-opening');
            void popupElement.offsetWidth;
            popupElement.classList.add('is-opening');

            window.setTimeout(() => {
                popupElement.classList.remove('is-opening');
            }, 500);
        }

        const openedStationId = Number.parseInt(event?.popup?._source?.options?.stationId, 10);

        if (Number.isFinite(openedStationId)) {
            activePopupStationId = openedStationId;
        }

        const markerPath = event?.popup?._source?._path;

        if (markerPath) {
            markerPath.classList.remove('marker-pop');
            void markerPath.getBoundingClientRect();
            markerPath.classList.add('marker-pop');

            window.setTimeout(() => {
                markerPath.classList.remove('marker-pop');
            }, 460);
        }
    });
    map.on('popupclose', (event) => {
        if (isRefreshingMarkers) {
            return;
        }

        const closedStationId = Number.parseInt(event?.popup?._source?.options?.stationId, 10);

        if (!Number.isFinite(closedStationId) || closedStationId === activePopupStationId) {
            activePopupStationId = null;
        }
    });

    document.querySelectorAll('[data-fuel]').forEach((button) => {
        button.addEventListener('click', () => {
            const nextFuel = normalizeFuel(button.dataset.fuel || '');

            if (nextFuel === currentFuel) {
                return;
            }

            currentFuel = nextFuel;
            updateFuelButtons();
            loadStations();
        });
    });

    document.querySelector('[data-compare-scope-toggle]')?.addEventListener('click', () => {
        currentCompareScope = currentCompareScope === 'viewport' ? 'austria' : 'viewport';
        updateCompareScopeToggle();
        updateMetaBar();
        loadStations();
    });

    legendToggleElement?.addEventListener('click', () => {
        const collapsed = legendCardElement?.classList.contains('is-collapsed') ?? true;
        setLegendCollapsed(!collapsed);
    });

    legendTierToggleElements.forEach((toggle) => {
        const tier = normalizeTier(toggle.dataset.tierToggle, null);

        if (tier === null) {
            return;
        }

        tierFilterState[tier] = toggle.checked;

        toggle.addEventListener('change', () => {
            tierFilterState[tier] = toggle.checked;
            updateLegendTierUi();
            animateTierFilterChange();
        });
    });

    mobileFilterToggleElement?.addEventListener('click', () => {
        const collapsed = mobileFilterCardElement?.classList.contains('is-collapsed') ?? true;
        setMobileFilterCollapsed(!collapsed);
    });

    document.querySelector('[data-retry-map]')?.addEventListener('click', () => {
        loadStations();
    });

    window.addEventListener('resize', () => {
        setMobileFilterCollapsed(mobileFilterCollapsed);
        updatePriceRange();
        syncBottomOverlayMeasurements();
        queueBottomOverlayMeasurements();
    });

    window.addEventListener('load', () => {
        queueBottomOverlayMeasurements();
    });

    document.fonts?.ready?.then(() => {
        queueBottomOverlayMeasurements();
    }).catch(() => {
        // Ignore font-loading issues and keep the last successful layout measurement.
    });

    updateFuelButtons();
    updateCompareScopeToggle();
    setMobileFilterCollapsed(true);
    updateMetaBar();
    setLegendCollapsed(true);
    updateLegendTierUi();
    updatePriceRange();
    queueBottomOverlayMeasurements();

    if ('geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                map.setView([position.coords.latitude, position.coords.longitude], 12, { animate: false });
                loadStations();
            },
            () => {
                loadStations();
            },
            { timeout: 4000, maximumAge: 60000 },
        );
    } else {
        loadStations();
    }
}
