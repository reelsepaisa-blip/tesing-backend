<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'eTaxi Test Update'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | This value determines if your application should run in debug mode.
    | When debug mode is enabled, detailed error messages with stack traces
    | will be shown on every error that occurs within your application.
    | If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, destructive actions (create, update, delete) are blocked
    | to protect demo data. Set DEMO_MODE=true in .env to enable.
    |
    */

    'demo_mode' => (bool) env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'Asia/Kolkata',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en_IN',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeders. For example, this will be used to
    | get localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's maintenance mode status. The "file" driver stores
    | the status in a simple file, while the "database" driver stores
    | it in the database.
    |
    | Supported drivers: "file", "database"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store' => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        /*
         * Laravel Framework Service Providers...
         */
        Illuminate\Auth\AuthServiceProvider::class,
        Illuminate\Broadcasting\BroadcastServiceProvider::class,
        Illuminate\Bus\BusServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
        Illuminate\Cookie\CookieServiceProvider::class,
        Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Encryption\EncryptionServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        Illuminate\Hashing\HashServiceProvider::class,
        Illuminate\Mail\MailServiceProvider::class,
        Illuminate\Notifications\NotificationServiceProvider::class,
        Illuminate\Pagination\PaginationServiceProvider::class,
        Illuminate\Pipeline\PipelineServiceProvider::class,
        Illuminate\Queue\QueueServiceProvider::class,
        Illuminate\Redis\RedisServiceProvider::class,
        Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
        Illuminate\Session\SessionServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class,

        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        App\Providers\ConfigurationServiceProvider::class,
        App\Providers\FormComponentsServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | E-Taxi Application Settings
    |--------------------------------------------------------------------------
    |
    | These are custom configuration values for the e-taxi application.
    |
    */

    'etaxi' => [
        // Driver waiting time settings
        'free_waiting_time' => env('FREE_WAITING_TIME', 3), // minutes
        'waiting_charge_per_minute' => env('WAITING_CHARGE_PER_MINUTE', 2), // rupees per minute

        // Driver commission settings
        'driver_commission_rate' => env('DRIVER_COMMISSION_RATE', 0.8), // 80% by default

        // Trip settings
        'max_booking_distance' => env('MAX_BOOKING_DISTANCE', 100), // km
        'min_booking_distance' => env('MIN_BOOKING_DISTANCE', 0.5), // km

        // Payment settings
        'payment_timeout' => env('PAYMENT_TIMEOUT', 300), // seconds
        'wallet_min_recharge' => env('WALLET_MIN_RECHARGE', 100), // rupees
        'wallet_max_recharge' => env('WALLET_MAX_RECHARGE', 10000), // rupees

        // Rating settings
        'min_rating' => env('MIN_RATING', 1),
        'max_rating' => env('MAX_RATING', 5),

        // Notification settings
        'push_notification_enabled' => env('PUSH_NOTIFICATION_ENABLED', true),
        'sms_notification_enabled' => env('SMS_NOTIFICATION_ENABLED', false),
        'email_notification_enabled' => env('EMAIL_NOTIFICATION_ENABLED', true),

        // Google API settings
        'google_places_api_key' => env('GOOGLE_PLACES_API_KEY', ''),
        'google_geocoding_api_key' => env('GOOGLE_GEOCODING_API_KEY', ''),
    ],

];
