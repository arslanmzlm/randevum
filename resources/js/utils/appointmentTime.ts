// Shared clinic-local date/time helpers for the create-appointment flow. The form keeps a
// Date (inline picker) + an "HH:mm" string (masked input); the server interprets the combined
// value in the clinic timezone. Kept in one place so the page transform, the availability
// pre-check and the day panel all agree.

const TIME_RE = /^([01]?\d|2[0-3]):([0-5]\d)$/;

/** Local Y-m-d (no timezone shift, unlike toISOString). */
export function formatLocalDate(date: Date): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** True when `time` is a real 24h clock time (HH:mm). */
export function isValidTime(time: string): boolean {
    return TIME_RE.test(time);
}

/**
 * Merge a Date + "HH:mm" into a clinic-local wall-clock ISO string
 * (`YYYY-MM-DDTHH:mm:ss`). Returns null until both sides are valid.
 */
export function combineDateTime(
    date: Date | null,
    time: string,
): string | null {
    const match = TIME_RE.exec(time);

    if (!date || !match) {
        return null;
    }

    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${formatLocalDate(date)}T${pad(Number(match[1]))}:${match[2]}:00`;
}

/**
 * Clamp a fully-typed "HH:mm" into a real clock time (hour ≤ 23, minute ≤ 59) so the masked
 * input can never hold an impossible time like 99:99. Incomplete input is left untouched for
 * the required-field validation to handle.
 */
export function clampTime(value: string): string {
    const match = /^(\d{2}):(\d{2})$/.exec(value);

    if (!match) {
        return value;
    }

    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${pad(Math.min(23, Number(match[1])))}:${pad(Math.min(59, Number(match[2])))}`;
}
