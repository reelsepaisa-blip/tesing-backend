@once
    @include('components.google-maps-script')
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.{{ $applyStateBindingModifiers('entangle(\'' . $getStatePath() . '\')') }},
        zoneField: '{{ $getZoneField() }}',
        locationField: '{{ $getLocationField() }}',
        autocomplete: null,
        init() {
            this.$nextTick(() => {
                this.ensureMapsReady(() => this.initAutocomplete());
            });
        },
        ensureMapsReady(callback) {
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                callback();
                return;
            }
    
            window.addEventListener('googleMapsLoaded', () => callback(), { once: true });
        },
        initAutocomplete() {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                return;
            }
    
            const input = this.$refs.input;
            this.autocomplete = new google.maps.places.Autocomplete(input, {
                types: ['address'],
                componentRestrictions: { country: 'IN' } // Restrict to India
            });
    
            this.autocomplete.addListener('place_changed', () => {
                const place = this.autocomplete.getPlace();
    
                if (place.geometry && place.geometry.location) {
                    const lat = place.geometry.location.lat();
                    const lng = place.geometry.location.lng();
    
                    // Update the location field with coordinates (for backward compatibility)
                    if (this.locationField) {
                        $wire.set(this.locationField, `${lat}, ${lng}`);
                    }
    
                    // Set separate latitude and longitude properties
                    const fieldName = this.locationField;
                    if (fieldName) {
                        if (fieldName.includes('pickup')) {
                            $wire.set('pickup_latitude', lat);
                            $wire.set('pickup_longitude', lng);
                        } else if (fieldName.includes('dropoff')) {
                            $wire.set('dropoff_latitude', lat);
                            $wire.set('dropoff_longitude', lng);
                        }
                    }
    
                    // Set the complete formatted address
                    const addressField = this.state;
                    if (place.formatted_address) {
                        this.state = place.formatted_address;
                    }
                }
            });
        },
        updateZone(addressComponents) {
            // You can customize this logic to match your zone detection
            // For now, we'll look for administrative_area_level_1 (state)
            let zoneName = null;
    
            for (const component of addressComponents) {
                if (component.types.includes('administrative_area_level_1')) {
                    zoneName = component.long_name;
                    break;
                }
            }
    
            if (zoneName) {
                // You might need to adjust this based on your zone data structure
                // This is a simplified example
                $wire.set(this.zoneField, zoneName);
            }
        }
    }">
        <x-filament::input.wrapper
            :disabled="$isDisabled()"
            :valid="! $errors->has($getStatePath())"
        >
            <input
                x-ref="input"
                type="text"
                id="{{ $getId() }}"
                name="{{ $getName() }}"
                placeholder="{{ filled($getPlaceholder()) ? $getPlaceholder() : 'Enter a location' }}"
                x-model="state"
                {{ $applyStateBindingModifiers('x-model') }}
                {{ $applyStateBindingModifiers('wire:model') }}
                {{ $getExtraAlpineAttributeBag() }}
                {{ $getExtraInputAttributeBag()->class(['fi-input']) }}
                {{ $isDisabled() ? 'disabled' : '' }}
                {{ $isRequired() ? 'required' : '' }}
                {{ $attributes->merge($getExtraAttributes())->merge($getExtraInputAttributeBag()->getAttributes()) }}
            />
        </x-filament::input.wrapper>
    </div>
</x-dynamic-component>
