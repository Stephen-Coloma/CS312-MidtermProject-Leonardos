<?php
    require_once('../database/database.php');
    $db = Database::getInstance();

    $pending_orders = $db->getTotalPendingOrders($_SESSION['ORG_ID']);
    $sales = $db->getSales($_SESSION['ORG_ID']);
    $most_ordered_products = $db->getMostOrderedProducts($_SESSION['ORG_ID'], 5);

    foreach ($most_ordered_products as &$product) {
        if (!empty($product['product_image'])) {
            // Convert BLOB to Base64
            $product['product_image'] = 'data:image/jpeg;base64,' . base64_encode($product['product_image']);
        }
    }
?>