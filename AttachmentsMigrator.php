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
        $attachment_service = BOL_AttachmentService::getInstance();
        $attachment_dao = BOL_AttachmentDao::getInstance();;

        $attachDto = new BOL_Attachment();
        $attachDto->setUserId(OW::getUser()->getId());
        $attachDto->setAddStamp(time());
        $attachDto->setStatus(0);
        $attachDto->setSize(floor($smf_attachment['size'] / 1024));
        $attachDto->setOrigFileName(htmlspecialchars($smf_attachment['filename']));
        $attachDto->setFileName(uniqid() . '_' . UTIL_File::sanitizeName($attachDto->getOrigFileName()));
        $attachDto->setPluginKey("forum");

        $attachment_dao->save($attachDto);
        $uploadPath = $attachment_service->getAttachmentsDir() . $attachDto->getFileName();

        if ( in_array(UTIL_File::getExtension($smf_file_path), array('jpg', 'jpeg', 'gif', 'png')) )
        {
            $image = new UTIL_Image($smf_file_path);

            if ( empty($dimensions) )
            {
                $dimensions = array('width' => 1000, 'height' => 1000);
            }

            $image->resizeImage($dimensions['width'], $dimensions['height'])->orientateImage()->saveImage($uploadPath);
            $image->destroy();
        }
        else
        {
            OW::getStorage()->copyFile($smf_file_path, $uploadPath);
        }

        $this->addOwPostAttachment($attachDto, $smf_attachment['id_msg']);
    }

    private function getSmfFilePath($smf_attachment) {
        $folder_index = $smf_attachment['id_folder'] - 1;
        $folders = unserialize(SMF_ATTACHMENT_FOLDERS);
        //TODO: the real system probably uses hash and not file name
        return $folders[$folder_index] . DS . $smf_attachment['filename'];
    }

    private function addOwPostAttachment($ow_attachment, $smf_post_id) {
        $dao = FORUM_BOL_PostAttachmentDao::getInstance();
        $ow_post_id = $this->migration_persistence->getOwPostId($smf_post_id);

        $attachmentDto = new FORUM_BOL_PostAttachment();
        $attachmentDto->postId = $ow_post_id;
        $attachmentDto->fileName = $ow_attachment->getOrigFileName();
        $attachmentDto->fileNameClean = $ow_attachment->getFileName();
        $attachmentDto->fileSize = $ow_attachment->getSize() * 1024;
        $attachmentDto->hash = uniqid();

        $dao->save($attachmentDto);
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