import { expect, test } from '../playwright/fixtures';
import type { Page } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';
import {
    getCurrentUserViaApi,
    updateOrganizationSettingViaApi,
    updateUserProfileViaApi,
} from './utils/api';
import { selectTimezone, timezoneField } from './utils/userSettingsFields';

// The owner's browser runs in Vienna, so a different organization timezone would
// normally open the "timezone mismatch" prompt.
test.use({ timezoneId: 'Europe/Vienna' });

function mismatchModal(page: Page) {
    return page.getByRole('dialog').filter({ hasText: 'Timezone mismatch detected' });
}

async function getEmployeeTimezone(page: Page) {
    const response = await page.request.get(`${PLAYWRIGHT_BASE_URL}/api/v1/users/me`);
    expect(response.status()).toBe(200);
    return (await response.json()).data as { id: string; timezone: string };
}

async function putAsBrowser(page: Page, url: string, data: Record<string, unknown>) {
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    return page.request.put(url, {
        headers: { 'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.value) : '' },
        data,
    });
}

test('admin sets one timezone for every member from organization settings', async ({
    page,
    ctx,
    employee,
}) => {
    await updateUserProfileViaApi(ctx, { timezone: 'Europe/Vienna' });
    await page.goto(PLAYWRIGHT_BASE_URL + '/organizations/' + ctx.orgId);
    await expect(page.getByRole('heading', { name: 'Timezone', level: 3 })).toBeVisible({
        timeout: 10000,
    });

    // A new organization has no timezone, so the first save applies it to everyone
    await expect(page.getByLabel('Apply to all members now')).toBeChecked();
    await selectTimezone(page, page, 'America/Swift_Current', 'Swift');

    const section = page.locator('form').filter({ has: page.locator('#organizationTimezone') });
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().includes(`/api/v1/organizations/${ctx.orgId}`) &&
                response.request().method() === 'PUT' &&
                response.status() === 200
        ),
        section.getByRole('button', { name: 'Save' }).click(),
    ]);

    expect((await getCurrentUserViaApi(ctx)).timezone).toBe('America/Swift_Current');
    expect((await getEmployeeTimezone(employee.page)).timezone).toBe('America/Swift_Current');

    // Not locked, so the employee keeps control of their own timezone
    await employee.page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(timezoneField(employee.page)).toHaveText('America/Swift_Current');
    await expect(timezoneField(employee.page)).toBeEnabled();

    // The setting is still there after a reload
    await page.reload();
    await expect(timezoneField(page)).toHaveText('America/Swift_Current');
});

test('a locked timezone can not be changed by members', async ({ page, ctx, employee }) => {
    await updateOrganizationSettingViaApi(ctx, {
        timezone: 'America/Regina',
        timezone_locked: true,
    });

    // Locking moves everyone onto the timezone
    expect((await getCurrentUserViaApi(ctx)).timezone).toBe('America/Regina');
    const employeeUser = await getEmployeeTimezone(employee.page);
    expect(employeeUser.timezone).toBe('America/Regina');

    // The profile field is read-only and says who set it
    await employee.page.goto(PLAYWRIGHT_BASE_URL + '/user/profile');
    await expect(timezoneField(employee.page)).toHaveText('America/Regina');
    await expect(timezoneField(employee.page)).toBeDisabled();
    await expect(
        employee.page.getByText(/^Set by .+\. Ask an admin to change it\.$/)
    ).toBeVisible();

    // The API refuses a change too
    const rejected = await putAsBrowser(
        employee.page,
        `${PLAYWRIGHT_BASE_URL}/api/v1/users/${employeeUser.id}`,
        { timezone: 'Europe/Vienna' }
    );
    expect(rejected.status()).toBe(422);
    expect((await getEmployeeTimezone(employee.page)).timezone).toBe('America/Regina');

    // The owner's browser is in Vienna, but there is nothing for them to switch
    await page.goto(PLAYWRIGHT_BASE_URL + '/dashboard');
    await expect(page.getByTestId('dashboard_view')).toBeVisible();
    await expect(mismatchModal(page)).toBeHidden();

    // Unlocking hands control back to members
    await updateOrganizationSettingViaApi(ctx, { timezone_locked: false });
    const accepted = await putAsBrowser(
        employee.page,
        `${PLAYWRIGHT_BASE_URL}/api/v1/users/${employeeUser.id}`,
        { timezone: 'Europe/Vienna' }
    );
    expect(accepted.status()).toBe(200);
});

test('employees do not see the timezone settings', async ({ ctx, employee }) => {
    await employee.page.goto(PLAYWRIGHT_BASE_URL + '/organizations/' + ctx.orgId);
    await expect(
        employee.page.getByRole('heading', { name: 'Organization Name', level: 3 })
    ).toBeVisible({ timeout: 10000 });
    await expect(employee.page.getByRole('heading', { name: 'Timezone', level: 3 })).toBeHidden();

    const response = await putAsBrowser(
        employee.page,
        `${PLAYWRIGHT_BASE_URL}/api/v1/organizations/${ctx.orgId}`,
        { timezone: 'America/Regina', timezone_locked: true }
    );
    expect(response.status()).toBe(403);
});
