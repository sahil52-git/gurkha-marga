<?php
// frontend/admin/admin_assign_consultant.php
// Quick assign redirect - now handled inline in admin_subscriptions.php
// Keeping this as a redirect for backward compatibility with existing links

session_start();
$uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
header("Location: admin_subscriptions.php?q=" . urlencode($uid > 0 ? '' : ''));
exit();