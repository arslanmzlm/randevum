// The project's single home for clinic-timezone + locale date/time formatting. Pure helpers
// taking an explicit { timeZone, locale } so they stay unit-testable; the useDateTime composable
// binds the clinic tz + active locale once. date-fns-tz handles ONLY the UTC↔clinic-zone step
// (parseUtc); the locale-correct display formatting stays on native Intl.DateTimeFormat (zero-cost
// and accurate), which also applies the timeZone during formatting.
import { toZonedTime } from 'date-fns-tz';

export type DateTimeContext = {
    timeZone: string;
    locale: string;
};

export type FormatRangeOptions = {
    /** Date-only range (no clock time), e.g. an all-day exception. */
    allDay?: boolean;
};

/** En-dash with hair spaces — the project's range separator. */
const RANGE_SEP = ' – ';

function toDate(value: string | Date): Date {
    return value instanceof Date ? value : new Date(value);
}

const formatterCache = new Map<string, Intl.DateTimeFormat>();

function formatter(
    locale: string,
    timeZone: string,
    options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat {
    const key = `${locale}|${timeZone}|${JSON.stringify(options)}`;
    let cached = formatterCache.get(key);

    if (!cached) {
        cached = new Intl.DateTimeFormat(locale, { timeZone, ...options });
        formatterCache.set(key, cached);
    }

    return cached;
}

/**
 * Convert a UTC instant to a Date whose local fields read as the clinic-local wall clock
 * (e.g. for reading the clinic-local hour). Display formatting should prefer the format* helpers,
 * which apply the timeZone via Intl directly.
 */
export function parseUtc(value: string | Date, timeZone: string): Date {
    return toZonedTime(toDate(value), timeZone);
}

export function formatDate(value: string | Date, ctx: DateTimeContext): string {
    return formatter(ctx.locale, ctx.timeZone, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(toDate(value));
}

export function formatTime(value: string | Date, ctx: DateTimeContext): string {
    return formatter(ctx.locale, ctx.timeZone, {
        hour: '2-digit',
        minute: '2-digit',
    }).format(toDate(value));
}

export function formatDateTime(
    value: string | Date,
    ctx: DateTimeContext,
): string {
    return formatter(ctx.locale, ctx.timeZone, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(toDate(value));
}

// The period-heading helpers below are CIVIL (no tz-conversion) — they format local Dates the
// calendar already works in (viewDate, week/day boundaries). Using the tz formatter here would shift
// a week/day edge across midnight (e.g. an end-of-week Sunday 23:59 reading as the next Monday).

/** Long month + year, e.g. "Haziran 2026" — for period/section headings. */
export function formatMonthYear(value: string | Date, locale: string): string {
    return localeDateFormatter(locale, {
        month: 'long',
        year: 'numeric',
    }).format(toDate(value));
}

/** Long date without weekday, e.g. "1 Haziran 2026" — for compact period headings. */
export function formatLongDate(value: string | Date, locale: string): string {
    return localeDateFormatter(locale, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(toDate(value));
}

/** Long weekday + full date, e.g. "Cumartesi, 6 Haziran 2026". */
export function formatFullDate(value: string | Date, locale: string): string {
    return localeDateFormatter(locale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(toDate(value));
}

/**
 * Render a start→end range compactly: same clinic-local day collapses to one date (+ a time
 * span when not all-day); a multi-day range shows both ends. Same logic the appointment surfaces
 * shared ad-hoc before this util existed.
 */
export function formatRange(
    start: string | Date,
    end: string | Date,
    ctx: DateTimeContext,
    options: FormatRangeOptions = {},
): string {
    const startDate = toDate(start);
    const endDate = toDate(end);
    const startDay = formatDate(startDate, ctx);
    const endDay = formatDate(endDate, ctx);
    const sameDay = startDay === endDay;

    if (options.allDay) {
        return sameDay ? startDay : `${startDay}${RANGE_SEP}${endDay}`;
    }

    if (sameDay) {
        return `${startDay} ${formatTime(startDate, ctx)}${RANGE_SEP}${formatTime(endDate, ctx)}`;
    }

    return `${formatDateTime(startDate, ctx)}${RANGE_SEP}${formatDateTime(endDate, ctx)}`;
}

/** True when the instant is strictly in the past (tz-independent — compares absolute time). */
export function isPast(value: string | Date): boolean {
    return toDate(value).getTime() < Date.now();
}

// Date-only helpers (NO timezone) — for calendar dates that are NOT instants: birthdates,
// date-filter values, picker bounds. They read/write LOCAL date fields; never route a date-only
// value through clinic-tz conversion (parseUtc / the format* helpers above) — it shifts the day.

function localeDateFormatter(
    locale: string,
    options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat {
    const key = `${locale}|local|${JSON.stringify(options)}`;
    let cached = formatterCache.get(key);

    if (!cached) {
        cached = new Intl.DateTimeFormat(locale, options);
        formatterCache.set(key, cached);
    }

    return cached;
}

/** A Date → 'YYYY-MM-DD' from its local date fields. */
export function toDateString(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/** 'YYYY-MM-DD' → a local-midnight Date (no tz shift). */
export function parseDateString(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** Locale-formatted date-only label (no timezone); accepts a Date or a 'YYYY-MM-DD' string. */
export function formatDateOnly(value: string | Date, locale: string): string {
    const date = typeof value === 'string' ? parseDateString(value) : value;

    return localeDateFormatter(locale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}
