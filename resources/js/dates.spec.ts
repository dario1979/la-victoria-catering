import { describe, expect, it } from 'vitest';
import { formatCalendarDate, formatDateTime } from './dates';

describe('date formatting', () => {
    it('keeps API civil dates on the same calendar day', () => {
        expect(formatCalendarDate('2026-07-31')).toBe('31/7/26');
        expect(formatCalendarDate('2026-07-31T00:00:00.000000Z')).toBe('31/7/26');
    });

    it('keeps instants separate from civil dates', () => {
        expect(formatDateTime('2026-07-31T15:30:00Z', 'UTC')).toContain('31/7/26');
        expect(formatDateTime(null)).toBe('Sin fecha');
    });
});
