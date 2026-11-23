<?php
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/service/Core.php';
require dirname(__DIR__).'/app/service/Manager.php';
date_default_timezone_set('Asia/Shanghai');
(new app\service\Manager)->run();
