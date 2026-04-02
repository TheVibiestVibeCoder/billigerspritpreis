# Spritmap.at

Echtzeit-Spritpreisvergleich fuer Oesterreich auf Basis von Laravel + Blade + Leaflet + E-Control API.

## Stack

- Laravel 13 (kompatibel zur angefragten Laravel-11-Architektur)
- Blade Templates
- Vanilla JavaScript (kein Build-Schritt noetig)
- Leaflet + OpenStreetMap
- E-Control API (`https://api.e-control.at/api`, Fallback auf `/sprit/1.0`)
- Queue + Scheduler fuer Preis-Warmup
- SQLite lokal / MySQL Produktion

## Enthaltene Funktionen

- Vollbildkarte (`100vh`) mit OSM
- API-Endpunkt `GET /api/stations?fuel=DIE|SUP|ALL&bounds=s,w,n,e`
- 5-stufige relative Preisfarben (Gruen bis Dunkelrot)
- Marker-Popup mit Preis, Adresse, Oeffnungsstatus
- Google-Maps-Routenlink pro Station
- Preislabels ab Zoom >= 13
- Fuel-Switcher (`Diesel`, `Super 95`, `Alle`)
- 15-Minuten-Cache fuer aufbereitete Daten
- Regionen-Cache fuer 24h
- Scheduler-Job alle 15 Minuten + Mo/Mi/Fr 12:02

## Projektstruktur

- `app/Http/Controllers/MapController.php`
- `app/Http/Controllers/ApiController.php`
- `app/Services/EControlService.php`
- `app/Services/PriceColorService.php`
- `app/Jobs/FetchPricesJob.php`
- `app/Models/GasStation.php`
- `database/migrations/2026_04_01_000003_create_gas_stations_table.php`
- `resources/views/map/index.blade.php`
- `resources/views/components/fuel-filter.blade.php`
- `public/js/spritmap.js`

## Lokales Starten

```bash
php artisan migrate
php artisan serve
```

## Scheduler lokal testen

```bash
php artisan schedule:list
php artisan schedule:work
```

## ENV

Wichtige Werte in `.env`:

```env
APP_NAME=Spritmap
ECONTROL_API_BASE=https://api.e-control.at/api
ECONTROL_API_FALLBACK_BASE=https://api.e-control.at/sprit/1.0
ECONTROL_CACHE_TTL=900
ECONTROL_HTTP_TIMEOUT=15
```
