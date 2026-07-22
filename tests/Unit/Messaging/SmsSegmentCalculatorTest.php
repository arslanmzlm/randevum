<?php

use App\Modules\Messaging\Support\SmsSegmentCalculator;

beforeEach(function (): void {
    $this->calculator = new SmsSegmentCalculator;
});

it('returns 0 segments for an empty string', function (): void {
    expect($this->calculator->count(''))->toBe(['encoding' => 'gsm7', 'length' => 0, 'segments' => 0]);
});

it('classifies plain ASCII text as GSM-7, single segment', function (): void {
    $result = $this->calculator->count('Randevunuz yarin saat 14:30 icin onaylandi.');

    expect($result['encoding'])->toBe('gsm7')
        ->and($result['segments'])->toBe(1);
});

it('classifies text containing a Turkish character as UCS-2 (forces non-GSM-7)', function (): void {
    $result = $this->calculator->count('Randevunuz için teşekkürler');

    expect($result['encoding'])->toBe('ucs2');
});

it('a 160-char GSM-7 message is exactly 1 segment; 161 chars spills to 2', function (): void {
    $at160 = $this->calculator->count(str_repeat('a', 160));
    $at161 = $this->calculator->count(str_repeat('a', 161));

    expect($at160['segments'])->toBe(1)
        ->and($at161['segments'])->toBe(2);
});

it('multi-segment GSM-7 uses the 153-char-per-segment concatenation length', function (): void {
    // 153*2 = 306 fits exactly 2 segments; 307 spills to 3.
    $at306 = $this->calculator->count(str_repeat('a', 306));
    $at307 = $this->calculator->count(str_repeat('a', 307));

    expect($at306['segments'])->toBe(2)
        ->and($at307['segments'])->toBe(3);
});

it('a 70-char UCS-2 message is exactly 1 segment; 71 chars spills to 2', function (): void {
    $at70 = $this->calculator->count(str_repeat('ş', 70));
    $at71 = $this->calculator->count(str_repeat('ş', 71));

    expect($at70['encoding'])->toBe('ucs2')
        ->and($at70['segments'])->toBe(1)
        ->and($at71['segments'])->toBe(2);
});

it('multi-segment UCS-2 uses the 67-char-per-segment concatenation length', function (): void {
    // 67*3 = 201 fits exactly 3 segments; 202 spills to 4.
    $at201 = $this->calculator->count(str_repeat('ş', 201));
    $at202 = $this->calculator->count(str_repeat('ş', 202));

    expect($at201['segments'])->toBe(3)
        ->and($at202['segments'])->toBe(4);
});

it('a GSM-7 extension character (€) counts as 2 septets toward the length', function (): void {
    $result = $this->calculator->count('€');

    expect($result['encoding'])->toBe('gsm7')
        ->and($result['length'])->toBe(2)
        ->and($result['segments'])->toBe(1);
});

it('a template exceeding the 3-segment cap (459 GSM-7 chars) computes 4 segments', function (): void {
    $exactlyAtCap = $this->calculator->count(str_repeat('a', 459));
    $overCap = $this->calculator->count(str_repeat('a', 460));

    expect($exactlyAtCap['segments'])->toBe(3)
        ->and($overCap['segments'])->toBe(4);
});
