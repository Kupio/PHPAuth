<?php

namespace Kupio\PHPAuth;

/*
* Auth class
* Works with PHP 5.3.7 and above.
*/

class Auth
{
    private $dbh;
    private $auth_const_prefix;
    private $email_as_username;
    private $email_generator;

    /*
    * Initiates database connection
    */

    public function __construct($auth_const_prefix, \PDO $dbh)
    {
        $this->dbh = $dbh;
        $this->auth_const_prefix = $auth_const_prefix;

        $this->email_as_username = ($this->configVal("EMAIL_USERNAME") == '1');
        $this->allow_inactive = ($this->configVal("ALLOW_INACTIVE") == '1');
        $this->allow_unlimited_attempts = ($this->configVal("ALLOW_UNLIMITED_ATTEMPTS") == '1');
        $this->send_emails = ($this->configVal("SEND_EMAILS") == '1');

        if (version_compare(phpversion(), '5.5.0', '<')) {
            require_once("files/password.php");
        }
    }

    public function configVal($val)
    {
        return constant($this->auth_const_prefix.$val);
    }

    /*
    * Logs a user in
    * @param string $username
    * @param string $password
    * @param bool $remember
    * @param bool $logoutOtherSessions
    * @return array $return
    */

    public function login($username, $password, $remember = 0, $logoutOtherSessions = 1)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";

            return $return;
        }

        if ($this->email_as_username) {
            $validateUsername = $this->validateEmail($username);
        } else {
            $validateUsername = $this->validateUsername($username);
        }
        $validatePassword = $this->validatePassword($password);

        if ($validateUsername['error'] == 1) {
            $this->addAttempt();

            $return['message'] = "username_password_invalid";
            return $return;
        } elseif($validatePassword['error'] == 1) {
            $this->addAttempt();

            $return['message'] = "username_password_invalid";
            return $return;
        } elseif($remember != 0 && $remember != 1) {
            $this->addAttempt();

            $return['message'] = "remember_me_invalid";
            return $return;
        }

        $uid = $this->getUID(strtolower($username));

        if(!$uid) {
            $this->addAttempt();

            $return['message'] = "username_password_incorrect";
            return $return;
        }

        $user = $this->getUser($uid);

        if (!password_verify($password, $user['password'])) {
            $this->addAttempt();

            $return['message'] = "username_password_incorrect";
            return $return;
        }

        if (!$this->allow_inactive) {
            if ($user['isactive'] != 1) {
                $this->addAttempt();

                $return['message'] = "account_inactive";
                return $return;
            }
        }

        $sessiondata = $this->addSession($user['uid'], $remember, $logoutOtherSessions);

        if($sessiondata == false) {
            $return['message'] = "system_error";
            return $return;
        }

        $return['error'] = 0;
        $return['message'] = "logged_in";
        $return['uid'] = $uid;

        $return['hash'] = $sessiondata['hash'];
        $return['expire'] = $sessiondata['expiretime'];

        return $return;
    }

    public function getRoles($uid)
    {
        $return['error'] = 1;

        $query = $this->dbh->prepare('SELECT role FROM '.$this->configVal("TABLE_USER_ROLES").' LEFT JOIN '.$this->configVal("TABLE_ROLES").' ON role_id='.$this->configVal("TABLE_ROLES").'.id WHERE user_id=?');
        $query->execute(array($uid));

        $result = $query->fetchAll();

        $func = function($value) {
            return $value['role'];
        };

        return array_map($func, $result);
    }

    public function setRoles($uid, $roles)
    {
        $return['error'] = 1;
        foreach ($roles as $role) {
            $query = $this->dbh->prepare('SELECT COUNT(*) FROM '.$this->configVal("TABLE_ROLES").' WHERE role=?');
            $query->execute(array($role));

            if(!$query->fetchColumn()) {
                $return['message'] = "bad_role";
                return $return;
            }
        }

        try {

            $this->dbh->beginTransaction();

            $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_USER_ROLES").' WHERE user_id = ?;');
            $query->execute(array($uid));

            foreach ($roles as $role) {
                $query = $this->dbh->prepare('INSERT INTO '.$this->configVal("TABLE_USER_ROLES").' (user_id, role_id) SELECT ?, id FROM '.$this->configVal("TABLE_ROLES").' WHERE role=?;');
                $query->execute(array($uid, $role));
            }

            $this->dbh->commit();

        } catch (Exception $e) {

            $this->dbh->rollBack();

            $return['message'] = "no_role_set";
            return $return;
        }

        $return['error'] = 0;
        $return['message'] = "roles_set";

        return $return;
    }

    public function usersByRole($role)
    {
        $return['error'] = 1;

        $query = $this->dbh->prepare('SELECT id FROM '.$this->configVal("TABLE_ROLES").' WHERE role=?');
        $query->execute(array($role));

        $roleid = $query->fetchColumn();

        $query = $this->dbh->prepare('SELECT user_id FROM '.$this->configVal("TABLE_USER_ROLES").' left join '.$this->configVal("TABLE_USERS").' on '.$this->configVal("TABLE_USERS").'.id = user_id  WHERE role_id=? AND isactive=1');
        $query->execute(array($roleid));

        $result = $query->fetchAll();

        $func = function($value) {
            return $value['user_id'];
        };

        return array_map($func, $result);
    }

    /*
    * Creates a new user, adds them to database
    * @param string $email
    * @param string $username
    * @param string $password
    * @param string $repeatpassword
    * @return array $return
    */

    public function register($email, $username, $password, $repeatpassword)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validateEmail = $this->validateEmail($email);
        if ($this->email_as_username) {
            if ($username != $email) {
                $return['message'] = 'username_email_mismatch';
                return $return;
            } else {
                $validateUsername['error'] = 0;
            }
        } else {
            $validateUsername = $this->validateUsername($username);
        }
        $validatePassword = $this->validatePassword($password);

        if ($validateEmail['error'] == 1) {
            $return['message'] = $validateEmail['message'];
            return $return;
        } elseif ($validateUsername['error'] == 1) {
            $return['message'] = $validateUsername['message'];
            return $return;
        } elseif ($validatePassword['error'] == 1) {
            $return['message'] = $validatePassword['message'];
            return $return;
        } elseif($password !== $repeatpassword) {
            $return['message'] = "password_nomatch";
            return $return;
        }

        if ($this->isEmailTaken($email)) {
            $this->addAttempt();

            $return['message'] = "email_taken";
            return $return;
        }

        if ($this->isUsernameTaken($username)) {
            $this->addAttempt();

            $return['message'] = "username_taken";
            return $return;
        }

        $addUser = $this->addUser($email, $username, $password);

        if($addUser['error'] != 0) {
            $return['message'] = $addUser['message'];
            return $return;
        }

        if (!$this->send_emails) {
            $return['email_type'] = $addUser['email_type'];
            $return['email_url'] = $addUser['email_url'];
        }

        $return['error'] = 0;
        $return['message'] = "register_success";

        return $return;
    }

    /*
    * Activates a user's account
    * @param string $key
    * @return array $return
    */

    public function activate($key)
    {
        $return['error'] = 1;

        if($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        if(strlen($key) !== 20) {
            $this->addAttempt();

            $return['message'] = "key_invalid";
            return $return;
        }

        $getRequest = $this->getRequest($key, "activation");

        if($getRequest['error'] == 1) {
            $return['message'] = $getRequest['message'];
            return $return;
        }

        if($this->getUser($getRequest['uid'])['isactive'] == 1) {
            $this->addAttempt();
            $this->deleteRequest($getRequest['id']);

            $return['message'] = "system_error";
            return $return;
        }

        $query = $this->dbh->prepare('UPDATE '.$this->configVal("TABLE_USERS").' SET isactive = ? WHERE id = ?');
        $query->execute(array(1, $getRequest['uid']));

        $this->deleteRequest($getRequest['id']);

        $return['error'] = 0;
        $return['message'] = "account_activated";

        return $return;
    }

    /* For activation from admin and invites, ignoring activation keys */
    public function forceActivate($uid) {
        $query = $this->dbh->prepare('UPDATE '.$this->configVal("TABLE_USERS").' SET isactive = ? WHERE id = ?');
        $query->execute(array(1, $uid));
    }

    /*
    * Creates a reset key for an email address and sends email
    * @param string $email
    * @return array $return
    */

    public function requestReset($email)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validateEmail = $this->validateEmail($email);

        if ($validateEmail['error'] == 1) {
            $return['message'] = "email_invalid";
            return $return;
        }

        $query = $this->dbh->prepare('SELECT id FROM '.$this->configVal("TABLE_USERS").' WHERE email = ?');
        $query->execute(array($email));

        if ($query->rowCount() == 0) {
            $this->addAttempt();

            $return['message'] = "email_incorrect";
            return $return;
        }

        $addRequest = $this->addRequest($query->fetch(\PDO::FETCH_ASSOC)['id'], $email, "reset");
        if ($addRequest['error'] == 1) {
            $this->addAttempt();

            $return['message'] = $addRequest['message'];
            return $return;
        }

        $return['error'] = 0;
        $return['message'] = "reset_requested";
        if (!$this->send_emails) {
            $return['email_type'] = $addRequest['email_type'];
            $return['email_url'] = $addRequest['email_url'];
        }

        return $return;
    }

    /*
    * Logs out the session, identified by hash
    * @param string $hash
    * @return boolean
    */

    public function logout($hash)
    {
        if (strlen($hash) != 40) {
            return false;
        }

        return $this->deleteSession($hash);
    }

    /*
    * Hashes provided string with Bcrypt
    * @param string $string
    * @param string $salt
    * @return string $hash
    */

    public function getHash($string, $salt)
    {
        return password_hash($string, PASSWORD_BCRYPT, ['cost' => $this->configVal("BCRYPT_COST")]);
    }

    /*
    * Gets UID for a given username and returns an array
    * @param string $username
    * @return array $uid
    */

    public function getUID($username)
    {
        $query = $this->dbh->prepare('SELECT id FROM '.$this->configVal("TABLE_USERS").' WHERE username = ?');
        $query->execute(array($username));

        if($query->rowCount() == 0) {
            return false;
        }

        return $query->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    /*
    * Creates a session for a specified user id
    * @param int $uid
    * @param boolean $remember
    * @return array $data
    */

    private function addSession($uid, $remember, $logoutOtherSessions)
    {
        $ip = $this->getIp();
        $user = $this->getUser($uid);

        if(!$user) {
            return false;
        }

        $data['hash'] = sha1($user['salt'] . microtime());
        $agent = $_SERVER['HTTP_USER_AGENT'];

        if ($logoutOtherSessions) {
            $this->deleteExistingSessions($uid);
        } else {
            $this->deleteExpiredSessions($uid);
        }

        if($remember == true) {
            $data['expire'] = date("Y-m-d H:i:s", strtotime($this->configVal("COOKIE_REMEMBER")));
            $data['expiretime'] = strtotime($data['expire']);
        } else {
            $data['expire'] = date("Y-m-d H:i:s", strtotime($this->configVal("COOKIE_REMEMBER")));
            $data['expiretime'] = 0;
        }

        $data['cookie_crc'] = sha1($data['hash'] . $this->configVal("SITE_KEY"));

        $query = $this->dbh->prepare('INSERT INTO '.$this->configVal("TABLE_SESSIONS").' (uid, hash, expiredate, ip, agent, cookie_crc) VALUES (?, ?, ?, ?, ?, ?)');

        if(!$query->execute(array($uid, $data['hash'], $data['expire'], $ip, $agent, $data['cookie_crc']))) {
            return false;
        }

        $data['expire'] = strtotime($data['expire']);
        return $data;
    }

    /*
    * Removes all existing sessions for a given UID
    * @param int $uid
    * @return boolean
    */

    private function deleteExistingSessions($uid)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_SESSIONS").' WHERE uid = ?');

        return $query->execute(array($uid));
    }

    /*
    * Removes all existing sessions for a given UID
    * @param int $uid
    * @return boolean
    */

    private function deleteSessionById($sid)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_SESSIONS").' WHERE id = ?');

        return $query->execute(array($sid));
    }

    /*
    * Removes all expired sessions for a given UID
    * @param int $uid
    * @return boolean
    */

    private function deleteExpiredSessions($uid)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_SESSIONS").' WHERE uid = ? AND expiredate < CURRENT_TIMESTAMP');

        return $query->execute(array($uid));
    }

    /*
    * Removes a session based on hash
    * @param string $hash
    * @return boolean
    */

    private function deleteSession($hash)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_SESSIONS").' WHERE hash = ?');

        return $query->execute(array($hash));
    }

    /*
    * Returns UID based on session hash
    * @param string $hash
    * @return string $uid
    */

    public function getSessionUID($hash)
    {
        $query = $this->dbh->prepare('SELECT uid FROM '.$this->configVal("TABLE_SESSIONS").' WHERE hash = ?');
        $query->execute(array($hash));

        if ($query->rowCount() == 0) {
            return false;
        }

        return $query->fetch(\PDO::FETCH_ASSOC)['uid'];
    }

    public function isPasswordChangeRequired($uid)
    {
        if ($this->getUser($uid)['forcechange'] == 1) {
            return true;
        }

        return false;
    }

    /*
    * Function to check if a session is valid
    * @param string $hash
    * @return boolean
    */

    public function checkSession($hash)
    {
        $ip = $this->getIp();

        if ($this->isBlocked()) {
            return false;
        }

        if (strlen($hash) != 40) {
            return false;
        }

        $query = $this->dbh->prepare('SELECT id, uid, expiredate, ip, agent, cookie_crc FROM '.$this->configVal("TABLE_SESSIONS").' WHERE hash = ?');
        $query->execute(array($hash));

        if ($query->rowCount() == 0) {
            return false;
        }

        $row = $query->fetch(\PDO::FETCH_ASSOC);

        $sid = $row['id'];
        $uid = $row['uid'];
        $expiredate = strtotime($row['expiredate']);
        $currentdate = strtotime(date("Y-m-d H:i:s"));
        $db_ip = $row['ip'];
        $db_agent = $row['agent'];
        $db_cookie = $row['cookie_crc'];

        if ($currentdate > $expiredate) {
            $this->deleteExpiredSessions($uid);

            return false;
        }

        if ($ip != $db_ip) {
            if ($_SERVER['HTTP_USER_AGENT'] != $db_agent) {
                $this->deleteSessionById($sid);

                return false;
            }

            return $this->updateSessionIp($sid, $ip);
        }

        if ($db_cookie == sha1($hash . $this->configVal("SITE_KEY"))) {
            return true;
        }

        return false;
    }

    /*
    * Updates the IP of a session (used if IP has changed, but agent has remained unchanged)
    * @param int $sid
    * @param string $ip
    * @return boolean
    */

    private function updateSessionIp($sid, $ip)
    {
        $query = $this->dbh->prepare('UPDATE '.$this->configVal("TABLE_SESSIONS").' SET ip = ? WHERE id = ?');
        return $query->execute(array($ip, $sid));
    }

    /*
    * Checks if an email is already in use
    * @param string $email
    * @return boolean
    */

    public function isEmailTaken($email)
    {
        $query = $this->dbh->prepare('SELECT * FROM '.$this->configVal("TABLE_USERS").' WHERE email = ?');
        $query->execute(array($email));

        if ($query->rowCount() == 0) {
            return false;
        }

        return true;
    }

    /*
    * Checks if a username is already in use
    * @param string $username
    * @return boolean
    */

    private function isUsernameTaken($username)
    {
        if($this->getUID($username)) {
            return true;
        }

        return false;
    }

    /*
    * Adds a new user to database
    * @param string $email
    * @param string $username
    * @param string $password
    * @return int $uid
    */

    private function addUser($email, $username, $password)
    {
        $return['error'] = 1;

        $query = $this->dbh->prepare('INSERT INTO '.$this->configVal("TABLE_USERS").' VALUES ()');

        if(!$query->execute()) {
            $return['message'] = "system_error";
            return $return;
        }

        $uid = $this->dbh->lastInsertId();
        $email = htmlentities($email);

        $addRequest = $this->addRequest($uid, $email, "activation");

        if($addRequest['error'] == 1) {
            $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_USERS").' WHERE id = ?');
            $query->execute(array($uid));

            $return['message'] = $addRequest['message'];
            return $return;
        }

        if (!$this->send_emails) {
            $return['email_type'] = $addRequest['email_type'];
            $return['email_url'] = $addRequest['email_url'];
        }

        $true = true; /* Because PHP is weird. Must pass variable by reference. */
        $salt = substr(strtr(base64_encode(openssl_random_pseudo_bytes(22, $true)), '+', '.'), 0, 22);
        /* Salt is ignored. As of PHP 7.0, salt is part of the hash, but we store salt anyway because we
         * use it in other things. */

        $username = htmlentities(strtolower($username));
        $password = $this->getHash($password, $salt);

        $query = $this->dbh->prepare('UPDATE '.$this->configVal("TABLE_USERS").' SET username = ?, password = ?, email = ?, salt = ? WHERE id = ?');

        if(!$query->execute(array($username, $password, $email, $salt, $uid))) {
            $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_USERS").' WHERE id = ?');
            $query->execute(array($uid));

            $return['message'] = "system_error";
            return $return;
        }

        $return['error'] = 0;
        return $return;
    }

    /*
    * Gets user data for a given UID and returns an array
    * @param int $uid
    * @return array $data
    */

    public function getUser($uid)
    {
        $query = $this->dbh->prepare('SELECT username, password, email, salt, isactive, forcechange FROM '.$this->configVal("TABLE_USERS").' WHERE id = ?');
        $query->execute(array($uid));

        if ($query->rowCount() == 0) {
            return false;
        }

        $data = $query->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return false;
        }

        $data['uid'] = $uid;
        return $data;
    }

    public function getIdentity($uid)
    {
        $query = $this->dbh->prepare('SELECT username, email, isactive FROM '.$this->configVal("TABLE_USERS").' WHERE id = ?');
        $query->execute(array($uid));

        if ($query->rowCount() == 0) {
            return null;
        }

        $data = $query->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        $data['uid'] = $uid;
        return $data;
    }

    public function forcePasswordChange($uid)
    {
        $query = $this->dbh->prepare('UPDATE '.$this->configVal('TABLE_USERS').' SET forcechange=1 WHERE id=?');
        return $query->execute(array($uid));
    }

    /*
    * Allows a user to delete their account
    * @param int $uid
    * @param string $password
    * @return array $return
    */

    public function deleteUser($uid, $password)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validatePassword = $this->validatePassword($password);

        if($validatePassword['error'] == 1) {
            $this->addAttempt();

            $return['message'] = $validatePassword['message'];
            return $return;
        }

        $getUser = $this->getUser($uid);

        if(!password_verify($password, $getUser['password'])) {
            $this->addAttempt();

            $return['message'] = "password_incorrect";
            return $return;
        }

        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_USERS").' WHERE id = ?');

        if(!$query->execute(array($uid))) {
            $return['message'] = "system_error";
            return $return;
        }

        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_SESSIONS").' WHERE uid = ?');

        if(!$query->execute(array($uid))) {
            $return['message'] = "system_error";
            return $return;
        }

        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_REQUESTS").' WHERE uid = ?');

        if(!$query->execute(array($uid))) {
            $return['message'] = "system_error";
            return $return;
        }

        $return['error'] = 0;
        $return['message'] = "account_deleted";

        return $return;
    }

    /*
    * Creates an activation entry and sends email to user
    * @param int $uid
    * @param string $email
    * @return boolean
    */

    private function addRequest($uid, $email, $type)
    {
        $return['error'] = 1;

        if($type != "activation" && $type != "reset") {
            $return['message'] = "system_error";
            return $return;
        }

        $query = $this->dbh->prepare('SELECT id, expire FROM '.$this->configVal("TABLE_REQUESTS").' WHERE uid = ? AND type = ?');
        $query->execute(array($uid, $type));

        if($query->rowCount() > 0) {
            $row = $query->fetch(\PDO::FETCH_ASSOC);

            $expiredate = strtotime($row['expire']);
            $currentdate = strtotime(date("Y-m-d H:i:s"));

            if ($currentdate < $expiredate) {
                $return['message'] = "request_exists";
                return $return;
            }

            $this->deleteRequest($row['id']);
        }

        if($type == "activation" && $this->getUser($uid)['isactive'] == 1) {
            $return['message'] = "already_activated";
            return $return;
        }

        $key = $this->getRandomKey(20);
        $expire = date("Y-m-d H:i:s", strtotime("+1 day"));

        $query = $this->dbh->prepare('INSERT INTO '.$this->configVal("TABLE_REQUESTS").' (uid, rkey, expire, type) VALUES (?, ?, ?, ?)');

        if(!$query->execute(array($uid, $key, $expire, $type))) {
            $return['message'] = "system_error";
            return $return;
        }

        if ($this->send_emails) {
            if($type == "activation") {
                $subject = $this->configVal('SITE_NAME')." - Account Activation";
                $message = "Account activation required : <strong><a href=\"".$this->configVal("SITE_URL")."activate/{$key}\">Activate my account</a></strong>";
            } else {
                $subject = $this->configVal('SITE_NAME')." - Password reset request";
                $message = "Password reset request : <strong><a href=\"".$this->configVal("SITE_URL")."reset/{$key}\">Reset my password</a></strong>";
            }

            $headers  = 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
            $headers .= "From: ".$this->configVal('SITE_EMAIL')."\r\n";

            if(!mail($email, $subject, $message, $headers)) {
                $return['message'] = "system_error";
                return $return;
            }

        } else {
            $return['email_type'] = $type;
            if($type == "activation") {
                $return['email_url'] = $this->configVal("SITE_URL")."activate/{$key}";
            } else {
                $return['email_url'] = $this->configVal("SITE_URL")."reset/{$key}";
            }
        }

        $return['error'] = 0;
        return $return;
    }

    /*
    * Returns request data if key is valid
    * @param string $key
    * @param string $type
    * @return array $return
    */

    private function getRequest($key, $type)
    {
        $return['error'] = 1;

        $query = $this->dbh->prepare('SELECT id, uid, expire FROM '.$this->configVal("TABLE_REQUESTS").' WHERE rkey = ? AND type = ?');
        $query->execute(array($key, $type));

        if ($query->rowCount() === 0) {
            $this->addAttempt();

            $return['message'] = "key_incorrect";
            return $return;
        }

        $row = $query->fetch();

        $expiredate = strtotime($row['expire']);
        $currentdate = strtotime(date("Y-m-d H:i:s"));

        if ($currentdate > $expiredate) {
            $this->addAttempt();

            $this->deleteRequest($row['id']);

            $return['message'] = "key_expired";
            return $return;
        }

        $return['error'] = 0;
        $return['id'] = $row['id'];
        $return['uid'] = $row['uid'];

        return $return;
    }

    /*
    * Deletes request from database
    * @param int $id
    * @return boolean
    */

    private function deleteRequest($id)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal("TABLE_REQUESTS").' WHERE id = ?');
        return $query->execute(array($id));
    }

    /*
    * Verifies that a username is valid
    * @param string $username
    * @return array $return
    */

    public function validateUsername($username) {
        $return['error'] = 1;

        if (strlen($username) < 3) {
            $return['message'] = "username_short";
            return $return;
        } elseif (strlen($username) > 30) {
            $return['message'] = "username_long";
            return $return;
        } elseif (!ctype_alnum($username)) {
            $return['message'] = "username_invalid";
            return $return;
        }

        $bannedUsernames = file(__DIR__ . "/files/banned-usernames.txt", FILE_IGNORE_NEW_LINES);

        if(0 < count(array_intersect(array(strtolower($username)), $bannedUsernames))) {
            $return['message'] = "username_banned";
            return $return;
        }

        $return['error'] = 0;
        return $return;
    }

    /*
    * Verifies that a password is valid and respects security requirements
    * @param string $password
    * @return array $return
    */

    private function validatePassword($password) {
        $return['error'] = 1;

        if (strlen($password) < 6) {
            $return['message'] = "password_short";
            return $return;
        } elseif (strlen($password) > 72) {
            $return['message'] = "password_long";
            return $return;
        } elseif (!preg_match('@[A-Z]@', $password) || !preg_match('@[a-z]@', $password) || !preg_match('@[0-9]@', $password)) {
            $return['message'] = "password_invalid";
            return $return;
        }

        $return['error'] = 0;
        return $return;
    }

    /*
    * Verifies that an email is valid
    * @param string $email
    * @return array $return
    */

    private function validateEmail($email) {
        $return['error'] = 1;

        if (strlen($email) < 5) {
            $return['message'] = "email_short";
            return $return;
        } elseif (strlen($email) > 100) {
            $return['message'] = "email_long";
            return $return;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $return['message'] = "email_invalid";
            return $return;
        }

        $bannedEmails = file(__DIR__ . "/files/banned-emails.txt", FILE_IGNORE_NEW_LINES);

        if(0 < count(array_intersect(array(strtolower($email)), $bannedEmails))) {
            $return['message'] = "email_banned";
            return $return;
        }

        $return['error'] = 0;
        return $return;
    }


    /*
    * Allows a user to reset there password after requesting a reset key.
    * @param string $key
    * @param string $password
    * @param string $repeatpassword
    * @return array $return
    */

    public function resetPass($key, $password, $repeatpassword)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        if(strlen($key) != 20) {
            $return['message'] = "key_invalid";
            return $return;
        }

        $validatePassword = $this->validatePassword($password);

        if($validatePassword['error'] == 1) {
            $return['message'] = $validatePassword['message'];
            return $return;
        }

        if($password !== $repeatpassword) {
            // Passwords don't match
            $return['message'] = "newpassword_nomatch";
            return $return;
        }

        $data = $this->getRequest($key, "reset");

        if($data['error'] == 1) {
            $return['message'] = $data['message'];
            return $return;
        }


        $uid = $data['uid'];

        $user = $this->getUser($uid);

        if(!$user) {
            $this->addAttempt();
            $this->deleteRequest(intval($data['id']));

            $return['message'] = "system_error";
            return $return;
        }

        if(password_verify($password, $user['password'])) {
            $this->deleteRequest(intval($data['id']));
            $return['error'] = 0;
            $return['message'] = "password_reset";
            return $return;
        }

        $password = $this->getHash($password, $user['salt']);

        $query = $this->dbh->prepare('UPDATE '.$this->configVal("TABLE_USERS").' SET password = ?, forcechange = 0 WHERE id = ?');
        $query->execute(array($password, $uid));

        if ($query->rowCount() == 0) {
            $return['message'] = "system_error";
            return $return;
        }

        $this->deleteRequest(intval($data['id']));

        $return['error'] = 0;
        $return['message'] = "password_reset";

        return $return;
    }

    /*
    * Recreates activation email for a given email and sends
    * @param string $email
    * @return array $return
    */

    public function resendActivation($email)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validateEmail = $this->validateEmail($email);

        if($validateEmail['error'] == 1) {
            $return['message'] = $validateEmail['message'];
            return $return;
        }

        $query = $this->dbh->prepare('SELECT id FROM '.$this->configVal("TABLE_USERS").' WHERE email = ?');
        $query->execute(array($email));

        if($query->rowCount() == 0) {
            $this->addAttempt();

            $return['message'] = "email_incorrect";
            return $return;
        }

        $row = $query->fetch(\PDO::FETCH_ASSOC);

        if ($this->getUser($row['id'])['isactive'] == 1) {
            $this->addAttempt();

            $return['message'] = "already_activated";
            return $return;
        }

        $addRequest = $this->addRequest($row['id'], $email, "activation");

        if ($addRequest['error'] == 1) {
            $this->addAttempt();

            $return['message'] = $addRequest['message'];
            return $return;
        }

        if (!$this->send_emails) {
            $return['email_type'] = $addRequest['email_type'];
            $return['email_url'] = $addRequest['email_url'];
        }

        $return['error'] = 0;
        $return['message'] = "activation_sent";
        return $return;
    }

    /*
    * Gets UID from Session hash
    * @param string $hash
    * @return int $uid
    */

    public function sessionUID($hash)
    {
        if (strlen($hash) != 40) {
            return false;
        }

        $query = $this->dbh->prepare('SELECT uid FROM '.$this->configVal("TABLE_SESSIONS").' WHERE hash = ?');
        $query->execute(array($hash));

        if($query->rowCount() == 0) {
            return false;
        }

        return $query->fetch(\PDO::FETCH_ASSOC)['uid'];
    }

    /*
    * Changes a user's password
    * @param int $uid
    * @param string $currpass
    * @param string $newpass
    * @return array $return
    */

    public function changePassword($uid, $currpass, $newpass, $repeatnewpass)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validatePassword = $this->validatePassword($currpass);

        if($validatePassword['error'] == 1) {
            $this->addAttempt();

            $return['message'] = $validatePassword['message'];
            return $return;
        }

        $validatePassword = $this->validatePassword($newpass);

        if($validatePassword['error'] == 1) {
            $return['message'] = $validatePassword['message'];
            return $return;
        } elseif($newpass !== $repeatnewpass) {
            $return['message'] = "newpassword_nomatch";
            return $return;
        }

        $user = $this->getUser($uid);

        if(!$user) {
            $this->addAttempt();

            $return['message'] = "system_error";
            return $return;
        }

        $newpass = $this->getHash($newpass, $user['salt']);

        if($currpass == $newpass) {
            $return['message'] = "newpassword_match";
            return $return;
        }

        if(!password_verify($currpass, $user['password'])) {
            $this->addAttempt();

            $return['message'] = "password_incorrect";
            return $return;
        }

        $query = $this->dbh->prepare('UPDATE '.$this->configVal('TABLE_USERS').' SET password = ?, forcechange = 0 WHERE id = ?');
        $query->execute(array($newpass, $uid));

        $return['error'] = 0;
        $return['message'] = "password_changed";
        return $return;
    }


    /*
    * Gets a user's email address by UID
    * @param int $uid
    * @return string $email
    */

    public function getEmail($uid)
    {
        $query = $this->dbh->prepare('SELECT email FROM '.$this->configVal('TABLE_USERS').' WHERE id = ?');
        $query->execute(array($uid));
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        return $row['email'];
    }

    /*
    * Changes a user's email
    * @param int $uid
    * @param string $currpass
    * @param string $newpass
    * @return array $return
    */

    public function changeEmail($uid, $email, $password)
    {
        $return['error'] = 1;

        if ($this->isBlocked()) {
            $return['message'] = "user_blocked";
            return $return;
        }

        $validateEmail = $this->validateEmail($email);

        if($validateEmail['error'] == 1)
        {
            $return['message'] = $validateEmail['message'];
            return $return;
        }

        $validatePassword = $this->validatePassword($password);

        if ($validatePassword['error'] == 1) {
            $return['message'] = "password_notvalid";
            return $return;
        }

        $user = $this->getUser($uid);

        if(!$user) {
            $this->addAttempt();

            $return['message'] = "system_error";
            return $return;
        }

        if(!password_verify($password, $user['password'])) {
            $this->addAttempt();

            $return['message'] = "password_incorrect";
            return $return;
        }

        if ($email == $user['email']) {
            $this->addAttempt();

            $return['message'] = "newemail_match";
            return $return;
        }

        $query = $this->dbh->prepare('UPDATE '.$this->configVal('TABLE_USERS').' SET email = ? WHERE id = ?');
        $query->execute(array($email, $uid));

        if ($query->rowCount() == 0) {
            $return['message'] = "system_error";
            return $return;
        }

        $return['error'] = 0;
        $return['message'] = "email_changed";
        return $return;
    }

    /*
    * Informs if a user is locked out
    * @return boolean
    */

    private function isBlocked()
    {
        $ip = $this->getIp();

        $query = $this->dbh->prepare('SELECT count, expiredate FROM '.$this->configVal('TABLE_ATTEMPTS').' WHERE ip = ?');
        $query->execute(array($ip));

        if($query->rowCount() == 0) {
            return false;
        }

        $row = $query->fetch(\PDO::FETCH_ASSOC);

        $expiredate = strtotime($row['expiredate']);
        $currentdate = strtotime(date("Y-m-d H:i:s"));

        if ($row['count'] == 5) {
            if ($currentdate < $expiredate) {
                /* Prefer this to a trivial reject at the start of the function because it means the housekeeping
                 * is still done and we can switch it on at any time with historical data. */
                return !$this->allow_unlimited_attempts;
            }

            $this->deleteAttempts($ip);
            return false;
        }

        if ($currentdate > $expiredate) {
            $this->deleteAttempts($ip);
        }

        return false;
    }


    /*
    * Adds an attempt to database
    * @return boolean
    */

    private function addAttempt()
    {
        $ip = $this->getIp();

        $query = $this->dbh->prepare('SELECT count FROM '.$this->configVal('TABLE_ATTEMPTS').' WHERE ip = ?');
        $query->execute(array($ip));

        $row = $query->fetch(\PDO::FETCH_ASSOC);

        $attempt_expiredate = date("Y-m-d H:i:s", strtotime("+30 minutes"));

        if (!$row) {
            $attempt_count = 1;

            $query = $this->dbh->prepare('INSERT INTO '.$this->configVal('TABLE_ATTEMPTS').' (ip, count, expiredate) VALUES (?, ?, ?)');
            return $query->execute(array($ip, $attempt_count, $attempt_expiredate));
        }

        $attempt_count = $row['count'] + 1;

        $query = $this->dbh->prepare('UPDATE '.$this->configVal('TABLE_ATTEMPTS').' SET count=?, expiredate=? WHERE ip=?');
        return $query->execute(array($attempt_count, $attempt_expiredate, $ip));
    }

    /*
    * Deletes all attempts for a given IP from database
    * @param string $ip
    * @return boolean
    */

    private function deleteAttempts($ip)
    {
        $query = $this->dbh->prepare('DELETE FROM '.$this->configVal('TABLE_ATTEMPTS').' WHERE ip = ?');
        return $query->execute(array($ip));
    }

    /*
    * Returns a random string of a specified length
    * @param int $length
    * @return string $key
    */

    public function getRandomKey($length = 20)
    {
        $chars = "A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6";
        $key = "";

        for ($i = 0; $i < $length; $i++) {
            $key .= $chars{mt_rand(0, strlen($chars) - 1)};
        }

        return $key;
    }

    /*
    * Returns IP address
    * @return string $ip
    */

    private function getIp()
    {
        return $_SERVER['REMOTE_ADDR'];
    }
}

?>
