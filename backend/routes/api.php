<?php

use App\Http\Controllers\ConstructorCatalogController;
use App\Http\Controllers\StoreCatalogItemController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\StoreOrderLogController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/constructor/catalog', ConstructorCatalogController::class);
Route::post('/v1/constructor/catalog', StoreCatalogItemController::class)->middleware('throttle:30,1,catalog-write:');
Route::post('/v1/orders', StoreOrderController::class)->middleware('throttle:20,1');
Route::post('/v1/log/{order}', StoreOrderLogController::class)
    ->whereNumber('order')
    ->middleware('throttle:20,1,order-log:');
