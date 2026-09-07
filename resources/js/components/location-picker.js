export default function locationPicker() {
    return {
        pickupAddress: '',
        dropoffAddress: '',
        pickupLat: '',
        pickupLng: '',
        dropoffLat: '',
        dropoffLng: '',
        estimatedDistance: '',
        estimatedDuration: '',
        map: null,
        directionsService: null,
        directionsRenderer: null,
        pickupMarker: null,
        dropoffMarker: null,
        pickupAutocomplete: null,
        dropoffAutocomplete: null,

        init() {
            if (typeof google === 'undefined' || !google.maps) {
                setTimeout(() => this.init(), 1000);
                return;
            }
            this.map = new google.maps.Map(this.$refs.map, {
                center: { lat: 20.5937, lng: 78.9629 },
                zoom: 5,
                styles: [
                    {
                        featureType: 'poi',
                        elementType: 'labels',
                        stylers: [{ visibility: 'off' }]
                    }
                ]
            });

            this.directionsService = new google.maps.DirectionsService();
            this.directionsRenderer = new google.maps.DirectionsRenderer({
                map: this.map,
                suppressMarkers: true
            });

            this.pickupMarker = new google.maps.Marker({
                map: this.map,
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
                }
            });

            this.dropoffMarker = new google.maps.Marker({
                map: this.map,
                icon: {
                    url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
                }
            });

            this.setupAutocomplete();
        },

        setupAutocomplete() {
            this.pickupAutocomplete = new google.maps.places.Autocomplete(this.$refs.pickupInput, {
                types: ['geocode'],
                componentRestrictions: { country: 'in' }
            });

            this.pickupAutocomplete.addListener('place_changed', () => {
                const place = this.pickupAutocomplete.getPlace();
                if (!place.geometry) return;

                this.pickupLat = place.geometry.location.lat();
                this.pickupLng = place.geometry.location.lng();
                this.pickupAddress = place.formatted_address;

                this.$wire.set('data.pickup_latitude', this.pickupLat);
                this.$wire.set('data.pickup_longitude', this.pickupLng);
                this.$wire.set('data.pickup_address', this.pickupAddress);

                this.pickupMarker.setPosition(place.geometry.location);
                this.updateRoute();
            });

            this.dropoffAutocomplete = new google.maps.places.Autocomplete(this.$refs.dropoffInput, {
                types: ['geocode'],
                componentRestrictions: { country: 'in' }
            });

            this.dropoffAutocomplete.addListener('place_changed', () => {
                const place = this.dropoffAutocomplete.getPlace();
                if (!place.geometry) return;

                this.dropoffLat = place.geometry.location.lat();
                this.dropoffLng = place.geometry.location.lng();
                this.dropoffAddress = place.formatted_address;

                this.$wire.set('data.dropoff_latitude', this.dropoffLat);
                this.$wire.set('data.dropoff_longitude', this.dropoffLng);
                this.$wire.set('data.dropoff_address', this.dropoffAddress);

                this.dropoffMarker.setPosition(place.geometry.location);
                this.updateRoute();
            });
        },

        updateRoute() {
            if (!this.pickupLat || !this.dropoffLat) return;

            const request = {
                origin: { lat: parseFloat(this.pickupLat), lng: parseFloat(this.pickupLng) },
                destination: { lat: parseFloat(this.dropoffLat), lng: parseFloat(this.dropoffLng) },
                travelMode: 'DRIVING'
            };

            this.directionsService.route(request, (result, status) => {
                if (status === 'OK') {
                    this.directionsRenderer.setDirections(result);

                    const route = result.routes[0];
                    const leg = route.legs[0];
                    this.estimatedDistance = leg.distance.value / 1000;
                    this.estimatedDuration = Math.ceil(leg.duration.value / 60);

                    this.$wire.set('data.estimated_distance', this.estimatedDistance);
                    this.$wire.set('data.estimated_duration', this.estimatedDuration);

                    this.map.fitBounds(route.bounds);
                }
            });
        }
    };
}























