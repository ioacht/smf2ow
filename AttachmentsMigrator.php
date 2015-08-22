<?php
require_once ("MigrationPersistence.php");

class AttachmentsMigrator {

    private static $classInstance;
    private $migration_persistence;
    private $smf_db;


    public static function getInstance()
    {
        if ( self::$classInstance === null )
        {
            self::$classInstance = new self();
        }

        return self::$classInstance;
    }

    public function migrate() {
        echo("Migrating Attachments ...<br/><br/>");
        $attachments = $this->getSmfAttachments();
        foreach($attachments as $attachment) {
            $attachment["filename"] = utf8_encode($attachment["filename"]);
            try{
                $this->addAttachmentToOW($attachment);
                echo("Attached file \"" . $attachment["filename"] . "\" migrated successfully!<br/>");
            } catch(Exception $e) {
                $this->migration_persistence->reportProblem($attachment['id_attach'], "attachment", $e->getMessage());
                echo 'Error while migrating attachment "' . $attachment["filename"] . '" : ' . $e->getMessage() . " <br/>";
            }
        }
        // Update state
        $last_attachment = end($attachments);
        $this->migration_persistence->setLastImportedAttachmentId($last_attachment['id_attach']);
        if(count($attachments) < BATCH_SIZE) {
            $this->migration_persistence->progressToNextStage();
        }
    }

    private function addAttachmentToOW($smf_attachment) {
        $smf_file_path = $this->getSmfFilePath($smf_attachment);
        $ow_post_id = $this->migration_persistence->getOwPostId($smf_attachment['id_msg']);
        $orig_filename = htmlspecialchars($smf_attachment['filename']);

        $attachmentService = FORUM_BOL_PostAttachmentService::getInstance();
        $attachmentDto = new FORUM_BOL_PostAttachment();

        $attachmentDto->postId = $ow_post_id;
        $attachmentDto->fileName = $orig_filename;
        $attachmentDto->fileNameClean = uniqid() . '_' . UTIL_File::sanitizeName($orig_filename);
        $attachmentDto->fileSize = $smf_attachment['size'];
        $attachmentDto->hash = uniqid();

        $attachmentService->addAttachment($attachmentDto, $smf_file_path);
    }

    private function getSmfFilePath($smf_attachment) {
        $folder_index = $smf_attachment['id_folder'] - 1;
        $folders = unserialize(SMF_ATTACHMENT_FOLDERS);
        return $folders[$folder_index] . DS . $smf_attachment[id_attach] . "_" . $smf_attachment[file_hash];
    }

    private function getSmfAttachments() {
        $bord_ids = join(', ', unserialize(SMF_FORUM_IDS));
        $last_attachment_id = $this->migration_persistence->getLastImportedAttachmentId();
        return $this->smf_db->query("SELECT smf_attachments.id_attach, smf_attachments.id_msg, smf_attachments.id_member,
                    smf_attachments.filename, smf_attachments.size, smf_attachments.file_hash, smf_attachments.id_folder
            FROM smf_attachments
            JOIN smf_messages ON smf_messages.id_msg = smf_attachments.id_msg
            JOIN smf_topics ON smf_topics.id_topic = smf_messages.id_topic
            WHERE smf_attachments.fileName NOT LIKE '%_thumb' AND smf_topics.id_board IN  (" . $bord_ids . ")
            AND smf_attachments.id_attach > %i  LIMIT %i;", $last_attachment_id, BATCH_SIZE);
    }


    private function __construct()
    {
        $this->migration_persistence = MigrationPersistence::getInstance();
        $this->smf_db = new MeekroDB(SMF_DB_HOST, SMF_DB_USER, SMF_DB_PASSWORD, SMF_DB_SCHEMA);
    }
} 