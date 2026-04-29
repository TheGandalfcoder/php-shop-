<?php
session_start();// for CSRF token
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//helper functions
function redirectTo(string $path): void {
  header('Location: ' . $path);
  exit;
}
// Safely get return_to URL from POST data
function safeReturnTo(string $fallback = 'main.php'): string {
  $returnTo = $_POST['return_to'] ?? $fallback;

  // allow only these pages
  $allowed = ['main.php', 'cart.php', 'home.php', 'product.php', 'category.php'];

  // allow optional query string but check the base file
  $base = strtok($returnTo, '?');
  if (!in_array($base, $allowed, true)) return $fallback;

  return $returnTo;
}
// Database connection
function dbConnect(): mysqli {
  $db = new mysqli('gllmhecomputing.net', 'ddm373811', 'DDMTest!JW1', 'ddm373811');//DB credentials
  if ($db->connect_error) {
    die('Database connection failed: ' . $db->connect_error);//error handling
  }
  $db->set_charset('utf8mb4');//set charset
  return $db;
}
// Save order to database
function saveOrderToDb(mysqli $db, array $order): bool {
  $createdAt = date('Y-m-d H:i:s', strtotime($order['created_at']));
  $ip = $order['ip'] ?? null;
// customer info
  $customerName  = $order['customer']['name'] ?? null;
  $customerEmail = $order['customer']['email'] ?? null;

  $addr1 = $order['address']['line1'] ?? null;
  $addr2 = $order['address']['line2'] ?? null;
  $city  = $order['address']['city'] ?? null;
  $post  = $order['address']['postcode'] ?? null;
  $ctry  = $order['address']['country'] ?? null;

  $total = (float)$order['total'];
// transaction
  try {
    $db->begin_transaction();

    //Prepared statement placeholders are used to securely insert data and prevent SQL injection ... ChatGPT-5 taught me this ideology
    $stmt = $db->prepare("
      INSERT INTO orders (
        order_id, created_at, ip_address,
        customer_name, customer_email,
        address_line1, address_line2, city, postcode, country,
        total
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) throw new Exception("Prepare failed (orders): " . $db->error);//error handling

    $stmt->bind_param(
      "ssssssssssd",//data types ( string .... double )
      $order['order_id'],
      $createdAt,
      $ip,
      $customerName,
      $customerEmail,
      $addr1,
      $addr2,
      $city,
      $post,
      $ctry,
      $total
    );
// execute statement
    if (!$stmt->execute()) throw new Exception("Execute failed (orders): " . $stmt->error);
    $stmt->close();

    //  Insert order line items
    $itemStmt = $db->prepare("
      INSERT INTO order_items (order_id, product_id, quantity)
      VALUES (?, ?, ?)
    ");
    if (!$itemStmt) throw new Exception("Prepare failed (order_items): " . $db->error);
// bind and execute for each item
    foreach ($order['items'] as $it) {
      $productId = (int)$it['id'];
      $qty = (int)$it['quantity'];
// bind params
      $itemStmt->bind_param("sii", $order['order_id'], $productId, $qty);
      if (!$itemStmt->execute()) throw new Exception("Execute failed (order_items): " . $itemStmt->error);
    }
    $itemStmt->close();

    $db->commit();
    return true;
  } catch (Throwable $e) {
    $db->rollback();
    // error_log($e->getMessage());
    return false;
  }
}


// Main processing
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {// only POST allowed
  http_response_code(405);
  echo 'Method not allowed';
  exit;
}

$returnTo = safeReturnTo('main.php');// get return_to URL

$token = $_POST['csrf_token'] ?? '';// CSRF token check
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
  $_SESSION['flash'] = ['errors' => ['Invalid CSRF token']];
  redirectTo($returnTo);
}

$action = $_POST['action'] ?? '';// get action
if ($action === '') {// action required
  $_SESSION['flash'] = ['errors' => ['Missing action']];
  redirectTo($returnTo);
}

// ensure cart exists
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
  $_SESSION['cart'] = [];
}
$cart =& $_SESSION['cart'];

// Handle actions
switch ($action) {// switch based on action

  case 'add': {// add to cart
    $id = $_POST['id'] ?? '';
    if ($id !== '' && ctype_digit((string)$id)) {
      $cart[$id] = ($cart[$id] ?? 0) + 1;
      $_SESSION['flash'] = ['success' => 'Added to cart.'];
    } else {
      $_SESSION['flash'] = ['errors' => ['Invalid product id.']];
    }
    redirectTo($returnTo);
  }

  case 'inc': {// increase quantity
    $id = $_POST['id'] ?? '';
    if ($id !== '' && ctype_digit((string)$id)) {
      $cart[$id] = ($cart[$id] ?? 0) + 1;
    }
    redirectTo($returnTo);
  }

  case 'dec': {// decrease quantity
    $id = $_POST['id'] ?? '';
    if ($id !== '' && isset($cart[$id])) {
      $cart[$id] = ((int)$cart[$id]) - 1;
      if ($cart[$id] <= 0) unset($cart[$id]);
    }
    redirectTo($returnTo);
  }

  case 'remove': {// remove item
    $id = $_POST['id'] ?? '';
    if ($id !== '' && isset($cart[$id])) {
      unset($cart[$id]);
    }
    redirectTo($returnTo);
  }

  case 'clear': {// clear cart
    $_SESSION['cart'] = [];
    $_SESSION['flash'] = ['success' => 'Cart cleared.'];
    redirectTo($returnTo);
  }

  case 'checkout': {// process checkout
    $cartData = $_SESSION['cart'] ?? [];
    if (empty($cartData)) {
      $_SESSION['flash'] = ['errors' => ['Cart is empty.']];
      redirectTo($returnTo);
    }

    $name  = trim($_POST['name'] ?? '');// customer info
    $email = trim($_POST['email'] ?? '');

    if ($name === '') {// name required
      $_SESSION['flash'] = ['errors' => ['Name is required for checkout.']];
      redirectTo($returnTo);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {// validate email
      $_SESSION['flash'] = ['errors' => ['Invalid email address.']];
      redirectTo($returnTo);
    }

    // Address (required)
    $addressLine1 = trim($_POST['address_line1'] ?? '');
    $addressLine2 = trim($_POST['address_line2'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $postcode     = trim($_POST['postcode'] ?? '');
    $country      = trim($_POST['country'] ?? '');

    if ($addressLine1 === '' || $city === '' || $postcode === '' || $country === '') {
      $_SESSION['flash'] = ['errors' => ['Address is required for checkout.']];
      redirectTo($returnTo);
    }

    // Build clean items list (numeric product ids)
    $items = [];
    foreach ($cartData as $pid => $qty) {
      $q = (int)$qty;
      $pid = (string)$pid;
      if ($q <= 0) continue;
      if ($pid === '' || !ctype_digit($pid)) continue;
      $items[] = ['id' => $pid, 'quantity' => $q];
    }

    if (empty($items)) {// no valid items
      $_SESSION['flash'] = ['errors' => ['No valid items in cart.']];
      redirectTo($returnTo);
    }

    // Calculate total from DB prices
    $db = dbConnect();

    $ids = array_map(fn($it) => (int)$it['id'], $items);// extract ids
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmt = $db->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");// prepare statement
    if (!$stmt) {
      $_SESSION['flash'] = ['errors' => ['DB error preparing product lookup.']];
      $db->close();
      redirectTo($returnTo);
    }

    $stmt->bind_param($types, ...$ids);// bind params
    $stmt->execute();
    $res = $stmt->get_result();

    $priceMap = [];
    while ($row = $res->fetch_assoc()) {
      $priceMap[(string)$row['id']] = (float)$row['price'];
    }
    $stmt->close();

    // total
    $total = 0.0;
    foreach ($items as $it) {
      if (!isset($priceMap[$it['id']])) continue;
      $total += $priceMap[$it['id']] * (int)$it['quantity'];
    }

    $order = [// build order array
      'order_id'   => bin2hex(random_bytes(16)),// unique order id
      'created_at' => date('c'),
      'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
      'customer'   => ['name' => $name, 'email' => $email],
      'address'    => [
        'line1'    => $addressLine1,
        'line2'    => $addressLine2,
        'city'     => $city,
        'postcode' => $postcode,
        'country'  => $country,
      ],
      'items'      => $items,
      'total'      => round($total, 2),
    ];

    if (!saveOrderToDb($db, $order)) {// save to DB
      $_SESSION['flash'] = ['errors' => ['Failed to save order to database.']];
      $db->close();
      redirectTo($returnTo);
    }

    $db->close();// close DB

    $_SESSION['cart'] = [];
    $_SESSION['flash'] = ['success' => 'Order placed. ID: ' . $order['order_id']];
   redirectTo('order_success.php?order_id=' . urlencode($order['order_id']));

  }

  default: {// unknown action
    $_SESSION['flash'] = ['errors' => ['Unknown action.']];
    redirectTo($returnTo);
  }
}
