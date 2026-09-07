<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    /**
     * Get app settings (public endpoint - no authentication required)
     */
    public function getSettings(): JsonResponse
    {
        try {
            // Get all active settings from SystemConfiguration
            $settings = SystemConfiguration::active()
                ->where('category', 'app_settings')
                ->get()
                ->mapWithKeys(function ($config) {
                    $value = $config->value;
                    $key = $config->key;

                    // Convert boolean-like values to actual booleans
                    if ($key === 'maintenance_mode') {
                        $value = $value === '1' || $value === 'true' || $value === true;
                    }

                    // Convert snake_case keys to camelCase for API response
                    $camelKey = $this->snakeToCamel($key);

                    return [$camelKey => $value];
                })
                ->toArray();

            // If no settings found, return empty object
            if (empty($settings)) {
                return response()->json([
                    'success' => true,
                    'data' => (object)[]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Convert snake_case to camelCase
     */
    private function snakeToCamel(string $string): string
    {
        // Special mappings for specific keys
        $specialMappings = [
            'appstore_id' => 'appstoreId',
            'country_code' => 'countryCode',
        ];

        if (isset($specialMappings[$string])) {
            return $specialMappings[$string];
        }

        // Convert snake_case to camelCase
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}
