<?php
declare(strict_types=1);

/**
 * classes/AssignmentManager.php
 * Dual Checkout (Serialized Assets & Bulk Inventory) and Return Custody Manager
 * 
 * Enforces:
 * - Serialized unit checkout with asset status locking
 * - Bulk quantity checkout from designated source locations
 * - Partial returns ($1 <= return <= remaining$) with automatic state transitions
 * - Condition-based stock routing on return (available vs damaged)
 * - Zero Fake Assets rule: bulk items never create rows in assets table
 */
class AssignmentManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate unique assignment code (ASG-20260909-XXXX)
     */
    public function generateAssignmentCode(): string
    {
        return 'ASG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Generate unique transaction code for inventory ledger
     */
    private function generateTransactionCode(): string
    {
        return 'ITX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Check out a single serialized asset unit to an employee
     */
    public function checkoutSerialized(
        int $assetId,
        int $employeeId,
        string $assignedDate,
        int $userId,
        array $options = []
    ): int {
        $this->db->beginTransaction();

        try {
            // Lock asset row
            $stmt = $this->db->prepare("
                SELECT id, asset_tag, product_id, current_location_id, lifecycle_status, condition_status 
                FROM assets 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $assetId]);
            $asset = $stmt->fetch();

            if (!$asset) {
                throw new InvalidArgumentException("Asset not found.");
            }

            if ($asset['lifecycle_status'] !== 'available') {
                throw new InvalidArgumentException("Asset {$asset['asset_tag']} is not available for assignment (Current status: {$asset['lifecycle_status']}).");
            }

            // Verify employee exists and is active
            $empStmt = $this->db->prepare("SELECT id, employment_status FROM employees WHERE id = :id");
            $empStmt->execute([':id' => $employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp || $emp['employment_status'] !== 'active') {
                throw new InvalidArgumentException("Selected employee is not active.");
            }

            $conditionAtIssue = $options['condition'] ?? $asset['condition_status'] ?? 'good';
            $agreementSigned = !empty($options['agreement_signed']) ? 1 : 0;
            $agreementDate = $agreementSigned ? ($options['agreement_date'] ?? $assignedDate) : null;
            $remarks = !empty($options['remarks']) ? trim($options['remarks']) : null;

            // Update asset custody
            $updateAsset = $this->db->prepare("
                UPDATE assets 
                SET current_holder_id = :emp_id,
                    lifecycle_status = 'assigned'
                WHERE id = :id
            ");
            $updateAsset->execute([':emp_id' => $employeeId, ':id' => $assetId]);

            // Create assignment record
            $code = $this->generateAssignmentCode();
            $asgStmt = $this->db->prepare("
                INSERT INTO asset_assignments (
                    assignment_code, asset_id, product_id, source_location_id,
                    quantity, quantity_returned, employee_id, assigned_date,
                    assigned_by_user_id, condition_at_issue, agreement_signed,
                    agreement_date, status, remarks
                ) VALUES (
                    :code, :asset_id, :product_id, :source_loc,
                    1, 0, :employee_id, :assigned_date,
                    :assigned_by, :condition, :agreement_signed,
                    :agreement_date, 'active', :remarks
                )
            ");
            $asgStmt->execute([
                ':code' => $code,
                ':asset_id' => $assetId,
                ':product_id' => $asset['product_id'],
                ':source_loc' => $asset['current_location_id'],
                ':employee_id' => $employeeId,
                ':assigned_date' => $assignedDate,
                ':assigned_by' => $userId,
                ':condition' => $conditionAtIssue,
                ':agreement_signed' => $agreementSigned,
                ':agreement_date' => $agreementDate,
                ':remarks' => $remarks
            ]);

            $assignmentId = (int)$this->db->lastInsertId();

            // Record movement history
            $movStmt = $this->db->prepare("
                INSERT INTO asset_movements (
                    asset_id, from_location_id, to_location_id, to_employee_id,
                    moved_by_user_id, reason, notes
                ) VALUES (
                    :asset_id, :from_loc, :to_loc, :to_emp,
                    :moved_by, 'Staff Assignment', :notes
                )
            ");
            $movStmt->execute([
                ':asset_id' => $assetId,
                ':from_loc' => $asset['current_location_id'],
                ':to_loc' => $asset['current_location_id'],
                ':to_emp' => $employeeId,
                ':moved_by' => $userId,
                ':notes' => "Assigned to staff via code {$code}"
            ]);

            $this->db->commit();
            return $assignmentId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Check out bulk consumable/un-serialized equipment to an employee
     * Strict Rule: NEVER creates rows in the assets table.
     */
    public function checkoutBulk(
        int $productId,
        int $sourceLocationId,
        int $quantity,
        int $employeeId,
        string $assignedDate,
        int $userId,
        array $options = []
    ): int {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Checkout quantity must be greater than zero.");
        }

        $this->db->beginTransaction();

        try {
            // Verify product
            $prodStmt = $this->db->prepare("SELECT id, tracking_type, average_unit_cost, brand, model_name FROM product_models WHERE id = :id");
            $prodStmt->execute([':id' => $productId]);
            $product = $prodStmt->fetch();

            if (!$product || $product['tracking_type'] !== 'bulk_quantity') {
                throw new InvalidArgumentException("Product is not configured for bulk quantity tracking.");
            }

            // Verify employee
            $empStmt = $this->db->prepare("SELECT id, employment_status FROM employees WHERE id = :id");
            $empStmt->execute([':id' => $employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp || $emp['employment_status'] !== 'active') {
                throw new InvalidArgumentException("Selected employee is not active.");
            }

            // Lock inventory stock row
            $stockStmt = $this->db->prepare("
                SELECT id, quantity_available 
                FROM inventory_stock 
                WHERE product_id = :p AND location_id = :l 
                FOR UPDATE
            ");
            $stockStmt->execute([':p' => $productId, ':l' => $sourceLocationId]);
            $stock = $stockStmt->fetch();

            if (!$stock || (int)$stock['quantity_available'] < $quantity) {
                $avail = $stock ? (int)$stock['quantity_available'] : 0;
                throw new InvalidArgumentException("Insufficient available stock at source location (Requested: {$quantity}, Available: {$avail}).");
            }

            // Update stock balances atomically
            $updateStock = $this->db->prepare("
                UPDATE inventory_stock 
                SET quantity_available = quantity_available - :qty1,
                    quantity_assigned = quantity_assigned + :qty2 
                WHERE id = :id
            ");
            $updateStock->execute([':qty1' => $quantity, ':qty2' => $quantity, ':id' => $stock['id']]);

            $conditionAtIssue = $options['condition'] ?? 'good';
            $remarks = !empty($options['remarks']) ? trim($options['remarks']) : null;

            // Create assignment record (asset_id is NULL)
            $code = $this->generateAssignmentCode();
            $asgStmt = $this->db->prepare("
                INSERT INTO asset_assignments (
                    assignment_code, asset_id, product_id, source_location_id,
                    quantity, quantity_returned, employee_id, assigned_date,
                    assigned_by_user_id, condition_at_issue, agreement_signed,
                    status, remarks
                ) VALUES (
                    :code, NULL, :product_id, :source_loc,
                    :qty, 0, :employee_id, :assigned_date,
                    :assigned_by, :condition, 0,
                    'active', :remarks
                )
            ");
            $asgStmt->execute([
                ':code' => $code,
                ':product_id' => $productId,
                ':source_loc' => $sourceLocationId,
                ':qty' => $quantity,
                ':employee_id' => $employeeId,
                ':assigned_date' => $assignedDate,
                ':assigned_by' => $userId,
                ':condition' => $conditionAtIssue,
                ':remarks' => $remarks
            ]);

            $assignmentId = (int)$this->db->lastInsertId();

            // Record transaction ledger entry
            $txCode = $this->generateTransactionCode();
            $txStmt = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    transaction_code, product_id, transaction_type, from_location_id,
                    quantity, unit_cost, reference_type, reference_id, notes, performed_by_user_id, created_by_user_id
                ) VALUES (
                    :code, :product_id, 'staff_checkout', :from_loc,
                    :qty, :cost, 'asset_assignment', :ref_id, :notes, :user_id, :user_id2
                )
            ");
            $txStmt->execute([
                ':code' => $txCode,
                ':product_id' => $productId,
                ':from_loc' => $sourceLocationId,
                ':qty' => -$quantity,
                ':cost' => (float)$product['average_unit_cost'],
                ':ref_id' => $assignmentId,
                ':notes' => "Checked out {$quantity} units to employee #{$employeeId} (Assignment: {$code})",
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);

            $this->db->commit();
            return $assignmentId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Process return of an item (Serialized or Bulk, with partial return support)
     */
    public function returnItem(
        int $assignmentId,
        int $returnedQuantity,
        int $returnLocationId,
        string $conditionAtReturn,
        string $returnedDate,
        int $userId,
        ?string $returnReason = null,
        ?string $remarks = null
    ): bool {
        $this->db->beginTransaction();

        try {
            // Lock assignment row
            $asgStmt = $this->db->prepare("SELECT * FROM asset_assignments WHERE id = :id FOR UPDATE");
            $asgStmt->execute([':id' => $assignmentId]);
            $asg = $asgStmt->fetch();

            if (!$asg) {
                throw new InvalidArgumentException("Assignment record not found.");
            }

            if (!in_array($asg['status'], ['active', 'partially_returned'], true)) {
                throw new InvalidArgumentException("This assignment is already closed (Status: {$asg['status']}).");
            }

            $totalQty = (int)$asg['quantity'];
            $alreadyReturned = (int)$asg['quantity_returned'];
            $remaining = $totalQty - $alreadyReturned;

            if ($returnedQuantity <= 0 || $returnedQuantity > $remaining) {
                throw new InvalidArgumentException("Returned quantity must be between 1 and remaining assigned units ({$remaining}).");
            }

            $isSerialized = !empty($asg['asset_id']);

            if ($isSerialized) {
                // Serialized return
                $assetId = (int)$asg['asset_id'];

                $assetStmt = $this->db->prepare("SELECT id, current_location_id FROM assets WHERE id = :id FOR UPDATE");
                $assetStmt->execute([':id' => $assetId]);
                $asset = $assetStmt->fetch();

                $newLifecycle = ($conditionAtReturn === 'damaged') ? 'damaged' : 'available';

                // Update asset
                $updateAsset = $this->db->prepare("
                    UPDATE assets 
                    SET current_holder_id = NULL,
                        current_location_id = :loc_id,
                        condition_status = :condition,
                        lifecycle_status = :lifecycle
                    WHERE id = :id
                ");
                $updateAsset->execute([
                    ':loc_id' => $returnLocationId,
                    ':condition' => $conditionAtReturn,
                    ':lifecycle' => $newLifecycle,
                    ':id' => $assetId
                ]);

                // Update assignment
                $updateAsg = $this->db->prepare("
                    UPDATE asset_assignments 
                    SET quantity_returned = 1,
                        returned_date = :ret_date,
                        returned_to_user_id = :ret_user,
                        condition_at_return = :cond_ret,
                        return_reason = :ret_reason,
                        status = 'returned',
                        remarks = CONCAT(COALESCE(remarks, ''), '\n', :remarks)
                    WHERE id = :id
                ");
                $updateAsg->execute([
                    ':ret_date' => $returnedDate,
                    ':ret_user' => $userId,
                    ':cond_ret' => $conditionAtReturn,
                    ':ret_reason' => $returnReason,
                    ':remarks' => $remarks ?: '',
                    ':id' => $assignmentId
                ]);

                // Record movement
                $movStmt = $this->db->prepare("
                    INSERT INTO asset_movements (
                        asset_id, from_location_id, to_location_id, from_employee_id,
                        moved_by_user_id, reason, notes
                    ) VALUES (
                        :asset_id, :from_loc, :to_loc, :from_emp,
                        :moved_by, 'Staff Return', :notes
                    )
                ");
                $movStmt->execute([
                    ':asset_id' => $assetId,
                    ':from_loc' => $asset['current_location_id'],
                    ':to_loc' => $returnLocationId,
                    ':from_emp' => $asg['employee_id'],
                    ':moved_by' => $userId,
                    ':notes' => "Returned by staff in {$conditionAtReturn} condition"
                ]);
            } else {
                // Bulk return
                $productId = (int)$asg['product_id'];
                $sourceLocationId = (int)$asg['source_location_id'];

                $newReturnedTotal = $alreadyReturned + $returnedQuantity;
                $newStatus = ($newReturnedTotal >= $totalQty) ? 'returned' : 'partially_returned';

                // Update assignment record
                $updateAsg = $this->db->prepare("
                    UPDATE asset_assignments 
                    SET quantity_returned = :new_ret_qty,
                        returned_date = :ret_date,
                        returned_to_user_id = :ret_user,
                        condition_at_return = :cond_ret,
                        return_reason = :ret_reason,
                        status = :new_status,
                        remarks = CONCAT(COALESCE(remarks, ''), '\n', :remarks)
                    WHERE id = :id
                ");
                $updateAsg->execute([
                    ':new_ret_qty' => $newReturnedTotal,
                    ':ret_date' => $returnedDate,
                    ':ret_user' => $userId,
                    ':cond_ret' => $conditionAtReturn,
                    ':ret_reason' => $returnReason,
                    ':new_status' => $newStatus,
                    ':remarks' => $remarks ?: '',
                    ':id' => $assignmentId
                ]);

                // Update source location's assigned stock
                $sourceStockStmt = $this->db->prepare("
                    SELECT id FROM inventory_stock 
                    WHERE product_id = :p AND location_id = :l 
                    FOR UPDATE
                ");
                $sourceStockStmt->execute([':p' => $productId, ':l' => $sourceLocationId]);
                $srcStock = $sourceStockStmt->fetch();

                if ($srcStock) {
                    $deductAssigned = $this->db->prepare("
                        UPDATE inventory_stock 
                        SET quantity_assigned = GREATEST(0, quantity_assigned - :qty) 
                        WHERE id = :id
                    ");
                    $deductAssigned->execute([':qty' => $returnedQuantity, ':id' => $srcStock['id']]);
                }

                // Ensure return location stock row exists
                $ensureDest = $this->db->prepare("
                    INSERT INTO inventory_stock (product_id, location_id, quantity_available, quantity_assigned, quantity_damaged, quantity_under_repair)
                    VALUES (:p, :l, 0, 0, 0, 0)
                    ON DUPLICATE KEY UPDATE id=id
                ");
                $ensureDest->execute([':p' => $productId, ':l' => $returnLocationId]);

                // Increment available or damaged at return location
                $targetColumn = ($conditionAtReturn === 'damaged') ? 'quantity_damaged' : 'quantity_available';
                $addStock = $this->db->prepare("
                    UPDATE inventory_stock 
                    SET {$targetColumn} = {$targetColumn} + :qty 
                    WHERE product_id = :p AND location_id = :l
                ");
                $addStock->execute([':qty' => $returnedQuantity, ':p' => $productId, ':l' => $returnLocationId]);

                // Log inventory transaction
                $costStmt = $this->db->prepare("SELECT average_unit_cost FROM product_models WHERE id = :id");
                $costStmt->execute([':id' => $productId]);
                $unitCost = (float)$costStmt->fetchColumn();

                $txCode = $this->generateTransactionCode();
                $txStmt = $this->db->prepare("
                    INSERT INTO inventory_transactions (
                        transaction_code, product_id, transaction_type, to_location_id,
                        quantity, unit_cost, reference_type, reference_id, notes, performed_by_user_id, created_by_user_id
                    ) VALUES (
                        :code, :product_id, 'staff_return', :to_loc,
                        :qty, :cost, 'asset_assignment', :ref_id, :notes, :user_id, :user_id2
                    )
                ");
                $txStmt->execute([
                    ':code' => $txCode,
                    ':product_id' => $productId,
                    ':to_loc' => $returnLocationId,
                    ':qty' => $returnedQuantity,
                    ':cost' => $unitCost,
                    ':ref_id' => $assignmentId,
                    ':notes' => "Returned {$returnedQuantity} units by employee #{$asg['employee_id']} ({$conditionAtReturn} condition)",
                    ':user_id' => $userId,
                    ':user_id2' => $userId
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
