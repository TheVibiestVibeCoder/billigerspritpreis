<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MapController extends Controller
{
    public function __invoke(): View
    {
        return view('map.index', [
            'mapCenter' => [
                'lat' => 47.8,
                'lng' => 13.3,
            ],
            'mapZoom' => 8,
            'initialFuel' => 'DIE',
            'initialCompareScope' => 'viewport',
        ]);
    }
}
