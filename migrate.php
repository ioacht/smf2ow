<?php
ini_set("display_errors", "1");
error_reporting(E_ALL);

require_once("conf.php");
require_once("MigrationPersistence.php");
require_once("UsersMigrator.php");
require_once("PostsMigrator.php");
require_once("AttachmentsMigrator.php");

include(OW_DIR_ROOT . 'ow_includes' . DS . 'init.php');
require_once(OW_DIR_ROOT . 'ow_includes' . DS . 'init.php');

// Init OW
$application = OW::getApplication();
$application->init();

// Persistence
$persistence = MigrationPersistence::getInstance();
$persistence->log("start");

// Migrators
$users_migrator = UsersMigrator::getInstance();
$posts_migrator = PostsMigrator::getInstance();
$attachments_migrator = AttachmentsMigrator::getInstance();

// Stages
$stages = array(
    array($users_migrator, "migrate"),
    array($posts_migrator, "migrate"),
    array($attachments_migrator, "migrate")
);

// Go!
$state = $persistence->getState();
$current_stage = $state['current_stage'];
if($current_stage < count($stages)){
    $start_time = time();
    call_user_func($stages[$current_stage]);
    $elapsed = time() - $start_time;
    echo("Operation Time:   " . $elapsed  ." sec <br/>");
} else {
    echo("Migration Done!");
}

$persistence->log("end");


