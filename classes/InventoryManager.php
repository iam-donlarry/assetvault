<?php
declare(strict_types=1);

/**
 * classes/InventoryManager.php
 * Bulk Inventory Stock & Immutable Transaction Ledger
 * 
 * Manages location-level stock balances and strictly maintains the invariant:
 * Total Owned = quantity_available + quantity_assigned + quantity_damaged + quantity_under_repair
 * Uses SELECT ... FOR UPDATE pessimistic row locks for concurrency safety.
 */
class InventoryManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate unique transaction code (ITX-20260909-XXXX format)
     */
    private function generateTransactionCode(): string
    {
        return 'ITX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Get stock balances for a product across all locations or a specific location
     */
    public function getStock(int $productId, ?int $locationId = null): array
    {
        $sql = "
            SELECT 
                s.*,
                l.name AS location_name,
                l.code AS location_code,
                l.building,
                l.floor,
                (s.quantity_available + s.quantity_assigned + s.quantity_damaged + s.quantity_under_repair) AS total_owned
            FROM inventory_stock s
            JOIN locations l ON s.location_id = l.id
            WHERE s.product_id = :product_id
        ";
        $params = [':product_id' => $productId];

        if ($locationId !== null) {
            $sql .= " AND s.location_id = :location_id";
            $params[':location_id'] = $locationId;
        }

        $sql .= " ORDER BY l.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get aggregated inventory stock across all products with reorder alerts
     */
    public function getAllStockSummary(array $filters = []): array
    {
        $sql = "
            SELECT 
                p.id AS product_id,
                p.product_code,
                p.brand,
                p.model_name,
                p.model_number,
                p.tracking_type,
                p.unit_of_measure,
                p.min_reorder_level,
                p.average_unit_cost,
                c.name AS category_name,
                COALESCE(SUM(s.quantity_available), 0) AS total_available,
                COALESCE(SUM(s.quantity_assigned), 0) AS total_assigned,
                COALESCE(SUM(s.quantity_damaged), 0) AS total_damaged,
                COALESCE(SUM(s.quantity_under_repair), 0) AS total_under_repair,
                (
                    COALESCE(SUM(s.quantity_available), 0) +
                    COALESCE(SUM(s.quantity_assigned), 0) +
                    COALESCE(SUM(s.quantity_damaged), 0) +
                    COALESCE(SUM(s.quantity_under_repair), 0)
                ) AS total_owned,
                (
                    (COALESCE(SUM(s.quantity_available), 0) +
                     COALESCE(SUM(s.quantity_assigned), 0) +
                     COALESCE(SUM(s.quantity_damaged), 0) +
                     COALESCE(SUM(s.quantity_under_repair), 0)) * p.average_unit_cost
                ) AS total_valuation
            FROM product_models p
            JOIN asset_categories c ON p.category_id = c.id
            LEFT JOIN inventory_stock s ON p.id = s.product_id
            WHERE p.tracking_type = 'bulk_quantity'
        ";

        $params = [];

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = (int)$filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.product_code LIKE :search OR p.brand LIKE :search OR p.model_name LIKE :search)";
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }

        $sql .= " GROUP BY p.id";

        if (!empty($filters['low_stock_only'])) {
            $sql .= " HAVING total_available <= p.min_reorder_level";
        }

        $sql .= " ORDER BY c.name ASC, p.brand ASC, p.model_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Ensure stock row exists for product at location
     */
    private function ensureStockRowExists(int $productId, int $locationId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO inventory_stock (product_id, location_id, quantity_available, quantity_assigned, quantity_damaged, quantity_under_repair)
            VALUES (:product_id, :location_id, 0, 0, 0, 0)
            ON DUPLICATE KEY UPDATE id=id
        ");
        $stmt->execute([':product_id' => $productId, ':location_id' => $locationId]);
    }

    /**
     * Receive new bulk stock into a specific location
     */
    public function receiveStock(
        int $productId,
        int $locationId,
        int $quantity,
        ?float $unitCost,
        int $userId,
        ?string $notes = null
    ): int {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Received quantity must be greater than zero.");
        }

        // Verify product is bulk_quantity
        $prodStmt = $this->db->prepare("SELECT tracking_type FROM product_models WHERE id = :id");
        $prodStmt->execute([':id' => $productId]);
        $prod = $prodStmt->fetch();
        if (!$prod || $prod['tracking_type'] !== 'bulk_quantity') {
            throw new InvalidArgumentException("Only products with bulk_quantity tracking type can be received into bulk inventory.");
        }

        $this->db->beginTransaction();

        try {
            $this->ensureStockRowExists($productId, $locationId);

            // Row lock
            $lockStmt = $this->db->prepare("
                SELECT id, quantity_available 
                FROM inventory_stock 
                WHERE product_id = :p AND location_id = :l 
                FOR UPDATE
            ");
            $lockStmt->execute([':p' => $productId, ':l' => $locationId]);
            $stockRow = $lockStmt->fetch();

            // Update Weighted Average Cost if cost provided (before stock update so prior quantity is accurate)
            if ($unitCost !== null && $unitCost > 0) {
                $pm = new ProductManager($this->db);
                $pm->updateAverageCostOnIntake($productId, $quantity, $unitCost);
            }

            // Update stock
            $updateStmt = $this->db->prepare("
                UPDATE inventory_stock 
                SET quantity_available = quantity_available + :qty 
                WHERE id = :id
            ");
            $updateStmt->execute([':qty' => $quantity, ':id' => $stockRow['id']]);

            // Write immutable transaction ledger
            $txCode = $this->generateTransactionCode();
            $txStmt = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    transaction_code, product_id, transaction_type, to_location_id,
                    quantity, unit_cost, reference_type, notes, performed_by_user_id, created_by_user_id
                ) VALUES (
                    :code, :product_id, 'purchase_receive', :to_loc,
                    :qty, :cost, 'direct_receipt', :notes, :user_id, :user_id2
                )
            ");
            $txStmt->execute([
                ':code' => $txCode,
                ':product_id' => $productId,
                ':to_loc' => $locationId,
                ':qty' => $quantity,
                ':cost' => $unitCost,
                ':notes' => $notes,
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            $txId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $txId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Atomically transfer bulk stock between locations
     */
    public function transferStock(
        int $productId,
        int $fromLocationId,
        int $toLocationId,
        int $quantity,
        int $userId,
        ?string $notes = null
    ): int {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Transfer quantity must be greater than zero.");
        }
        if ($fromLocationId === $toLocationId) {
            throw new InvalidArgumentException("Source and destination locations cannot be identical.");
        }

        $this->db->beginTransaction();

        try {
            $this->ensureStockRowExists($productId, $fromLocationId);
            $this->ensureStockRowExists($productId, $toLocationId);

            // Pessimistic row locks on both rows in deterministic order to prevent deadlocks
            $firstLoc = min($fromLocationId, $toLocationId);
            $secondLoc = max($fromLocationId, $toLocationId);

            $lockStmt = $this->db->prepare("
                SELECT id, location_id, quantity_available 
                FROM inventory_stock 
                WHERE product_id = :p AND location_id IN (:loc1, :loc2) 
                ORDER BY location_id ASC 
                FOR UPDATE
            ");
            $lockStmt->execute([':p' => $productId, ':loc1' => $firstLoc, ':loc2' => $secondLoc]);
            $rows = $lockStmt->fetchAll();

            $sourceRow = null;
            $destRow = null;
            foreach ($rows as $r) {
                if ((int)$r['location_id'] === $fromLocationId) $sourceRow = $r;
                if ((int)$r['location_id'] === $toLocationId) $destRow = $r;
            }

            if (!$sourceRow || (int)$sourceRow['quantity_available'] < $quantity) {
                $avail = $sourceRow ? (int)$sourceRow['quantity_available'] : 0;
                throw new InvalidArgumentException("Insufficient available stock at source location (Requested: {$quantity}, Available: {$avail}).");
            }

            // Deduct from source
            $deductStmt = $this->db->prepare("UPDATE inventory_stock SET quantity_available = quantity_available - :qty WHERE id = :id");
            $deductStmt->execute([':qty' => $quantity, ':id' => $sourceRow['id']]);

            // Add to destination
            $addStmt = $this->db->prepare("UPDATE inventory_stock SET quantity_available = quantity_available + :qty WHERE id = :id");
            $addStmt->execute([':qty' => $quantity, ':id' => $destRow['id']]);

            // Fetch average unit cost for ledger
            $costStmt = $this->db->prepare("SELECT average_unit_cost FROM product_models WHERE id = :id");
            $costStmt->execute([':id' => $productId]);
            $avgCost = (float)$costStmt->fetchColumn();

            // Record immutable ledger entry
            $txCode = $this->generateTransactionCode();
            $txStmt = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    transaction_code, product_id, transaction_type, from_location_id, to_location_id,
                    quantity, unit_cost, reference_type, notes, performed_by_user_id, created_by_user_id
                ) VALUES (
                    :code, :product_id, 'location_transfer', :from_loc, :to_loc,
                    :qty, :cost, 'location_transfer', :notes, :user_id, :user_id2
                )
            ");
            $txStmt->execute([
                ':code' => $txCode,
                ':product_id' => $productId,
                ':from_loc' => $fromLocationId,
                ':to_loc' => $toLocationId,
                ':qty' => $quantity,
                ':cost' => $avgCost,
                ':notes' => $notes,
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            $txId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $txId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Adjust physical inventory count (Cycle count discrepancy)
     */
    public function adjustStock(
        int $productId,
        int $locationId,
        string $bucket,
        int $newQuantity,
        string $reason,
        int $userId,
        ?string $notes = null
    ): int {
        $allowedBuckets = [
            'available'    => 'quantity_available',
            'damaged'      => 'quantity_damaged',
            'under_repair' => 'quantity_under_repair'
        ];

        if (!isset($allowedBuckets[$bucket])) {
            throw new InvalidArgumentException("Invalid adjustment bucket. Cannot directly adjust 'assigned' quantity.");
        }

        if ($newQuantity < 0) {
            throw new InvalidArgumentException("Quantity cannot be negative.");
        }

        $column = $allowedBuckets[$bucket];

        $this->db->beginTransaction();

        try {
            $this->ensureStockRowExists($productId, $locationId);

            $lockStmt = $this->db->prepare("SELECT id, {$column} FROM inventory_stock WHERE product_id = :p AND location_id = :l FOR UPDATE");
            $lockStmt->execute([':p' => $productId, ':l' => $locationId]);
            $row = $lockStmt->fetch();

            $currentQty = (int)$row[$column];
            $delta = $newQuantity - $currentQty;

            if ($delta === 0) {
                $this->db->rollBack();
                return 0; // No change
            }

            $updateStmt = $this->db->prepare("UPDATE inventory_stock SET {$column} = :new_qty WHERE id = :id");
            $updateStmt->execute([':new_qty' => $newQuantity, ':id' => $row['id']]);

            // Record transaction
            $txCode = $this->generateTransactionCode();
            $txType = 'cycle_count_adjustment';
            $fullNotes = "Cycle count adjustment on {$bucket} bucket from {$currentQty} to {$newQuantity} (Delta: " . ($delta > 0 ? "+{$delta}" : "{$delta}") . "). Reason: {$reason}. " . ($notes ?: '');

            $txStmt = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    transaction_code, product_id, transaction_type, 
                    " . ($delta > 0 ? "to_location_id" : "from_location_id") . ",
                    quantity, reference_type, notes, performed_by_user_id, created_by_user_id
                ) VALUES (
                    :code, :product_id, :tx_type, :loc,
                    :qty, 'adjustment', :notes, :user_id, :user_id2
                )
            ");
            $txStmt->execute([
                ':code' => $txCode,
                ':product_id' => $productId,
                ':tx_type' => $txType,
                ':loc' => $locationId,
                ':qty' => $delta,
                ':notes' => trim($fullNotes),
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            $txId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $txId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark available units as damaged
     */
    public function markAsDamaged(int $productId, int $locationId, int $quantity, int $userId, ?string $notes = null): int
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quantity must be greater than zero.");
        }

        $this->db->beginTransaction();

        try {
            $this->ensureStockRowExists($productId, $locationId);

            $lockStmt = $this->db->prepare("SELECT id, quantity_available FROM inventory_stock WHERE product_id = :p AND location_id = :l FOR UPDATE");
            $lockStmt->execute([':p' => $productId, ':l' => $locationId]);
            $row = $lockStmt->fetch();

            if (!$row || (int)$row['quantity_available'] < $quantity) {
                $avail = $row ? (int)$row['quantity_available'] : 0;
                throw new InvalidArgumentException("Insufficient available stock to write down as damaged (Requested: {$quantity}, Available: {$avail}).");
            }

            $updateStmt = $this->db->prepare("
                UPDATE inventory_stock 
                SET quantity_available = quantity_available - :qty,
                    quantity_damaged = quantity_damaged + :qty
                WHERE id = :id
            ");
            $updateStmt->execute([':qty' => $quantity, ':id' => $row['id']]);

            $txCode = $this->generateTransactionCode();
            $txStmt = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    transaction_code, product_id, transaction_type, from_location_id,
                    quantity, reference_type, notes, performed_by_user_id, created_by_user_id
                ) VALUES (
                    :code, :product_id, 'damage_write_down', :loc,
                    :qty, 'damage_inspection', :notes, :user_id, :user_id2
                )
            ");
            $txStmt->execute([
                ':code' => $txCode,
                ':product_id' => $productId,
                ':loc' => $locationId,
                ':qty' => -$quantity,
                ':notes' => $notes ?: 'Stock marked as damaged during inspection',
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            $txId = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $txId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Retrieve inventory audit ledger transactions
     */
    public function getTransactions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT 
                t.*,
                p.product_code,
                p.brand,
                p.model_name,
                p.tracking_type,
                c.name AS category_name,
                from_loc.name AS from_location_name,
                to_loc.name AS to_location_name,
                u.full_name AS created_by_name
            FROM inventory_transactions t
            JOIN product_models p ON t.product_id = p.id
            JOIN asset_categories c ON p.category_id = c.id
            LEFT JOIN locations from_loc ON t.from_location_id = from_loc.id
            LEFT JOIN locations to_loc ON t.to_location_id = to_loc.id
            LEFT JOIN users u ON t.created_by_user_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['product_id'])) {
            $sql .= " AND t.product_id = :product_id";
            $params[':product_id'] = (int)$filters['product_id'];
        }

        if (!empty($filters['transaction_type'])) {
            $sql .= " AND t.transaction_type = :transaction_type";
            $params[':transaction_type'] = $filters['transaction_type'];
        }

        if (!empty($filters['location_id'])) {
            $sql .= " AND (t.from_location_id = :loc_id OR t.to_location_id = :loc_id)";
            $params[':loc_id'] = (int)$filters['location_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (t.transaction_code LIKE :search OR p.brand LIKE :search OR p.model_name LIKE :search OR t.notes LIKE :search)";
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }

        $sql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
