<?php
$params = $_GET;
$params['page'] = 'user_send';
header('Location: /?' . http_build_query($params));
exit;
