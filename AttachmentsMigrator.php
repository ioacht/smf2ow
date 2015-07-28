<?php
require_once ("MigrationPersistence.php");

class AttachmentsMigrator {

    private static $classInstance;
    private $migration_persistence;


    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function migrate() {
        echo("Migrating users ...<br/><br/>");
    }

    private function __construct()
    {
        $this->migration_persistence = MigrationPersistence::getInstance();
    }
} 