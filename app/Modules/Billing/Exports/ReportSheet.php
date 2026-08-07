<?php

namespace App\Modules\Billing\Exports;

use App\Enums\ReportTab;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet for a single breakdown tab (doctor/service/product/appointment_type/
 * expense_owner) — the shared row shape from ReportBreakdownService, plus a trailing
 * totals row. Column set is per-tab: the doctor tab exposes the appointment/rate
 * columns, every other tab exposes just label/amount/count/average.
 */
class ReportSheet implements FromArray, WithColumnFormatting, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{amount: string, count: int}  $totals
     */
    public function __construct(
        private ReportTab $tab,
        private array $rows,
        private array $totals,
    ) {}

    public function title(): string
    {
        return Str::limit(__('report.tabs.'.$this->tab->value), 31, '');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_map(fn (string $column): string => $this->heading($column), $this->columns());
    }

    /**
     * label/amount/count mean something different per tab, so the sheet uses the same
     * per-tab headers the screen shows; the rest fall back to the generic column names.
     */
    private function heading(string $column): string
    {
        $perTab = 'report.'.$column.'_header.'.$this->tab->value;

        return Lang::has($perTab) ? __($perTab) : __('report.columns.'.$column);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = array_map(fn (array $row): array => $this->rowValues($row), $this->rows);
        $rows[] = $this->totalsRow();

        return $rows;
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
        $widths = [];

        foreach ($this->columns() as $index => $column) {
            $widths[Coordinate::stringFromColumnIndex($index + 1)] = $column === 'label' ? 32 : 16;
        }

        return $widths;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->columns() as $index => $column) {
            if ($column === 'label') {
                continue;
            }

            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $formats[$letter] = match ($column) {
                'amount', 'average' => NumberFormat::FORMAT_NUMBER_00,
                'cancelled_rate', 'no_show_rate' => '0.0',
                default => NumberFormat::FORMAT_NUMBER,
            };
        }

        return $formats;
    }

    /**
     * @return array<int, mixed>
     */
    private function rowValues(array $row): array
    {
        return array_map(fn (string $column): mixed => match ($column) {
            'label' => $row['label'],
            'amount', 'average' => (float) $row[$column],
            'cancelled_rate', 'no_show_rate' => (float) $row[$column],
            default => (int) $row[$column],
        }, $this->columns());
    }

    /**
     * @return array<int, mixed>
     */
    private function totalsRow(): array
    {
        return array_map(fn (string $column): mixed => match ($column) {
            'label' => __('report.total'),
            'amount' => (float) $this->totals['amount'],
            'count' => $this->totals['count'],
            default => null,
        }, $this->columns());
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return $this->tab === ReportTab::Doctor
            ? ['label', 'amount', 'count', 'average', 'appointment_count', 'cancelled_count', 'no_show_count', 'cancelled_rate', 'no_show_rate']
            : ['label', 'amount', 'count', 'average'];
    }
}
