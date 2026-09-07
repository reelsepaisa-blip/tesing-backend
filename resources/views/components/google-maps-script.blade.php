@php
    $googleMapsKey = config('services.google_maps.api_key');
@endphp

@if ($googleMapsKey)
    <script>
        (function() {
            if (!window.googleMapsScriptLoading) {
                window.googleMapsScriptLoading = {
                    loaded: typeof google !== 'undefined' && typeof google.maps !== 'undefined',
                    loading: false,
                    callbacks: []
                };
            }

            function triggerLoaded() {
                window.googleMapsScriptLoading.loaded = true;
                window.googleMapsScriptLoading.loading = false;
                window.dispatchEvent(new CustomEvent('googleMapsLoaded'));
                // Execute any queued callbacks
                window.googleMapsScriptLoading.callbacks.forEach(cb => {
                    try {
                        cb();
                    } catch (e) {
                        console.error('Error in Google Maps callback:', e);
                    }
                });
                window.googleMapsScriptLoading.callbacks = [];
            }

            // Check if already loaded
            if (window.googleMapsScriptLoading.loaded || (typeof google !== 'undefined' && typeof google.maps !== 'undefined')) {
                triggerLoaded();
                return;
            }

            // Check if already loading
            if (window.googleMapsScriptLoading.loading) {
                // Wait for existing load
                window.addEventListener('googleMapsLoaded', function handler() {
                    window.removeEventListener('googleMapsLoaded', handler);
                }, { once: true });
                return;
            }

            // Check for existing script tag
            const existingScript = document.querySelector('script[data-google-maps-loader]');
            if (existingScript) {
                // Script exists, wait for it to load
                if (existingScript.onload) {
                    const originalOnload = existingScript.onload;
                    existingScript.onload = function() {
                        originalOnload();
                        triggerLoaded();
                    };
                } else {
                    existingScript.addEventListener('load', triggerLoaded);
                }
                return;
            }

            // Start loading
            window.googleMapsScriptLoading.loading = true;
            const script = document.createElement('script');
            script.dataset.googleMapsLoader = 'true';
            script.src = 'https://maps.googleapis.com/maps/api/js?key={{ $googleMapsKey }}&libraries=places,drawing,geometry';
            script.async = true;
            script.defer = true;

            script.onerror = function() {
                window.googleMapsScriptLoading.loading = false;
                console.error('Failed to load Google Maps API script. Please check your API key and network connection.');
                // Dispatch error event
                window.dispatchEvent(new CustomEvent('googleMapsError', {
                    detail: { message: 'Failed to load Google Maps API' }
                }));
            };

            // Check when script loads
            script.onload = function() {
                // Poll for Google Maps API availability
                let attempts = 0;
                const maxAttempts = 50; // 5 seconds max
                const checkInterval = setInterval(function() {
                    attempts++;
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                        clearInterval(checkInterval);
                        triggerLoaded();
                    } else if (attempts >= maxAttempts) {
                        clearInterval(checkInterval);
                        window.googleMapsScriptLoading.loading = false;
                        console.error('Google Maps script loaded but API is not available. Check API key restrictions.');
                        window.dispatchEvent(new CustomEvent('googleMapsError', {
                            detail: { message: 'Google Maps API not available after script load' }
                        }));
                    }
                }, 100);
            };

            document.head.appendChild(script);
        })();
    </script>
@endif
