<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.{{ $applyStateBindingModifiers('entangle(\'' . $getStatePath() . '\')') }},
        stateField: '{{ $getStateField() }}',
        countryField: '{{ $getCountryField() }}',
        latitudeField: '{{ $getLatitudeField() }}',
        longitudeField: '{{ $getLongitudeField() }}',
        autocomplete: null,
        init() {
            this.$nextTick(() => {
                this.initAutocomplete();
            });
    
            // Listen for Google Maps loaded event
            window.addEventListener('googleMapsLoaded', () => {
                this.initAutocomplete();
            });
        },
        initAutocomplete() {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(() => this.initAutocomplete(), 1000);
                return;
            }
    
            const input = this.$refs.input;
            this.autocomplete = new google.maps.places.Autocomplete(input, {
                types: ['(cities)']
            });
    
            this.autocomplete.addListener('place_changed', () => {
                const place = this.autocomplete.getPlace();
    
                if (place.geometry && place.geometry.location) {
                    const lat = place.geometry.location.lat();
                    const lng = place.geometry.location.lng();
    
                    // Extract address components
                    let cityName = null;
                    let stateName = null;
                    let countryName = null;
    
                    for (const component of place.address_components) {
                        const types = component.types;
    
                        if (types.includes('locality')) {
                            cityName = component.long_name;
                        } else if (types.includes('administrative_area_level_1')) {
                            stateName = component.long_name;
                        } else if (types.includes('country')) {
                            countryName = component.long_name;
                        }
                    }
    
                    // Update the main city name field
                    this.state = place.formatted_address;
    
                    // Update other fields using Livewire
                    if (stateName) {
                        $wire.set('data.' + this.stateField, stateName);
                    }
                    if (countryName) {
                        $wire.set('data.' + this.countryField, countryName);
                    }
                    if (lat !== null) {
                        $wire.set('data.' + this.latitudeField, parseFloat(lat).toFixed(4));
                    }
                    if (lng !== null) {
                        $wire.set('data.' + this.longitudeField, parseFloat(lng).toFixed(4));
                    }
                }
            });
        }
    }">
        <x-filament::input.wrapper
            :disabled="$isDisabled()"
            :valid="! $errors->has($getStatePath())"
        >
            <input x-ref="input" type="text" id="{{ $getId() }}" name="{{ $getName() }}" x-model="state"
                placeholder="{{ $getPlaceholder() ?? 'Start typing city name...' }}"
                {{ $getExtraInputAttributeBag()->class(['fi-input']) }}
                {{ $isDisabled() ? 'disabled' : '' }} {{ $isRequired() ? 'required' : '' }}
                {{ $attributes->merge($getExtraAttributes())->merge($getExtraInputAttributeBag()->getAttributes()) }} />
        </x-filament::input.wrapper>
    </div>
</x-dynamic-component>
