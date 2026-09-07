<?php

namespace App\Services;

use App\Models\SystemConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;


class ConfigurationService
{

    public static function get(string $key, $default = null)
    {
        return Cache::remember("config.{$key}", 3600, function () use ($key, $default) {
            return SystemConfiguration::getValue($key, $default);
        });
    }


    public static function set(string $key, $value, string $category = 'general', ?string $description = null): bool
    {
        $result = SystemConfiguration::setValue($key, $value, $category, $description);
        Cache::forget("config.{$key}");
        Cache::forget('system_configs_all'); // Clear the aggregated cache
        return $result !== null;
    }


    public static function applyDatabaseConfig(): void
    {
        try {
            $configs = Cache::remember('system_configs_all', 300, function () {
                return SystemConfiguration::active()->get();
            });
        } catch (\Exception $e) {
            return; // Silently fail if database is not ready
        }

        foreach ($configs as $config) {
            switch ($config->key) {
                case 'razorpay_key_id':
                    Config::set('services.razorpay.key_id', $config->value);
                    break;
                case 'razorpay_key_secret':
                    Config::set('services.razorpay.key_secret', $config->value);
                    break;
                case 'razorpay_webhook_secret':
                    Config::set('services.razorpay.webhook_secret', $config->value);
                    break;
                case 'stripe_key':
                    Config::set('services.stripe.key', $config->value);
                    break;
                case 'stripe_secret':
                    Config::set('services.stripe.secret', $config->value);
                    break;
                case 'stripe_webhook_secret':
                    Config::set('services.stripe.webhook_secret', $config->value);
                    break;

                case 'fcm_server_key':
                    Config::set('services.fcm.server_key', $config->value);
                    break;
                case 'fcm_sender_id':
                    Config::set('services.fcm.sender_id', $config->value);
                    break;
                case 'fcm_project_id':
                    Config::set('services.fcm.project_id', $config->value);
                    break;

                case 'twilio_sid':
                    Config::set('services.twilio.sid', $config->value);
                    break;
                case 'twilio_token':
                    Config::set('services.twilio.token', $config->value);
                    break;
                case 'twilio_from':
                    Config::set('services.twilio.from', $config->value);
                    break;
                case 'msg91_api_key':
                    Config::set('services.msg91.api_key', $config->value);
                    break;
                case 'msg91_sender_id':
                    Config::set('services.msg91.sender_id', $config->value);
                    break;

                case 'google_maps_api_key':
                    Config::set('services.google_maps.api_key', $config->value);
                    break;

                case 'google_client_id':
                    Config::set('services.google.client_id', $config->value);
                    break;
                case 'google_client_secret':
                    Config::set('services.google.client_secret', $config->value);
                    break;

                case 'app_name':
                    Config::set('app.name', $config->value);
                    break;
            }
        }
    }


    public static function isServiceConfigured(string $service): bool
    {
        return match ($service) {
            'razorpay' => !empty(self::get('razorpay_key_id')) && !empty(self::get('razorpay_key_secret')),
            'stripe' => !empty(self::get('stripe_key')) && !empty(self::get('stripe_secret')),
            'fcm' => !empty(self::get('fcm_server_key')),
            'twilio' => !empty(self::get('twilio_sid')) && !empty(self::get('twilio_token')),
            'msg91' => !empty(self::get('msg91_api_key')),
            'google_maps' => !empty(self::get('google_maps_api_key')),
            default => false,
        };
    }


    public static function getServiceStatus(): array
    {
        return [
            'payment' => [
                'razorpay' => self::isServiceConfigured('razorpay'),
                'stripe' => self::isServiceConfigured('stripe'),
            ],
            'notification' => [
                'fcm' => self::isServiceConfigured('fcm'),
            ],
            'sms' => [
                'twilio' => self::isServiceConfigured('twilio'),
                'msg91' => self::isServiceConfigured('msg91'),
            ],
            'maps' => [
                'google_maps' => self::isServiceConfigured('google_maps'),
            ],
        ];
    }
}
