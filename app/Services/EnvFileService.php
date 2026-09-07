<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class EnvFileService
{
    /**
     * Update or set an environment variable in .env file
     */
    public static function updateEnvVariable(string $key, string $value): bool
    {
        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            return false;
        }

        $envContent = File::get($envPath);
        
        // Escape special regex characters in the key
        $escapedKey = preg_quote($key, '/');
        
        // Pattern to match: KEY=value or KEY="value" or KEY='value'
        $pattern = "/^{$escapedKey}=(.*)$/m";
        
        if (preg_match($pattern, $envContent)) {
            // Update existing key - replace the entire line
            $envContent = preg_replace(
                $pattern,
                "{$key}={$value}",
                $envContent
            );
        } else {
            // Add new key at the end (before the last newline if exists)
            if (substr($envContent, -1) !== "\n") {
                $envContent .= "\n";
            }
            $envContent .= "{$key}={$value}\n";
        }

        try {
            File::put($envPath, $envContent);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get value of an environment variable from .env file
     */
    public static function getEnvVariable(string $key, ?string $default = null): ?string
    {
        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            return $default;
        }

        $envContent = File::get($envPath);
        
        if (preg_match("/^{$key}=(.*)/m", $envContent, $matches)) {
            return trim($matches[1]);
        }

        return $default;
    }
}

