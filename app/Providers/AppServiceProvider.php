<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void {}


    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Clean output for Livewire requests to prevent JSON parsing errors
        $request = request();
        if ($request && ($request->header('X-Livewire') || $request->header('X-Livewire-Request'))) {
            // Clean any existing output buffers
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            // Start output buffering to catch any accidental output with BOM removal
            @ob_start(function ($buffer) {
                // Remove BOM from any buffered output
                $buffer = preg_replace('/^\xEF\xBB\xBF/', '', $buffer);
                $buffer = preg_replace('/\xEF\xBB\xBF/', '', $buffer);
                $buffer = preg_replace('/^\x{FEFF}/u', '', $buffer);
                $buffer = preg_replace('/\x{FEFF}/u', '', $buffer);
                return $buffer;
            });
        }

        // Add global response macro to clean BOM from all JSON responses
        \Illuminate\Support\Facades\Response::macro('jsonClean', function ($data, $status = 200, $headers = [], $options = 0) {
            $json = json_encode($data, $options);
            // Remove BOM if present
            $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
            $json = preg_replace('/\xEF\xBB\xBF/', '', $json);
            $json = preg_replace('/^\x{FEFF}/u', '', $json);
            $json = preg_replace('/\x{FEFF}/u', '', $json);

            return response($json, $status, array_merge($headers, [
                'Content-Type' => 'application/json; charset=UTF-8',
            ]));
        });

        // Add response listener to clean BOM from all responses
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Routing\Events\ResponsePrepared::class, function ($event) {
            $response = $event->response;
            $request = request();

            // Check if this is a Livewire or JSON response
            $isLivewire = $request && (
                $request->header('X-Livewire') ||
                $request->header('X-Livewire-Request') ||
                str_contains($request->path(), 'livewire')
            );

            $isJson = $response->headers->get('Content-Type') &&
                str_contains($response->headers->get('Content-Type'), 'application/json');

            if ($isLivewire || $isJson) {
                $content = $response->getContent();
                if (!empty($content)) {
                    // Aggressively remove all BOM variants
                    $content = str_replace("\xEF\xBB\xBF", '', $content);
                    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
                    $content = preg_replace('/\xEF\xBB\xBF/', '', $content);
                    $content = preg_replace('/^\x{FEFF}/u', '', $content);
                    $content = preg_replace('/\x{FEFF}/u', '', $content);
                    $content = ltrim($content, " \t\n\r\0\x0B,");
                    $content = preg_replace('/^[\x00-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]+/u', '', $content);

                    $response->setContent($content);
                    $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
                }
            }
        });

        Model::shouldBeStrict(config('app.env') === 'production');

        if (config('app.debug')) {
            Model::preventLazyLoading();
        }
    }
}
