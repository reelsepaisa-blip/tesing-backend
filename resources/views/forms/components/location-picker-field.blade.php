@once
    @include('components.google-maps-script')
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
        $latitudeStatePath = "data.{$getLatitudeField()}";
        $longitudeStatePath = "data.{$getLongitudeField()}";
    @endphp

    <div x-data="cashCollectionPointLocationPicker({
        latitudeModel: $wire.entangle('{{ $latitudeStatePath }}').live,
        longitudeModel: $wire.entangle('{{ $longitudeStatePath }}').live,
        apiKey: '{{ $getMapApiKey() }}'
    })" x-init="init()" class="space-y-3" wire:ignore>
        <template x-if="!apiKey">
            <div class="rounded-md border border-dashed border-danger-400 bg-danger-50 p-3 text-sm text-danger-700">
                Google Maps API key is missing. Please configure `GOOGLE_MAPS_API_KEY`.
            </div>
        </template>

        <template x-if="apiKey">
            <div class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-gray-600 dark:text-gray-300">
                        Click anywhere on the map or search below to pick a location.
                    </p>
                    <button type="button" class="text-xs text-primary-600 hover:underline disabled:opacity-50"
                        @click="clearSelection()" x-bind:disabled="!hasSelection">
                        Clear
                    </button>
                </div>

                <div class="relative">
                    <input type="text" x-ref="searchInput" placeholder="Search for a place or address"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 pr-12 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white" />
                    <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-400">
                        {{-- <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M21 21l-4.35-4.35M11 5a6 6 0 000 12 6 6 0 000-12z" />
                        </svg> --}}
                    </div>
                </div>

                <div x-ref="map"
                    class="h-80 w-full rounded-xl border border-gray-200 bg-gray-100 shadow-inner dark:border-gray-700 dark:bg-gray-800"
                    style="height: 300px !important;">
                    <div x-show="!map && !mapError" class="flex h-full items-center justify-center text-sm text-gray-500">
                        Loading map...
                    </div>
                    <div x-show="mapError" class="flex h-full flex-col items-center justify-center rounded-xl bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="mb-2 h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="font-medium">Failed to load map</p>
                        <p class="mt-1 text-xs" x-text="mapError"></p>
                        <button type="button" @click="retryMapLoad()" class="mt-3 rounded-md bg-danger-600 px-3 py-1.5 text-xs text-white hover:bg-danger-700">
                            Retry
                        </button>
                    </div>
                </div>

                <div class="text-xs text-gray-600 dark:text-gray-300 space-y-1">
                    <div class="flex flex-wrap items-baseline gap-1">
                        <span class="font-medium text-gray-700 dark:text-gray-200"
                            style="margin: 5px 0 10px 0 !important;">Selected Address:</span>
                        <span x-text="selectedAddress ?? 'Not determined yet'"
                            style="margin: 5px 0 10px 0 !important;"></span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-dynamic-component>

@once
    @push('scripts-before')
        <script>
            window.cashCollectionPointLocationPicker = function(config) {
                return {
                    latitudeModel: config.latitudeModel,
                    longitudeModel: config.longitudeModel,
                    apiKey: config.apiKey,
                    map: null,
                    marker: null,
                    autocomplete: null,
                    hasSelection: false,
                    loadingScript: false,
                    selectedAddress: null,
                    geocoder: null,
                    geocodeTimeout: null,
                    lastGeocodeKey: null,
                    isInternalUpdate: false,
                    mapError: null,
                    init() {
                        this.hasSelection = this.latitudeModel !== null && this.latitudeModel !== '' &&
                            this.longitudeModel !== null && this.longitudeModel !== '';

                        this.$watch('latitudeModel', value => {
                            if (this.marker && value !== null && value !== '' && this.longitudeModel !== null &&
                                this.longitudeModel !== '') {
                                const lat = parseFloat(value);
                                const lng = parseFloat(this.longitudeModel);

                                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                                    this.updateMarker(lat, lng);

                                    if (!this.isInternalUpdate) {
                                        this.reverseGeocode(lat, lng);
                                    }
                                    this.panTo(lat, lng, {
                                        zoom: 13,
                                    });
                                }
                            }
                            this.hasSelection = value !== null && value !== '' && this.longitudeModel !== null &&
                                this.longitudeModel !== '';
                        });

                        this.$watch('longitudeModel', value => {
                            if (this.marker && value !== null && value !== '' && this.latitudeModel !== null && this
                                .latitudeModel !== '') {
                                const lat = parseFloat(this.latitudeModel);
                                const lng = parseFloat(value);

                                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                                    this.updateMarker(lat, lng);

                                    if (!this.isInternalUpdate) {
                                        this.reverseGeocode(lat, lng);
                                    }
                                    this.panTo(lat, lng, {
                                        zoom: 13,
                                    });
                                }
                            }
                            this.hasSelection = value !== null && value !== '' && this.latitudeModel !== null &&
                                this.latitudeModel !== '';
                        });

                        if (!this.apiKey) {
                            return;
                        }

                        // Defer so x-if="apiKey" has rendered and x-ref="map" is in the DOM
                        this.$nextTick(() => {
                            this.ensureMapsReady(() => this.initializeMap());
                        });
                    },
                    ensureMapsReady(callback) {
                        // Check if already loaded
                        if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                            callback();
                            return;
                        }

                        // Set up timeout fallback
                        const timeout = setTimeout(() => {
                            console.warn('Google Maps loading timeout. Attempting to initialize anyway...');
                            if (typeof google !== 'undefined' && google.maps) {
                                callback();
                            } else {
                                console.error('Google Maps API is not available. Please check your API key and console for errors.');
                            }
                        }, 10000); // 10 second timeout

                        // Listen for loaded event
                        const handler = () => {
                            clearTimeout(timeout);
                            if (typeof google !== 'undefined' && google.maps) {
                                callback();
                            } else {
                                console.error('Google Maps loaded event fired but API is not available');
                            }
                        };

                        window.addEventListener('googleMapsLoaded', handler, { once: true });
                        
                        // Also listen for errors
                        window.addEventListener('googleMapsError', (event) => {
                            clearTimeout(timeout);
                            console.error('Google Maps loading error:', event.detail);
                            this.mapError = event.detail?.message || 'Failed to load Google Maps. Please check your API key and console for details.';
                        }, { once: true });
                    },
                    initializeMap() {
                        this.mapError = null;
                        
                        if (!this.$refs || !this.$refs.map) {
                            console.error('Map container reference not found');
                            this.mapError = 'Map container not found';
                            return;
                        }

                        if (typeof google === 'undefined' || !google.maps) {
                            console.error('Google Maps API is not available');
                            this.mapError = 'Google Maps API is not available. Please check your API key configuration.';
                            return;
                        }

                        try {
                            const initialLat = this.parseCoordinate(this.latitudeModel) ?? 20.5937;
                            const initialLng = this.parseCoordinate(this.longitudeModel) ?? 78.9629;

                            this.map = new google.maps.Map(this.$refs.map, {
                                center: {
                                    lat: initialLat,
                                    lng: initialLng
                                },
                                zoom: this.hasSelection ? 15 : 5,
                                streetViewControl: false,
                                mapTypeControl: false,
                            });

                            this.marker = new google.maps.Marker({
                                map: this.map,
                                position: {
                                    lat: initialLat,
                                    lng: initialLng
                                },
                                draggable: true,
                                animation: google.maps.Animation.DROP,
                            });

                            this.marker.addListener('dragend', event => {
                                this.setCoordinates(event.latLng.lat(), event.latLng.lng());
                            });

                            this.map.addListener('click', event => {
                                this.setCoordinates(event.latLng.lat(), event.latLng.lng());
                            });

                            this.geocoder = new google.maps.Geocoder();

                            this.initAutocomplete();

                            if (!this.hasSelection) {
                                this.marker.setVisible(false);
                            } else {
                                this.reverseGeocode(initialLat, initialLng, {
                                    force: true,
                                    immediate: true
                                });
                                this.panTo(initialLat, initialLng, {
                                    zoom: 13,
                                });
                            }
                        } catch (error) {
                            console.error('Error initializing Google Map:', error);
                            this.mapError = 'Error initializing map: ' + (error.message || 'Unknown error');
                        }
                    },
                    retryMapLoad() {
                        this.mapError = null;
                        this.map = null;
                        this.marker = null;
                        this.ensureMapsReady(() => this.initializeMap());
                    },
                    initAutocomplete() {
                        if (!this.$refs || !this.$refs.searchInput || !google.maps.places) {
                            return;
                        }

                        this.autocomplete = new google.maps.places.Autocomplete(this.$refs.searchInput, {
                            types: ['geocode'],
                        });

                        this.autocomplete.addListener('place_changed', () => {
                            const place = this.autocomplete.getPlace();
                            if (!place.geometry || !place.geometry.location) {
                                return;
                            }

                            const lat = place.geometry.location.lat();
                            const lng = place.geometry.location.lng();

                            this.panTo(lat, lng, {
                                zoom: 15,
                            });
                            this.setCoordinates(lat, lng, {
                                address: place.formatted_address || place.name || null,
                                zoom: 15,
                            });
                        });
                    },
                    setCoordinates(lat, lng, options = {}) {
                        if (Number.isNaN(lat) || Number.isNaN(lng)) {
                            return;
                        }

                        const roundedLat = parseFloat(lat.toFixed(8));
                        const roundedLng = parseFloat(lng.toFixed(8));

                        this.isInternalUpdate = true;
                        this.latitudeModel = roundedLat;
                        this.longitudeModel = roundedLng;
                        this.$nextTick(() => {
                            this.isInternalUpdate = false;
                        });
                        this.hasSelection = true;
                        this.updateMarker(roundedLat, roundedLng);

                        if (options.address) {
                            this.selectedAddress = options.address;
                        } else {
                            this.reverseGeocode(roundedLat, roundedLng, {
                                immediate: true
                            });
                        }

                        this.panTo(roundedLat, roundedLng, {
                            zoom: options.zoom ?? 14,
                        });
                    },
                    updateMarker(lat, lng) {
                        if (!this.marker) {
                            return;
                        }

                        this.marker.setPosition({
                            lat,
                            lng
                        });
                        this.marker.setVisible(true);

                        this.panTo(lat, lng);
                    },
                    clearSelection() {
                        this.latitudeModel = null;
                        this.longitudeModel = null;
                        this.hasSelection = false;
                        this.selectedAddress = null;

                        if (this.marker) {
                            this.marker.setVisible(false);
                        }
                    },
                    parseCoordinate(value) {
                        if (value === null || value === '') {
                            return null;
                        }

                        const num = parseFloat(value);
                        return Number.isNaN(num) ? null : num;
                    },
                    panTo(lat, lng, options = {}) {
                        if (!this.map) {
                            return;
                        }

                        this.map.setCenter({
                            lat,
                            lng
                        });

                        if (options.zoom) {
                            this.map.setZoom(options.zoom);
                        }
                    },
                    reverseGeocode(lat, lng, options = {}) {
                        if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
                            return;
                        }

                        if (!this.geocoder) {
                            this.geocoder = new google.maps.Geocoder();
                        }

                        const key = `${lat.toFixed(5)},${lng.toFixed(5)}`;
                        if (!options.force && this.lastGeocodeKey === key) {
                            return;
                        }
                        this.lastGeocodeKey = key;

                        if (this.geocodeTimeout) {
                            clearTimeout(this.geocodeTimeout);
                        }

                        const delay = options.immediate ? 0 : 300;
                        this.geocodeTimeout = setTimeout(() => {
                            this.geocoder.geocode({
                                location: {
                                    lat,
                                    lng
                                }
                            }, (results, status) => {
                                if (status === 'OK' && results && results[0]) {
                                    this.selectedAddress = results[0].formatted_address;
                                } else if (status === 'ZERO_RESULTS') {
                                    this.selectedAddress = null;
                                }
                            });
                        }, delay);
                    }
                };
            };
        </script>
    @endpush
@endonce
