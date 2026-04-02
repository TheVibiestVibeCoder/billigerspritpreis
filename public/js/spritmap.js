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

    const loadingElement = document.getElementById('map-loading');
    const emptyElement = document.getElementById('map-empty');
    const errorElement = globalErrorElement;
    const metaCardElement = document.getElementById('map-meta');
    const metaCountElement = document.getElementById('map-meta-count');
    const metaScopeElement = document.getElementById('map-meta-scope');
    const legendCardElement = document.querySelector('[data-legend]');
    const legendToggleElement = document.querySelector('[data-legend-toggle]');
    const legendContentElement = document.querySelector('[data-legend-content]');
    const stationCountFormatter = new Intl.NumberFormat('de-AT');

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

    const setMetaLoadingState = (isLoading) => {
        if (!metaCardElement) {
            return;
        }

        metaCardElement.classList.toggle('is-loading', Boolean(isLoading));
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

    const renderLabels = () => {
        priceLabelLayer.clearLayers();

        if (map.getZoom() < 13) {
            return;
        }

        latestStations.forEach((station) => {
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

            window.L.marker([lat, lng], { icon, interactive: false }).addTo(priceLabelLayer);
        });
    };

    const renderStations = (stations) => {
        markerLayer.clearLayers();
        const safeStations = Array.isArray(stations) ? stations : [];
        latestStations = safeStations;
        updateLegendTierCounts(safeStations);

        hideElement(emptyElement);

        if (safeStations.length === 0) {
            showElement(emptyElement, 'flex');
            renderLabels();

            return;
        }

        safeStations.forEach((station) => {
            const lat = Number.parseFloat(station.latitude);
            const lng = Number.parseFloat(station.longitude);

            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }

            const color = station.price_color || '#EAB308';

            const marker = window.L.circleMarker([lat, lng], {
                radius: 8,
                color: '#FFFFFF',
                weight: 2,
                fillColor: color,
                fillOpacity: 0.95,
            });

            marker.on('mouseover', () => marker.setStyle({ radius: 11 }));
            marker.on('mouseout', () => marker.setStyle({ radius: 8 }));
            marker.bindPopup(buildPopupHtml(station), {
                maxWidth: 280,
                className: 'spritmap-popup',
                autoPan: false,
                closeButton: true,
                closeOnClick: true,
                autoClose: true,
                offset: window.L.point(0, -8),
            });
            marker.addTo(markerLayer);
        });

        renderLabels();
    };

    const updateFuelButtons = () => {
        document.querySelectorAll('[data-fuel]').forEach((button) => {
            const isActive = (button.dataset.fuel || '').toUpperCase() === currentFuel;

            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });
    };

    const updateCompareScopeToggle = () => {
        const toggleButton = document.querySelector('[data-compare-scope-toggle]');

        if (!toggleButton) {
            return;
        }

        toggleButton.dataset.compareScope = currentCompareScope;
        toggleButton.classList.toggle('is-active', currentCompareScope === 'austria');
        toggleButton.setAttribute('aria-pressed', String(currentCompareScope === 'austria'));
        toggleButton.textContent = compareScopeLabels[currentCompareScope] ?? compareScopeLabels.viewport;
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

    const loadStations = async () => {
        if (currentAbortController) {
            currentAbortController.abort();
        }

        currentAbortController = new AbortController();

        showElement(loadingElement, 'flex');
        hideElement(errorElement);
        hideElement(emptyElement);
        setMetaLoadingState(true);

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

            renderStations(Array.isArray(payload.stations) ? payload.stations : []);
            updateMetaBar(payload);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
                showElement(errorElement, 'flex');
            }
        } finally {
            hideElement(loadingElement);
            setMetaLoadingState(false);
        }
    };

    const debounceReload = () => {
        if (moveDebounceHandle) {
            window.clearTimeout(moveDebounceHandle);
        }

        moveDebounceHandle = window.setTimeout(() => {
            loadStations();
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

    document.querySelector('[data-retry-map]')?.addEventListener('click', () => {
        loadStations();
    });

    updateFuelButtons();
    updateCompareScopeToggle();
    updateMetaBar();
    setLegendCollapsed(true);

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

