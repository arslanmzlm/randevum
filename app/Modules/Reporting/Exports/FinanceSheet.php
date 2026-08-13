<?php

namespace App\Modules\Reporting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The finance tab's export sheet: a two-column (item, amount) summary, followed by
 * the same breakdown blocks the finance page shows (by method, by period, manual
 * income by category, expense by category), each introduced by a section-header row.
 */
class FinanceSheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array{summary: array{today: string, this_month: string}, range: array<string, mixed>}  $revenue
     * @param  array{total: string, by_category: array<int, array{category: ?string, total: string}>}  $expense
     */
    public function __construct(
        private array $revenue,
        private array $expense,
        private string $net,
    ) {}

    public function title(): string
    {
        return __('report.tabs.finance');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [__('report.finance.item'), __('report.finance.amount')];
    }

    /**
     * @return array<int, array{0: string, 1: float|null}>
     */
    public function array(): array
    {
        return [
            [__('report.finance.revenue'), (float) $this->revenue['range']['total']],
            [__('report.finance.expense'), (float) $this->expense['total']],
            [__('report.finance.net'), (float) $this->net],
            ['', null],
            ...$this->section(__('report.finance.by_method'), $this->methodRows()),
            ['', null],
            ...$this->section(__('report.finance.by_period'), $this->periodRows($this->revenue['range']['by_period'])),
            ['', null],
            ...$this->section(__('report.finance.manual_by_category'), $this->categoryRows($this->revenue['range']['manual_by_category'])),
            ['', null],
            ...$this->section(__('report.finance.expense_by_category'), $this->categoryRows($this->expense['by_category'])),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return ['A' => 32, 'B' => 16];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<int, array{0: string, 1: float|null}>  $rows
     * @return array<int, array{0: string, 1: float|null}>
     */
    private function section(string $title, array $rows): array
    {
        return [[$title, null], ...$rows];
    }

    /**
     * @return array<int, array{0: string, 1: float}>
     */
    private function methodRows(): array
    {
        return array_map(
            fn (array $row): array => [__('treatment.payment.method.'.$row['method']), (float) $row['total']],
            $this->revenue['range']['by_method'],
        );
    }

    /**
     * @param  array<int, array{period: string, total: string}>  $periods
     * @return array<int, array{0: string, 1: float}>
     */
    private function periodRows(array $periods): array
    {
        return array_map(
            fn (array $row): array => [$row['period'], (float) $row['total']],
            $periods,
        );
    }

    /**
     * @param  array<int, array{category: ?string, total: string}>  $categories
     * @return array<int, array{0: string, 1: float}>
     */
    private function categoryRows(array $categories): array
    {
        return array_map(
            fn (array $row): array => [$row['category'] ?? __('report.unspecified'), (float) $row['total']],
            $categories,
        );
    }
}
