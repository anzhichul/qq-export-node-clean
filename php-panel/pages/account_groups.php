<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
try {
  $uin = $_GET['uin'] ?? '';
  if (!$uin) throw new Exception('missing uin');
  $groups = db()->prepare('SELECT group_id,group_name FROM groups_data WHERE account_uin=? ORDER BY group_name')->execute([$uin])->fetchAll();
  echo json_encode(['ok' => true, 'groups' => $groups]);
} catch (Exception $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
