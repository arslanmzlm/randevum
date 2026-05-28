<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Session;

/**
 * Flash toast notifications to the session. Picked up by HandleInertiaRequests
 * (shared as `flash.toasts`) and rendered client-side via PrimeVue Toast.
 *
 * Multiple calls in one request accumulate. Severity maps to PrimeVue:
 * success | info | warn | error.
 */
class Toast
{
    public static function success(string $summary, ?string $detail = null, int $life = 4000): void
    {
        self::add('success', $summary, $detail, $life);
    }

    public static function info(string $summary, ?string $detail = null, int $life = 4000): void
    {
        self::add('info', $summary, $detail, $life);
    }

    public static function warning(string $summary, ?string $detail = null, int $life = 5000): void
    {
        self::add('warn', $summary, $detail, $life);
    }

    public static function error(string $summary, ?string $detail = null, int $life = 6000): void
    {
        self::add('error', $summary, $detail, $life);
    }

    /**
     * @param  'success'|'info'|'warn'|'error'  $severity
     */
    public static function add(string $severity, string $summary, ?string $detail = null, int $life = 4000): void
    {
        $toasts = Session::get('toasts', []);
        $toasts[] = [
            'severity' => $severity,
            'summary' => $summary,
            'detail' => $detail,
            'life' => $life,
        ];
        Session::flash('toasts', $toasts);
    }
}
