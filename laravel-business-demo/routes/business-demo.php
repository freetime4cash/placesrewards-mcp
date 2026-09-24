<?php
use App\Http\Controllers\Demo\BusinessDemoController;
use Illuminate\Support\Facades\Route;

Route::post('/demo/internal/native-build', [BusinessDemoController::class, 'nativeBuild'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\VerifyCsrfToken::class])
    ->name('demo.business.native-build');

Route::post('/demo/internal/asset-deploy', [BusinessDemoController::class, 'assetDeploy'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\VerifyCsrfToken::class])
    ->name('demo.business.asset-deploy');

Route::post('/demo/internal/manifest-deploy', [BusinessDemoController::class, 'manifestDeploy'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \App\Http\Middleware\VerifyCsrfToken::class])
    ->name('demo.business.manifest-deploy');

Route::get('/demo/business/{slug}/module/{kind}', [BusinessDemoController::class, 'module'])
    ->where(['slug' => '[a-z0-9-]+', 'kind' => '[a-z0-9_-]+'])
    ->name('demo.business.module');

Route::get('/demo/business/{slug}/{sequence?}', [BusinessDemoController::class, 'show'])
    ->where(['slug' => '[a-z0-9-]+', 'sequence' => '[0-9]+'])
    ->name('demo.business.show');
