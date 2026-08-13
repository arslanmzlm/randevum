<?php

namespace App\Enums;

enum StockMovementReason: string
{
    case TreatmentUsage = 'treatment_usage';
    case TreatmentVoid = 'treatment_void';
    case Initial = 'initial';

    case StockIn = 'stock_in';
    case PatientReturn = 'patient_return';
    case TransferIn = 'transfer_in';
    case SupplierReturn = 'supplier_return';
    case Wastage = 'wastage';
    case TransferOut = 'transfer_out';
    case CountCorrection = 'count_correction';

    // Superseded by the reasons above, kept so historical rows stay readable. Never written
    // again and never offered in the manual dialog.
    case ManualAdjustment = 'manual_adjustment';
    case Return = 'return';

    /**
     * Which way this reason may move stock: 1 = in, -1 = out, null = either direction.
     * The ledger's core rule — a reason and its quantity can never disagree, which is what
     * made a negative "return" row possible before.
     */
    public function sign(): ?int
    {
        return match ($this) {
            self::StockIn, self::PatientReturn, self::TransferIn, self::TreatmentVoid, self::Return => 1,
            self::SupplierReturn, self::Wastage, self::TransferOut, self::TreatmentUsage => -1,
            self::CountCorrection, self::Initial, self::ManualAdjustment => null,
        };
    }

    /**
     * The reasons a user may pick in the manual stock dialog, in display order. Excludes the
     * system reasons (treatment usage/void, opening stock) and the two legacy ones.
     *
     * @return list<self>
     */
    public static function manualCases(): array
    {
        return [
            self::StockIn,
            self::PatientReturn,
            self::TransferIn,
            self::SupplierReturn,
            self::Wastage,
            self::TransferOut,
            self::CountCorrection,
        ];
    }

    /**
     * Movement mode derives the delta's sign from the reason, so a reason without a fixed
     * sign (count correction) cannot be used there.
     *
     * @return list<self>
     */
    public static function movementModeCases(): array
    {
        return array_values(array_filter(
            self::manualCases(),
            static fn (self $reason): bool => $reason->sign() !== null,
        ));
    }
}
