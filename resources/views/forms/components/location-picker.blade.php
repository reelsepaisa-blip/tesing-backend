<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="locationPicker()" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Pickup Location</label>
                <input
                    type="text"
                    x-ref="pickupInput"
                    x-model="pickupAddress"
                    placeholder="Enter pickup location"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                >
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Dropoff Location</label>
                <input
                    type="text"
                    x-ref="dropoffInput"
                    x-model="dropoffAddress"
                    placeholder="Enter dropoff location"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                >
            </div>
        </div>

        <div x-ref="map" class="w-full h-96 rounded-lg shadow-md"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Estimated Distance</label>
                <div x-text="estimatedDistance ? estimatedDistance.toFixed(2) + ' km' : '-'" class="mt-1 p-2 block w-full rounded-md border border-gray-300 bg-gray-50"></div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Estimated Duration</label>
                <div x-text="estimatedDuration ? estimatedDuration + ' minutes' : '-'" class="mt-1 p-2 block w-full rounded-md border border-gray-300 bg-gray-50"></div>
            </div>
        </div>

        <!-- Hidden fields for form submission -->
        <input type="hidden" x-model="pickupLat" name="pickup_latitude">
        <input type="hidden" x-model="pickupLng" name="pickup_longitude">
        <input type="hidden" x-model="dropoffLat" name="dropoff_latitude">
        <input type="hidden" x-model="dropoffLng" name="dropoff_longitude">
        <input type="hidden" x-model="estimatedDistance" name="estimated_distance">
        <input type="hidden" x-model="estimatedDuration" name="estimated_duration">
    </div>
</x-dynamic-component>