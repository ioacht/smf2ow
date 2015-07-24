<?php
require_once ("MigrationPersistence.php");
require_once("ContentTranslator.php");
require_once(OW_DIR_ROOT . 'ow_plugins' . DS . 'forum' . DS . 'bol' . DS . 'forum_service.php');

class PostsMigrator {

    private static $classInstance;
    private $smf_db;
    private $ow_db;
    private $migration_persistence;
    private $ow_forum_service;
    private $remaining_count;

    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function migrate() {
        $this->remaining_count = BATCH_SIZE;
        $index = $this->migration_persistence->getState()["current_forum_index"];
        $smf_forum_ids = unserialize(SMF_FORUM_IDS);
        $ow_forum_ids = unserialize(OW_FORUM_IDS);
        $this->migrateTopicsForForum($smf_forum_ids[$index], $ow_forum_ids[$index]);
    }

    private function __construct()
    {
        $this->smf_db = new MeekroDB(SMF_DB_HOST, SMF_DB_USER, SMF_DB_PASSWORD, SMF_DB_SCHEMA);
        $this->ow_db = new MeekroDB(OW_DB_HOST, OW_DB_USER, OW_DB_PASSWORD, OW_DB_NAME);
        $this->migration_persistence = MigrationPersistence::getInstance();
        $this->ow_forum_service = FORUM_BOL_ForumService::getInstance();
    }

    private function migrateTopicsForForum($smf_forum_id, $ow_forum_id) {
        while($this->remaining_count > 0) {
            $last_post_id = $this->migration_persistence->getState()["last_post_id"];
            $topicAndNext = $this->getCurrentAndNextTopicForForum($smf_forum_id);
            $topic = $topicAndNext[0];
            if($last_post_id == 0) {
                $ow_topic_id = $this->addTopicToOw($topic, $ow_forum_id);
            } else {
                $ow_topic_id = $this->migration_persistence->getOwTopicId($topic["id_topic"]);
            }
            $this->migrateMessagesForTopic($topic["id_topic"], $ow_topic_id, $topic['id_last_msg']);
            // Update state
            if($this->remaining_count > 0) {
                $this->migration_persistence->setLastTopicId($topicAndNext[1]["id_topic"]);
                $this->migration_persistence->setLastPostId(0);
            }
        }
    }

    private function getCurrentAndNextTopicForForum($smf_forum_id) {
        $last_topic_id = $this->migration_persistence->getState()["last_topic_id"];
        return $this->smf_db->query("SELECT smf_topics.id_topic, smf_topics.is_sticky, smf_topics.id_board,
          smf_topics.id_member_started, smf_topics.locked, smf_topics.id_last_msg,
          smf_topics.num_views, smf_messages.subject
          FROM smf_topics INNER JOIN smf_messages
          ON smf_topics.id_first_msg = smf_messages.id_msg
          WHERE smf_topics.id_board = %i AND smf_topics.id_topic >= %i ORDER BY smf_topics.id_topic ASC LIMIT 2",
            $smf_forum_id, $last_topic_id);
    }

    private function migrateMessagesForTopic($smf_topic_id, $ow_topic_id, $smf_topic_last_post_id) {
        $posts = $this->getPostsForTopic($smf_topic_id);
        foreach($posts as $post) {
            $ow_post_id = $this->addMessageToOw($post, $ow_topic_id);
            if($post["id_msg"] == $smf_topic_last_post_id) {
                $this->update_ow_topic_last_post_is($ow_topic_id, $ow_post_id);
            }
        }
        // Update state
        $this->migration_persistence->setLastPostId(end($posts)["id_msg"]);
        $this->remaining_count -= count($posts);
    }

    private function getPostsForTopic($smf_topic_id) {
        $last_post_id = $this->migration_persistence->getState()["last_post_id"];
        return $this->smf_db->query("SELECT id_msg, id_member, body, poster_time
          FROM smf_messages
          WHERE id_topic = %i AND id_msg > %i ORDER BY id_msg ASC LIMIT %i;",
            $smf_topic_id, $last_post_id, $this->remaining_count);
    }

    private function addTopicToOw($smf_topic_data, $ow_group_id) {
        try {
            $smf_topic_id = $smf_topic_data["id_topic"];
            $title = utf8_encode($smf_topic_data['subject']);
            $ow_topic_dto = new FORUM_BOL_Topic();
            $ow_topic_dto->groupId = $ow_group_id;
            $ow_topic_dto->lastPostId = 0;
            $ow_topic_dto->sticky = $smf_topic_data['is_sticky'];
            $ow_topic_dto->title = $title;
            $ow_topic_dto->userId = $this->migration_persistence->getOwUserId($smf_topic_data["id_member_started"]);
            $ow_topic_dto->viewCount = $smf_topic_data['num_views'];
            $ow_topic_dto->locked = $smf_topic_data['locked'];
            $this->ow_forum_service->saveOrUpdateTopic($ow_topic_dto);
            $this->migration_persistence->addTopicEntry($smf_topic_id, $ow_topic_dto->getId());
            echo("Topic \"" . $smf_topic_id ."\" migrated successfully! <br/>");
            return $ow_topic_dto->getId();
        } catch (Exception $e) {
            $this->migration_persistence->reportProblem($smf_topic_id, "topic", $e->getMessage());
            echo 'Error while migrating topic : ' . $e->getMessage() . "  ID: " . $smf_topic_id . " <br/>";
        }
    }

    private function addMessageToOw($smf_post_data, $ow_topic_id) {
        $smf_post_id =  $smf_post_data['id_msg'];
        try {
            $ow_post_dto = new FORUM_BOL_Post();
            $ow_post_dto->userId = $this->migration_persistence->getOwUserId($smf_post_data['id_member']);
            $ow_post_dto->createStamp = $smf_post_data['poster_time'];
            $ow_post_dto->topicId = $ow_topic_id;
            $ow_post_dto->text = ContentTranslator::translate($smf_post_data['body']);
            $this->ow_forum_service->saveOrUpdatePost($ow_post_dto);
            $this->migration_persistence->addPostEntry($smf_post_id, $ow_post_dto->getId());
            echo("    Post \"" . $smf_post_id ."\" migrated successfully! <br/>");
            return $ow_post_dto->getId();
        } catch (Exception $e) {
            $this->migration_persistence->reportProblem($smf_post_id, "post", $e->getMessage());
            echo '    Error while migrating post : ' . $e->getMessage() . "  ID: " . $smf_post_id . " <br/>";
        }
    }

    private function update_ow_topic_last_post_is($topic_id, $last_post_id) {
        $ow_topic_dao = FORUM_BOL_TopicDao::getInstance();
        $ow_topic_dto = $ow_topic_dao->findById($topic_id);
        $ow_topic_dto->lastPostId = $last_post_id;
        $this->ow_forum_service->saveOrUpdateTopic($ow_topic_dto);
    }

//    private function getNextOwTopicId() {
//        $status = $this->ow_db->queryFirstRow("SHOW TABLE STATUS LIKE 'ow_forum_topic'");
//        return $status["Auto_increment"];
//    }
//
//    private function getNextOwPostId() {
//        $status = $this->ow_db->queryFirstRow("SHOW TABLE STATUS LIKE 'ow_forum_post'");
//        return $status["Auto_increment"];
//    }
//
//    private function extractPostById(&$posts, $id) {
//        foreach($posts as $i => $post) {
//            if($post["id_msg"] == $id) {
//                unset($posts[$i]);
//                break;
//            }
//        }
//        return $post;
//    }
} 