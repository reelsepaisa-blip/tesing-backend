<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return redirect('/admin/login');
    })->name('login');
});

Route::get('/test-google-maps', function () {
    return view('test-google-maps');
});

Route::get('/test-realtime', function () {
    return response()->file(base_path('test_realtime_websocket.html'));
});

Route::get('/test-realtime-browser', function () {
    return response()->file(base_path('test_realtime_browser.php'));
});

Route::get('/test-google-maps', function () {
    if (!app()->environment(['local', 'testing'])) {
        abort(404);
    }
    return view('test-google-maps');
});

Route::get('/test-realtime', function () {
    if (!app()->environment(['local', 'testing'])) {
        abort(404);
    }
    return response()->file(base_path('test_realtime_websocket.html'));
});

Route::get('/test-realtime-browser', function () {
    if (!app()->environment(['local', 'testing'])) {
        abort(404);
    }
    return response()->file(base_path('test_realtime_browser.php'));
});

Route::prefix('page')->group(function () {
    Route::get('about-us', [App\Http\Controllers\PageController::class, 'aboutUs'])->name('page.about-us');
    Route::get('terms-conditions', [App\Http\Controllers\PageController::class, 'termsConditions'])->name('page.terms-conditions');
    Route::get('privacy-policy', [App\Http\Controllers\PageController::class, 'privacyPolicy'])->name('page.privacy-policy');
    Route::get('contact-us', [App\Http\Controllers\PageController::class, 'contactUs'])->name('page.contact-us');
});

Route::prefix('help')->group(function () {
    Route::get('articles', [App\Http\Controllers\HelpArticleController::class, 'index'])->name('help.articles.index');
    Route::get('articles/{article}', [App\Http\Controllers\HelpArticleController::class, 'show'])->name('help.articles.show');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/bookings/{booking}/invoice', [App\Http\Controllers\Admin\InvoiceController::class, 'show'])
        ->name('admin.bookings.invoice');
    Route::post('/admin/reset-data', [App\Http\Controllers\Admin\ResetDataController::class, 'resetData'])
        ->name('admin.reset-data');
});
