<?php
// add_to_cart.php — AJAX endpoint, returns JSON
session_start();
require_once 'includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'not_logged_in']);
    exit();
}

$pid = (int)($_POST['product_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));

if (!$pid) {
    echo json_encode(['error' => 'invalid_product']);
    exit();
}

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

if (isset($_SESSION['cart'][$pid])) {
    $_SESSION['cart'][$pid]['qty'] += $qty;
    $name = $_SESSION['cart'][$pid]['name'];
} else {
    $pq = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $pq->bind_param("i", $pid);
    $pq->execute();
    $pr = $pq->get_result()->fetch_assoc();
    if (!$pr) {
        echo json_encode(['error' => 'product_not_found']);
        exit();
    }
    $price = ($pr['is_promo'] && $pr['promo_price']) ? (float)$pr['promo_price'] : (float)$pr['price'];
    $_SESSION['cart'][$pid] = [
        'id'    => $pr['id'],
        'name'  => $pr['name'],
        'price' => $price,
        'qty'   => $qty
    ];
    $name = $pr['name'];
}

$cart_count = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_count += $item['qty'];
}

echo json_encode([
    'success'    => true,
    'name'       => $name,
    'cart_count' => $cart_count
]);
