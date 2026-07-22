<?php

namespace App\Modules\Messaging\Support;

/**
 * Encoding-aware SMS length/segment count, provider-independent (3GPP TS 23.038
 * default alphabet + extension table). Any character outside GSM-7 — including
 * Turkish ç/ğ/ı/ö/ş/ü — forces UCS-2, matching how SMS gateways bill non-GSM-7
 * text; this mirrors the project convention ("Türkçe karakter → UCS-2").
 */
class SmsSegmentCalculator
{
    // Escaped "\$" avoids PHP's legacy \x80-\xff "identifier byte" interpolation
    // rule, which would otherwise read "$¥" as the start of a variable name.
    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXTENDED = '^{}\\[~]|€';

    private const SINGLE_SEGMENT_LENGTH = ['gsm7' => 160, 'ucs2' => 70];

    private const MULTI_SEGMENT_LENGTH = ['gsm7' => 153, 'ucs2' => 67];

    /**
     * @return array{encoding: 'gsm7'|'ucs2', length: int, segments: int}
     */
    public function count(string $text): array
    {
        $encoding = $this->isGsm7($text) ? 'gsm7' : 'ucs2';
        $length = $this->effectiveLength($text, $encoding);

        if ($length === 0) {
            return ['encoding' => $encoding, 'length' => 0, 'segments' => 0];
        }

        $segments = $length <= self::SINGLE_SEGMENT_LENGTH[$encoding]
            ? 1
            : (int) ceil($length / self::MULTI_SEGMENT_LENGTH[$encoding]);

        return ['encoding' => $encoding, 'length' => $length, 'segments' => $segments];
    }

    private function isGsm7(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            if (! str_contains(self::GSM7_BASIC, $char) && ! str_contains(self::GSM7_EXTENDED, $char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * GSM-7 extension characters (e.g. `€`) are escaped and count as 2 septets.
     */
    private function effectiveLength(string $text, string $encoding): int
    {
        if ($encoding === 'ucs2') {
            return mb_strlen($text);
        }

        $length = 0;

        foreach (mb_str_split($text) as $char) {
            $length += str_contains(self::GSM7_EXTENDED, $char) ? 2 : 1;
        }

        return $length;
    }
}
