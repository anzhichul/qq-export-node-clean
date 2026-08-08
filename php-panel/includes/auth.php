<?php
if (empty($_SESSION['user_id'])) {
  header('Location: /?page=login');
  exit;
}
$current_user = $_SESSION['username'] ?? '';
$current_role = $_SESSION['role'] ?? '';
$current_display_name = $_SESSION['display_name'] ?? $current_user;
$page = $_GET['page'] ?? 'dashboard';
