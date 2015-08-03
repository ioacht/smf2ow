<?php
require_once 'meekrodb.2.3.class.php';
require_once 'conf.php';

class MigrationPersistence {

    private static $classInstance;
    private static $pre = MIGRATION_HELPER_DB_TABLE_PREFIX;
    private $db;
    private $ow_db;
    private $default_ow_user_id;
    private $state;

    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function addUserEntry($smf_id, $ow_id, $smf_username) {
        $this->db->insert(self::$pre.'_users', array("smf_id" => $smf_id, "ow_id" => $ow_id, "smf_user_name" => $smf_username));
    }

    public function addTopicEntry($smf_id, $ow_id) {
        $this->db->insert(self::$pre.'_topics', array("smf_id" => $smf_id, "ow_id" => $ow_id));
    }

    public function addPostEntry($smf_id, $ow_id) {
        $this->db->insert(self::$pre.'_posts', array("smf_id" => $smf_id, "ow_id" => $ow_id));
    }

    public function addAttachmentEntry($smf_id, $ow_id) {
        $this->db->insert(self::$pre.'_attachments', array("smf_id" => $smf_id, "ow_id" => $ow_id));
    }

    public function reportProblem($smf_id, $type, $error) {
        $this->db->insert(self::$pre.'_problems', array("object_type" => $type, "object_id" => $smf_id, "error_message" => $error));
    }

    public function getOwUserId($smf_id) {
        $t = self::$pre."_users";
        $data = $this->db->queryFirstRow("SELECT ow_id, smf_user_name FROM " . $t . " WHERE smf_id = %i", $smf_id);
        $ow_id = $data["ow_id"];
        if(!is_null($ow_id)){
            return $ow_id;
        } else {
            return $this->default_ow_user_id;
        }
    }

    public function getOwTopicId($smf_id) {
        $t = self::$pre."_topics";
        $data = $this->db->queryFirstRow("SELECT ow_id FROM " . $t . " WHERE smf_id = %i", $smf_id);
        return $data["ow_id"];
    }

    public function getOwAttachmentId($smf_id) {
        $t = self::$pre."_attachments";
        $data = $this->db->queryFirstRow("SELECT ow_id FROM " . $t . " WHERE smf_id = %i", $smf_id);
        return $data["ow_id"];
    }

    public function getState() {
        if(is_null($this->state)){
            $t = self::$pre."_state";
            $this->state = $this->db->queryFirstRow("SELECT * FROM " . $t . " WHERE id = 8");
            if(is_null($this->state)) {
                $this->state = array(
                    "id" => 8,
                    "current_stage" => 0,
                    "current_forum_index" => 0,
                    "last_user_id" => 0
                );
                $this->db->insert(self::$pre."_state", $this->state);
            }
        }
        return $this->state;
    }

    public function progressToNextStage() {
        $this->state["current_stage"] += 1;
        $this->db->update(self::$pre."_state", $this->state, "id = 8");
    }

    public function setLastUserId($id) {
        $this->state["last_user_id"] = $id;
        $this->db->update(self::$pre."_state", $this->state, "id = 8");
    }

    public function setLastImportedTopicId($topic_id, $forum_id) {
        $this->db->insert(self::$pre."_last_import", array("object_type" => "topic",
            "object_id" => $topic_id, "parent_id" => $forum_id));
    }

    public function setLastImportedPostId($post_id, $topic_id) {
        $this->db->insert(self::$pre."_last_import", array("object_type" => "post",
            "object_id" => $post_id, "parent_id" => $topic_id));
    }

    public function setLastImportedAttachmentId($attachment_id, $post_id) {
        $this->db->insert(self::$pre."_last_import", array("object_type" => "attachment",
            "object_id" => $attachment_id, "parent_id" => $post_id));
    }

    public function getLastImportedTopicId($forum_id) {
        $table = self::$pre."_last_import";
        return $this->db->queryFirstField("SELECT object_id FROM " . $table . " WHERE object_type=%s AND parent_id=%i
                                          ORDER BY id DESC LIMIT 1",
            "topic", $forum_id);
    }

    public function getLastImportedPostId($topic_id) {
        $table = self::$pre."_last_import";
        return $this->db->queryFirstField("SELECT object_id FROM " . $table . " WHERE object_type=%s AND parent_id=%i
                                           ORDER BY id DESC LIMIT 1",
            "post", $topic_id);
    }

    public function getLastImportedAttachmentId($post_id) {
        $table = self::$pre."_last_import";
        return $this->db->queryFirstField("SELECT object_id FROM " . $table . " WHERE object_type=%s AND parent_id=%i
                                           ORDER BY id DESC LIMIT 1",
            "attachment", $post_id);
    }

    private function __construct()
    {
        $this->createHelperDbTables();
        $this->db = new MeekroDB(MIGRATION_HELPER_DB_HOST, MIGRATION_HELPER_DB_USER, MIGRATION_HELPER_DB_PASSWORD, MIGRATION_HELPER_DB_SCHEMA);
        $this->ow_db = new MeekroDB(OW_DB_HOST, OW_DB_USER, OW_DB_PASSWORD, OW_DB_NAME);
        $this->createDefaultUser();
    }

    private function createDefaultUser() {
        $default_user_id = $this->ow_db->queryFirstField("SELECT id FROM " . OW_DB_PREFIX . "base_user
                                                          WHERE username = 'anonymous'");
        if(is_null($default_user_id )) {
            $this->ow_db->insert(OW_DB_PREFIX . 'base_user', array(
                "username" => "anonymous",
                "email" => "a@nonymo.us",
                "joinIp" => 0
            ));
            $id = $this->default_ow_user_id = $this->ow_db->insertId();
            $this->ow_db->insert(OW_DB_PREFIX.'base_question_data', array(
                "questionName" => "realname",
                "userId" => $id,
                "textValue" => "Anonymous"
            ));
        } else {
            $this->default_ow_user_id = $default_user_id;
        }
    }

    private function createHelperDbTables() {
        // Create connection
        $conn = new mysqli(MIGRATION_HELPER_DB_HOST, MIGRATION_HELPER_DB_USER, MIGRATION_HELPER_DB_PASSWORD, MIGRATION_HELPER_DB_SCHEMA);
        // Check connection
        if ($conn->connect_error) {
            throw new Exception('MigrationMapper database connection failed');
        }
        // sql to create table
        $sql_users = sprintf("CREATE TABLE IF NOT EXISTS %s_users (
          smf_id INT(8) UNSIGNED PRIMARY KEY,
          ow_id INT(11),
          smf_user_name varchar(80)
        )", self::$pre);

        $sql_topics = sprintf("CREATE TABLE IF NOT EXISTS %s_topics (
          smf_id INT(8) UNSIGNED PRIMARY KEY,
          ow_id INT(11)
        )", self::$pre);

        $sql_posts = sprintf("CREATE TABLE IF NOT EXISTS %s_posts (
          smf_id INT(8) UNSIGNED PRIMARY KEY,
          ow_id INT(11)
        )", self::$pre);

        $sql_attachments = sprintf("CREATE TABLE IF NOT EXISTS %s_attachments (
          smf_id INT(8) UNSIGNED PRIMARY KEY,
          ow_id INT(11)
        )", self::$pre);

        $sql_problems = sprintf("CREATE TABLE IF NOT EXISTS %s_problems (
          id INT(8) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          object_type varchar(20),
          object_id INT(8) UNSIGNED,
          error_message varchar(256)
        )", self::$pre);

        $sql_state = sprintf("CREATE TABLE IF NOT EXISTS %s_state (
          id INT(1) UNSIGNED PRIMARY KEY,
          current_stage INT(1) UNSIGNED,
          current_forum_index INT(2) UNSIGNED,
          last_user_id INT(8) UNSIGNED,
          last_topic_id INT(8) UNSIGNED,
          last_post_id INT(8) UNSIGNED,
          last_attachment_id INT(8) UNSIGNED
        )", self::$pre);

        $sql_last_import = sprintf("CREATE TABLE IF NOT EXISTS %s_last_import (
          id INT(1) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          object_type varchar(20),
          object_id INT(8) UNSIGNED,
          parent_id INT(8) UNSIGNED
        )", self::$pre);

        if ($conn->query($sql_users) !== TRUE) {
            throw new Exception('MigrationMapper users table creation failed');
        }

        if ($conn->query($sql_topics) !== TRUE) {
            throw new Exception('MigrationMapper topics table creation failed');
        }

        if ($conn->query($sql_posts) !== TRUE) {
            throw new Exception('MigrationMapper posts table creation failed');
        }

        if ($conn->query($sql_attachments) !== TRUE) {
            throw new Exception('MigrationMapper attachments table creation failed');
        }

        if ($conn->query($sql_problems) !== TRUE) {
            throw new Exception('MigrationMapper problems table creation failed');
        }

        if ($conn->query($sql_state) !== TRUE) {
            throw new Exception('MigrationMapper state table creation failed');
        }

        if ($conn->query($sql_last_import) !== TRUE) {
            throw new Exception('MigrationMapper last_import table creation failed');
        }

        $conn->close();
    }
} 