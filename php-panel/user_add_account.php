<?php
$params = $_GET;
$params['page'] = 'user_add_account';
header('Location: /?' . http_build_query($params));
exit;
