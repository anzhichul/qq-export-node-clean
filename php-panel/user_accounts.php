<?php
$params = $_GET;
$params['page'] = 'user_accounts';
header('Location: /?' . http_build_query($params));
exit;
