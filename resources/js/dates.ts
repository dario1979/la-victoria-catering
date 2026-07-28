const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})(?:T00:00:00(?:\.\d+)?Z)?$/;

export function formatCalendarDate(value: unknown): string {
    if (value === null || value === undefined || value === '') return 'Sin fecha';
    const source = String(value);
    const match = source.match(CIVIL_DATE);
    if (!match) return source;
    const [, year, month, day] = match;
    const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
    if (
        date.getUTCFullYear() !== Number(year)
        || date.getUTCMonth() !== Number(month) - 1
        || date.getUTCDate() !== Number(day)
    ) return source;

    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'short',
        timeZone: 'UTC',
    }).format(date);
}

export function formatDateTime(value: unknown, timeZone?: string): string {
    if (value === null || value === undefined || value === '') return 'Sin fecha';
    const source = String(value);
    const date = new Date(source);
    if (Number.isNaN(date.getTime())) return source;

    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'short',
        timeStyle: 'short',
        ...(timeZone ? { timeZone } : {}),
    }).format(date);
}
