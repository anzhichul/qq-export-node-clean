<?php
$params = $_GET;
$params['page'] = 'user_recharge';
header('Location: /?' . http_build_query($params));
exit;
