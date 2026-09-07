@include('components.google-maps-script')
<x-filament::page>
    <div class="space-y-6">
        <div x-data="{
            map: null,
            markers: {},
            infoWindow: null,
            markerCluster: null,
        
            async init() {
                await this.loadGoogleMaps();
                await this.initMap();
                this.fetchLocations();
                setInterval(() => this.fetchLocations(), 10000);
            },
        
            async loadGoogleMaps() {
                if (typeof google !== 'undefined' && google.maps) {
                    return;
                }
                return new Promise((resolve) => {
                    if (window.googleMapsScriptLoading && window.googleMapsScriptLoading.loaded) {
                        resolve();
                        return;
                    }
                    let settled = false;
                    const done = () => {
                        if (settled) return;
                        settled = true;
                        window.removeEventListener('googleMapsLoaded', done);
                        if (tid) clearInterval(tid);
                        resolve();
                    };
                    window.addEventListener('googleMapsLoaded', done, { once: true });
                    let tid = setInterval(() => {
                        if (typeof google !== 'undefined' && google.maps) done();
                    }, 100);
                    setTimeout(done, 10000);
                });
            },
        
            async initMap() {
                if (!this.$refs || !this.$refs.map) {
                    console.error('Driver tracking: map container not found');
                    return;
                }
                try {
                    this.map = new google.maps.Map(this.$refs.map, {
                        center: { lat: 21.1702, lng: 72.8311 }, // Surat center (default)
                        zoom: 12, // City level zoom
                        mapTypeId: google.maps.MapTypeId.ROADMAP,
                        mapTypeControl: true,
                        streetViewControl: true,
                        fullscreenControl: true,
                        zoomControl: true,
                        styles: [{
                            featureType: 'poi',
                            elementType: 'labels',
                            stylers: [{ visibility: 'off' }]
                        }]
                    });
        
                    this.infoWindow = new google.maps.InfoWindow();
        
                    // Initialize MarkerClusterer if available
                    if (typeof MarkerClusterer !== 'undefined') {
                        this.markerCluster = new MarkerClusterer(this.map, [], {
                            imagePath: 'https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/m'
                        });
                    }
        
                    
        
                    // Add map load listener
                    google.maps.event.addListenerOnce(this.map, 'idle', () => {
                        
                    });
        
                } catch (error) {
                    console.error('❌ Error initializing map:', error);
                    alert('Error loading map. Please refresh the page.');
                }
            },
        
            getUserLocation() {
                
        
                if (navigator.geolocation) {
                    // Request location with high accuracy
                    const options = {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 300000 // 5 minutes
                    };
        
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const userLocation = {
                                lat: position.coords.latitude,
                                lng: position.coords.longitude
                            };
                            
        
                            // Center map on user location
                            this.map.setCenter(userLocation);
                            this.map.setZoom(14); // Closer zoom for city view
        
                            // Add user location marker
                            const userMarker = new google.maps.Marker({
                                position: userLocation,
                                map: this.map,
                                title: 'Your Location',
                                icon: {
                                    url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                                    scaledSize: new google.maps.Size(32, 32)
                                },
                                label: {
                                    text: 'You',
                                    color: '#ffffff',
                                    fontSize: '12px',
                                    fontWeight: 'bold'
                                }
                            });
        
                            
        
                            // Now fetch driver locations
                            this.fetchLocations();
                        },
                        (error) => {
        
                            // Show user-friendly error message
                            if (error.code === 1) {
                                
                                alert('Please allow location access to see drivers near you. Click OK to continue with default location.');
                            } else if (error.code === 2) {
                                
                                alert('Location unavailable. Using default location.');
                            } else if (error.code === 3) {
                                
                                alert('Location request timed out. Using default location.');
                            }
        
                            // Fallback to Surat center
                            this.map.setCenter({ lat: 21.1702, lng: 72.8311 });
                            this.map.setZoom(12);
                            
                            this.fetchLocations();
                        },
                        options
                    );
                } else {
                    console.warn('⚠️ Geolocation not supported by browser');
                    alert('Geolocation not supported by your browser. Using default location.');
                    this.map.setCenter({ lat: 21.1702, lng: 72.8311 });
                    this.map.setZoom(12);
                    this.fetchLocations();
                }
            },
        
            async fetchLocations() {
                try {
                    
        
                    const url = '/api/driver-tracking/all';
                    
        
                    const response = await fetch(url);
        
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
        
                    const data = await response.json();
                    
        
                    if (!data.success) {
                        throw new Error(data.message || 'API request failed');
                    }
        
                    const locations = data.data.drivers;
                    
        
                    // If no drivers returned, clear all markers immediately
                    if (locations.length === 0) {
                        
                        Object.keys(this.markers).forEach(markerId => {
                            if (this.markers[markerId]) {
                                this.markers[markerId].setMap(null);
                                delete this.markers[markerId];
                            }
                        });
                        return; // Exit early since no drivers to process
                    }
        
                    // Clear existing markers that are no longer needed
                    const currentMarkers = new Set(Object.keys(this.markers));
                    let validDrivers = 0;
                    let isFirstLoad = Object.keys(this.markers).length === 0;
        
                    locations.forEach(driver => {
                        // Validate coordinates with proper type checking
                        if (!driver.latitude || !driver.longitude ||
                            isNaN(parseFloat(driver.latitude)) || isNaN(parseFloat(driver.longitude))) {
                            console.warn('Invalid coordinates for driver:', driver.driver_name || 'Unknown', {
                                latitude: driver.latitude,
                                longitude: driver.longitude
                            });
                            return;
                        }
        
                        currentMarkers.delete(driver.driver_id.toString());
        
                        const position = {
                            lat: parseFloat(driver.latitude),
                            lng: parseFloat(driver.longitude)
                        };
        
                        
        
                        if (this.markers[driver.driver_id]) {
                            // Update existing marker with new data
                            const existingMarker = this.markers[driver.driver_id];
        
                            // Update position
                            this.animateMarkerMovement(existingMarker, position);
        
                            // Update marker appearance based on new status
                            const vehicleType = driver.vehicles && driver.vehicles.length > 0 ?
                                driver.vehicles[0].ride_type || 'car' :
                                'car';
        
                            const detailedStatus = this.getDetailedStatus(driver);
                            const vehicleIcon = this.getVehicleIcon(vehicleType, detailedStatus.status);
                            const statusIndicator = this.getStatusIndicator(detailedStatus.status);
        
                            // Update marker properties
                            existingMarker.setIcon(vehicleIcon);
                            existingMarker.setOpacity(statusIndicator.opacity);
                            existingMarker.setTitle(`${driver.driver_name} (${vehicleType}) - ${driver.status}`);
        
                            // Store updated driver data for info window
                            existingMarker.driverData = driver;
        
                            
                        } else {
                            // Get vehicle type from driver data
                            const vehicleType = driver.vehicles && driver.vehicles.length > 0 ?
                                driver.vehicles[0].ride_type || 'car' :
                                'car';
        
                            const detailedStatus = this.getDetailedStatus(driver);
                            const vehicleIcon = this.getVehicleIcon(vehicleType, detailedStatus.status);
                            const statusIndicator = this.getStatusIndicator(detailedStatus.status);
        
                            const marker = new google.maps.Marker({
                                position,
                                map: this.map,
                                title: `${driver.driver_name} (${vehicleType}) - ${driver.status}`,
                                icon: vehicleIcon,
                                opacity: statusIndicator.opacity
                            });
        
                            // Store driver data for info window updates
                            marker.driverData = driver;
        
                            marker.addListener('click', async () => {
                                try {
                                    // Use stored driver data if available, otherwise use original driver data
                                    const currentDriverData = marker.driverData || driver;
        
                                    // Get detailed status
                                    const detailedStatus = this.getDetailedStatus(currentDriverData);
        
                                    // Get address from coordinates
                                    const address = await this.getAddressFromCoordinates(currentDriverData.latitude, currentDriverData.longitude);
        
                                    const vehicleInfo = currentDriverData.vehicles && currentDriverData.vehicles.length > 0 ?
                                        `
                                                                                                                                                                                                                                                                             <div class='mb-4'>
                                                                                                                                                                                                                                                                                 <h4 class='font-semibold text-gray-800 mb-3 text-lg'>🚗 Vehicle Information</h4>
                                                                                                                                                                                                                                                                                 <div class='grid grid-cols-1 gap-3'>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-gray-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>🚙</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Model</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.vehicles[0].model || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-gray-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>📋</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Registration</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.vehicles[0].registration_number || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-gray-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>🚗</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Type</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.vehicles[0].ride_type || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                 </div>
                                                                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                                                                         ` : '';
        
                                    const bookingInfo = currentDriverData.current_booking ?
                                        `
                                                                                                                                                                                                                                                                             <div class='mb-4'>
                                                                                                                                                                                                                                                                                 <h4 class='font-semibold text-gray-800 mb-3 text-lg'>📋 Current Booking</h4>
                                                                                                                                                                                                                                                                                 <div class='grid grid-cols-1 gap-3'>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-yellow-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>🆔</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Booking ID</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.current_booking.booking_id || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-yellow-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>📊</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Status</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.current_booking.status || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                     <div class='flex items-center gap-3 p-3 bg-yellow-50 rounded-lg'>
                                                                                                                                                                                                                                                                                         <span class='text-lg'>👤</span>
                                                                                                                                                                                                                                                                                         <div>
                                                                                                                                                                                                                                                                                             <p class='text-sm text-gray-500'>Customer</p>
                                                                                                                                                                                                                                                                                             <p class='font-semibold text-gray-900'>${driver.current_booking.customer_name || 'N/A'}</p>
                                                                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                                                                                 </div>
                                                                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                                                                         ` : '';
        
                                    // Safely format coordinates with null checks
                                    const latitude = currentDriverData.latitude ? parseFloat(currentDriverData.latitude).toFixed(6) : 'N/A';
                                    const longitude = currentDriverData.longitude ? parseFloat(currentDriverData.longitude).toFixed(6) : 'N/A';
                                    const driverName = currentDriverData.driver_name || 'Unknown Driver';
                                    const driverId = currentDriverData.driver_id || 'N/A';
                                    const driverPhone = currentDriverData.driver_phone || 'N/A';
                                    const isOnline = currentDriverData.is_online ? 'Yes' : 'No';
                                    const lastUpdate = currentDriverData.last_location_at ? new Date(currentDriverData.last_location_at).toLocaleTimeString() : 'N/A';
                                    const statusText = detailedStatus.text;
                                    const statusColor = detailedStatus.color;
        
                                    // Get vehicle type
                                    const vehicleType = currentDriverData.vehicles && currentDriverData.vehicles.length > 0 ?
                                        currentDriverData.vehicles[0].ride_type || 'Car' :
                                        'Car';
        
                                    this.infoWindow.setContent(`
                                                                                                                                                                                                                 <div class='p-6 max-w-md'>
                                                                                                                                                                                                                     <!-- Header Section -->
                                                                                                                                                                                                                     <div class='flex items-center gap-4 mb-4'>
                                                                                                                                                                                                                         <div class='w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center'>
                                                                                                                                                                                                                             <span class='text-xl'>👤</span>
                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                                                                   <div class='flex-1'>
                                                                                                                                                                                                                              <p class='text-sm text-gray-500 mb-1'>Driver ID: ${driverId}</p>
                                                                                                                                                                                                                              <h3 class='font-bold text-xl text-gray-900 mb-1'>${driverName}</h3>
                                                                                                                                                                                                                              <p class='text-sm text-gray-600 mb-2'>🚗 ${vehicleType}</p>
                                                                                                                                                                                                                              <div class='mb-2'>
                                                                                                                                                                                                                                  <span class='px-4 py-2 rounded-full text-sm font-semibold text-white' style='background-color: ${statusColor}'>
                                                                                                                                                                                                                                      ${statusText}
                                                                                                                                                                                                                                  </span>
                                                                                                                                                                                                                              </div>
                                                                                                                                                                                                                          </div>
                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                     
                                                                                                                                                                                                                     <!-- Contact & Location Section -->
                                                                                                                                                                                                                     <div class='grid grid-cols-1 gap-3 mb-4'>
                                                                                                                                                                                                                         <div class='flex items-center gap-3 p-3 bg-blue-50 rounded-lg'>
                                                                                                                                                                                                                             <span class='text-lg'>📞</span>
                                                                                                                                                                                                                             <div>
                                                                                                                                                                                                                                 <p class='text-sm text-gray-500'>Phone</p>
                                                                                                                                                                                                                                 <p class='font-semibold text-gray-900'>${driverPhone}</p>
                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                         
                                                                                                                                                                                                                         <div class='flex items-start gap-3 p-3 bg-green-50 rounded-lg'>
                                                                                                                                                                                                                             <span class='text-lg mt-1'>📍</span>
                                                                                                                                                                                                                             <div class='flex-1'>
                                                                                                                                                                                                                                 <p class='text-sm text-gray-500'>Address</p>
                                                                                                                                                                                                                                 <p class='font-semibold text-gray-900'>${address}</p>
                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                         
                                                                                                                                                                                                                         <div class='flex items-center gap-3 p-3 bg-purple-50 rounded-lg'>
                                                                                                                                                                                                                             <span class='text-lg'>🌐</span>
                                                                                                                                                                                                                             <div>
                                                                                                                                                                                                                                 <p class='text-sm text-gray-500'>Coordinates</p>
                                                                                                                                                                                                                                 <p class='font-semibold text-gray-900'>${latitude}, ${longitude}</p>
                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                         
                                                                                                                                                                                                                         <div class='flex items-center gap-3 p-3 bg-orange-50 rounded-lg'>
                                                                                                                                                                                                                             <span class='text-lg'>🕒</span>
                                                                                                                                                                                                                             <div>
                                                                                                                                                                                                                                 <p class='text-sm text-gray-500'>Last Update</p>
                                                                                                                                                                                                                                 <p class='font-semibold text-gray-900'>${lastUpdate}</p>
                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                     </div>
                                                                                                                                                                                                                     
                                                                                                                                                                                                                     <!-- Vehicle & Booking Info -->
                                                                                                                                                                                                                     ${vehicleInfo}
                                                                                                                                                                                                                     ${bookingInfo}
                                                                                                                                                                                                                 </div>
                                                                                                                                                                                                             `);
                                    this.infoWindow.open(this.map, marker);
                                } catch (error) {
                                    console.error('❌ Error creating info window for driver:', driver.driver_name || 'Unknown', error);
                                    // Show a simple error message
                                    this.infoWindow.setContent(`
                                                                                                                                                                                                                                         <div class='p-4 max-w-sm'>
                                                                                                                                                                                                                                             <div class='text-center'>
                                                                                                                                                                                                                                                 <h3 class='font-bold text-lg text-red-600 mb-2'>⚠️ Error</h3>
                                                                                                                                                                                                                                                 <p class='text-sm text-gray-600'>Unable to load driver information</p>
                                                                                                                                                                                                                                                 <p class='text-xs text-gray-500 mt-2'>Please try again later</p>
                                                                                                                                                                                                                                             </div>
                                                                                                                                                                                                                                         </div>
                                                                                                                                                                                                                                     `);
                                    this.infoWindow.open(this.map, marker);
                                }
                            });
        
                            this.markers[driver.driver_id] = marker;
                            validDrivers++;
                        }
                    });
        
                    // Remove old markers
                    currentMarkers.forEach(markerId => {
                        if (this.markers[markerId]) {
                            
                            this.markers[markerId].setMap(null);
                            delete this.markers[markerId];
                        }
                    });
        
                    // Update marker cluster if available
                    if (this.markerCluster) {
                        this.markerCluster.clearMarkers();
                        this.markerCluster.addMarkers(Object.values(this.markers));
                    }
        
                    // Only fit bounds on first load, not during API calls
                    if (isFirstLoad && validDrivers > 0) {
                        
                        const bounds = new google.maps.LatLngBounds();
                        Object.values(this.markers).forEach(marker => {
                            bounds.extend(marker.getPosition());
                        });
        
                        this.map.fitBounds(bounds);
        
                        // Prevent over-zooming
                        google.maps.event.addListenerOnce(this.map, 'bounds_changed', () => {
                            if (this.map.getZoom() > 14) {
                                this.map.setZoom(14);
                            }
                        });
                    } else if (validDrivers === 0) {
                        console.warn('⚠️ No valid driver locations found');
                    }
        
                } catch (error) {
                    console.error('❌ Error fetching driver data:', error);
                    // Show error in console but don't break the UI
                }
            },
        
            animateMarkerMovement(marker, newPosition) {
                const frames = 30;
                const duration = 500;
        
                const oldPosition = marker.getPosition();
                const deltaLat = (newPosition.lat - oldPosition.lat()) / frames;
                const deltaLng = (newPosition.lng - oldPosition.lng()) / frames;
        
                let frame = 0;
        
                const animate = () => {
                    frame++;
        
                    const lat = oldPosition.lat() + deltaLat * frame;
                    const lng = oldPosition.lng() + deltaLng * frame;
        
                    marker.setPosition({ lat, lng });
        
                    if (frame < frames) {
                        requestAnimationFrame(animate);
                    }
                };
        
                requestAnimationFrame(animate);
            },
        
            getVehicleIcon(vehicleType, status) {
                // Use custom local vehicle images with status-based styling
                const baseIcons = {
                    'car': '/assets/images/car.png',
                    'auto': '/assets/images/rickshaw.png',
                    'bike': '/assets/images/bike.png'
                };
        
                const vehicleImage = baseIcons[vehicleType?.toLowerCase()] || baseIcons.car;
        
                // Create icon with status-based styling
                const icon = {
                    url: vehicleImage,
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 20)
                };
        
                return icon;
            },
        
            getDetailedStatus(driver) {
                // Enhanced status logic based on is_online and ride status
                if (!driver.is_online) {
                    return {
                        status: 'offline',
                        text: 'Offline',
                        color: '#6c757d',
                        opacity: 0.5
                    };
                }
        
                // Check if driver is in ride (has current booking)
                if (driver.current_booking && driver.current_booking.status) {
                    return {
                        status: 'in_ride',
                        text: 'In Ride',
                        color: '#dc3545',
                        opacity: 0.8
                    };
                }
        
                // Driver is online but not in ride
                return {
                    status: 'available',
                    text: 'Available',
                    color: '#28a745',
                    opacity: 1.0
                };
            },
        
            getStatusIndicator(status) {
                // Return status-based visual indicators
                const indicators = {
                    'available': {
                        borderColor: '#28a745',
                        borderWidth: 3,
                        opacity: 1.0
                    },
                    'in_ride': {
                        borderColor: '#dc3545',
                        borderWidth: 3,
                        opacity: 0.8
                    },
                    'offline': {
                        borderColor: '#6c757d',
                        borderWidth: 2,
                        opacity: 0.5
                    }
                };
        
                return indicators[status] || indicators.available;
            },
        
            async getAddressFromCoordinates(latitude, longitude) {
                try {
                    const geocoder = new google.maps.Geocoder();
                    const latlng = { lat: parseFloat(latitude), lng: parseFloat(longitude) };
        
                    return new Promise((resolve, reject) => {
                        geocoder.geocode({ location: latlng }, (results, status) => {
                            if (status === 'OK' && results[0]) {
                                // Get a simplified address (street + city)
                                const addressComponents = results[0].address_components;
                                let street = '';
                                let city = '';
        
                                for (const component of addressComponents) {
                                    if (component.types.includes('route')) {
                                        street = component.long_name;
                                    }
                                    if (component.types.includes('locality')) {
                                        city = component.long_name;
                                    }
                                }
        
                                const address = street && city ? `${street}, ${city}` : results[0].formatted_address;
                                resolve(address);
                            } else {
                                resolve('Address not available');
                            }
                        });
                    });
                } catch (error) {
                    console.warn('Error getting address:', error);
                    return 'Address not available';
                }
            },
        
            getStatusColor(status) {
                return {
                    'available': '#28a745', // Green for available
                    'in_ride': '#dc3545', // Red for in ride
                    'offline': '#6c757d' // Gray for offline
                } [status] || '#28a745';
            },
        
            // Debug function to manually clear all markers
            clearAllMarkers() {
                
                Object.keys(this.markers).forEach(markerId => {
                    if (this.markers[markerId]) {
                        
                        this.markers[markerId].setMap(null);
                        delete this.markers[markerId];
                    }
                });
                
            }
        }" x-init="init()" class="space-y-4">
            <!-- Map -->
            <div
                class="fi-card rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="p-6">


                    <div x-ref="map" class="w-full rounded-lg" style="height: calc(100vh - 150px);"></div>
                </div>
            </div>
        </div>
</x-filament::page>
