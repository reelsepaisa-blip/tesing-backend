{{-- Boundaries sync field - OUTSIDE wire:ignore so Livewire can track it --}}
<div x-data="{ 
        lastSyncedData: '',
        syncToWire(data) {
            // Only sync if data is different from last sync (prevents loops)
            if (data === this.lastSyncedData) {
                
                return;
            }
            this.lastSyncedData = data;

            // CRITICAL: Set mapBoundaries which is used in afterSave
            if (this.$wire) {
                try {
                    this.$wire.set('mapBoundaries', data);
                    
                } catch (e) {
                    console.warn('[BoundariesSync] $wire.set mapBoundaries failed:', e);
                }
            }
            
            // Also dispatch Livewire event as backup
            if (window.Livewire) {
                Livewire.dispatch('boundaries-updated', { boundaries: data });
            }
        }
     }" x-on:boundaries-changed.window="syncToWire($event.detail)" class="hidden">
</div>

<div class="w-full" x-data="googleMapsDraw()" x-init="init()"
    x-on:update-boundaries.window="handleBoundariesUpdate($event.detail)" wire:ignore>
    <div class="mb-4">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Zone Boundaries</h3>
        <p class="text-sm text-gray-600 mb-4">Draw the zone boundaries on the map below. Click on the map to add points
            and create a polygon.</p>
    </div>

    <div class="space-y-4">
        <!-- Search box -->
        <div class="flex items-center gap-2">
            <input x-ref="searchInput" id="zone-boundary-search" type="text" placeholder="Search area, place or address"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                style="color: black">
        </div>
        <!-- Google Maps Drawing Interface -->
        <div id="map" class="w-full h-[400px] rounded-lg border-2 border-gray-300"
            style="position: relative; overflow: hidden; min-height: 400px; background-color: #f3f4f6;">
            <div class="w-full h-full flex items-center justify-center">
                <div class="text-center">
                    <div class="text-4xl mb-2">🗺️</div>
                    <div class="text-lg font-medium text-gray-700 mb-2">Loading Map...</div>
                    <div class="text-sm text-gray-500">Please wait while the map initializes</div>
                </div>
            </div>
        </div>


        <!-- Drawing Controls -->
        <div class="flex space-x-2 mb-4">
            <button type="button" @click="startDrawing()"
                class="px-4 py-2 bg-blue-600 text-black rounded-md hover:bg-blue-700 text-sm">
                Draw Polygon
            </button>
            <button type="button" @click="clearPolygon()"
                class="px-4 py-2 bg-red-600 text-black rounded-md hover:bg-red-700 text-sm">
                Clear All
            </button>
            {{-- <button type="button" @click="loadGoogleMaps()"
                class="px-4 py-2 bg-green-600 text-black rounded-md hover:bg-green-700 text-sm">
                Load Google Maps
            </button> --}}
            {{-- <button type="button" @click="testApiKey()"
                class="px-4 py-2 bg-yellow-600 text-black rounded-md hover:bg-yellow-700 text-sm">
                Test API Key
            </button> --}}
        </div>


        <!-- Hidden input for boundaries (backup, main sync is via entangle) -->
        <input type="hidden" name="map_boundaries" x-model="boundariesJson" x-ref="boundariesInput">


        <!-- Ensure boundaries data is included in form submission -->
        <script>
            document.addEventListener('DOMContentLoaded', function () {


                // Hook into Livewire form submission to ensure boundaries are synced
                if (window.Livewire) {
                    // Hook into Livewire's request cycle
                    try {
                        Livewire.hook('request', ({ component, commit, respond, succeed, fail }) => {
                            // Sync boundaries before any Livewire request
                            const calls = commit?.calls || [];
                            const isSaveAction = calls.some(call =>
                                call?.method === 'save' ||
                                call?.method === 'create'
                            );

                            if (isSaveAction) {

                                syncBoundariesBeforeSubmit(component);
                            }
                        });
                    } catch (e) {
                        console.warn('[GoogleMapsDraw] Could not hook into Livewire request:', e);
                    }

                    // Also hook into commit as backup
                    try {
                        Livewire.hook('commit', ({ component, commit }) => {
                            const calls = commit?.calls || [];
                            const isSaveAction = calls.some(call =>
                                call?.method === 'save' ||
                                call?.method === 'create'
                            );

                            if (isSaveAction) {

                                syncBoundariesBeforeSubmit(component);
                            }
                        });
                    } catch (e) {
                        console.warn('[GoogleMapsDraw] Could not hook into Livewire commit:', e);
                    }
                }

                function syncBoundariesBeforeSubmit(livewireComponent) {
                    try {
                        // Get boundaries from Alpine.js component
                        const mapComponent = document.querySelector('[x-data*="googleMapsDraw"]');
                        if (!mapComponent) {
                            console.warn('[GoogleMapsDraw] Map component not found');
                            return;
                        }

                        const alpineComponent = Alpine.$data(mapComponent);
                        if (!alpineComponent) {
                            console.warn('[GoogleMapsDraw] Alpine component not found');
                            return;
                        }

                        let boundariesData = null;

                        // Get from coordinates
                        if (alpineComponent.coordinates && alpineComponent.coordinates.length >= 3) {
                            boundariesData = JSON.stringify(alpineComponent.coordinates);
                        }

            if (boundariesData && boundariesData !== '[]' && boundariesData !== 'null') {
                // PRIMARY: Dispatch window event to the outer Alpine component
                window.dispatchEvent(new CustomEvent('boundaries-changed', { detail: boundariesData }));


                // ALSO: Dispatch Livewire event as backup
                if (window.Livewire) {
                    try {
                        Livewire.dispatch('boundaries-updated', { boundaries: boundariesData });

                    } catch (e) {
                        console.warn('[GoogleMapsDraw] Event dispatch failed:', e);
                    }
                }

                // Also update all input fields as backup
                const selectors = [
                    'input[wire\\:model\\.live="data.boundaries"]',
                    'input[wire\\:model="data.boundaries"]',
                    'input[name="data.boundaries"]',
                    'input[name="data[boundaries]"]'
                ];

                selectors.forEach(selector => {
                    try {
                        document.querySelectorAll(selector).forEach(input => {
                            if (input.value !== boundariesData) {
                                input.value = boundariesData;
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        });
                    } catch (e) { }
                });


            }
                    } catch (e) {
                console.error('[GoogleMapsDraw] Error syncing boundaries:', e);
            }
                }
            });
        </script>
    </div>
</div>

@push('scripts-before')
    <script>
        function googleMapsDraw() {
            return {
                map: null,
                drawingManager: null,
                selectedShape: null,
                searchAutocomplete: null,
                searchMarker: null,
                coordinates: [],
                manualLat: '',
                manualLng: '',
                mapInitialized: false,
                mapsLoaded: false,
                loadAttempts: 0,
                maxLoadAttempts: 3,
                debugMode: true,
                initialized: false,
                pendingCenter: null,
                existingZones: [], // Store existing zones for the selected city
                existingZonePolygons: [], // Store polygon objects for existing zones
                currentZone: null, // Currently edited zone data
                lastBoundariesJson: null, // Remember last applied boundaries JSON
                livewireHookRegistered: false,
                lastCityChangeDetail: null, // Remember last city change event

                get boundariesJson() {
                    return JSON.stringify(this.coordinates);
                },

                // Handle external boundaries update
                handleBoundariesUpdate(detail) {
                    if (detail && detail.coordinates) {
                        this.debugLog('Received boundaries update from Livewire');
                        this.createPolygonFromCoordinates(detail.coordinates);
                    }
                },

                // Method to sync coordinates to Livewire
                syncToLivewire() {
                    if (this.coordinates && this.coordinates.length >= 3) {
                        const json = JSON.stringify(this.coordinates);
                        this.debugLog('Syncing to Livewire: ' + json.substring(0, 100) + '...');

                        // PRIMARY: Dispatch window event to the outer Alpine component (outside wire:ignore)
                        // This component will then use $wire.set to update Livewire
                        window.dispatchEvent(new CustomEvent('boundaries-changed', { detail: json }));
                        this.debugLog('Dispatched boundaries-changed window event');

                        // BACKUP: Also try Livewire.dispatch
                        if (window.Livewire) {
                            try {
                                Livewire.dispatch('boundaries-updated', { boundaries: json });
                                this.debugLog('Also dispatched via Livewire.dispatch');
                            } catch (e) {
                                this.debugLog('Livewire.dispatch info: ' + e.message);
                            }
                        }

                        // Also try to set mapBoundaries property directly
                        this.setMapBoundariesProperty(json);
                    }
                },

                // Find the correct Livewire component (the page component, not global search)
                findPageComponent() {
                    // Look for the component that contains the form
                    const formElement = document.querySelector('form[wire\\:submit\\.prevent]') ||
                        document.querySelector('form.fi-form');
                    if (formElement) {
                        const pageElement = formElement.closest('[wire\\:id]');
                        if (pageElement) {
                            const wireId = pageElement.getAttribute('wire:id');
                            if (wireId && window.Livewire) {
                                return Livewire.find(wireId);
                            }
                        }
                    }

                    // Fallback: find the main content area component
                    const mainContent = document.querySelector('.fi-main [wire\\:id]') ||
                        document.querySelector('main [wire\\:id]');
                    if (mainContent) {
                        const wireId = mainContent.getAttribute('wire:id');
                        if (wireId && window.Livewire) {
                            return Livewire.find(wireId);
                        }
                    }

                    return null;
                },

                // Try to set the mapBoundaries property on the Livewire component
                setMapBoundariesProperty(json) {
                    try {
                        const component = this.findPageComponent();
                        if (component) {
                            // Try to set the property directly
                            if (typeof component.set === 'function') {
                                component.set('mapBoundaries', json);
                                this.debugLog('Set mapBoundaries property via component.set()');
                            } else if (typeof component.$set === 'function') {
                                component.$set('mapBoundaries', json);
                                this.debugLog('Set mapBoundaries property via component.$set()');
                            }
                        } else {
                            this.debugLog('Could not find page component');
                        }
                    } catch (e) {
                        this.debugLog('Property set error: ' + e.message);
                    }
                },

                init() {
                    // Prevent double initialization
                    if (this.initialized) {
                        this.debugLog("Already initialized, skipping");
                        return;
                    }
                    this.initialized = true;

                    this.debugLog("Initializing Google Maps Draw component");

                    // Check if we have boundaries from $wire on init
                    if (this.$wire) {
                        try {
                            const wireData = this.$wire.get('data.boundaries');
                            if (wireData && wireData !== '' && wireData !== '[]' && wireData !== 'null') {
                                this.debugLog(`Found boundaries from $wire on init: ${wireData.substring(0, 100)}...`);
                                try {
                                    const coords = JSON.parse(wireData);
                                    if (Array.isArray(coords) && coords.length >= 3) {
                                        this.currentZone = {
                                            id: null,
                                            name: 'Current Zone',
                                            coordinates: coords
                                        };
                                        this.debugLog(`Set currentZone from $wire: ${coords.length} coordinates`);
                                    }
                                } catch (e) {
                                    console.warn('Error parsing $wire boundaries on init:', e);
                                }
                            }
                        } catch (e) {
                            this.debugLog('Could not read from $wire on init: ' + e.message);
                        }
                    }

                    // Listen for city change events from Filament to recenter the map
                    const handleCityChanged = (event) => {
                        try {
                            const detail = event?.detail || {};
                            const lat = parseFloat(detail.lat);
                            const lng = parseFloat(detail.lng);
                            const zones = detail.zones || [];
                            const currentZone = detail.currentZone || null;

                            this.lastCityChangeDetail = detail;
                            const currentZoneInfo = currentZone ? `ID: ${currentZone.id}, Name: ${currentZone.name}` :
                                'none';
                            this.debugLog(
                                `City changed event received: lat=${lat}, lng=${lng}, zones=${zones.length}, currentZone=${currentZoneInfo}`
                            );

                            // Store existing zones and current zone
                            this.existingZones = zones;
                            this.currentZone = currentZone;

                            const hasLatLng = !isNaN(lat) && !isNaN(lng);

                            if (this.map) {
                                if (hasLatLng) {
                                    this.debugLog(`Re-centering map to city: ${lat}, ${lng}`);
                                    this.map.setCenter({
                                        lat,
                                        lng
                                    });
                                    this.map.setZoom(12);
                                }

                                // Display existing zones and current editable polygon
                                this.displayExistingZones();

                                // Always try to display current zone if available
                                if (this.currentZone) {
                                    this.debugLog(
                                        `Attempting to display current zone: ${this.currentZone.id} - ${this.currentZone.name}`
                                    );
                                    this.tryDisplayCurrentZone(true);
                                }
                            } else {
                                // Map not ready yet, store pending data
                                this.debugLog('Map not ready, storing pending center data');
                                this.pendingCenter = {
                                    lat: hasLatLng ? lat : null,
                                    lng: hasLatLng ? lng : null,
                                    zones,
                                    currentZone
                                };

                                // If manual trigger and we have a city selected, try to get currentZone from Livewire
                                if (detail.manualTrigger && !currentZone) {
                                    this.debugLog('Manual trigger detected, checking Livewire for currentZone');
                                    setTimeout(() => {
                                        if (window.Livewire) {
                                            try {
                                                const wireId = document.querySelector('[wire\\:id]')
                                                    ?.getAttribute('wire:id');
                                                if (wireId) {
                                                    const livewireComponent = Livewire.find(wireId);
                                                    if (livewireComponent) {
                                                        const formData = livewireComponent.get('data');
                                                        if (formData && formData.boundaries && formData
                                                            .boundaries !== '[]' && formData.boundaries !== ''
                                                        ) {
                                                            try {
                                                                const coords = typeof formData.boundaries ===
                                                                    'string' ? JSON.parse(formData.boundaries) :
                                                                    formData.boundaries;
                                                                if (coords && Array.isArray(coords) && coords
                                                                    .length >= 3) {
                                                                    this.debugLog(
                                                                        `Found ${coords.length} coordinates from Livewire in manual trigger`
                                                                    );
                                                                    this.currentZone = {
                                                                        id: null,
                                                                        name: 'Current Zone',
                                                                        coordinates: coords
                                                                    };
                                                                    if (this.pendingCenter) {
                                                                        this.pendingCenter.currentZone = this
                                                                            .currentZone;
                                                                    }
                                                                }
                                                            } catch (e) {
                                                                console.error(
                                                                    'Error parsing Livewire boundaries in manual trigger:',
                                                                    e);
                                                            }
                                                        }
                                                    }
                                                }
                                            } catch (e) {
                                                console.warn('Error checking Livewire in manual trigger:', e);
                                            }
                                        }
                                    }, 200);
                                }
                            }
                        } catch (e) {
                            console.warn('Failed to handle zone-city-changed event', e);
                        }
                    };

                    window.addEventListener('zone-city-changed', handleCityChanged);

                    // If we're on an edit page, manually check for city/zone data after a short delay
                    // This handles cases where the event fired before the listener was set up
                    setTimeout(() => {
                        const citySelect = document.querySelector('select[name="data.city_id"]') ||
                            document.querySelector('select[id$="city_id"]');
                        if (citySelect && citySelect.value) {
                            this.debugLog(
                                `Detected pre-selected city: ${citySelect.value}, currentZone: ${this.currentZone ? 'yes' : 'no'}, existingZones: ${this.existingZones.length}`
                            );

                            // Try multiple methods to get boundaries data
                            // Method 1: Check Livewire component data directly
                            if (!this.currentZone && window.Livewire) {
                                try {
                                    const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
                                    if (wireId) {
                                        const livewireComponent = Livewire.find(wireId);
                                        if (livewireComponent) {
                                            // Try to get from data object
                                            const formData = livewireComponent.get('data');
                                            if (formData && formData.boundaries && formData.boundaries !== '[]' &&
                                                formData.boundaries !== '') {
                                                this.debugLog(
                                                    `Found boundaries in Livewire data: ${formData.boundaries.substring(0, 50)}...`
                                                );
                                                try {
                                                    const coords = typeof formData.boundaries === 'string' ? JSON
                                                        .parse(formData.boundaries) : formData.boundaries;
                                                    if (coords && Array.isArray(coords) && coords.length >= 3) {
                                                        this.currentZone = {
                                                            id: null,
                                                            name: 'Current Zone',
                                                            coordinates: coords
                                                        };
                                                        this.debugLog(
                                                            `Set currentZone from Livewire data: ${coords.length} coordinates`
                                                        );
                                                    }
                                                } catch (e) {
                                                    console.error('Error parsing Livewire boundaries:', e);
                                                }
                                            }
                                        }
                                    }
                                } catch (e) {
                                    console.warn('Error checking Livewire component:', e);
                                }
                            }

                            // Method 2: If we still don't have currentZone, try boundaries field
                            if (!this.currentZone) {
                                this.debugLog(
                                    'No currentZone from Livewire, attempting to load from boundaries field');
                                setTimeout(() => {
                                    const boundariesData = this.getBoundariesData();
                                    if (boundariesData && boundariesData !== '[]' && boundariesData !==
                                        'null' && boundariesData !== '') {
                                        try {
                                            const coords = JSON.parse(boundariesData);
                                            if (coords && coords.length >= 3) {
                                                this.debugLog(
                                                    `Found boundaries in field: ${coords.length} coordinates`
                                                );
                                                this.currentZone = {
                                                    id: null,
                                                    name: 'Current Zone',
                                                    coordinates: coords
                                                };
                                            }
                                        } catch (e) {
                                            console.error('Error parsing boundaries data:', e);
                                        }
                                    } else {
                                        this.debugLog('Boundaries field is empty');
                                    }

                                    // If we now have currentZone and map is ready, display it
                                    if (this.currentZone && this.map) {
                                        this.tryDisplayCurrentZone(true);
                                    } else if (this.currentZone) {
                                        // Wait for map
                                        setTimeout(() => {
                                            if (this.map && this.currentZone) {
                                                this.tryDisplayCurrentZone(true);
                                            }
                                        }, 1000);
                                    }
                                }, 500);
                            } else if (this.currentZone && this.map) {
                                // If we got currentZone from Livewire and map is ready, display it
                                this.tryDisplayCurrentZone(true);
                            }
                        }
                    }, 300);

                    // Check if city is already selected on page load (edit mode)
                    // This handles the case where afterStateHydrated fired before listener was ready
                    setTimeout(() => {
                        const citySelect = document.querySelector('select[name="data.city_id"]') ||
                            document.querySelector('select[id$="city_id"]');
                        if (citySelect && citySelect.value && !this.currentZone && !this.pendingCenter) {
                            this.debugLog('City already selected on page load, manually checking for zone data');

                            // Try to get data from Livewire first
                            if (window.Livewire) {
                                try {
                                    const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
                                    if (wireId) {
                                        const livewireComponent = Livewire.find(wireId);
                                        if (livewireComponent) {
                                            // Get city data to trigger proper event
                                            const formData = livewireComponent.get('data');
                                            const cityId = formData?.city_id || citySelect.value;

                                            // Get boundaries from form data
                                            if (formData && formData.boundaries && formData.boundaries !== '[]' &&
                                                formData.boundaries !== '') {
                                                try {
                                                    const coords = typeof formData.boundaries === 'string' ? JSON
                                                        .parse(formData.boundaries) : formData.boundaries;
                                                    if (coords && Array.isArray(coords) && coords.length >= 3) {
                                                        this.debugLog(
                                                            `Found ${coords.length} coordinates in Livewire on page load`
                                                        );
                                                        this.currentZone = {
                                                            id: null,
                                                            name: 'Current Zone',
                                                            coordinates: coords
                                                        };
                                                        // Store in pendingCenter so map can display it when ready
                                                        if (!this.pendingCenter) {
                                                            this.pendingCenter = {
                                                                lat: null,
                                                                lng: null,
                                                                zones: [],
                                                                currentZone: this.currentZone
                                                            };
                                                        } else {
                                                            this.pendingCenter.currentZone = this.currentZone;
                                                        }
                                                    }
                                                } catch (e) {
                                                    console.error('Error parsing Livewire boundaries on page load:',
                                                        e);
                                                }
                                            }
                                        }
                                    }
                                } catch (e) {
                                    console.warn('Error checking Livewire on page load:', e);
                                }
                            }
                        }
                    }, 100);

                    this.registerLivewireHooks();

                    // Check if Google Maps is already loaded
                    if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                        this.debugLog("Google Maps already loaded, creating map immediately");
                        this.mapsLoaded = true;
                        setTimeout(() => this.createMap(), 100);
                        return;
                    }

                    // Wait for DOM to be fully ready
                    if (document.readyState === 'loading') {
                        this.debugLog("DOM still loading, waiting...");
                        document.addEventListener('DOMContentLoaded', () => {
                            setTimeout(() => {
                                this.loadGoogleMaps();
                            }, 500);
                        });
                    } else {
                        // DOM is already ready
                        this.debugLog("DOM ready, loading Google Maps");
                        setTimeout(() => {
                            this.loadGoogleMaps();
                        }, 500);
                    }
                },

                debugLog(message) {
                    if (this.debugMode) {

                    }
                },

                loadGoogleMaps() {
                    if (this.mapsLoaded) {
                        this.debugLog("Maps already loaded, creating map");
                        this.createMap();
                        return;
                    }

                    this.loadAttempts++;
                    this.debugLog(`Loading Google Maps API... Attempt ${this.loadAttempts}/${this.maxLoadAttempts}`);
                    this.updateMapDisplay(
                        `Loading Google Maps API... Attempt ${this.loadAttempts}/${this.maxLoadAttempts}`);

                    // Check if script is already loaded
                    const existingScript = document.querySelector('script[src*="maps.googleapis.com"]');
                    if (existingScript) {
                        this.debugLog("Google Maps script already exists, waiting for it to load");
                        this.waitForGoogleMaps();
                        return;
                    }

                    // Get API key with debugging
                    const apiKey = '{{ config('services.google_maps.api_key') }}';
                    this.debugLog('Using API Key: ' + (apiKey ? apiKey.substring(0, 10) + '...' : 'NONE'));

                    if (!apiKey) {
                        this.updateMapDisplay('❌ No Google Maps API key configured');
                        return;
                    }

                    // Create unique callback function
                    const callbackName = 'initGoogleMaps_' + Date.now();
                    this.debugLog('Creating callback: ' + callbackName);

                    window[callbackName] = () => {
                        this.debugLog('Google Maps callback triggered');
                        this.mapsLoaded = true;
                        this.createMap();
                        delete window[callbackName];
                    };

                    // Create and load the script
                    const script = document.createElement('script');
                    script.src = 'https://maps.googleapis.com/maps/api/js?key=' + apiKey +
                        '&libraries=places,drawing,geometry&callback=' + callbackName;
                    script.async = true;
                    script.defer = true;

                    this.debugLog('Loading script: ' + script.src);

                    // Add timeout to detect if script fails to load
                    const timeout = setTimeout(() => {
                        this.debugLog('Script load timeout reached');
                        this.handleLoadFailure();
                        delete window[callbackName];
                    }, 15000); // Increased timeout to 15 seconds

                    script.onload = () => {
                        this.debugLog('Script loaded successfully');
                        clearTimeout(timeout);
                    };

                    script.onerror = (error) => {
                        this.debugLog('Script load error:', error);
                        clearTimeout(timeout);
                        this.handleLoadFailure();
                        delete window[callbackName];
                    };

                    document.head.appendChild(script);
                },

                handleLoadFailure() {
                    if (this.loadAttempts < this.maxLoadAttempts) {
                        this.updateMapDisplay(
                            `Google Maps failed to load (Attempt ${this.loadAttempts}/${this.maxLoadAttempts}). Retrying...`
                        );
                        setTimeout(() => {
                            this.loadGoogleMaps();
                        }, 2000);
                    } else {
                        this.updateMapDisplay(
                            'Google Maps failed to load after multiple attempts. Please use manual coordinate input below or click "Load Google Maps" to retry.'
                        );
                    }
                },

                waitForGoogleMaps() {
                    let attempts = 0;
                    const maxAttempts = 30; // Increased attempts

                    const checkInterval = setInterval(() => {
                        attempts++;

                        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                            clearInterval(checkInterval);
                            this.mapsLoaded = true;
                            this.createMap();
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkInterval);
                            this.handleLoadFailure();
                        }
                    }, 500);
                },

                createMap() {
                    try {
                        this.debugLog('Creating map...');

                        // Ensure Google Maps API is loaded
                        if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                            this.debugLog('Google Maps API not loaded');
                            this.updateMapDisplay('Google Maps API not loaded. Please try again.');
                            return;
                        }

                        // Ensure required libraries are loaded
                        if (typeof google.maps.drawing === 'undefined') {
                            this.debugLog('Google Maps Drawing library not loaded');
                            this.updateMapDisplay('Google Maps Drawing library not loaded. Please try again.');
                            return;
                        }

                        this.debugLog('Google Maps API and Drawing library loaded successfully');

                        // Get the map container
                        const mapElement = document.getElementById('map');
                        if (!mapElement) {
                            this.debugLog('Map container not found');
                            this.updateMapDisplay('Map container not found. Please refresh the page.');
                            return;
                        }

                        this.debugLog(
                            `Map container found. Current dimensions: ${mapElement.offsetWidth}x${mapElement.offsetHeight}`
                        );

                        // Clear the loading content and ensure proper styling
                        mapElement.innerHTML = '';
                        mapElement.style.position = 'relative';
                        mapElement.style.overflow = 'hidden';
                        mapElement.style.width = '100%';
                        mapElement.style.height = '400px';
                        mapElement.style.display = 'block';
                        mapElement.style.visibility = 'visible';
                        mapElement.style.minHeight = '400px';
                        mapElement.style.backgroundColor = '#f0f0f0';

                        // Force a layout recalculation
                        mapElement.offsetHeight;

                        this.debugLog(
                            `Map container styled. New dimensions: ${mapElement.offsetWidth}x${mapElement.offsetHeight}`
                        );

                        // Check if map container has proper dimensions
                        if (mapElement.offsetWidth === 0 || mapElement.offsetHeight === 0) {
                            this.debugLog('Map container has zero dimensions, waiting...');
                            this.updateMapDisplay('Waiting for page to fully load...');

                            // Try multiple times with increasing delays
                            let attempts = 0;
                            const maxAttempts = 5;
                            const retryInterval = setInterval(() => {
                                attempts++;
                                this.debugLog(
                                    `Retry attempt ${attempts}: ${mapElement.offsetWidth}x${mapElement.offsetHeight}`
                                );

                                if (mapElement.offsetWidth > 0 && mapElement.offsetHeight > 0) {
                                    clearInterval(retryInterval);
                                    this.debugLog('Map container ready, retrying createMap');
                                    this.createMap();
                                } else if (attempts >= maxAttempts) {
                                    clearInterval(retryInterval);
                                    this.debugLog('Max attempts reached, map container still not ready');
                                    this.updateMapDisplay('Map container not ready. Please refresh the page.');
                                }
                            }, 1000);
                            return;
                        }

                        // Initialize the map
                        this.debugLog('Creating Google Maps instance...');
                        const mapInstance = new google.maps.Map(mapElement, {
                            center: {
                                lat: 21.1702,
                                lng: 72.8311
                            }, // Default to Surat
                            zoom: 12,
                            mapTypeId: google.maps.MapTypeId.ROADMAP,
                            mapTypeControl: true,
                            streetViewControl: true,
                            fullscreenControl: true,
                            zoomControl: true,
                            scaleControl: true
                        });

                        // Store the map instance in the component
                        this.map = mapInstance;
                        this.debugLog('Google Maps instance created successfully');

                        // Wait for map to be ready before adding drawing manager
                        google.maps.event.addListenerOnce(this.map, 'idle', () => {
                            this.debugLog('Map idle event fired, initializing drawing manager...');

                            // Initialize drawing manager
                            this.drawingManager = new google.maps.drawing.DrawingManager({
                                drawingMode: null,
                                drawingControl: true,
                                drawingControlOptions: {
                                    position: google.maps.ControlPosition.TOP_CENTER,
                                    drawingModes: [google.maps.drawing.OverlayType.POLYGON]
                                },
                                polygonOptions: {
                                    fillColor: '#FF0000',
                                    fillOpacity: 0.3,
                                    strokeWeight: 2,
                                    strokeColor: '#FF0000',
                                    clickable: true,
                                    editable: true,
                                    zIndex: 1
                                }
                            });

                            this.drawingManager.setMap(this.map);
                            this.debugLog('Drawing manager attached to map');

                            // Listen for polygon completion
                            google.maps.event.addListener(this.drawingManager, 'polygoncomplete', (polygon) => {
                                this.debugLog('Polygon completed');
                                this.handlePolygonComplete(polygon);
                            });

                            this.mapInitialized = true;
                            this.debugLog('Map initialization completed');

                            // Load existing boundaries after a short delay to ensure everything is ready
                            // Try multiple times with increasing delays since Filament might populate the field later
                            let loadAttempts = 0;
                            const maxLoadAttempts = 5;
                            const tryLoadBoundaries = () => {
                                loadAttempts++;
                                const boundariesData = this.getBoundariesData();
                                if (boundariesData) {
                                    this.debugLog('Found boundaries data, loading polygon...');
                                    if (this.applyBoundariesJson(boundariesData, 'initial-load')) {
                                        return; // Success, stop trying
                                    }
                                }

                                // Try again if not found and haven't exceeded max attempts
                                if (loadAttempts < maxLoadAttempts) {
                                    setTimeout(tryLoadBoundaries, 500 * loadAttempts); // Increasing delay
                                }
                            };

                            setTimeout(tryLoadBoundaries, 500);

                            // Initialize the place search box
                            this.initSearchAutocomplete();

                            // Apply pending center or try geocoding the currently selected city label
                            setTimeout(() => {
                                if (this.pendingCenter) {
                                    if (!isNaN(this.pendingCenter.lat) && !isNaN(this.pendingCenter.lng)) {
                                        this.debugLog(
                                            `Applying pending center ${this.pendingCenter.lat}, ${this.pendingCenter.lng}`
                                        );
                                        this.map.setCenter({
                                            lat: this.pendingCenter.lat,
                                            lng: this.pendingCenter.lng
                                        });
                                        this.map.setZoom(12);
                                    }

                                    if (Array.isArray(this.pendingCenter.zones)) {
                                        this.existingZones = this.pendingCenter.zones;
                                    }

                                    if (this.pendingCenter.currentZone) {
                                        this.currentZone = this.pendingCenter.currentZone;
                                    }

                                    if (this.existingZones.length > 0) {
                                        this.displayExistingZones();
                                    }

                                    if (this.currentZone) {
                                        this.tryDisplayCurrentZone(true);
                                    }
                                    this.pendingCenter = null;
                                } else {
                                    this.tryCenterFromSelectedCityLabel();

                                    // Always try to load from boundaries field first (for edit mode)
                                    // This handles the case where city is pre-selected on page load
                                    this.loadExistingBoundaries();

                                    // Then display other zones
                                    if (this.existingZones.length > 0) {
                                        this.displayExistingZones();
                                    }

                                    // Finally, try to display current zone if available from event
                                    if (this.currentZone) {
                                        this.tryDisplayCurrentZone(true);
                                    }
                                }
                            }, 200);

                            // Force a resize to ensure proper rendering
                            setTimeout(() => {
                                this.debugLog('Triggering map resize');
                                google.maps.event.trigger(this.map, 'resize');
                            }, 100);

                            // Final check: After map is fully ready, try to display current zone if we have it
                            // This handles cases where event fired before map or boundaries field wasn't ready
                            setTimeout(() => {
                                this.debugLog(
                                    `Final check - currentZone: ${this.currentZone ? 'yes' : 'no'}, map: ${this.map ? 'ready' : 'not ready'}`
                                );

                                if (this.map && this.lastCityChangeDetail && !this.selectedShape) {
                                    this.applyStoredCityChange();
                                }

                                if (this.map && !this.selectedShape) {
                                    // Try currentZone first (from event)
                                    if (this.currentZone && this.currentZone.coordinates && this.currentZone
                                        .coordinates.length >= 3) {
                                        this.debugLog(
                                            'Final check: Attempting to display currentZone from event data'
                                        );
                                        this.tryDisplayCurrentZone(true);
                                    } else {
                                        // Fallback: check boundaries field
                                        this.debugLog(
                                            'Final check: No currentZone, checking boundaries field');
                                        const boundariesData = this.getBoundariesData();
                                        if (boundariesData && boundariesData !== '[]' && boundariesData !==
                                            'null' && boundariesData !== '') {
                                            try {
                                                const coords = JSON.parse(boundariesData);
                                                if (coords && coords.length >= 3) {
                                                    this.debugLog(
                                                        `Final check: Found ${coords.length} coordinates in boundaries field`
                                                    );
                                                    this.currentZone = {
                                                        id: null,
                                                        name: 'Current Zone',
                                                        coordinates: coords
                                                    };
                                                    this.tryDisplayCurrentZone(true);
                                                }
                                            } catch (e) {
                                                console.error('Final check: Error parsing boundaries:', e);
                                            }
                                        } else {
                                            this.debugLog('Final check: Boundaries field is empty');
                                        }
                                    }
                                }
                            }, 1500);
                        });

                    } catch (error) {
                        console.error('Error creating map:', error);
                        this.updateMapDisplay('Failed to create Google Map. Use manual coordinate input below.');
                    }
                },

                handlePolygonComplete(polygon) {

                    const self = this;

                    // Clear any existing polygon and its listeners
                    if (this.selectedShape) {
                        google.maps.event.clearInstanceListeners(this.selectedShape);
                        if (this.selectedShape.getPath) {
                            google.maps.event.clearInstanceListeners(this.selectedShape.getPath());
                        }
                        this.selectedShape.setMap(null);
                    }
                    this.selectedShape = polygon;

                    // Reset drawing mode
                    this.drawingManager.setDrawingMode(null);

                    // Make polygon editable
                    polygon.setEditable(true);
                    polygon.setDraggable(true);

                    // Extract coordinates from the NEW polygon and sync
                    this.updateCoordinatesFromPolygon(polygon);

                // Listen for changes on the path
                const path = polygon.getPath();

                path.addListener('set_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                path.addListener('insert_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                path.addListener('remove_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                // Also listen for polygon drag
                polygon.addListener('dragend', function () {

                    self.updateCoordinatesFromPolygon(polygon);
                });
            },

                // Debounce timer for syncing
                syncDebounceTimer: null,

                    updateCoordinatesFromPolygon(polygon) {
                const path = polygon.getPath();
                this.coordinates = [];

                for (let i = 0; i < path.getLength(); i++) {
                    const point = path.getAt(i);
                    this.coordinates.push({
                        lat: point.lat(),
                        lng: point.lng()
                    });
                }

                // Update boundaries data
                const json = JSON.stringify(this.coordinates);
                this.debugLog('Coordinates updated: ' + json.substring(0, 100) + '...');

                // Update the hidden input field immediately
                if (this.$refs.boundariesInput) {
                    this.$refs.boundariesInput.value = json;
                    this.$refs.boundariesInput.dispatchEvent(new Event('input', { bubbles: true }));
                    this.$refs.boundariesInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                // Also update any Filament-generated boundaries inputs
                const boundariesSelectors = [
                    'input[wire\\:model\\.live="data.boundaries"]',
                    'input[wire\\:model="data.boundaries"]',
                    'input[name="data.boundaries"]',
                    'input[name="data[boundaries]"]'
                ];

                boundariesSelectors.forEach(selector => {
                    try {
                        document.querySelectorAll(selector).forEach(input => {
                            if (input.value !== json) {
                                input.value = json;
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                this.debugLog('Updated input field: ' + selector);
                            }
                        });
                    } catch (e) { }
                });

                // Debounced sync to Livewire (to avoid too many requests while dragging)
                this.debouncedSyncToLivewire(json);


            },

            // Sync immediately (no debounce - we need the data to be available for save)
            debouncedSyncToLivewire(json) {
                // Sync immediately - the save button might be clicked at any time
                this.syncToLivewire();
            },

            startDrawing() {
                if (this.drawingManager) {
                    this.drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
                } else {
                    alert('Please load Google Maps first by clicking "Load Google Maps"');
                }
            },

            clearPolygon() {
                if (this.selectedShape) {
                    this.selectedShape.setMap(null);
                    this.selectedShape = null;
                }
                this.coordinates = [];

                // Clear the boundaries data via $wire
                if (this.$wire) {
                    try {
                        this.$wire.set('data.boundaries', '');
                        this.debugLog('Cleared boundaries via $wire');
                    } catch (e) {
                        console.warn('Could not clear via $wire:', e);
                    }
                }

                // Also clear the hidden input
                const json = JSON.stringify([]);
                if (this.$refs.boundariesInput) {
                    this.$refs.boundariesInput.value = json;
                    this.$refs.boundariesInput.dispatchEvent(new Event('input', { bubbles: true }));
                    this.$refs.boundariesInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (this.drawingManager) {
                    this.drawingManager.setDrawingMode(null);
                }
            },

            removeCoordinate(index) {
                this.coordinates.splice(index, 1);
            },

            addManualCoordinate() {
                const lat = parseFloat(this.manualLat);
                const lng = parseFloat(this.manualLng);

                if (isNaN(lat) || isNaN(lng)) {
                    alert('Please enter valid latitude and longitude values');
                    return;
                }

                this.coordinates.push({
                    lat,
                    lng
                });

                // Clear inputs
                this.manualLat = '';
                this.manualLng = '';
            },

            addQuickCoordinate(lat, lng, name) {
                this.coordinates.push({
                    lat,
                    lng
                });
            },

            getBoundariesData() {
                // Try multiple ways to get the boundaries data
                let boundariesData = null;

                // Method 0: Try to get from $wire first
                if (this.$wire) {
                    try {
                        const wireData = this.$wire.get('data.boundaries');
                        if (wireData && wireData !== '' && wireData !== '[]' && wireData !== 'null') {
                            try {
                                const parsed = JSON.parse(wireData);
                                if (Array.isArray(parsed) && parsed.length >= 3) {
                                    this.debugLog(`Found boundaries from $wire: ${wireData.substring(0, 100)}...`);
                                    return wireData;
                                }
                            } catch (e) { }
                        }
                    } catch (e) {
                        this.debugLog('Could not get from $wire: ' + e.message);
                    }
                }

                // Method 1: Check Livewire component data (has the form state)
                // This is where mutateFormDataBeforeFill stores the JSON data
                if (window.Livewire) {
                    try {
                        const wireId = document.querySelector('[wire\\:id]')?.getAttribute('wire:id');
                        if (wireId) {
                            const livewireComponent = Livewire.find(wireId);
                            if (livewireComponent) {
                                // Try to get from data object (where form state is stored)
                                const formData = livewireComponent.get('data');
                                if (formData && formData.boundaries) {
                                    const data = formData.boundaries;
                                    // Validate it's not empty or invalid
                                    if (data && data !== '' && data !== '[]' && data !== 'null' && data !==
                                        'undefined') {
                                        boundariesData = typeof data === 'string' ? data : JSON.stringify(data);
                                        this.debugLog(
                                            `Found boundaries from Livewire data: ${boundariesData.substring(0, 100)}...`
                                        );
                                    }
                                }
                                // Also try direct get
                                if (!boundariesData) {
                                    const directData = livewireComponent.get('data.boundaries');
                                    if (directData && directData !== '' && directData !== '[]' && directData !==
                                        'null' && directData !== 'undefined') {
                                        boundariesData = typeof directData === 'string' ? directData : JSON.stringify(
                                            directData);
                                        this.debugLog(
                                            `Found boundaries from Livewire get: ${boundariesData.substring(0, 100)}...`
                                        );
                                    }
                                }

                                // Also try to get from the snapshot (initial state)
                                if (!boundariesData && livewireComponent.get('snapshot')) {
                                    const snapshot = livewireComponent.get('snapshot');
                                    if (snapshot?.data?.boundaries) {
                                        const snapData = snapshot.data.boundaries;
                                        if (snapData && snapData !== '' && snapData !== '[]' && snapData !== 'null') {
                                            boundariesData = typeof snapData === 'string' ? snapData : JSON.stringify(
                                                snapData);
                                            this.debugLog(
                                                `Found boundaries from Livewire snapshot: ${boundariesData.substring(0, 100)}...`
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    } catch (e) {
                        console.warn('Error checking Livewire component:', e);
                    }
                }

                // Method 2: Check Filament's generated hidden input fields (fallback)
                // Only check if Livewire didn't have it, and validate values properly
                if (!boundariesData) {
                    const boundariesSelectors = [
                        'input[name="data.boundaries"]',
                        'input[name="data[boundaries]"]',
                        'input[wire\\:model*="boundaries"]',
                        'input[name*="boundaries"][type="hidden"]',
                        'input[id*="boundaries"]',
                        'input[name="boundaries"]'
                    ];

                    for (const selector of boundariesSelectors) {
                        try {
                            const input = document.querySelector(selector);
                            if (input && input.value) {
                                const value = input.value.trim();
                                // Validate it's a valid non-empty JSON array
                                if (value && value !== '' && value !== '[]' && value !== 'null' && value !==
                                    'undefined') {
                                    // Try to parse to validate it's valid JSON
                                    try {
                                        const parsed = JSON.parse(value);
                                        if (Array.isArray(parsed) && parsed.length >= 3) {
                                            boundariesData = value;
                                            this.debugLog(
                                                `Found boundaries data from input: ${selector} (${parsed.length} coordinates)`
                                            );
                                            break;
                                        }
                                    } catch (e) {
                                        // Not valid JSON, skip
                                    }
                                }
                            }
                        } catch (e) {
                            console.warn('Error with selector:', selector, e);
                        }
                    }
                }

                // Method 3: Check for data attributes on the form
                if (!boundariesData) {
                    const form = document.querySelector('form');
                    if (form && form.dataset.boundaries) {
                        boundariesData = form.dataset.boundaries;

                    }
                }

                return boundariesData;
            },

            loadExistingBoundaries() {
                // Use the helper function to get boundaries data
                const boundariesData = this.getBoundariesData();


                if (boundariesData) {
                    if (this.applyBoundariesJson(boundariesData, 'loadExistingBoundaries')) {
                        return;
                    }
                } else {

                }
            },

            createPolygonFromCoordinates(coords) {
                if (!this.map) return;

                if (this.selectedShape) {
                    // Clear old listeners
                    google.maps.event.clearInstanceListeners(this.selectedShape);
                    if (this.selectedShape.getPath) {
                        google.maps.event.clearInstanceListeners(this.selectedShape.getPath());
                    }
                    this.selectedShape.setMap(null);
                    this.selectedShape = null;
                }

                this.coordinates = coords;
                const self = this; // Capture reference for callbacks

                const polygon = new google.maps.Polygon({
                    paths: coords,
                    fillColor: '#FF0000',
                    fillOpacity: 0.3,
                    strokeWeight: 2,
                    strokeColor: '#FF0000',
                    map: this.map,
                    editable: true,
                    draggable: true
                });

                this.selectedShape = polygon;

                // Fit map to polygon
                const bounds = new google.maps.LatLngBounds();
                coords.forEach(coord => {
                    bounds.extend(new google.maps.LatLng(coord.lat, coord.lng));
                });
                this.map.fitBounds(bounds);

                // Listen for changes on the PATH - this handles vertex edits
                const path = polygon.getPath();

                path.addListener('set_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                path.addListener('insert_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                path.addListener('remove_at', function (index) {

                    self.updateCoordinatesFromPolygon(polygon);
                });

                // Also listen for polygon drag end (when dragging the whole polygon)
                polygon.addListener('dragend', function () {

                    self.updateCoordinatesFromPolygon(polygon);
                });


            },

            updateMapDisplay(message) {
                const mapElement = document.getElementById('map');
                if (mapElement) {
                    mapElement.innerHTML = `
                                <div class="w-full h-full flex items-center justify-center">
                                    <div class="text-center">
                                        <div class="text-4xl mb-2">🗺️</div>
                                        <div class="text-lg font-medium text-gray-700 mb-2">${message}</div>
                                        <div class="text-sm text-gray-500">Click "Load Google Maps" to retry or use manual input below</div>
                                    </div>
                                </div>
                            `;
                }
            },

            testApiKey() {
                if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                    alert('Google Maps API is loaded. API Key: {{ config('services.google_maps.api_key') }}');
                } else {
                    alert('Google Maps API is NOT loaded. Please ensure the API key is correct and not blocked.');
                }
            },

            initSearchAutocomplete() {
                try {
                    const input = this.$refs.searchInput;
                    if (!input || !this.map || !google.maps.places) return;
                    this.searchAutocomplete = new google.maps.places.Autocomplete(input, {
                        fields: ['geometry', 'name'],
                        types: ['geocode']
                    });

                    this.searchAutocomplete.addListener('place_changed', () => {
                        const place = this.searchAutocomplete.getPlace();
                        if (!place || !place.geometry) return;

                        // Clear previous marker
                        if (this.searchMarker) {
                            this.searchMarker.setMap(null);
                            this.searchMarker = null;
                        }

                        if (place.geometry.viewport) {
                            this.map.fitBounds(place.geometry.viewport);
                        } else if (place.geometry.location) {
                            this.map.setCenter(place.geometry.location);
                            this.map.setZoom(14);
                        }

                        // Add a marker to indicate searched spot
                        if (place.geometry.location) {
                            this.searchMarker = new google.maps.Marker({
                                position: place.geometry.location,
                                map: this.map
                            });
                        }
                    });

                    // Bias results around current viewport
                    this.map.addListener('bounds_changed', () => {
                        if (this.searchAutocomplete) {
                            this.searchAutocomplete.setBounds(this.map.getBounds());
                        }
                    });
                } catch (e) {
                    console.warn('initSearchAutocomplete error', e);
                }
            },

            tryCenterFromSelectedCityLabel() {
                try {
                    if (!this.map || typeof google === 'undefined' || !google.maps) return;

                    // Filament native select; get selected option text
                    const select = document.querySelector('select[name="data.city_id"]') || document.querySelector(
                        'select[id$="city_id"]');
                    const label = select ? (select.options[select.selectedIndex]?.text || '').trim() : '';
                    if (!label) return;

                    this.debugLog(`Attempting geocode for selected city label: ${label}`);
                    const geocoder = new google.maps.Geocoder();
                    geocoder.geocode({
                        address: label
                    }, (results, status) => {
                        if (status === 'OK' && results && results[0]) {
                            const loc = results[0].geometry.location;
                            this.map.setCenter(loc);
                            this.map.setZoom(12);
                        } else {
                            this.debugLog(`Geocoder failed: ${status}`);
                        }
                    });
                } catch (e) {
                    console.warn('tryCenterFromSelectedCityLabel error', e);
                }
            },

            tryDisplayCurrentZone(force = false) {
                try {
                    if (!this.map) {
                        return;
                    }

                    if (!this.currentZone || !Array.isArray(this.currentZone.coordinates)) {
                        return;
                    }

                    const coords = this.currentZone.coordinates;
                    if (!coords || coords.length < 3) {
                        return;
                    }

                    const newCoordsJson = JSON.stringify(coords);
                    const currentCoordsJson = JSON.stringify(this.coordinates || []);

                    if (
                        !force &&
                        this.coordinates &&
                        this.coordinates.length >= 3 &&
                        newCoordsJson === currentCoordsJson
                    ) {
                        return;
                    }

                    this.createPolygonFromCoordinates(coords);
                } catch (e) {
                    console.warn('tryDisplayCurrentZone error', e);
                }
            },

            applyStoredCityChange() {
                try {
                    if (!this.lastCityChangeDetail || !this.map) {
                        return;
                    }

                    this.debugLog('Re-applying stored city change after map ready');
                    const detail = this.lastCityChangeDetail;
                    const lat = parseFloat(detail.lat);
                    const lng = parseFloat(detail.lng);
                    const zones = detail.zones || [];
                    const hasLatLng = !isNaN(lat) && !isNaN(lng);

                    if (zones.length) {
                        this.existingZones = zones;
                        this.displayExistingZones();
                    }

                    if (hasLatLng) {
                        this.map.setCenter({
                            lat,
                            lng
                        });
                        this.map.setZoom(12);
                    }

                    if (this.currentZone) {
                        this.tryDisplayCurrentZone(true);
                    }
                } catch (e) {
                    console.warn('applyStoredCityChange error', e);
                }
            },

            registerLivewireHooks() {
                const attachHook = () => {
                    if (this.livewireHookRegistered || !window.Livewire || typeof window.Livewire.hook !==
                        'function') {
                        return;
                    }
                    this.livewireHookRegistered = true;
                    window.Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                        succeed(({ snapshot, effect }) => {
                            this.handleLivewireProcessed();
                        });
                    });
                    this.debugLog('Registered Livewire commit hook');
                };

                if (window.Livewire && typeof window.Livewire.hook === 'function') {
                    attachHook();
                } else {
                    document.addEventListener('livewire:initialized', () => {
                        attachHook();
                    }, {
                        once: true
                    });
                }
            },

            handleLivewireProcessed() {
                try {
                    const boundariesJson = this.getBoundariesData();
                    if (this.isValidBoundariesJson(boundariesJson) && boundariesJson !== this.lastBoundariesJson) {
                        this.debugLog('Livewire processed update with new boundaries');
                        this.applyBoundariesJson(boundariesJson, 'livewire');
                    }
                } catch (e) {
                    console.warn('handleLivewireProcessed error', e);
                }
            },

            isValidBoundariesJson(data) {
                if (!data || typeof data !== 'string') {
                    return false;
                }
                const trimmed = data.trim();
                if (!trimmed || trimmed === '[]' || trimmed === 'null' || trimmed === 'undefined') {
                    return false;
                }
                try {
                    const parsed = JSON.parse(trimmed);
                    return Array.isArray(parsed) && parsed.length >= 3;
                } catch (e) {
                    return false;
                }
            },

            applyBoundariesJson(json, source = 'unknown') {
                try {
                    const coords = JSON.parse(json);
                    if (!Array.isArray(coords) || coords.length < 3) {
                        return false;
                    }

                    this.lastBoundariesJson = json;
                    this.currentZone = this.currentZone || {
                        id: null,
                        name: 'Current Zone',
                        coordinates: coords
                    };
                    this.currentZone.coordinates = coords;

                    this.debugLog(`Applying ${coords.length} boundary points from ${source}`);

                    if (this.map) {
                        this.createPolygonFromCoordinates(coords);
                    }

                    return true;
                } catch (e) {
                    console.warn('applyBoundariesJson error', e);
                    return false;
                }
            },

            displayExistingZones() {
                try {
                    if (!this.map || typeof google === 'undefined' || !google.maps) {
                        this.debugLog('Map not ready for displaying existing zones');
                        return;
                    }

                    // Clear any existing zone polygons
                    this.clearExistingZones();

                    if (!this.existingZones || this.existingZones.length === 0) {
                        this.debugLog('No existing zones to display');
                        return;
                    }

                    const activeZoneId = this.currentZone?.id;
                    this.debugLog(`Displaying ${this.existingZones.length} existing zones`);

                    // Display each zone as a red polygon
                    this.existingZones.forEach((zone, index) => {
                        if (activeZoneId && zone.id === activeZoneId) {
                            return;
                        }

                        if (!zone.coordinates || zone.coordinates.length < 3) {
                            this.debugLog(`Zone ${zone.id} (${zone.name}) has invalid coordinates`);
                            return;
                        }

                        try {
                            // Convert coordinates to Google Maps LatLng format
                            const path = zone.coordinates.map(coord =>
                                new google.maps.LatLng(coord.lat, coord.lng)
                            );

                            // Create a red polygon for existing zone
                            const polygon = new google.maps.Polygon({
                                paths: path,
                                fillColor: '#FF0000', // Red fill
                                fillOpacity: 0.2, // Semi-transparent
                                strokeColor: '#FF0000', // Red stroke
                                strokeWeight: 3, // Thicker stroke to make it stand out
                                strokeOpacity: 0.8,
                                map: this.map,
                                editable: false, // Don't allow editing existing zones
                                draggable: false, // Don't allow dragging existing zones
                                zIndex: 0 // Behind new drawings
                            });

                            // Create an info window with zone name
                            const infoWindow = new google.maps.InfoWindow({
                                content: `<div style="padding: 5px;"><strong>${zone.name || 'Zone ' + zone.id}</strong><br/>Existing Zone</div>`
                            });

                            // Show info window on click
                            polygon.addListener('click', () => {
                                // Close all other info windows first
                                this.existingZonePolygons.forEach(existing => {
                                    if (existing.infoWindow) {
                                        existing.infoWindow.close();
                                    }
                                });
                                infoWindow.open(this.map, polygon);
                            });

                            // Store the polygon and info window
                            this.existingZonePolygons.push({
                                polygon: polygon,
                                infoWindow: infoWindow,
                                zoneId: zone.id,
                                zoneName: zone.name
                            });

                            this.debugLog(`Displayed zone: ${zone.name} (ID: ${zone.id})`);
                        } catch (e) {
                            console.error(`Error displaying zone ${zone.id}:`, e);
                        }
                    });

                    // Fit map bounds to show all existing zones if any
                    if (this.existingZonePolygons.length > 0) {
                        const bounds = new google.maps.LatLngBounds();
                        this.existingZonePolygons.forEach(item => {
                            const path = item.polygon.getPath();
                            path.forEach(point => {
                                bounds.extend(point);
                            });
                        });

                        // Only adjust bounds if we have zones, but keep zoom level reasonable
                        if (bounds.isEmpty()) {
                            this.debugLog('Bounds empty, skipping fitBounds');
                        } else {
                            this.map.fitBounds(bounds);
                            // Ensure zoom level is not too close
                            google.maps.event.addListenerOnce(this.map, 'bounds_changed', () => {
                                if (this.map.getZoom() > 15) {
                                    this.map.setZoom(15);
                                }
                            });
                        }
                    }
                } catch (e) {
                    console.error('Error displaying existing zones:', e);
                }
            },

            clearExistingZones() {
                try {
                    // Remove all existing zone polygons from the map
                    this.existingZonePolygons.forEach(item => {
                        if (item.polygon) {
                            item.polygon.setMap(null);
                        }
                        if (item.infoWindow) {
                            item.infoWindow.close();
                        }
                    });

                    // Clear the array
                    this.existingZonePolygons = [];
                    this.debugLog('Cleared existing zones');
                } catch (e) {
                    console.error('Error clearing existing zones:', e);
                }
            }
        }
            }
        if (typeof Alpine !== 'undefined' && typeof Alpine.data === 'function') {
            Alpine.data('googleMapsDraw', googleMapsDraw);
        }
    </script>
@endpush