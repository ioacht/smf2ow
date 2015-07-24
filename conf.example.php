<?php
define('BATCH_SIZE', 1000);

// USERS AND GROUPS
define('SMF_ACTIVE_GROUP_ID', 10);
define('SMF_NON_ACTIVE_GROUP_ID', 11);

define('OW_ACTIVE_GROUP_ID', 15);
define('OW_NON_ACTIVE_GROUP_ID', 16);

// FORUMS
define('SMF_FORUM_IDS', serialize([1]));
define('OW_FORUM_IDS', serialize([4]));

// DATABASES
define('MIGRATION_HELPER_DB_HOST', '127.0.0.1');
define('MIGRATION_HELPER_DB_USER', 'root');
define('MIGRATION_HELPER_DB_PASSWORD', '');
define('MIGRATION_HELPER_DB_SCHEMA', 'migration_helper');
define('MIGRATION_HELPER_DB_TABLE_PREFIX', 'mgr8');

define('SMF_DB_HOST', '127.0.0.1');
define('SMF_DB_USER', 'root');
define('SMF_DB_PASSWORD', '');
define('SMF_DB_SCHEMA', 'old_smf');

// PATHS
define('_OW_', true);
define('DS', DIRECTORY_SEPARATOR);
define('DIR_ROOT', dirname(__FILE__) . DS);
define('OW_DIR_ROOT', dirname(__FILE__) . DS . 'oxwall' . DS);