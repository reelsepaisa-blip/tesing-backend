import './bootstrap';
import locationPicker from './components/location-picker';

// Register custom components using the alpine:init event
// This ensures they are added to whatever Alpine instance is running (Filament's or ours)
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('locationPicker', locationPicker);
    }
});

// Avoid bundling/starting Alpine if we are in the admin panel and it's already provided
import Alpine from 'alpinejs';

if (typeof window.Alpine === 'undefined') {
    window.Alpine = Alpine;
}

const isAdminPanel = typeof window.location !== 'undefined' && window.location.pathname.startsWith('/admin');

// Only start Alpine if it is not already being managed by Filament.
if (typeof window.filamentData === 'undefined' && !isAdminPanel) {
    if (typeof window.Alpine.start === 'function') {
        window.Alpine.start();
    }
}