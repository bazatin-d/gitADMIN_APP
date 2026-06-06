<?php
defined('ASR_ADMIN') || exit;
// Compatibility shim.
// Some Admin App installations still include this legacy module entry file directly,
// while the current module.php points to pages/index.php. Keep one real renderer.
require __DIR__ . '/pages/index.php';
