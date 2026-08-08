<?php
$params = $_GET;
$params['page'] = 'user_exports';
header('Location: /?' . http_build_query($params));
exit;
