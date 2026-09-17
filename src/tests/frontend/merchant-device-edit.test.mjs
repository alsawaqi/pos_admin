import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

// Exercise the same payload builder used by the Vue editor without adding a
// second frontend test framework to this application.
const source = readFileSync(new URL('../../resources/js/lib/merchantDeviceEdit.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText;
const { bankTerminalPayload, deviceDonationPayload, deviceIdentityPayload } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
const device = { company_id: 7, branch_id: 12, bank_id: 4, terminal_id: '000123', terminal_pin: 'test-pin', name: 'Station', label: null, serial_number: '00100', kiosk_id: null };
const form = (overrides = {}) => ({ bank_id: 4, terminal_id: '000123', terminal_pin: '', use_default_pin: false, ...overrides });

test('saving unchanged bank settings is a no-op and never erases the existing password', () => {
    assert.equal(bankTerminalPayload(device, form()), null);
    assert.equal(bankTerminalPayload(device, form({ terminal_pin: '   ' })), null);
    assert.equal(device.terminal_pin, 'test-pin');
});

test('password-only edit retains bank, terminal and merchant assignment', () => {
    assert.deepEqual(bankTerminalPayload(device, form({ terminal_pin: ' new-test-pin ' })), {
        company_id: 7, branch_id: 12, bank_id: 4, terminal_id: '000123', terminal_pin: 'new-test-pin',
    });
});

test('switching bank cannot silently reuse the old bank password', () => {
    assert.throws(() => bankTerminalPayload(device, form({ bank_id: 5 })), /new_terminal_password/);
    assert.throws(() => bankTerminalPayload(device, form({ terminal_id: '000124' })), /new_terminal_password/);
    assert.deepEqual(bankTerminalPayload(device, form({ bank_id: 5, terminal_id: '000124', terminal_pin: 'other-test-pin' })), {
        company_id: 7, branch_id: 12, bank_id: 5, terminal_id: '000124', terminal_pin: 'other-test-pin',
    });
});

test('explicit bank-default choice clears stored password even when the input contains text', () => {
    assert.equal(bankTerminalPayload(device, form({ use_default_pin: true, terminal_pin: 'ignored-test-pin' })).terminal_pin, null);
    assert.equal(bankTerminalPayload(device, form({ bank_id: 5, use_default_pin: true })).terminal_pin, null);
    assert.equal(bankTerminalPayload({ ...device, terminal_pin: null }, form({ use_default_pin: true })), null);
});

test('missing assignment, bank or blank terminal cannot produce a save request', () => {
    assert.throws(() => bankTerminalPayload({ ...device, branch_id: null }, form()), /assignment_changed/);
    assert.throws(() => bankTerminalPayload({ ...device, company_id: null }, form()), /assignment_changed/);
    assert.throws(() => bankTerminalPayload(device, form({ bank_id: 0 })), /required_bank_terminal/);
    assert.throws(() => bankTerminalPayload(device, form({ terminal_id: '  ' })), /required_bank_terminal/);
});

test('identity-only edits send only changed fields, with no bank credentials or assignment changes', () => {
    assert.deepEqual(deviceIdentityPayload(device, { name: 'Station', label: 'Front counter', serial_number: '00100', kiosk_id: '' }), { label: 'Front counter' });
});

test('identity editing retains leading zeroes and allows clearing optional labels', () => {
    assert.deepEqual(deviceIdentityPayload({ ...device, label: 'Old' }, { name: 'Station', label: ' ', serial_number: '00099', kiosk_id: '' }), { label: null, serial_number: '00099' });
});

test('unchanged legacy identity with no kiosk ID is a no-op', () => {
    assert.deepEqual(deviceIdentityPayload(device, { name: 'Station', label: '', serial_number: '00100', kiosk_id: '' }), {});
});

test('commission profile and charity edits send only the new donation bindings', () => {
    const current = { ...device, commission_profile_id: 10, organization_id: 20 };
    assert.deepEqual(deviceDonationPayload(current, { commission_profile_id: 11, organization_id: 21 }), {
        commission_profile_id: 11, organization_id: 21,
    });
});

test('changing only the charity leaves the commission profile and bank configuration untouched', () => {
    const current = { ...device, commission_profile_id: 10, organization_id: 20 };
    assert.deepEqual(deviceDonationPayload(current, { commission_profile_id: 10, organization_id: 21 }), { organization_id: 21 });
    assert.deepEqual(deviceDonationPayload(current, { commission_profile_id: 11, organization_id: 20 }), { commission_profile_id: 11 });
});

test('unchanged donation selections do not overwrite saved bindings when editing identity', () => {
    const current = { ...device, commission_profile_id: 10, organization_id: 20 };
    assert.deepEqual({
        ...deviceIdentityPayload(current, { name: 'Station', label: 'Front counter', serial_number: '00100', kiosk_id: '' }),
        ...deviceDonationPayload(current, { commission_profile_id: 10, organization_id: 20 }),
    }, { label: 'Front counter' });
});

test('missing or invalid donation selections never produce a request that clears the beneficiary', () => {
    for (const invalid of [0, -1, 1.5, NaN, null, undefined]) {
        assert.throws(() => deviceDonationPayload(device, { commission_profile_id: invalid, organization_id: 20 }), /required_donation_settings/);
        assert.throws(() => deviceDonationPayload(device, { commission_profile_id: 10, organization_id: invalid }), /required_donation_settings/);
    }
});
