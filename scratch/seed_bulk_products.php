<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pm = new ProductManager($pdo);

$bulkItems = [
    [
        'category_id' => 3, // Mouse
        'brand' => 'Logitech',
        'model_name' => 'B100 Optical USB Mouse',
        'model_number' => '910-001439',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'units',
        'min_reorder_level' => 10,
        'default_purchase_cost' => 6500.00,
        'specifications' => 'Wired USB Optical Mouse, 800 DPI, Ambidextrous design, 1.8m cable'
    ],
    [
        'category_id' => 4, // Keyboard
        'brand' => 'Dell',
        'model_name' => 'KB216 Wired Multimedia Keyboard',
        'model_number' => '580-ADMT',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'units',
        'min_reorder_level' => 8,
        'default_purchase_cost' => 9500.00,
        'specifications' => 'Full-size standard layout, Chiclet keys, Multimedia shortcut keys, Spill resistant'
    ],
    [
        'category_id' => 11, // Headset
        'brand' => 'Logitech',
        'model_name' => 'H390 USB Computer Headset',
        'model_number' => '981-000014',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'units',
        'min_reorder_level' => 5,
        'default_purchase_cost' => 18000.00,
        'specifications' => 'Noise-cancelling mic, In-line audio controls, Padded headband and ear cups, USB-A plug'
    ],
    [
        'category_id' => 14, // Other
        'brand' => 'Ugreen',
        'model_name' => 'Cat6 UTP RJ45 Gigabit Patch Cable (2m)',
        'model_number' => 'NW102',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'pieces',
        'min_reorder_level' => 15,
        'default_purchase_cost' => 2500.00,
        'specifications' => 'Cat6 Gigabit 1000Mbps, 24AWG Pure Copper, Gold-plated RJ45 pins, 2-meter length'
    ],
    [
        'category_id' => 14, // Other
        'brand' => 'Baseus',
        'model_name' => '4K High-Speed HDMI 2.0 Cable (1.8m)',
        'model_number' => 'CAKGQ-B01',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'pieces',
        'min_reorder_level' => 10,
        'default_purchase_cost' => 4000.00,
        'specifications' => 'HDMI 2.0, 4K@60Hz, Braided nylon jacket, Gold-plated connectors, 18Gbps bandwidth'
    ],
    [
        'category_id' => 5, // ID Card
        'brand' => 'Generic',
        'model_name' => 'Staff RFID Proximity Smart Cards (Pack of 50)',
        'model_number' => 'EM4100-50',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'packs',
        'min_reorder_level' => 5,
        'default_purchase_cost' => 15000.00,
        'specifications' => '125kHz RFID access control cards, Printable PVC surface, Read-only pre-programmed ID'
    ],
    [
        'category_id' => 14, // Other
        'brand' => 'Apple',
        'model_name' => '20W USB-C Power Adapter',
        'model_number' => 'MHJE3ZM/A',
        'tracking_type' => 'bulk_quantity',
        'unit_of_measure' => 'units',
        'min_reorder_level' => 5,
        'default_purchase_cost' => 22000.00,
        'specifications' => '20W Fast Charger with USB-C Port, Power Delivery (PD 3.0), Compact wall plug'
    ]
];

echo "Registering standard bulk catalog models...\n";
foreach ($bulkItems as $item) {
    if (!$pm->checkCanonicalExists($item['category_id'], $item['brand'], $item['model_name'], $item['model_number'])) {
        $id = $pm->create($item);
        echo " ✓ Created Product #{$id}: {$item['brand']} {$item['model_name']}\n";
    } else {
        echo " - Already exists: {$item['brand']} {$item['model_name']}\n";
    }
}
echo "Done!\n";
