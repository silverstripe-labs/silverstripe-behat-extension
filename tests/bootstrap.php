<?php
$frameworkPath = __DIR__ . '/../framework';
$frameworkDir = basename($frameworkPath);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname($frameworkPath));
}
require_once $frameworkPath . '/core/Core.php';
