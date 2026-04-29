<?php
<?php
session_start();// for cart
$id = $_POST['id'] ?? '';
if (!ctype_digit($id)) die("Invalid product");

$_SESSION['cart'] = $_SESSION['cart'] ?? [];// ensure cart exists
$_SESSION['cart'][] = (int)$id;

header("Location: cart.php");// redirect to cart
exit;