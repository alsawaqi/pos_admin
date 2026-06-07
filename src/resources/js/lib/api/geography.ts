import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

/**
 * Geography catalogue (shared charity tables: countries -> regions ->
 * districts -> cities). Reads are open to any admin; create/update/delete
 * are gated server-side by the `settings.manage` permission. IDs are numeric.
 *
 * GET    /admin/api/v1/countries|regions|districts|cities   (parent-filtered)
 * POST   /admin/api/v1/{entity}
 * PATCH  /admin/api/v1/{entity}/{id}
 * DELETE /admin/api/v1/{entity}/{id}
 */

export interface Country {
    id: number;
    name: string;
    iso_code: string;
    phone_code: string | null;
    is_active: boolean;
    regions_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Region {
    id: number;
    country_id: number;
    name: string;
    type: string | null;
    code: string | null;
    is_active: boolean;
    districts_count?: number;
    cities_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface District {
    id: number;
    region_id: number;
    name: string;
    cities_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface City {
    id: number;
    region_id: number;
    district_id: number | null;
    name: string;
    postal_code: string | null;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface CountryPayload {
    name: string;
    iso_code: string;
    phone_code?: string | null;
    is_active?: boolean;
}

export interface RegionPayload {
    country_id: number;
    name: string;
    type?: string | null;
    code?: string | null;
    is_active?: boolean;
}

export interface DistrictPayload {
    region_id: number;
    name: string;
}

export interface CityPayload {
    region_id: number;
    district_id?: number | null;
    name: string;
    postal_code?: string | null;
    is_active?: boolean;
}

interface GeoQuery {
    [key: string]: string | number | boolean | null | undefined;
}
export interface CountriesQuery extends GeoQuery {
    search?: string;
    include_inactive?: boolean;
}
export interface RegionsQuery extends GeoQuery {
    country_id?: number;
    search?: string;
    include_inactive?: boolean;
}
export interface DistrictsQuery extends GeoQuery {
    region_id?: number;
    search?: string;
}
export interface CitiesQuery extends GeoQuery {
    region_id?: number;
    district_id?: number;
    search?: string;
    include_inactive?: boolean;
}

// ---- Countries ----
export function listAllCountries(query: CountriesQuery = {}): Promise<{ data: Country[] }> {
    return apiGet<{ data: Country[] }>('/admin/api/v1/countries', { query });
}
export function createCountry(payload: CountryPayload): Promise<{ data: Country }> {
    return apiPost<{ data: Country }>('/admin/api/v1/countries', payload as unknown as JsonValue);
}
export function updateCountry(id: number, payload: Partial<CountryPayload>): Promise<{ data: Country }> {
    return apiPatch<{ data: Country }>(`/admin/api/v1/countries/${id}`, payload as unknown as JsonValue);
}
export function deleteCountry(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/countries/${id}`);
}

// ---- Regions ----
export function listAllRegions(query: RegionsQuery = {}): Promise<{ data: Region[] }> {
    return apiGet<{ data: Region[] }>('/admin/api/v1/regions', { query });
}
export function createRegion(payload: RegionPayload): Promise<{ data: Region }> {
    return apiPost<{ data: Region }>('/admin/api/v1/regions', payload as unknown as JsonValue);
}
export function updateRegion(id: number, payload: Partial<RegionPayload>): Promise<{ data: Region }> {
    return apiPatch<{ data: Region }>(`/admin/api/v1/regions/${id}`, payload as unknown as JsonValue);
}
export function deleteRegion(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/regions/${id}`);
}

// ---- Districts ----
export function listAllDistricts(query: DistrictsQuery = {}): Promise<{ data: District[] }> {
    return apiGet<{ data: District[] }>('/admin/api/v1/districts', { query });
}
export function createDistrict(payload: DistrictPayload): Promise<{ data: District }> {
    return apiPost<{ data: District }>('/admin/api/v1/districts', payload as unknown as JsonValue);
}
export function updateDistrict(id: number, payload: Partial<DistrictPayload>): Promise<{ data: District }> {
    return apiPatch<{ data: District }>(`/admin/api/v1/districts/${id}`, payload as unknown as JsonValue);
}
export function deleteDistrict(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/districts/${id}`);
}

// ---- Cities ----
export function listAllCities(query: CitiesQuery = {}): Promise<{ data: City[] }> {
    return apiGet<{ data: City[] }>('/admin/api/v1/cities', { query });
}
export function createCity(payload: CityPayload): Promise<{ data: City }> {
    return apiPost<{ data: City }>('/admin/api/v1/cities', payload as unknown as JsonValue);
}
export function updateCity(id: number, payload: Partial<CityPayload>): Promise<{ data: City }> {
    return apiPatch<{ data: City }>(`/admin/api/v1/cities/${id}`, payload as unknown as JsonValue);
}
export function deleteCity(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/cities/${id}`);
}
