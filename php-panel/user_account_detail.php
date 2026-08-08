<?php
$params = $_GET;
$params['page'] = 'user_account_detail';
header('Location: /?' . http_build_query($params));
exit;
