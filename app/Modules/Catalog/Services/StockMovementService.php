<?php

namespace App\Modules\Catalog\Services;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Catalog\Exceptions\StockMovementSignConflictException;
use App\Modules\Catalog\Repositories\ProductRepository;
use App\Modules\Catalog\Repositories\StockMovementRepository;
use App\Support\ClinicContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The single funnel for every write to products.current_stock. Every path that changes
 * stock (treatment completion, the manual stock dialog, product creation with an opening
 * stock) goes through here so the balance_after snapshot on each movement is race-free.
 */
class StockMovementService implements StockAdjusterContract
{
    public function __construct(
        private ProductRepository $productRepository,
        private StockMovementRepository $movementRepository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Lock the product row, apply $delta to current_stock, and record the movement — all
     * inside one transaction. Returns null (and writes nothing) when $delta is 0.
     *
     * @throws StockMovementSignConflictException when $delta contradicts the reason's own direction
     */
    public function record(
        int $productId,
        int $delta,
        StockMovementReason $reason,
        ?int $treatmentId = null,
        ?string $note = null,
        ?User $actor = null,
    ): ?StockMovement {
        if ($delta === 0) {
            return null;
        }

        // Enforced at the funnel, not at the form: every caller writes the ledger through
        // here, so a reason can never end up with a quantity pointing the other way.
        $sign = $reason->sign();

        if ($sign !== null && ($delta <=> 0) !== $sign) {
            throw new StockMovementSignConflictException($reason, $delta);
        }

        return DB::transaction(function () use ($productId, $delta, $reason, $treatmentId, $note, $actor): StockMovement {
            $product = $this->productRepository->lockForUpdate($productId);

            if ($product === null) {
                throw new \RuntimeException("Product [{$productId}] not found for stock adjustment.");
            }

            $balanceAfter = $product->current_stock + $delta;

            $this->productRepository->updateStock($product, $balanceAfter);

            // clinic_id comes from the locked product, not the ambient ClinicContext, so a
            // movement always lands on the product's own clinic regardless of caller context.
            return $this->movementRepository->create([
                'clinic_id' => $product->clinic_id,
                'product_id' => $product->id,
                'quantity' => $delta,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'treatment_id' => $treatmentId,
                'note' => $note,
                'created_by' => $actor?->id ?? auth()->id(),
            ]);
        });
    }

    public function adjust(
        int $productId,
        int $delta,
        StockMovementReason $reason,
        ?int $treatmentId = null,
        ?string $note = null,
        ?User $actor = null,
    ): void {
        $this->record($productId, $delta, $reason, $treatmentId, $note, $actor);
    }

    /**
     * Set current_stock to an absolute value (the manual stock dialog's contract) by
     * computing the delta from the locked row and delegating to record(). Wrapped in its
     * own transaction so the lock is held from the delta computation through record()'s
     * write — record()'s own DB::transaction() nests as a savepoint on the same connection
     * (the TreatmentService::complete() precedent), so the row stays locked throughout.
     */
    public function setAbsolute(
        Product $product,
        int $newStock,
        StockMovementReason $reason,
        ?string $note,
        ?User $actor,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $newStock, $reason, $note, $actor): ?StockMovement {
            $locked = $this->productRepository->lockForUpdate($product->id);

            if ($locked === null) {
                throw new \RuntimeException("Product [{$product->id}] not found for stock adjustment.");
            }

            $delta = $newStock - $locked->current_stock;

            return $this->record($product->id, $delta, $reason, null, $note, $actor);
        });
    }

    /**
     * Insert an `initial` movement directly, without touching current_stock — used only on
     * product creation, where the product row already carries the opening stock value and
     * balance_after equals the quantity verbatim (applying it again via record() would
     * double it).
     */
    public function recordInitial(Product $product, int $quantity, ?User $actor = null): StockMovement
    {
        return $this->movementRepository->create([
            'clinic_id' => $product->clinic_id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'balance_after' => $quantity,
            'reason' => StockMovementReason::Initial,
            'treatment_id' => null,
            'note' => null,
            'created_by' => $actor?->id ?? auth()->id(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<StockMovement>
     */
    public function paginateForProduct(Product $product): LengthAwarePaginator
    {
        return $this->movementRepository->paginateForProduct($product, $this->clinicContext->timezone());
    }
}
