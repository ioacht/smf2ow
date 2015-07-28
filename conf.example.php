<?php
define('BATCH_SIZE', 1000);

// USERS AND GROUPS (first value in array is the default)
define('SMF_USER_GROUPS_IDS', serialize(array(2, 4, 8)));
define('OW_USER_GROUPS_IDS', serialize(array(10, 11, 12)));

// FORUMS
define('SMF_FORUM_IDS', serialize(array(1)));
define('OW_FORUM_IDS', serialize(array(8)));

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

define('OW_SMILEYS_BASE_URL', "http://example.com/ow_userfiles/plugins/smileys/smileys/");