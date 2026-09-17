import type { AssignDevicePayload, DeviceListItem, UpdateDevicePayload } from './api/devices';

export interface BankTerminalForm {
    bank_id: number;
    terminal_id: string;
    terminal_pin: string;
    use_default_pin: boolean;
}

export interface DeviceIdentityForm {
    name: string;
    label: string;
    serial_number: string;
    kiosk_id: string;
}

/** Keep bank-issued identifiers as strings (including leading zeroes). */
export function bankTerminalPayload(device: DeviceListItem, form: BankTerminalForm): AssignDevicePayload | null {
    if (!device.company_id || !device.branch_id) throw new Error('assignment_changed');
    const terminalId = form.terminal_id.trim();
    const enteredPin = form.terminal_pin.trim();
    if (!form.bank_id || !terminalId) throw new Error('required_bank_terminal');
    const bindingChanged = form.bank_id !== device.bank_id || terminalId !== device.terminal_id;
    // Never silently carry the old bank's password to a different terminal.
    if (bindingChanged && !enteredPin && !form.use_default_pin) throw new Error('new_terminal_password');
    const pin = form.use_default_pin ? null : enteredPin || device.terminal_pin;
    if (!bindingChanged && pin === device.terminal_pin) return null;
    return {
        company_id: device.company_id,
        branch_id: device.branch_id,
        bank_id: form.bank_id,
        terminal_id: terminalId,
        terminal_pin: pin,
    };
}

/** Partial identity update; this endpoint never receives terminal credentials. */
export function deviceIdentityPayload(device: DeviceListItem, form: DeviceIdentityForm): UpdateDevicePayload {
    const payload: UpdateDevicePayload = {};
    for (const key of ['name', 'label'] as const) {
        const value = form[key].trim() || null;
        if (value !== device[key]) payload[key] = value;
    }
    for (const key of ['serial_number', 'kiosk_id'] as const) {
        const value = form[key].trim();
        if (value !== (device[key] ?? '')) payload[key] = value;
    }
    return payload;
}

export interface DeviceDonationForm {
    commission_profile_id: number;
    organization_id: number;
}

/** Only write donation bindings that the administrator actually changed. */
export function deviceDonationPayload(device: DeviceListItem, form: DeviceDonationForm): UpdateDevicePayload {
    const payload: UpdateDevicePayload = {};
    for (const key of ['commission_profile_id', 'organization_id'] as const) {
        if (!Number.isInteger(form[key]) || form[key] <= 0) throw new Error('required_donation_settings');
        if (form[key] !== device[key]) payload[key] = form[key];
    }
    return payload;
}
