<?php

use Illuminate\Support\Facades\Route;

// Vue Router (history mode) owns client-side routing; every path renders the
// same SPA shell so the frontend can resolve the actual screen. Paths under
// api/ are excluded so unknown API URLs return a JSON 404 instead of the SPA.
Route::view('/{any?}', 'app')->where('any', '^(?!api(/|$)).*');
