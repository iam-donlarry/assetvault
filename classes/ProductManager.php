<?php
declare(strict_types=1);

/**
 * classes/ProductManager.php
 * Master Product Catalog Business Logic Layer
 * 
 * Enforces canonical uniqueness, tracking type immutability,
 * and weighted average cost calculations.
 */
class ProductManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieve all products with aggregated category and stock counts
     */
    public function getAll(array $filters = []): array
    {
        $sql = "
            SELECT 
                p.*,
                c.name AS category_name,
                c.code AS category_code,
                COALESCE(a_counts.asset_count, 0) AS serialized_asset_count,
                COALESCE(s_counts.total_available, 0) AS bulk_available_qty,
                COALESCE(s_counts.total_assigned, 0) AS bulk_assigned_qty,
                COALESCE(s_counts.total_damaged, 0) AS bulk_damaged_qty,
                COALESCE(s_counts.total_under_repair, 0) AS bulk_repair_qty
            FROM product_models p
            JOIN asset_categories c ON p.category_id = c.id
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS asset_count
                FROM assets
                GROUP BY product_id
            ) a_counts ON p.id = a_counts.product_id
            LEFT JOIN (
                SELECT 
                    product_id,
                    SUM(quantity_available) AS total_available,
                    SUM(quantity_assigned) AS total_assigned,
                    SUM(quantity_damaged) AS total_damaged,
                    SUM(quantity_under_repair) AS total_under_repair
                FROM inventory_stock
                GROUP BY product_id
            ) s_counts ON p.id = s_counts.product_id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = (int)$filters['category_id'];
        }

        if (!empty($filters['tracking_type'])) {
            $sql .= " AND p.tracking_type = :tracking_type";
            $params[':tracking_type'] = $filters['tracking_type'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.product_code LIKE :search OR p.brand LIKE :search OR p.model_name LIKE :search OR p.model_number LIKE :search)";
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= " AND p.is_active = :is_active";
            $params[':is_active'] = (int)$filters['is_active'];
        }

        $sql .= " ORDER BY c.name ASC, p.brand ASC, p.model_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve single product by ID with full details
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                p.*,
                c.name AS category_name,
                c.code AS category_code,
                c.is_serialized AS category_is_serialized,
                COALESCE(a_counts.asset_count, 0) AS serialized_asset_count,
                COALESCE(s_counts.total_available, 0) AS bulk_available_qty,
                COALESCE(s_counts.total_assigned, 0) AS bulk_assigned_qty,
                COALESCE(s_counts.total_damaged, 0) AS bulk_damaged_qty,
                COALESCE(s_counts.total_under_repair, 0) AS bulk_repair_qty
            FROM product_models p
            JOIN asset_categories c ON p.category_id = c.id
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS asset_count
                FROM assets
                GROUP BY product_id
            ) a_counts ON p.id = a_counts.product_id
            LEFT JOIN (
                SELECT 
                    product_id,
                    SUM(quantity_available) AS total_available,
                    SUM(quantity_assigned) AS total_assigned,
                    SUM(quantity_damaged) AS total_damaged,
                    SUM(quantity_under_repair) AS total_under_repair
                FROM inventory_stock
                GROUP BY product_id
            ) s_counts ON p.id = s_counts.product_id
            WHERE p.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Generate next sequential product code (PRD-001 format)
     */
    public function generateProductCode(): string
    {
        $stmt = $this->db->query("SELECT MAX(id) AS max_id FROM product_models");
        $row = $stmt->fetch();
        $nextNum = ($row['max_id'] ? (int)$row['max_id'] : 0) + 1;
        return sprintf("PRD-%04d", $nextNum);
    }

    /**
     * Check if canonical product combination already exists
     */
    public function checkCanonicalExists(int $categoryId, string $brand, string $modelName, ?string $modelNumber, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT id FROM product_models 
            WHERE category_id = :category_id 
              AND LOWER(TRIM(brand)) = LOWER(TRIM(:brand))
              AND LOWER(TRIM(model_name)) = LOWER(TRIM(:model_name))
              AND (
                  (model_number IS NULL AND :model_number IS NULL)
                  OR LOWER(TRIM(COALESCE(model_number, ''))) = LOWER(TRIM(COALESCE(:model_number2, '')))
              )
        ";
        $params = [
            ':category_id' => $categoryId,
            ':brand' => $brand,
            ':model_name' => $modelName,
            ':model_number' => $modelNumber,
            ':model_number2' => $modelNumber ?? ''
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch();
    }

    /**
     * Create a new product catalog item
     */
    public function create(array $data): int
    {
        $categoryId = (int)($data['category_id'] ?? 0);
        $brand = trim($data['brand'] ?? '');
        $modelName = trim($data['model_name'] ?? '');
        $modelNumber = !empty(trim($data['model_number'] ?? '')) ? trim($data['model_number']) : null;
        $trackingType = ($data['tracking_type'] ?? 'serialized') === 'bulk_quantity' ? 'bulk_quantity' : 'serialized';
        $unitOfMeasure = trim($data['unit_of_measure'] ?? 'units') ?: 'units';
        $specifications = !empty($data['specifications']) ? trim($data['specifications']) : null;
        $minReorderLevel = max(0, (int)($data['min_reorder_level'] ?? 0));
        $defaultPurchaseCost = isset($data['default_purchase_cost']) && $data['default_purchase_cost'] !== '' ? (float)$data['default_purchase_cost'] : null;
        $averageCost = $defaultPurchaseCost ?? 0.00;

        if ($categoryId <= 0 || empty($brand) || empty($modelName)) {
            throw new InvalidArgumentException("Category, Brand, and Model Name are required.");
        }

        if ($this->checkCanonicalExists($categoryId, $brand, $modelName, $modelNumber)) {
            throw new InvalidArgumentException("A product with this Category, Brand, Model Name, and Model Number already exists.");
        }

        $productCode = !empty($data['product_code']) ? trim($data['product_code']) : $this->generateProductCode();

        // Ensure product code is unique
        $checkStmt = $this->db->prepare("SELECT id FROM product_models WHERE product_code = :code");
        $checkStmt->execute([':code' => $productCode]);
        if ($checkStmt->fetch()) {
            $productCode = $this->generateProductCode();
        }

        $stmt = $this->db->prepare("
            INSERT INTO product_models (
                product_code, category_id, brand, model_name, model_number,
                tracking_type, unit_of_measure, specifications, min_reorder_level,
                default_purchase_cost, average_unit_cost, is_active
            ) VALUES (
                :product_code, :category_id, :brand, :model_name, :model_number,
                :tracking_type, :unit_of_measure, :specifications, :min_reorder_level,
                :default_purchase_cost, :average_unit_cost, :is_active
            )
        ");

        $stmt->execute([
            ':product_code' => $productCode,
            ':category_id' => $categoryId,
            ':brand' => $brand,
            ':model_name' => $modelName,
            ':model_number' => $modelNumber,
            ':tracking_type' => $trackingType,
            ':unit_of_measure' => $unitOfMeasure,
            ':specifications' => $specifications,
            ':min_reorder_level' => $minReorderLevel,
            ':default_purchase_cost' => $defaultPurchaseCost,
            ':average_unit_cost' => $averageCost,
            ':is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Check if tracking type can be safely changed
     */
    public function canChangeTrackingType(int $id, string $newTrackingType): bool
    {
        $current = $this->getById($id);
        if (!$current || $current['tracking_type'] === $newTrackingType) {
            return true;
        }

        // Check if any serialized assets exist for this product
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM assets WHERE product_id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }

        // Check if any inventory stock exists (any quantity > 0)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM inventory_stock 
            WHERE product_id = :id 
              AND (quantity_available > 0 OR quantity_assigned > 0 OR quantity_damaged > 0 OR quantity_under_repair > 0)
        ");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }

        // Check if any assignment records exist
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM asset_assignments WHERE product_id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Update product details
     */
    public function update(int $id, array $data): bool
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new InvalidArgumentException("Product not found.");
        }

        $categoryId = (int)($data['category_id'] ?? $existing['category_id']);
        $brand = trim($data['brand'] ?? $existing['brand']);
        $modelName = trim($data['model_name'] ?? $existing['model_name']);
        $modelNumber = array_key_exists('model_number', $data) 
            ? (!empty(trim((string)$data['model_number'])) ? trim((string)$data['model_number']) : null)
            : $existing['model_number'];
        $newTrackingType = $data['tracking_type'] ?? $existing['tracking_type'];

        if ($newTrackingType !== $existing['tracking_type']) {
            if (!$this->canChangeTrackingType($id, $newTrackingType)) {
                throw new LogicException("Tracking type cannot be changed once physical units, inventory stock, or assignment records exist.");
            }
        }

        if ($this->checkCanonicalExists($categoryId, $brand, $modelName, $modelNumber, $id)) {
            throw new InvalidArgumentException("Another product with this Category, Brand, Model Name, and Model Number already exists.");
        }

        $unitOfMeasure = trim($data['unit_of_measure'] ?? $existing['unit_of_measure']) ?: 'units';
        $specifications = array_key_exists('specifications', $data) ? trim((string)$data['specifications']) : $existing['specifications'];
        $minReorderLevel = isset($data['min_reorder_level']) ? max(0, (int)$data['min_reorder_level']) : (int)$existing['min_reorder_level'];
        $defaultCost = array_key_exists('default_purchase_cost', $data) 
            ? ($data['default_purchase_cost'] !== '' && $data['default_purchase_cost'] !== null ? (float)$data['default_purchase_cost'] : null)
            : $existing['default_purchase_cost'];
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : (int)$existing['is_active'];

        $stmt = $this->db->prepare("
            UPDATE product_models SET
                category_id = :category_id,
                brand = :brand,
                model_name = :model_name,
                model_number = :model_number,
                tracking_type = :tracking_type,
                unit_of_measure = :unit_of_measure,
                specifications = :specifications,
                min_reorder_level = :min_reorder_level,
                default_purchase_cost = :default_purchase_cost,
                is_active = :is_active
            WHERE id = :id
        ");

        return $stmt->execute([
            ':category_id' => $categoryId,
            ':brand' => $brand,
            ':model_name' => $modelName,
            ':model_number' => $modelNumber,
            ':tracking_type' => $newTrackingType,
            ':unit_of_measure' => $unitOfMeasure,
            ':specifications' => $specifications,
            ':min_reorder_level' => $minReorderLevel,
            ':default_purchase_cost' => $defaultCost,
            ':is_active' => $isActive,
            ':id' => $id
        ]);
    }

    /**
     * Recalculate Weighted Average Cost (WAC) when receiving bulk stock
     */
    public function updateAverageCostOnIntake(int $productId, int $newQty, float $intakeUnitCost): float
    {
        $product = $this->getById($productId);
        if (!$product) {
            throw new InvalidArgumentException("Product not found.");
        }

        $currentAvailable = (int)$product['bulk_available_qty'];
        $currentAvgCost = (float)$product['average_unit_cost'];

        $totalQty = $currentAvailable + $newQty;
        if ($totalQty <= 0) {
            $newAvgCost = $intakeUnitCost;
        } else {
            $totalValue = ($currentAvailable * $currentAvgCost) + ($newQty * $intakeUnitCost);
            $newAvgCost = round($totalValue / $totalQty, 2);
        }

        $stmt = $this->db->prepare("UPDATE product_models SET average_unit_cost = :avg_cost WHERE id = :id");
        $stmt->execute([':avg_cost' => $newAvgCost, ':id' => $productId]);

        return $newAvgCost;
    }
}
