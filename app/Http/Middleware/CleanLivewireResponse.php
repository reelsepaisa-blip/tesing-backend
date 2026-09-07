<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class CleanLivewireResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isLivewireRequest = $request->header('X-Livewire') ||
            $request->header('X-Livewire-Request') ||
            str_contains($request->path(), 'livewire') ||
            str_contains($request->url(), '/livewire/');

        // Clean any output that might have been sent before Livewire response
        if ($isLivewireRequest) {
            // Clean any existing output buffers completely
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Start fresh output buffering to catch any accidental output
            ob_start(function ($buffer) {
                // Remove BOM from any buffered output
                $buffer = preg_replace('/^\xEF\xBB\xBF/', '', $buffer);
                $buffer = preg_replace('/^\x{FEFF}/u', '', $buffer);
                return $buffer;
            });
        }

        try {
            $response = $next($request);

            // For Livewire requests, ensure clean JSON response
            if ($isLivewireRequest) {
                // Get any output that was accidentally sent
                $output = ob_get_clean();

                // Get the response content
                $content = $response->getContent();

                // If there was output before the response, log it
                if (!empty($output)) {
                }

                // Clean the content - remove leading commas, whitespace, BOM, etc.
                if (!empty($content)) {
                    // Aggressively remove BOM (Byte Order Mark) character - U+FEFF in all possible encodings
                    // UTF-8 BOM: EF BB BF (most common)
                    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
                    $content = preg_replace('/\xEF\xBB\xBF/', '', $content); // Remove anywhere, not just start

                    // UTF-16 BE BOM: FE FF
                    $content = preg_replace('/^\xFE\xFF/', '', $content);
                    // UTF-16 LE BOM: FF FE
                    $content = preg_replace('/^\xFF\xFE/', '', $content);
                    // UTF-32 BE BOM: 00 00 FE FF
                    $content = preg_replace('/^\x00\x00\xFE\xFF/', '', $content);
                    // UTF-32 LE BOM: FF FE 00 00
                    $content = preg_replace('/^\xFF\xFE\x00\x00/', '', $content);

                    // Unicode BOM character (U+FEFF) in UTF-8 - multiple patterns
                    $content = preg_replace('/^\x{FEFF}/u', '', $content);
                    $content = preg_replace('/\x{FEFF}/u', '', $content); // Remove anywhere
                    $content = preg_replace('/\uFEFF/', '', $content); // JavaScript escape sequence

                    // Remove leading invalid characters (commas, whitespace, etc.)
                    $content = ltrim($content, " \t\n\r\0\x0B,");

                    // Remove any invisible/zero-width characters at the start
                    // This includes: control characters, zero-width spaces, etc.
                    $content = preg_replace('/^[\x00-\x1F\x7F-\x9F\x{200B}-\x{200D}\x{FEFF}]+/u', '', $content);

                    // Additional cleanup: remove any remaining BOM-like characters
                    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8'); // Normalize encoding
                    $content = preg_replace('/^[\p{C}\p{Z}]+/u', '', $content); // Remove all control and separator chars at start

                    // Try to validate and fix JSON
                    $decoded = json_decode($content, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Try to find valid JSON in the content
                        // Look for the first valid JSON object starting with {
                        if (preg_match('/\{.*\}/s', $content, $matches)) {
                            $potentialJson = $matches[0];
                            $testDecode = json_decode($potentialJson, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $content = $potentialJson;
                            } else {
                            }
                        } else {
                        }
                    }
                }

                // Set the cleaned content
                $response->setContent($content);

                // Ensure proper Content-Type header for JSON
                $response->headers->set('Content-Type', 'application/json; charset=UTF-8');

                // Force remove BOM one more time as final safety check
                $finalContent = $response->getContent();
                if (!empty($finalContent)) {
                    // Remove BOM in all possible forms
                    $finalContent = str_replace("\xEF\xBB\xBF", '', $finalContent);
                    $finalContent = preg_replace('/^\x{FEFF}/u', '', $finalContent);
                    $finalContent = preg_replace('/\x{FEFF}/u', '', $finalContent);
                    $finalContent = ltrim($finalContent, " \t\n\r\0\x0B,");
                    $response->setContent($finalContent);
                }
            }

            return $response;
        } catch (\Throwable $e) {
            // Clean output buffer on exception
            if ($isLivewireRequest && ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
