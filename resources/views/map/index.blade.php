<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Spritmap.at - Echtzeit-Spritpreisvergleich Österreich</title>

        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        >
        <script
            src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""
        ></script>
        <script defer src="{{ asset('js/spritmap.js') }}?v={{ file_exists(public_path('js/spritmap.js')) ? filemtime(public_path('js/spritmap.js')) : 1 }}"></script>

        <style>
            :root {
                --safe-top: env(safe-area-inset-top, 0px);
                --safe-bottom: env(safe-area-inset-bottom, 0px);
                --top-card-top: calc(var(--safe-top) + 0.75rem);
                --top-card-height: 64px;
                --range-card-space: 112px;
                --range-card-height: 84px;
                --spritmap-surface: rgba(255, 255, 255, 0.90);
                --spritmap-surface-strong: rgba(255, 255, 255, 0.96);
                --spritmap-border: rgba(8, 50, 84, 0.13);
                --spritmap-shadow: 0 14px 34px rgba(5, 25, 45, 0.14);
                --spritmap-text-main: #08253a;
                --spritmap-text-subtle: #4b6578;
                --spritmap-brand: #0f766e;
                --spritmap-brand-soft: #0ea5e9;
                --spritmap-route: #1d4ed8;
            }

            html,
            body {
                height: 100%;
                margin: 0;
                overflow: hidden;
                font-family: 'Trebuchet MS', 'Candara', 'Segoe UI', sans-serif;
                color: var(--spritmap-text-main);
                background: #dbe8f4;
            }

            #spritmap-shell {
                position: relative;
                height: 100vh;
                height: 100dvh; /* dynamic viewport height — prevents browser chrome from clipping bottom elements on mobile */
                width: 100vw;
                isolation: isolate;
            }

            #spritmap-shell::before {
                content: '';
                position: absolute;
                inset: 0;
                z-index: 600;
                pointer-events: none;
                background: linear-gradient(
                    180deg,
                    rgba(9, 26, 43, 0.20) 0%,
                    rgba(9, 26, 43, 0.00) 24%,
                    rgba(9, 26, 43, 0.05) 100%
                );
            }

            #map {
                height: 100%;
                width: 100%;
                background: #dfe7ef;
            }

            .ui-card {
                background: var(--spritmap-surface);
                border: 1px solid var(--spritmap-border);
                border-radius: 16px;
                box-shadow: var(--spritmap-shadow);
                backdrop-filter: blur(10px) saturate(1.15);
            }

            .mobile-top-banner {
                position: static;
            }

            .brand-card {
                position: absolute;
                top: var(--top-card-top);
                left: 0.75rem;
                z-index: 900;
                min-height: var(--top-card-height);
                padding: 0.46rem 0.68rem;
                max-width: min(10.5rem, calc(100vw - 11.5rem));
            }

            .brand-title {
                margin: 0;
                font-size: 0.96rem;
                line-height: 1.1;
                font-weight: 800;
                color: var(--spritmap-brand);
                letter-spacing: 0.01em;
                text-transform: lowercase;
            }

            .meta-card {
                position: absolute;
                top: var(--top-card-top);
                right: 0.9rem;
                z-index: 900;
                min-height: var(--top-card-height);
                padding: 0.45rem 0.72rem;
                display: inline-flex;
                align-items: center;
                gap: 0.36rem;
                font-size: 0.74rem;
                color: var(--spritmap-text-subtle);
            }

            .meta-card.is-loading {
                opacity: 0.72;
            }

            .meta-value {
                font-weight: 700;
                color: #114a68;
            }

            .meta-divider {
                color: #87a0b2;
            }

            .filter-card {
                position: absolute;
                top: var(--top-card-top);
                left: 50%;
                transform: translateX(-50%);
                z-index: 900;
                min-height: var(--top-card-height);
                padding: 0.34rem;
                width: min(41rem, calc(100vw - 22rem));
                min-width: 23rem;
                display: flex;
                align-items: center;
            }

            .mobile-filter-toggle {
                display: none;
            }

            .mobile-filter-panel {
                display: flex;
                flex: 1 1 auto;
                width: 100%;
                min-width: 0;
                height: auto;
                align-items: center;
            }

            .mobile-filter-panel-inner {
                overflow: visible;
                width: 100%;
                height: auto;
            }

            .filter-toolbar {
                --filter-gap: 0.45rem;
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                align-items: center;
                gap: var(--filter-gap);
                width: 100%;
                height: auto;
            }

            .fuel-filter {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-column: 1 / span 2;
                gap: var(--filter-gap);
                min-width: 0;
                align-items: center;
                height: auto;
            }

            .fuel-button,
            .scope-toggle {
                min-height: 48px;
                height: auto;
                border: 0;
                border-radius: 11px;
                font-size: 0.92rem;
                font-weight: 700;
                line-height: 1;
                cursor: pointer;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
                transition: transform 160ms ease, background-color 160ms ease, color 160ms ease;
                color: #09243a;
                background: rgba(235, 243, 249, 0.95);
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .fuel-button {
                flex: none;
                width: 100%;
                padding: 0.58rem 0.65rem;
            }

            .scope-toggle {
                grid-column: 3;
                width: 100%;
                min-width: 0;
                padding: 0.58rem 0.78rem;
                background: rgba(196, 229, 251, 0.88);
                white-space: nowrap;
            }

            .fuel-button:hover,
            .scope-toggle:hover {
                transform: translateY(-1px);
                background: #def0fc;
            }

            .fuel-button.is-active {
                color: #ffffff;
                background: linear-gradient(135deg, #0f766e, #0f9a91);
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3);
            }

            .scope-toggle.is-active {
                color: #ffffff;
                background: linear-gradient(135deg, #0284c7, #0ea5e9);
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3);
            }

            .fuel-button:focus-visible,
            .scope-toggle:focus-visible,
            .mobile-filter-toggle:focus-visible,
            .legend-toggle:focus-visible,
            .popup-route:focus-visible,
            .state-card button:focus-visible {
                outline: 2px solid #0ea5e9;
                outline-offset: 2px;
            }

            .legend-card {
                position: absolute;
                bottom: calc(var(--safe-bottom) + var(--range-card-space));
                left: 0.9rem;
                z-index: 900;
                padding: 0.54rem 0.58rem;
                width: min(14.5rem, calc(100vw - 11.2rem));
            }

            .legend-toggle {
                width: 100%;
                border: 0;
                background: transparent;
                color: #0f4f69;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.56rem;
                cursor: pointer;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
                text-align: left;
                padding: 0.18rem 0.2rem;
                border-radius: 10px;
                min-height: 44px;
                transition: background-color 180ms ease;
            }

            .legend-toggle:hover {
                background: rgba(14, 165, 233, 0.10);
            }

            .legend-title {
                margin: 0;
                font-size: 0.74rem;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                font-weight: 800;
            }

            .legend-chevron {
                width: 18px;
                height: 18px;
                border-radius: 999px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #0f4f69;
                background: rgba(14, 165, 233, 0.14);
                transition: transform 380ms cubic-bezier(0.22, 1, 0.36, 1), background-color 180ms ease;
            }

            .legend-chevron svg {
                width: 12px;
                height: 12px;
                fill: currentColor;
            }

            .legend-content {
                display: grid;
                grid-template-rows: 1fr;
                opacity: 1;
                transition: grid-template-rows 380ms cubic-bezier(0.22, 1, 0.36, 1), opacity 280ms ease;
            }

            .legend-content-inner {
                overflow: hidden;
            }

            .legend-list {
                margin: 0.58rem 0 0;
                padding: 0;
                list-style: none;
                display: grid;
                gap: 0.34rem;
                opacity: 1;
                transform: translateY(0);
                transition: opacity 220ms ease, transform 300ms ease;
            }

            .legend-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.46rem;
                padding: 0.16rem 0.08rem;
                border-radius: 10px;
                font-size: 0.76rem;
                color: var(--spritmap-text-subtle);
                transition: opacity 180ms ease, filter 180ms ease;
            }

            .legend-left {
                display: inline-flex;
                align-items: center;
                gap: 0.42rem;
                min-width: 0;
            }

            .legend-right {
                display: inline-flex;
                align-items: center;
                gap: 0.34rem;
                flex-shrink: 0;
            }

            .legend-dot {
                width: 12px;
                height: 12px;
                border-radius: 999px;
                border: 2px solid #ffffff;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.16);
            }

            .legend-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 1.75rem;
                padding: 0.08rem 0.34rem;
                border-radius: 999px;
                font-size: 0.7rem;
                font-weight: 700;
                color: #0f4f69;
                background: rgba(14, 165, 233, 0.16);
            }

            .legend-switch {
                position: relative;
                display: inline-flex;
                width: 40px;
                height: 24px;
                cursor: pointer;
                flex-shrink: 0;
            }

            .legend-switch-input {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                margin: 0;
                opacity: 0;
                cursor: pointer;
                z-index: 1;
            }

            .legend-switch-track {
                position: relative;
                width: 100%;
                height: 100%;
                border-radius: 999px;
                background: #c8d7e3;
                box-shadow: inset 0 0 0 1px rgba(85, 110, 131, 0.24);
                transition: background-color 180ms ease, box-shadow 180ms ease;
            }

            .legend-switch-track::after {
                content: '';
                position: absolute;
                top: 3px;
                left: 3px;
                width: 18px;
                height: 18px;
                border-radius: 999px;
                background: #ffffff;
                box-shadow: 0 1px 3px rgba(6, 27, 43, 0.36);
                transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1);
            }

            .legend-switch-input:checked + .legend-switch-track {
                background: linear-gradient(135deg, #0f766e, #0ea5e9);
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.34);
            }

            .legend-switch-input:checked + .legend-switch-track::after {
                transform: translateX(16px);
            }

            .legend-switch-input:focus-visible + .legend-switch-track {
                outline: 2px solid #0ea5e9;
                outline-offset: 2px;
            }

            .legend-item.is-disabled {
                opacity: 0.56;
            }

            .legend-item.is-disabled .legend-left {
                filter: saturate(0.64);
            }

            .legend-item.is-disabled .legend-count {
                color: #6e8191;
                background: rgba(148, 163, 184, 0.20);
            }

            .legend-card.is-collapsed .legend-chevron {
                transform: rotate(-90deg);
                background: rgba(148, 163, 184, 0.20);
            }

            .legend-card.is-collapsed .legend-content {
                grid-template-rows: 0fr;
                opacity: 0.65;
            }

            .legend-card.is-collapsed .legend-list {
                opacity: 0;
                transform: translateY(-0.32rem);
            }

            @media (min-width: 821px) {
                .legend-card.is-collapsed {
                    box-sizing: border-box;
                    height: var(--range-card-height);
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                }

                .legend-card.is-collapsed .legend-toggle {
                    min-height: 100%;
                    height: 100%;
                }
            }

            .range-card {
                position: absolute;
                left: 50%;
                bottom: calc(var(--safe-bottom) + 0.75rem);
                transform: translateX(-50%);
                z-index: 910;
                width: min(46rem, calc(100vw - 2rem));
                padding: 0.86rem 1rem 0.98rem;
                display: grid;
                gap: 0.72rem;
                pointer-events: none;
                --range-dot-size: clamp(0.98rem, 2.3vw, 1.26rem);
                --range-dot-border: 3px;
            }

            .range-card.is-loading {
                opacity: 0.72;
            }

            .range-card-header {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 0.75rem;
            }

            .range-card-title {
                margin: 0;
                font-size: 0.92rem;
                line-height: 1.1;
                font-weight: 800;
                color: #18577a;
                letter-spacing: 0.01em;
            }

            .range-card-caption {
                font-size: 0.76rem;
                line-height: 1;
                font-weight: 700;
                color: #5a7a90;
                white-space: nowrap;
            }

            .range-card-scale {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 0.82rem;
                align-items: center;
            }

            .range-card-value {
                margin: 0;
                font-family: 'Consolas', 'Menlo', 'Monaco', monospace;
                font-size: clamp(1rem, 2.4vw, 1.24rem);
                line-height: 1;
                font-weight: 800;
                letter-spacing: -0.03em;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
                transition: color 180ms ease;
            }

            .range-card-track-shell {
                position: relative;
                min-width: 0;
                height: 2.45rem;
            }

            .range-card-track-shell::before {
                content: '';
                position: absolute;
                inset: 0.08rem 0;
                border-radius: 999px;
                background: repeating-linear-gradient(
                    to right,
                    rgba(94, 118, 136, 0.00) 0 0.5rem,
                    rgba(94, 118, 136, 0.30) 0.5rem 0.68rem
                );
                opacity: 0.95;
            }

            .range-card-track-shell::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                top: 50%;
                height: 1px;
                background: rgba(94, 118, 136, 0.14);
                transform: translateY(-50%);
            }

            .range-card-dots {
                position: absolute;
                inset: 0;
                pointer-events: none;
            }

            .range-card-dot {
                position: absolute;
                top: 50%;
                left: var(--dot-position, 50%);
                width: var(--range-dot-size);
                aspect-ratio: 1;
                padding: 0;
                border-radius: 999px;
                background: var(--dot-color, #22C55E);
                border: var(--range-dot-border) solid rgba(255, 255, 255, 0.96);
                box-sizing: border-box;
                box-shadow: 0 6px 14px rgba(11, 35, 53, 0.18);
                cursor: pointer;
                pointer-events: auto;
                appearance: none;
                -webkit-appearance: none;
                transform: translate(-50%, -50%);
                transition: transform 180ms ease, box-shadow 180ms ease, filter 180ms ease;
            }

            .range-card-dot.dot-entering {
                animation: range-dot-enter 220ms cubic-bezier(0.22, 1, 0.36, 1);
            }

            .range-card-dot.dot-exiting {
                pointer-events: none;
                animation: range-dot-exit 170ms ease both;
            }

            .range-card-dot:hover,
            .range-card-dot:focus-visible {
                transform: translate(-50%, -50%) scale(1.08);
                box-shadow: 0 8px 18px rgba(11, 35, 53, 0.24);
                filter: saturate(1.08);
            }

            .range-card-dot:focus-visible {
                outline: 2px solid #0ea5e9;
                outline-offset: 2px;
            }

            .range-card.is-dense .range-card-dot {
                box-shadow: none;
            }

            .range-card.is-dense .range-card-dot:hover,
            .range-card.is-dense .range-card-dot:focus-visible {
                box-shadow: none;
            }

            @keyframes range-dot-enter {
                0% {
                    opacity: 0;
                    transform: translate(-50%, -50%) scale(0.62);
                }

                100% {
                    opacity: 1;
                    transform: translate(-50%, -50%) scale(1);
                }
            }

            @keyframes range-dot-exit {
                0% {
                    opacity: 1;
                    transform: translate(-50%, -50%) scale(1);
                }

                100% {
                    opacity: 0;
                    transform: translate(-50%, -50%) scale(0.62);
                }
            }

            .range-card-empty {
                display: none;
                margin: 0;
                font-size: 0.82rem;
                line-height: 1.35;
                color: var(--spritmap-text-subtle);
            }

            .range-card.is-empty .range-card-scale {
                display: none;
            }

            .range-card.is-empty .range-card-empty {
                display: block;
            }

            .leaflet-control-zoom {
                border: 0 !important;
                border-radius: 12px !important;
                overflow: hidden;
                box-shadow: 0 10px 24px rgba(7, 28, 46, 0.18);
            }

            .leaflet-popup-pane {
                z-index: 960 !important;
            }

            .leaflet-control-zoom a {
                width: 34px;
                height: 34px;
                line-height: 34px;
                color: #0d3550;
                background: var(--spritmap-surface-strong);
            }

            .leaflet-control-zoom a:hover {
                background: #e8f3fc;
            }

            .leaflet-control-attribution {
                background: rgba(255, 255, 255, 0.5) !important;
                border-radius: 9px 0 0 0;
                padding: 0.08rem 0.34rem !important;
                font-size: 0.6rem;
                line-height: 1.2;
                color: #5a7384 !important;
                opacity: 0.72;
                transition: opacity 160ms ease, background-color 160ms ease;
            }

            .leaflet-control-attribution:hover {
                opacity: 1;
                background: rgba(255, 255, 255, 0.72) !important;
            }

            .leaflet-control-attribution a {
                color: #4a6f89 !important;
            }

            .leaflet-top.leaflet-right {
                top: 50%;
                transform: translateY(-50%);
            }

            .leaflet-top .leaflet-control {
                margin-top: 0;
            }

            .leaflet-right .leaflet-control {
                margin-right: 0.9rem;
            }

            .leaflet-left .leaflet-control {
                margin-left: 0.9rem;
            }

            .leaflet-bottom .leaflet-control {
                margin-bottom: calc(var(--range-card-space) - 0.3rem);
                transition: margin-bottom 200ms ease;
            }

            .map-state {
                position: absolute;
                inset: 0;
                z-index: 980;
                align-items: center;
                justify-content: center;
                pointer-events: none;
                padding: 1rem;
            }

            .state-card {
                pointer-events: auto;
                max-width: min(26rem, calc(100vw - 2rem));
                padding: 0.95rem 1.08rem;
                border-radius: 12px;
                background: rgba(3, 15, 28, 0.86);
                color: #f8fafc;
                font-size: 0.92rem;
                line-height: 1.38;
                box-shadow: 0 18px 40px rgba(3, 10, 18, 0.3);
            }

            .state-card button {
                margin-top: 0.56rem;
                border: 0;
                border-radius: 9px;
                padding: 0.48rem 0.78rem;
                min-height: 40px;
                font-weight: 700;
                cursor: pointer;
                color: #ffffff;
                background: #1d4ed8;
            }

            .loading-content {
                display: flex;
                align-items: center;
                gap: 0.62rem;
            }

            .spinner {
                width: 1.05rem;
                height: 1.05rem;
                border-radius: 999px;
                border: 2px solid rgba(255, 255, 255, 0.35);
                border-top-color: #ffffff;
                animation: spin 0.8s linear infinite;
            }

            @keyframes spin {
                to {
                    transform: rotate(360deg);
                }
            }

            .price-label-wrap {
                pointer-events: none;
                background: transparent;
            }

            .price-label-wrap.label-entering {
                animation: price-label-enter 220ms cubic-bezier(0.22, 1, 0.36, 1);
            }

            .price-label-wrap.label-exiting {
                animation: price-label-exit 170ms ease both;
            }

            .price-label {
                display: inline-block;
                font-family: 'Consolas', 'Menlo', 'Monaco', monospace;
                font-size: 0.65rem;
                font-weight: 600;
                padding: 0.12rem 0.34rem;
                border-radius: 6px;
                color: #041218;
                border: 1px solid rgba(255, 255, 255, 0.86);
                background: color-mix(in srgb, var(--label-color, #eab308) 28%, #ffffff);
                box-shadow: 0 3px 8px rgba(1, 9, 15, 0.22);
            }

            @keyframes price-label-enter {
                0% {
                    opacity: 0;
                    transform: translateY(0.18rem) scale(0.94);
                }

                100% {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }

            @keyframes price-label-exit {
                0% {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }

                100% {
                    opacity: 0;
                    transform: translateY(0.14rem) scale(0.94);
                }
            }

            .spritmap-popup .leaflet-popup-content-wrapper {
                border-radius: 22px;
                border: 1px solid #dddede;
                box-shadow: 0 18px 40px rgba(12, 14, 16, 0.24);
                background: #f5f5f5;
                overflow: hidden;
            }

            .spritmap-popup .leaflet-popup-content {
                margin: 0;
                width: auto !important;
            }

            .spritmap-popup .leaflet-popup-tip {
                background: #f5f5f5;
                border: 1px solid #dddede;
                box-shadow: 0 10px 22px rgba(6, 20, 31, 0.2);
            }

            .spritmap-popup .leaflet-popup-close-button {
                top: 0.5rem;
                right: 0.5rem;
                width: 44px;
                height: 38px;
                padding: 0 0 2px;
                border-radius: 14px;
                line-height: 35px;
                font-size: 1.05rem;
                font-weight: 400;
                text-align: center;
                color: #d1d1d1;
                background: #f0f0f0;
                border: 1px solid #dddddd;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
                transition: background-color 180ms ease, transform 180ms ease;
            }

            .spritmap-popup .leaflet-popup-close-button:hover {
                color: #b7b7b7;
                background: #ececec;
                transform: scale(1.04);
            }

            .spritmap-popup.dir-top.is-opening .leaflet-popup-content-wrapper {
                animation: popup-entry-top 460ms cubic-bezier(0.2, 0.85, 0.2, 1) both;
            }

            .spritmap-popup.dir-bottom.is-opening .leaflet-popup-content-wrapper {
                animation: popup-entry-bottom 460ms cubic-bezier(0.2, 0.85, 0.2, 1) both;
            }

            .spritmap-popup.dir-left.is-opening .leaflet-popup-content-wrapper {
                animation: popup-entry-left 460ms cubic-bezier(0.2, 0.85, 0.2, 1) both;
            }

            .spritmap-popup.dir-right.is-opening .leaflet-popup-content-wrapper {
                animation: popup-entry-right 460ms cubic-bezier(0.2, 0.85, 0.2, 1) both;
            }

            @keyframes popup-entry-top {
                0% {
                    opacity: 0;
                    transform: translateY(10px) scale(0.94);
                    box-shadow: 0 8px 18px rgba(6, 20, 31, 0.16);
                }

                70% {
                    opacity: 1;
                    transform: translateY(-2px) scale(1.012);
                    box-shadow: 0 24px 44px rgba(6, 20, 31, 0.3);
                }

                100% {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                    box-shadow: 0 20px 36px rgba(6, 20, 31, 0.26);
                }
            }

            @keyframes popup-entry-bottom {
                0% {
                    opacity: 0;
                    transform: translateY(-10px) scale(0.94);
                    box-shadow: 0 8px 18px rgba(6, 20, 31, 0.16);
                }

                70% {
                    opacity: 1;
                    transform: translateY(2px) scale(1.012);
                    box-shadow: 0 24px 44px rgba(6, 20, 31, 0.3);
                }

                100% {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                    box-shadow: 0 20px 36px rgba(6, 20, 31, 0.26);
                }
            }

            @keyframes popup-entry-left {
                0% {
                    opacity: 0;
                    transform: translateX(10px) scale(0.94);
                    box-shadow: 0 8px 18px rgba(6, 20, 31, 0.16);
                }

                70% {
                    opacity: 1;
                    transform: translateX(-2px) scale(1.012);
                    box-shadow: 0 24px 44px rgba(6, 20, 31, 0.3);
                }

                100% {
                    opacity: 1;
                    transform: translateX(0) scale(1);
                    box-shadow: 0 20px 36px rgba(6, 20, 31, 0.26);
                }
            }

            @keyframes popup-entry-right {
                0% {
                    opacity: 0;
                    transform: translateX(-10px) scale(0.94);
                    box-shadow: 0 8px 18px rgba(6, 20, 31, 0.16);
                }

                70% {
                    opacity: 1;
                    transform: translateX(2px) scale(1.012);
                    box-shadow: 0 24px 44px rgba(6, 20, 31, 0.3);
                }

                100% {
                    opacity: 1;
                    transform: translateX(0) scale(1);
                    box-shadow: 0 20px 36px rgba(6, 20, 31, 0.26);
                }
            }

            .popup-card {
                font-family: 'Trebuchet MS', 'Candara', 'Segoe UI', sans-serif;
                display: flex;
                align-items: stretch;
                width: min(19rem, calc(100vw - 2rem));
                min-width: 0;
                padding: 0;
                --popup-accent: #eab308;
            }

            .popup-left {
                flex: 1 1 0;
                min-width: 0;
                padding: 0.55rem 0.6rem;
                display: flex;
                flex-direction: column;
                gap: 0.22rem;
            }

            .popup-right {
                flex: 0 0 auto;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: space-between;
                padding: 0.55rem 0.65rem;
                border-left: 1px solid #e2e2e2;
                min-width: 5.2rem;
                text-align: center;
            }

            .popup-name {
                margin: 0;
                font-size: 0.78rem;
                font-weight: 800;
                line-height: 1.2;
                color: #14171b;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .popup-name-rest {
                font-weight: 500;
                color: #5a5a5a;
            }

            .popup-address {
                margin: 0;
                font-size: 0.64rem;
                line-height: 1.3;
                color: #8c8c8c;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .popup-bottom-row {
                margin: 0;
                display: flex;
                align-items: center;
                gap: 0.4rem;
                flex-wrap: wrap;
            }

            .popup-status {
                display: inline-flex;
                align-items: center;
                gap: 0.22rem;
                font-size: 0.62rem;
                font-weight: 700;
            }

            .popup-status-dot {
                width: 0.42rem;
                height: 0.42rem;
                border-radius: 999px;
                background: currentColor;
                flex-shrink: 0;
            }

            .popup-status.open  { color: #16a34a; }
            .popup-status.closed { color: #dc2626; }

            .popup-sub-prices {
                font-size: 0.6rem;
                color: #a5a5a5;
                white-space: nowrap;
            }

            .popup-price-big {
                margin: 0;
                font-size: 1.45rem;
                font-weight: 800;
                letter-spacing: -0.02em;
                line-height: 1;
            }

            .popup-price-meta {
                margin: 0.1rem 0 0;
                font-size: 0.56rem;
                font-weight: 600;
                color: #b0b0b0;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }

            .popup-route {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-top: 0.4rem;
                border-radius: 8px;
                padding: 0.3rem 0.5rem;
                font-size: 0.66rem;
                font-weight: 800;
                color: #ffffff !important;
                text-decoration: none;
                background: #111111;
                white-space: nowrap;
            }

            .popup-route:visited,
            .popup-route:hover,
            .popup-route:active,
            .popup-route:focus {
                color: #ffffff !important;
                text-decoration: none;
            }

            .leaflet-interactive.marker-pop {
                transform-box: fill-box;
                transform-origin: center;
                animation: marker-pop 420ms cubic-bezier(0.22, 1, 0.36, 1);
            }

            .leaflet-interactive.marker-filterable {
                transform-box: fill-box;
                transform-origin: center;
            }

            .leaflet-interactive.marker-entering {
                animation: marker-filter-enter 220ms cubic-bezier(0.22, 1, 0.36, 1);
            }

            .leaflet-interactive.marker-exiting {
                pointer-events: none;
                animation: marker-filter-exit 170ms ease both;
            }

            @keyframes marker-filter-enter {
                0% {
                    opacity: 0;
                    transform: scale(0.56);
                }

                100% {
                    opacity: 1;
                    transform: scale(1);
                }
            }

            @keyframes marker-filter-exit {
                0% {
                    opacity: 1;
                    transform: scale(1);
                }

                100% {
                    opacity: 0;
                    transform: scale(0.56);
                }
            }

            @keyframes marker-pop {
                0% {
                    transform: scale(1);
                    stroke-width: 2;
                }

                45% {
                    transform: scale(1.42);
                    stroke-width: 2.6;
                }

                100% {
                    transform: scale(1);
                    stroke-width: 2;
                }
            }

            .brand-card {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                justify-content: center;
                gap: 0.15rem;
            }

            .brand-top {
                display: flex;
                align-items: center;
                gap: 0.4rem;
            }

            .brand-updated {
                font-size: 0.6rem;
                color: var(--spritmap-text-subtle);
                letter-spacing: 0.01em;
                line-height: 1;
                opacity: 0;
                transition: opacity 400ms ease;
            }

            .brand-updated.is-visible {
                opacity: 1;
            }

            .info-btn {
                flex-shrink: 0;
                width: 18px;
                height: 18px;
                border-radius: 999px;
                border: 1.5px solid var(--spritmap-brand);
                background: transparent;
                color: var(--spritmap-brand);
                font-size: 0.64rem;
                font-weight: 800;
                line-height: 1;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                transition: background-color 160ms ease, color 160ms ease;
            }

            .info-btn:hover {
                background: var(--spritmap-brand);
                color: #ffffff;
            }

            .info-overlay {
                position: fixed;
                inset: 0;
                z-index: 1100;
                background: rgba(5, 20, 35, 0.55);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: calc(env(safe-area-inset-top, 0px) + 1rem) calc(env(safe-area-inset-right, 0px) + 1rem) calc(env(safe-area-inset-bottom, 0px) + 1rem) calc(env(safe-area-inset-left, 0px) + 1rem);
                backdrop-filter: blur(3px);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            .info-overlay.hidden {
                display: none;
            }

            .info-modal {
                background: #ffffff;
                border-radius: 18px;
                box-shadow: 0 24px 56px rgba(4, 16, 28, 0.28);
                padding: 1.4rem 1.5rem 1.2rem;
                max-width: min(22rem, calc(100vw - 2rem));
                max-height: calc(100dvh - 4rem);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
                width: 100%;
                position: relative;
            }

            .info-modal-title {
                margin: 0 0 0.7rem;
                font-size: 1rem;
                font-weight: 800;
                color: var(--spritmap-brand);
            }

            .info-modal p {
                margin: 0 0 0.55rem;
                font-size: 0.85rem;
                line-height: 1.45;
                color: #1a3344;
            }

            .info-modal .info-note {
                margin-top: 0.9rem;
                padding-top: 0.75rem;
                border-top: 1px solid #e5edf3;
                font-size: 0.75rem;
                color: #7a95a8;
            }

            .info-modal-close {
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
                border: 0;
                background: transparent;
                font-size: 1.1rem;
                color: #aab8c2;
                cursor: pointer;
                line-height: 1;
                padding: 0;
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 10px;
                touch-action: manipulation;
                -webkit-tap-highlight-color: transparent;
                transition: background-color 160ms ease;
            }

            .info-modal-close:hover {
                background: #f0f5f8;
            }

            .hidden {
                display: none;
            }

            @media (prefers-reduced-motion: reduce) {
                .legend-content,
                .legend-list,
                .legend-chevron,
                .mobile-filter-panel,
                .mobile-filter-panel-inner,
                .mobile-filter-toggle-chevron,
                .spritmap-popup .leaflet-popup-content-wrapper,
                .leaflet-interactive.marker-pop,
                .leaflet-interactive.marker-entering,
                .leaflet-interactive.marker-exiting,
                .price-label-wrap.label-entering,
                .price-label-wrap.label-exiting,
                .range-card-dot.dot-entering,
                .range-card-dot.dot-exiting {
                    transition: none;
                    animation: none;
                }
            }

            @media (max-width: 1120px) {
                .filter-card {
                    top: calc(var(--safe-top) + 5.35rem);
                    width: min(40rem, calc(100vw - 2rem));
                    min-width: 0;
                }
            }

            @media (max-width: 820px) {
                .mobile-top-banner {
                    position: absolute;
                    top: calc(var(--safe-top) + 0.4rem);
                    left: 0.5rem;
                    right: 0.5rem;
                    z-index: 905;
                    display: grid;
                    grid-template-columns: minmax(0, 1fr) auto;
                    align-items: start;
                    gap: 0.45rem 0.58rem;
                    padding: 0.5rem 0.58rem 0.56rem;
                    background: var(--spritmap-surface-strong);
                    border: 1px solid var(--spritmap-border);
                    border-radius: 16px;
                    box-shadow: 0 14px 30px rgba(8, 32, 50, 0.2);
                    backdrop-filter: blur(10px) saturate(1.15);
                }

                .mobile-top-banner .brand-card,
                .mobile-top-banner .meta-card,
                .mobile-top-banner .filter-card {
                    position: static;
                    top: auto;
                    right: auto;
                    left: auto;
                    transform: none;
                    z-index: auto;
                    width: auto;
                    min-width: 0;
                    max-width: none;
                    min-height: 0;
                    height: auto;
                    margin: 0;
                    padding: 0;
                    background: transparent;
                    border: 0;
                    border-radius: 0;
                    box-shadow: none;
                    backdrop-filter: none;
                }

                .mobile-top-banner .brand-card {
                    align-self: center;
                }

                .mobile-top-banner .brand-title {
                    font-size: 0.9rem;
                }

                .mobile-top-banner .meta-card {
                    justify-self: end;
                    align-self: center;
                    font-size: 0.7rem;
                    gap: 0.28rem;
                    white-space: nowrap;
                }

                .mobile-top-banner .filter-card {
                    grid-column: 1 / -1;
                    display: block;
                }

                .mobile-top-banner .mobile-filter-toggle {
                    width: 100%;
                    border: 0;
                    border-radius: 11px;
                    min-height: 44px;
                    padding: 0.44rem 0.56rem;
                    background: rgba(213, 232, 247, 0.88);
                    color: #0a344f;
                    display: inline-flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 0.52rem;
                    cursor: pointer;
                    touch-action: manipulation;
                    -webkit-tap-highlight-color: transparent;
                    transition: background-color 200ms ease, transform 200ms ease;
                }

                .mobile-top-banner .mobile-filter-toggle:hover {
                    background: #d4ebfa;
                    transform: translateY(-1px);
                }

                .mobile-filter-toggle-text {
                    display: grid;
                    gap: 0.05rem;
                    min-width: 0;
                    text-align: left;
                }

                .mobile-filter-toggle-title {
                    font-size: 0.7rem;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    font-weight: 800;
                    color: #256185;
                }

                .mobile-filter-toggle-summary {
                    font-size: 0.8rem;
                    font-weight: 700;
                    color: #0a344f;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }

                .mobile-filter-toggle-chevron {
                    width: 20px;
                    height: 20px;
                    border-radius: 999px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    color: #0f4f69;
                    background: rgba(14, 165, 233, 0.18);
                    flex-shrink: 0;
                    transition: transform 360ms cubic-bezier(0.22, 1, 0.36, 1), background-color 180ms ease;
                }

                .mobile-filter-toggle-chevron svg {
                    width: 12px;
                    height: 12px;
                    fill: currentColor;
                }

                .mobile-top-banner .mobile-filter-panel {
                    display: grid;
                    grid-template-rows: 1fr;
                    opacity: 1;
                    transition: grid-template-rows 360ms cubic-bezier(0.22, 1, 0.36, 1), opacity 220ms ease;
                    height: auto;
                    overflow: hidden;
                }

                .mobile-top-banner .mobile-filter-panel-inner {
                    overflow: hidden;
                    padding-top: 0.4rem;
                    transition: padding-top 240ms ease, transform 320ms cubic-bezier(0.22, 1, 0.36, 1), opacity 220ms ease;
                    transform: translateY(0);
                    height: auto;
                    opacity: 1;
                }

                .mobile-top-banner .filter-card.is-collapsed .mobile-filter-toggle-chevron {
                    transform: rotate(-90deg);
                    background: rgba(148, 163, 184, 0.24);
                }

                .mobile-top-banner .filter-card.is-collapsed .mobile-filter-panel {
                    grid-template-rows: 0fr;
                    opacity: 0;
                    pointer-events: none;
                }

                .mobile-top-banner .filter-card.is-collapsed .mobile-filter-panel-inner {
                    padding-top: 0;
                    transform: translateY(-0.24rem);
                    opacity: 0;
                }

                .mobile-top-banner .filter-toolbar {
                    display: flex;
                    flex-direction: column;
                    gap: 0.42rem;
                }

                .mobile-top-banner .fuel-filter {
                    display: flex;
                    gap: 0.42rem;
                    width: 100%;
                    align-items: stretch;
                }

                .mobile-top-banner .fuel-button,
                .mobile-top-banner .scope-toggle {
                    height: auto;
                    min-height: 46px;
                    font-size: 0.92rem;
                }

                .mobile-top-banner .scope-toggle {
                    width: 100%;
                    min-width: 0;
                    white-space: normal;
                }

                .mobile-top-banner .fuel-button {
                    flex: 1 1 0;
                    width: auto;
                    min-width: 0;
                    justify-content: center;
                }

                .legend-card {
                    bottom: calc(var(--safe-bottom) + var(--range-card-space));
                    left: 0.5rem;
                    width: min(16rem, calc(100vw - 1rem));
                    padding: 0.65rem 0.72rem;
                }

                .range-card {
                    left: 0.5rem;
                    right: 0.5rem;
                    bottom: calc(var(--safe-bottom) + 0.55rem);
                    transform: none;
                    width: auto;
                    padding: 0.74rem 0.82rem 0.82rem;
                    gap: 0.6rem;
                }

                .range-card-title {
                    font-size: 0.86rem;
                }

                .range-card-caption {
                    font-size: 0.72rem;
                }

                .range-card-scale {
                    gap: 0.62rem;
                }

                .range-card-track-shell {
                    height: 2.15rem;
                }

                .legend-title {
                    margin-bottom: 0.42rem;
                    font-size: 0.72rem;
                }

                .legend-item {
                    font-size: 0.72rem;
                }

                .leaflet-right .leaflet-control {
                    margin-right: 0.52rem;
                }

                .map-state {
                    justify-content: flex-start;
                    padding-top: calc(var(--safe-top) + 8rem);
                }

                .state-card {
                    width: min(24.5rem, calc(100vw - 1rem));
                }

                .popup-card {
                    width: min(17rem, calc(100vw - 2rem));
                }
            }

            @media (max-width: 560px) {
                .mobile-top-banner {
                    left: 0.45rem;
                    right: 0.45rem;
                    padding: 0.46rem 0.5rem 0.52rem;
                    gap: 0.38rem 0.48rem;
                }

                .mobile-top-banner .brand-title {
                    font-size: 0.86rem;
                }

                .mobile-top-banner .meta-card {
                    font-size: 0.66rem;
                    gap: 0.2rem;
                }

                .mobile-filter-toggle-summary {
                    font-size: 0.75rem;
                }

                .leaflet-control-attribution {
                    font-size: 0.56rem;
                    padding: 0.04rem 0.28rem !important;
                    background: rgba(255, 255, 255, 0.42) !important;
                }

                .legend-card {
                    left: 0.5rem;
                    right: 0.5rem;
                    bottom: calc(var(--safe-bottom) + var(--range-card-space));
                    width: auto;
                }

                .range-card {
                    left: 0.45rem;
                    right: 0.45rem;
                    bottom: calc(var(--safe-bottom) + 0.48rem);
                    padding: 0.7rem 0.76rem 0.76rem;
                    gap: 0.54rem;
                }

                .range-card-header {
                    gap: 0.5rem;
                }

                .range-card-title {
                    font-size: 0.8rem;
                }

                .range-card-caption {
                    font-size: 0.68rem;
                }

                .range-card-value {
                    font-size: 0.96rem;
                }

                .range-card-track-shell::before {
                    background: repeating-linear-gradient(
                        to right,
                        rgba(94, 118, 136, 0.00) 0 0.4rem,
                        rgba(94, 118, 136, 0.28) 0.4rem 0.56rem
                    );
                }

                .legend-list {
                    grid-template-columns: 1fr 1fr;
                    gap: 0.38rem 0.5rem;
                }

                .legend-item {
                    gap: 0.34rem;
                    font-size: 0.7rem;
                }

                .legend-right {
                    gap: 0.28rem;
                }

                .legend-count {
                    min-width: 1.55rem;
                    padding: 0.08rem 0.26rem;
                    font-size: 0.62rem;
                }

                .legend-switch {
                    width: 34px;
                    height: 20px;
                }

                .legend-switch-track::after {
                    top: 2px;
                    left: 2px;
                    width: 16px;
                    height: 16px;
                }

                .legend-switch-input:checked + .legend-switch-track::after {
                    transform: translateX(14px);
                }
            }

            @media (max-width: 400px) {
                .mobile-top-banner {
                    left: 0.35rem;
                    right: 0.35rem;
                }

                .mobile-top-banner .meta-card {
                    font-size: 0.62rem;
                }

                .legend-card {
                    left: 0.35rem;
                    right: 0.35rem;
                }

                .range-card {
                    left: 0.35rem;
                    right: 0.35rem;
                }
            }
        </style>
    </head>
    <body>
        <main id="spritmap-shell">
            <div
                id="map"
                data-api-url="{{ route('api.stations') }}"
                data-boundary-url="{{ route('api.austria-boundary') }}"
                data-center-lat="{{ $mapCenter['lat'] }}"
                data-center-lng="{{ $mapCenter['lng'] }}"
                data-zoom="{{ $mapZoom }}"
                data-initial-fuel="{{ $initialFuel }}"
                data-initial-compare-scope="{{ $initialCompareScope }}"
            ></div>

            <section class="mobile-top-banner">
                <section class="ui-card brand-card">
                    <div class="brand-top">
                        <h1 class="brand-title">spritmap.at</h1>
                        <button class="info-btn" type="button" aria-label="Info" onclick="document.getElementById('info-overlay').classList.remove('hidden')">i</button>
                    </div>
                    <span class="brand-updated" id="brand-updated" aria-live="polite"></span>
                </section>

                <section id="map-meta" class="ui-card meta-card" aria-live="polite">
                    <span>Stationen</span>
                    <span id="map-meta-count" class="meta-value">-</span>
                    <span class="meta-divider">|</span>
                    <span id="map-meta-scope" class="meta-value">Ansicht</span>
                </section>

                <section class="ui-card filter-card" aria-label="Kraftstofffilter" data-mobile-filter-card>
                    <button
                        type="button"
                        class="mobile-filter-toggle"
                        data-mobile-filter-toggle
                        aria-expanded="true"
                        aria-controls="mobile-filter-panel"
                    >
                        <span class="mobile-filter-toggle-text">
                            <span class="mobile-filter-toggle-title">Filter</span>
                            <span class="mobile-filter-toggle-summary" data-mobile-filter-summary>Diesel · Ansicht</span>
                        </span>
                        <span class="mobile-filter-toggle-chevron" aria-hidden="true">
                            <svg viewBox="0 0 20 20">
                                <path d="M5.6 7.1 10 11.5l4.4-4.4 1.2 1.2-5.6 5.6-5.6-5.6z" />
                            </svg>
                        </span>
                    </button>

                    <div
                        id="mobile-filter-panel"
                        class="mobile-filter-panel"
                        data-mobile-filter-panel
                        aria-hidden="false"
                    >
                        <div class="mobile-filter-panel-inner">
                            <x-fuel-filter :fuel="$initialFuel" :compare-scope="$initialCompareScope" />
                        </div>
                    </div>
                </section>
            </section>

            <div id="info-overlay" class="info-overlay hidden" onclick="if(event.target===this)this.classList.add('hidden')">
                <div class="info-modal" role="dialog" aria-modal="true" aria-labelledby="info-modal-title">
                    <button class="info-modal-close" type="button" aria-label="Schließen" onclick="document.getElementById('info-overlay').classList.add('hidden')">×</button>
                    <h2 class="info-modal-title" id="info-modal-title">Was zeigt diese Karte?</h2>
                    <p>Die günstigsten Tankstellen in Österreich — pro Bezirk, in Echtzeit, direkt auf der Karte.</p>
                    <p>Die Preisdaten kommen von der <strong>E-Control API</strong>, der offiziellen Regulierungsbehörde für Energie in Österreich. Tankstellen sind gesetzlich verpflichtet, ihre Preise dort zu melden.</p>
                    <p><strong>Wichtig:</strong> Die API liefert maximal <strong>5 Stationen pro Bezirk</strong> — ausschließlich die günstigsten. Österreich hat ~94 Bezirke, also sind bis zu ~470 Stationen sichtbar. Teure Stationen erscheinen nicht.</p>
                    <p>Die Farbe zeigt den Preisvergleich unter den angezeigten Stationen — von <strong style="color:#22C55E">sehr günstig</strong> bis <strong style="color:#DC2626">vergleichsweise teuer</strong>.</p>
                    <p class="info-note">⚠️ Dies ist eine Testversion. Es kann zu Fehlern oder veralteten Daten kommen.</p>
                </div>
            </div>

            <section class="ui-card legend-card is-collapsed" aria-label="Preislegende" data-legend>
                <button
                    type="button"
                    class="legend-toggle"
                    data-legend-toggle
                    aria-expanded="false"
                    aria-controls="legend-content"
                >
                    <span class="legend-title">Preisniveau</span>
                    <span class="legend-chevron" aria-hidden="true">
                        <svg viewBox="0 0 20 20">
                            <path d="M5.6 7.1 10 11.5l4.4-4.4 1.2 1.2-5.6 5.6-5.6-5.6z" />
                        </svg>
                    </span>
                </button>

                <div id="legend-content" class="legend-content" data-legend-content aria-hidden="true">
                    <div class="legend-content-inner">
                        <ul class="legend-list">
                            <li class="legend-item" data-tier-row="1">
                                <span class="legend-left">
                                    <span class="legend-dot" style="background:#22C55E"></span>
                                    <span>Sehr günstig</span>
                                </span>
                                <span class="legend-right">
                                    <span class="legend-count" data-tier-count="1">0</span>
                                    <label class="legend-switch" aria-label="Sehr günstig anzeigen">
                                        <input class="legend-switch-input" type="checkbox" data-tier-toggle="1" checked>
                                        <span class="legend-switch-track" aria-hidden="true"></span>
                                    </label>
                                </span>
                            </li>
                            <li class="legend-item" data-tier-row="2">
                                <span class="legend-left">
                                    <span class="legend-dot" style="background:#84CC16"></span>
                                    <span>Eher günstig</span>
                                </span>
                                <span class="legend-right">
                                    <span class="legend-count" data-tier-count="2">0</span>
                                    <label class="legend-switch" aria-label="Eher günstig anzeigen">
                                        <input class="legend-switch-input" type="checkbox" data-tier-toggle="2" checked>
                                        <span class="legend-switch-track" aria-hidden="true"></span>
                                    </label>
                                </span>
                            </li>
                            <li class="legend-item" data-tier-row="3">
                                <span class="legend-left">
                                    <span class="legend-dot" style="background:#EAB308"></span>
                                    <span>Durchschnitt</span>
                                </span>
                                <span class="legend-right">
                                    <span class="legend-count" data-tier-count="3">0</span>
                                    <label class="legend-switch" aria-label="Durchschnitt anzeigen">
                                        <input class="legend-switch-input" type="checkbox" data-tier-toggle="3" checked>
                                        <span class="legend-switch-track" aria-hidden="true"></span>
                                    </label>
                                </span>
                            </li>
                            <li class="legend-item" data-tier-row="4">
                                <span class="legend-left">
                                    <span class="legend-dot" style="background:#F97316"></span>
                                    <span>Eher teuer</span>
                                </span>
                                <span class="legend-right">
                                    <span class="legend-count" data-tier-count="4">0</span>
                                    <label class="legend-switch" aria-label="Eher teuer anzeigen">
                                        <input class="legend-switch-input" type="checkbox" data-tier-toggle="4" checked>
                                        <span class="legend-switch-track" aria-hidden="true"></span>
                                    </label>
                                </span>
                            </li>
                            <li class="legend-item" data-tier-row="5">
                                <span class="legend-left">
                                    <span class="legend-dot" style="background:#DC2626"></span>
                                    <span>Sehr teuer</span>
                                </span>
                                <span class="legend-right">
                                    <span class="legend-count" data-tier-count="5">0</span>
                                    <label class="legend-switch" aria-label="Sehr teuer anzeigen">
                                        <input class="legend-switch-input" type="checkbox" data-tier-toggle="5" checked>
                                        <span class="legend-switch-track" aria-hidden="true"></span>
                                    </label>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="ui-card range-card" aria-label="Preisverteilung im Ausschnitt" data-price-range>
                <div class="range-card-header">
                    <p class="range-card-title">Preise im Ausschnitt</p>
                    <span class="range-card-caption" data-range-scope>AT-Vergleich</span>
                </div>

                <div class="range-card-scale">
                    <p class="range-card-value" data-range-min>-</p>
                    <div class="range-card-track-shell">
                        <div class="range-card-dots" data-range-dots></div>
                    </div>
                    <p class="range-card-value" data-range-max>-</p>
                </div>

                <p class="range-card-empty" data-range-empty>Keine Preise im Ausschnitt.</p>
            </section>

            <section id="map-loading" class="map-state hidden" aria-live="polite">
                <div class="state-card loading-content">
                    <span class="spinner" aria-hidden="true"></span>
                    <span>Lade aktuelle Preise...</span>
                </div>
            </section>

            <section id="map-empty" class="map-state hidden" aria-live="polite">
                <div class="state-card">
                    Keine Tankstellen für den aktuellen Kartenausschnitt gefunden.
                </div>
            </section>

            <section id="map-error" class="map-state hidden" aria-live="assertive">
                <div class="state-card">
                    Die Daten konnten gerade nicht geladen werden.
                    <br>
                    Bitte erneut versuchen.
                    <br>
                    <button type="button" data-retry-map>Neu laden</button>
                </div>
            </section>
        </main>
    </body>
</html>
