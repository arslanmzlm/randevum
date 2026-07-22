/**
 * Encoding-aware SMS length/segment count — the client-side mirror of the PHP
 * `App\Modules\Messaging\Support\SmsSegmentCalculator` (3GPP TS 23.038 default
 * alphabet + extension table). Any character outside GSM-7 — including Turkish
 * ç/ğ/ı/ö/ş/ü — forces UCS-2, matching how gateways bill non-GSM-7 text.
 *
 * By design there is no shared code path with the PHP calculator (per the spec):
 * this counter drives the live UX; the server FormRequest is authoritative and
 * re-computes the same rule before persisting.
 */

const GSM7_BASIC =
    '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

const GSM7_EXTENDED = '^{}\\[~]|€';

const SINGLE_SEGMENT_LENGTH = { gsm7: 160, ucs2: 70 } as const;
const MULTI_SEGMENT_LENGTH = { gsm7: 153, ucs2: 67 } as const;

/**
 * Hard cap mirrored from `config('platform.sms.max_segments')` (default 3). The
 * server enforces the real limit; this drives the client-side block/warning.
 */
export const MAX_SEGMENTS = 3;

export type SmsEncoding = 'gsm7' | 'ucs2';

export interface SmsSegmentInfo {
    encoding: SmsEncoding;
    length: number;
    segments: number;
}

function isGsm7(chars: string[]): boolean {
    return chars.every(
        (char) => GSM7_BASIC.includes(char) || GSM7_EXTENDED.includes(char),
    );
}

/** GSM-7 extension characters (e.g. `€`) are escaped and count as 2 septets. */
function effectiveLength(chars: string[], encoding: SmsEncoding): number {
    if (encoding === 'ucs2') {
        return chars.length;
    }

    return chars.reduce(
        (total, char) => total + (GSM7_EXTENDED.includes(char) ? 2 : 1),
        0,
    );
}

export function countSegments(text: string): SmsSegmentInfo {
    // Spread splits by code point, matching PHP's mb_str_split (multi-byte safe).
    const chars = [...text];
    const encoding: SmsEncoding = isGsm7(chars) ? 'gsm7' : 'ucs2';
    const length = effectiveLength(chars, encoding);

    if (length === 0) {
        return { encoding, length: 0, segments: 0 };
    }

    const segments =
        length <= SINGLE_SEGMENT_LENGTH[encoding]
            ? 1
            : Math.ceil(length / MULTI_SEGMENT_LENGTH[encoding]);

    return { encoding, length, segments };
}
