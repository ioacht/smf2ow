<?php
require_once ("MigrationPersistence.php");

class UsersMigrator {

    private static $classInstance;
    private $smf_db;
    private $ow_db;
    private $migration_persistence;
    private $roles_map;
    private $smf_roles;
    private $ow_roles;


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
        $user_service =  BOL_UserService::getInstance();
        $smf_users = $this->getSmfUsers();
        foreach($smf_users as $smf_user) {
            try {
                if($user_service->isExistEmail($smf_user['email_address'])) {
                    $user_dao = BOL_UserDao::getInstance();
                    $ow_user = $user_dao->findByUseEmail($smf_user["email_address"]);
                } else {
                    $smf_user_name = $smf_user['member_name'];
                    $name = $this->cleanSmfUsername($smf_user_name);
                    $ow_user = $this->createOxwallUser($name, "pass", $smf_user['email_address'], $smf_user["date_registered"]);
                    $ow_user_id = $ow_user->getId();
                    $this->setUserRole($ow_user_id, $smf_user['id_group']);
                    $this->setUserMetaData($ow_user_id, $smf_user);
                }
                $this->migration_persistence->addUserEntry($smf_user['id_member'], $ow_user->getId(), $smf_user_name);
                echo("User " . $smf_user['member_name'] ." migrated successfully! <br/>");
            } catch (Exception $e) {
                $user_string = sprintf("  [%s, %s, %d]", $smf_user['member_name'], $smf_user['email_address'],
                    $smf_user["date_registered"]);
                $this->migration_persistence->reportProblem($smf_user['id_member'], "user", $e->getMessage() . $user_string);
                echo 'Error while migrating user : ' . $e->getMessage() . $user_string . " <br/>";
            }
        }
        // Update state
        $this->migration_persistence->setLastUserId(end($smf_users)['id_member']);
        if(count($smf_users) < BATCH_SIZE) {
            $this->migration_persistence->progressToNextStage();
        }
    }

    private function __construct()
    {
        $this->smf_db = new MeekroDB(SMF_DB_HOST, SMF_DB_USER, SMF_DB_PASSWORD, SMF_DB_SCHEMA);
        $this->ow_db = new MeekroDB(OW_DB_HOST, OW_DB_USER, OW_DB_PASSWORD, OW_DB_NAME);
        $this->migration_persistence = MigrationPersistence::getInstance();
        $this->smf_roles = unserialize(SMF_USER_GROUPS_IDS);
        $this->ow_roles = unserialize(OW_USER_GROUPS_IDS);
    }

    private function getSmfUsers() {
        $bord_ids = join(', ', unserialize(SMF_FORUM_IDS));
        $last_user_id = $this->migration_persistence->getState()['last_user_id'];
        return $this->smf_db->query("SELECT DISTINCT smf_members.id_member, smf_members.member_name,
            smf_members.date_registered, smf_members.id_group, smf_members.email_address, smf_members.real_name
        FROM smf_members
        JOIN smf_messages ON smf_messages.id_member = smf_members.id_member
        JOIN smf_topics ON smf_topics.id_topic = smf_messages.id_topic
        WHERE smf_topics.id_board IN (" . $bord_ids . ")
        AND smf_members.id_member > %i LIMIT %i", $last_user_id, BATCH_SIZE);
    }

    private function createOxwallUser( $username, $password, $email, $joinStamp, $accountType = null, $emailVerify = true )
    {
        $user_service =  BOL_UserService::getInstance();
        if ( !UTIL_Validator::isEmailValid($email) )
        {
            throw new InvalidArgumentException('Invalid email!', $user_service::CREATE_USER_INVALID_EMAIL);
        }

        if ( !UTIL_Validator::isUserNameValid($username) )
        {
            throw new InvalidArgumentException('Invalid username!', $user_service::CREATE_USER_INVALID_USERNAME);
        }

        if ( !isset($password) || strlen($password) === 0 )
        {
            throw new InvalidArgumentException('Invalid password!', $user_service::CREATE_USER_INVALID_PASSWORD);
        }

        if ( $user_service->isExistUserName($username) )
        {
            throw new LogicException('Duplicate username!', $user_service::CREATE_USER_DUPLICATE_USERNAME);
        }

        if ( $user_service->isExistEmail($email) )
        {
            throw new LogicException('Duplicate email!', $user_service::CREATE_USER_DUPLICATE_EMAIL);
        }

        $userAccountType = $accountType;

        if ( $userAccountType === null )
        {
            $userAccountType = '';
            $accountTypes = BOL_QuestionService::getInstance()->findAllAccountTypes();

            if ( count($accountTypes) === 1 )
            {
                $userAccountType = $accountTypes[0]->name;
            }
        }

        $user = new BOL_User();

        $user->username = trim($username);
        $user->password = BOL_UserService::getInstance()->hashPassword($password);
        $user->email = trim($email);
        $user->joinStamp = $joinStamp;
        $user->activityStamp = time();
        $user->accountType = $userAccountType;
        $user->joinIp = 0;

        if ( $emailVerify === true )
        {
            $user->emailVerify = true;
        }

        $user_service->saveOrUpdate($user);

        return $user;
    }

    private function setUserMetaData($ow_user_id, $smf_user) {
        // This is kind of hacky, but oh wel...
        // (the questions mechanism seem like a bit of an overkill of that)
        $this->ow_db->insert(OW_DB_PREFIX.'base_question_data', array(
            "questionName" => "realname",
            "userId" => $ow_user_id,
            "textValue" => $smf_user['real_name']
        ));
    }

    private function setUserRole($ow_user_id, $smf_role) {
        $dao = BOL_AuthorizationUserRoleDao::getInstance();
        $user_role = new BOL_AuthorizationUserRole();
        $smf_role_index = array_search($smf_role, $this->smf_roles);
        if($smf_role_index !== FALSE) {
            $roleId = $this->ow_roles[$smf_role_index];
        } else {
            $roleId = $this->ow_roles[0];
        }
        $user_role->roleId = $roleId;
        $user_role->userId = $ow_user_id;
        $dao->save($user_role);
    }

    private function cleanSmfUsername($name) {
        return preg_replace("/[^\\w]/", "", $name);
    }
} 