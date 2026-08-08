<?php
$params = $_GET;
$params['page'] = 'user_smtp';
header('Location: /?' . http_build_query($params));
exit;
