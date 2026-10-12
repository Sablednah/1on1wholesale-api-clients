<?php
require __DIR__ . '/Net1on1Client.php';

// ID = the email you log in to 1on1wholesale.co.uk with.
// Key = shown under My Account -> API Documentation (or omit it and it is derived).
$client = new Net1on1Client('you@yourshop.example', 'your-api-key-here');

// ---- 1. Send an order ---------------------------------------------------
try {
    $result = $client->sendOrder([
        'orderno'  => 'SHOP-10001',           // your reference - must be unique
        'shipping' => Net1on1Client::SHIP_TRACKED24,
        'address'  => [
            'firstname' => 'Jane',
            'lastname'  => 'Smith',
            'street'    => '12 High Street',
            'street2'   => 'Flat 3',
            'city'      => 'Manchester',
            'region'    => 'Greater Manchester',
            'postcode'  => 'M1 1AA',
            'country'   => 'GB',
            'telephone' => '07700900123',
            'email'     => 'jane.smith@example.com',
        ],
        'items' => [
            ['sku' => 'N0533', 'quantity' => 1],
            ['sku' => 'N9127', 'variation' => 'NS6335', 'quantity' => 2],
        ],
    ]);

    if ($result['success']) {
        echo "Order created: 1on1 order number {$result['order_id']}\n";
    } else {
        echo "Order NOT created. Problem lines:\n";
        foreach ($result['lines'] as $line) {
            if (!$line['ok']) {
                echo "  {$line['sku']}: {$line['status']}\n";
            }
        }
    }
} catch (Net1on1ApiException $e) {
    // bad key, duplicate orderno, invalid XML, unknown account ...
    echo "Rejected: ", $e->getMessage(), "\n";
} catch (Net1on1TransportException $e) {
    echo "Network problem: ", $e->getMessage(), "\n";
}

// ---- 2. Poll the status feed --------------------------------------------
foreach ($client->getOrders(Net1on1Client::WINDOW_WEEK) as $o) {
    printf("%s  %-11s  yourref=%-12s  %s %s  %s\n",
        $o['orderref'],
        $o['status'],
        $o['importorderref'] ?? '-',
        $o['shiptype'] ?? '',
        $o['tracking'] ?? '',
        Net1on1Client::trackingUrl($o['shiptype'], $o['tracking']) ?? ''
    );
}

// ---- 3. Look up one order by your own reference --------------------------
$o = $client->findOrderByRef('SHOP-10001');
echo $o ? "SHOP-10001 is 1on1 order {$o['orderref']} ({$o['status']})\n" : "SHOP-10001 not found in window\n";
