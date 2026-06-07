/// <reference types="google.maps" />
/**
 * Single shared loader for the Google Maps JS API, used by the device
 * route map + the branch location picker.
 *
 * The browser key is read from VITE_GOOGLE_MAPS_API_KEY -- set it in
 * pos_admin/src/.env and restart the vite container. It is a PUBLIC,
 * HTTP-referrer-restricted browser key (it ships in the JS bundle), so
 * lock it to your domains in the Google Cloud console.
 */
import { importLibrary, setOptions } from '@googlemaps/js-api-loader';

let promise: Promise<typeof google> | null = null;

export function googleMapsApiKey(): string {
    return (import.meta.env.VITE_GOOGLE_MAPS_API_KEY as string | undefined) ?? '';
}

export function loadGoogleMaps(): Promise<typeof google> {
    if (promise) {
        return promise;
    }

    // v2 functional API: configure once, then import the `maps` library
    // (Map, Marker (classic), Polyline, Circle, InfoWindow, LatLngBounds,
    // SymbolPath) — everything both map components use.
    setOptions({ key: googleMapsApiKey(), v: 'weekly' });
    promise = importLibrary('maps').then(() => google);

    return promise;
}
