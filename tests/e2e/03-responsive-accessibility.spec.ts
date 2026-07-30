import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { login } from './support/session';

async function expectNoHorizontalOverflow(page: import('@playwright/test').Page) {
    const dimensions = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(dimensions.content, `Horizontal overflow: ${JSON.stringify(dimensions)}`)
        .toBeLessThanOrEqual(dimensions.viewport + 1);
}

async function openMore(page: import('@playwright/test').Page) {
    const trigger = page.getByRole('button', { name: /Más$/ });
    await trigger.click();
    await expect(page.getByRole('dialog', { name: 'Más módulos' })).toBeVisible();

    return trigger;
}

test('@viewport dashboard, modal and table actions stay usable and accessible', async ({ context, page }) => {
    const session = await login(page, 'compras@lavictoria.test');
    await expect(page.getByLabel('Sucursal')).toHaveValue(String(session.branchId));
    await expectNoHorizontalOverflow(page);

    const dashboardA11y = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    expect(dashboardA11y.violations).toEqual([]);

    const viewport = page.viewportSize();
    expect(viewport).not.toBeNull();
    const mobileNavigation = (viewport?.width ?? 0) <= 780;
    await context.setOffline(true);
    await expect(page.getByText('Sin conexión.', { exact: false }).first()).toBeVisible();
    await context.setOffline(false);

    if (mobileNavigation) {
        await openMore(page);
        await page.getByRole('dialog').getByRole('button', { name: 'Compras', exact: true }).click();
    } else {
        await page.getByRole('button', { name: 'Compras', exact: true }).click();
    }
    await expect(page.getByRole('heading', { level: 1, name: 'Compras' })).toBeVisible();
    await page.getByRole('button', { name: 'Proveedores', exact: true }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'Proveedores' })).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
    await expectNoHorizontalOverflow(page);

    const returnFocus = page.getByRole('button', { name: 'Nuevo proveedor' });
    await returnFocus.click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo proveedor' });
    await expect(dialog).toBeVisible();
    const dialogBox = await dialog.boundingBox();
    expect(dialogBox).not.toBeNull();
    expect(dialogBox!.x).toBeGreaterThanOrEqual(0);
    expect(dialogBox!.y).toBeGreaterThanOrEqual(0);
    expect(dialogBox!.x + dialogBox!.width).toBeLessThanOrEqual(viewport!.width + 1);
    expect(dialogBox!.y + dialogBox!.height).toBeLessThanOrEqual(viewport!.height + 1);
    await expect(dialog.locator(':focus')).toHaveCount(1);

    const modalA11y = await new AxeBuilder({ page })
        .include('.base-modal')
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    expect(modalA11y.violations).toEqual([]);

    await page.keyboard.press('Tab');
    await expect(dialog.locator(':focus')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(returnFocus).toBeFocused();

    const actionTrigger = page.getByRole('button', { name: /Acciones/ }).last();
    await actionTrigger.focus();
    await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('menuitem').first()).toBeFocused();

    const clipping = await menu.evaluate((element) => {
        const menuRect = element.getBoundingClientRect();
        let visible = menuRect.left >= 0
            && menuRect.top >= 0
            && menuRect.right <= window.innerWidth
            && menuRect.bottom <= window.innerHeight;
        let ancestor = element.parentElement;
        while (ancestor && ancestor !== document.body) {
            const style = getComputedStyle(ancestor);
            if (/(auto|hidden|scroll|clip)/.test(`${style.overflow} ${style.overflowX} ${style.overflowY}`)) {
                const rect = ancestor.getBoundingClientRect();
                visible = visible
                    && menuRect.left >= rect.left
                    && menuRect.right <= rect.right
                    && menuRect.top >= rect.top
                    && menuRect.bottom <= rect.bottom;
            }
            ancestor = ancestor.parentElement;
        }

        return {
            visible,
            menu: {
                left: menuRect.left,
                top: menuRect.top,
                right: menuRect.right,
                bottom: menuRect.bottom,
            },
        };
    });
    expect(clipping.visible, `Action menu is clipped: ${JSON.stringify(clipping)}`).toBe(true);

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(actionTrigger).toBeFocused();
});
