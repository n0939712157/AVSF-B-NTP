<?php
/** AVSF root connector. All internal paths are derived from this file location. */
if (!defined('AVSF_ROOT')) define('AVSF_ROOT', __DIR__);
if (!defined('AVSF_VERSION')) define('AVSF_VERSION', '3.2.8');
if (!defined('AVSF_CORE_DIR')) define('AVSF_CORE_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-core');
if (!defined('AVSF_ADMIN_DIR')) define('AVSF_ADMIN_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-admin');
if (!defined('AVSF_FRONTEND_DIR')) define('AVSF_FRONTEND_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-frontend');
if (!defined('AVSF_INSTALLER_DIR')) define('AVSF_INSTALLER_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-installer');
if (!defined('AVSF_STORAGE_DIR')) define('AVSF_STORAGE_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-storage');
if (!defined('AVSF_UPDATE_DIR')) define('AVSF_UPDATE_DIR', AVSF_ROOT . DIRECTORY_SEPARATOR . 'avsf-updates');
if (!defined('AVSF_UPLOAD_DIR')) define('AVSF_UPLOAD_DIR', AVSF_STORAGE_DIR . DIRECTORY_SEPARATOR . 'uploads');
if (!defined('AVSF_CACHE_DIR')) define('AVSF_CACHE_DIR', AVSF_STORAGE_DIR . DIRECTORY_SEPARATOR . 'cache');
if (!defined('AVSF_LOG_DIR')) define('AVSF_LOG_DIR', AVSF_STORAGE_DIR . DIRECTORY_SEPARATOR . 'logs');
if (!defined('AVSF_BACKUP_DIR')) define('AVSF_BACKUP_DIR', AVSF_STORAGE_DIR . DIRECTORY_SEPARATOR . 'backups');
if (!defined('AVSF_QUARANTINE_DIR')) define('AVSF_QUARANTINE_DIR', AVSF_STORAGE_DIR . DIRECTORY_SEPARATOR . 'quarantine');
require_once AVSF_CORE_DIR . DIRECTORY_SEPARATOR . 'bootstrap.php';
