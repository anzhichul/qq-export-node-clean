<?php
$params = $_GET;
$params['page'] = 'user_home';
header('Location: /?' . http_build_query($params));
exit;
