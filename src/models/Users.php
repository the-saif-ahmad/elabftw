<?php
/**
 * \Elabftw\Elabftw\Users
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Elabftw;

use Exception;
use PDO;

/**
 * Users
 */
class Users
{
    /** @var Db $Db SQL Database */
    protected $Db;

    /** @var Auth $Auth instance of Auth */
    public $Auth;

    /** @var Config $Config instance of Config */
    public $Config;

    /** @var bool $needValidation flag to check if we need validation or not */
    public $needValidation = false;

    /** @var array $userData what you get when you read() */
    public $userData = array();

    /** @var int|null $userid our userid */
    public $userid;

    /**
     * Constructor
     *
     * @param int|null $userid
     * @param Auth|null $auth
     * @param Config|null $config
     */
    public function __construct(?int $userid = null, ?Auth $auth = null, ?Config $config = null)
    {
        $this->Db = Db::getConnection();
        if ($userid !== null) {
            $this->setId($userid);
        }

        $this->Auth = $auth;
        $this->Config = $config;
    }

    /**
     * Assign an id and populate userData
     *
     * @param int $userid
     */
    public function setId(int $userid): void
    {
        if (Tools::checkId($userid) === false) {
            throw new Exception('Bad userid');
        }
        $this->userid = $userid;
        $this->userData = $this->read($this->userid);
    }

    /**
     * Create a new user. If no password is provided, it's because we create it from SAML.
     *
     * @param string $email
     * @param int $team
     * @param string $firstname
     * @param string $lastname
     * @param string $password
     * @return bool
     */
    public function create(string $email, int $team, string $firstname = '', string $lastname = '', string $password = ''): bool
    {
        // check for duplicate of email
        if ($this->isDuplicateEmail($email)) {
            throw new Exception(_('Someone is already using that email address!'));
        }

        if ($password) {
            $this->Auth->checkPasswordLength($password);
        }

        $firstname = \filter_var($firstname, FILTER_SANITIZE_STRING);
        $lastname = \filter_var($lastname, FILTER_SANITIZE_STRING);

        // Create salt
        $salt = \hash("sha512", \bin2hex(\random_bytes(16)));
        // Create hash
        $passwordHash = \hash("sha512", $salt . $password);

        // Registration date is stored in epoch
        $registerDate = \time();

        // get the group for the new user
        $group = $this->getGroup($team);

        // will new user be validated?
        $validated = $this->getValidated($group);

        $sql = "INSERT INTO users (
            `email`,
            `password`,
            `firstname`,
            `lastname`,
            `team`,
            `usergroup`,
            `salt`,
            `register_date`,
            `validated`,
            `lang`
        ) VALUES (
            :email,
            :password,
            :firstname,
            :lastname,
            :team,
            :usergroup,
            :salt,
            :register_date,
            :validated,
            :lang);";
        $req = $this->Db->prepare($sql);

        $req->bindParam(':email', $email);
        $req->bindParam(':team', $team, PDO::PARAM_INT);
        $req->bindParam(':salt', $salt);
        $req->bindParam(':password', $passwordHash);
        $req->bindParam(':firstname', $firstname);
        $req->bindParam(':lastname', $lastname);
        $req->bindParam(':register_date', $registerDate);
        $req->bindParam(':validated', $validated);
        $req->bindParam(':usergroup', $group);
        $req->bindValue(':lang', $this->Config->configArr['lang']);

        if (!$req->execute()) {
            throw new Exception('Error inserting user in SQL!');
        }

        if ($validated == '0') {
            $Email = new Email($this->Config);
            $Email->alertAdmin($team);
            // set a flag to show correct message to user
            $this->needValidation = true;
        }

        return true;
    }

    /**
     * Get what will be the value of the validated column in users table
     *
     * @param int $group
     * @return int
     */
    private function getValidated(int $group): int
    {
        // validation is required for normal user
        if ($this->Config->configArr['admin_validate'] === '1' && $group === 4) {
            return 0; // so new user will need validation
        }
        return 1;
    }

    /**
     * Return the group int that will be assigned to a new user in a team
     * 1 = sysadmin if it's the first user ever
     * 2 = admin for first user in a team
     * 4 = normal user
     *
     * @param int $team
     * @return int
     */
    private function getGroup(int $team): int
    {
        if ($this->isFirstUser()) {
            return 1;
        } elseif ($this->isFirstUserInTeam($team)) {
            return 2;
        }
        return 4;
    }

    /**
     * Check we have not a duplicate email in DB
     *
     * @param string $email
     * @return bool true if there is a duplicate
     */
    public function isDuplicateEmail(string $email): bool
    {
        $sql = "SELECT email FROM users WHERE email = :email AND archived = 0";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':email', $email);
        $req->execute();

        return (bool) $req->rowCount();
    }

    /**
     * Do we have users in the DB ?
     *
     * @return bool
     */
    private function isFirstUser(): bool
    {
        $sql = "SELECT COUNT(*) AS usernb FROM users";
        $req = $this->Db->prepare($sql);
        $req->execute();
        $test = $req->fetch();

        return $test['usernb'] === '0';
    }

    /**
     * Are we the first user to register in a team ?
     *
     * @param int $team
     * @return bool
     */
    private function isFirstUserInTeam(int $team): bool
    {
        $sql = "SELECT COUNT(*) AS usernb FROM users WHERE team = :team";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $team, PDO::PARAM_INT);
        $req->execute();
        $test = $req->fetch();

        return $test['usernb'] === '0';
    }

    /**
     * Get info about a user
     *
     * @param int $userid
     * @return array
     */
    public function read(int $userid): array
    {
        $sql = "SELECT users.*, CONCAT(users.firstname, ' ', users.lastname) AS fullname,
            groups.can_lock, groups.is_admin, groups.is_sysadmin FROM users
            LEFT JOIN groups ON groups.group_id = users.usergroup
            WHERE users.userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userid, PDO::PARAM_INT);
        $req->execute();

        return $req->fetch();
    }

    /**
     * Select by email
     *
     * @param string $email
     * @return array
     */
    public function readFromEmail(string $email): array
    {
        $sql = "SELECT userid, CONCAT(firstname, ' ', lastname) AS fullname, team FROM users
            WHERE email = :email AND archived = 0";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':email', $email);
        $req->execute();

        return $req->fetch();
    }

    /**
     * Get a user from their API key
     *
     * @param string $apiKey
     */
    public function readFromApiKey(string $apiKey): void
    {
        $sql = "SELECT userid FROM users WHERE api_key = :key AND archived = 0";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':key', $apiKey);
        $req->execute();

        $userid = (int) $req->fetchColumn();

        if (empty($userid)) {
            throw new Exception('Invalid API key.');
        }

        $this->userData = $this->read($userid);
        $this->userid = $this->userData['userid'];
    }

    /**
     * Read all users from the team
     *
     * @param int|null $validated
     * @return array
     */
    public function readAllFromTeam(?int $validated = null): array
    {
        $valSql = '';
        if (is_int($validated)) {
            $valSql = " users.validated = :validated AND ";
        }
        $sql = "SELECT users.*, CONCAT (users.firstname, ' ', users.lastname) AS fullname,
            teams.team_name AS teamname
            FROM users
            LEFT JOIN teams ON (users.team = teams.team_id)
            WHERE " . $valSql . " users.team = :team";
        $req = $this->Db->prepare($sql);
        if (is_int($validated)) {
            $req->bindValue(':validated', $validated);
        }
        $req->bindValue(':team', $this->userData['team']);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Get all users
     *
     * @return array
     */
    public function readAll(): array
    {
        $sql = "SELECT users.*, teams.team_name AS teamname
            FROM users
            LEFT JOIN teams ON (users.team = teams.team_id)
            ORDER BY users.team ASC, users.usergroup ASC, users.lastname ASC";
        $req = $this->Db->prepare($sql);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Get email for every single user
     *
     * @return array
     */
    public function getAllEmails(): array
    {
        $sql = "SELECT email FROM users WHERE validated = 1 AND archived = 0";
        $req = $this->Db->prepare($sql);
        $req->execute();

        return $req->fetchAll();
    }

    /**
     * Update user
     *
     * @param array $params POST
     * @throws Exception
     * @return bool
     */
    public function update(array $params): bool
    {
        $userid = Tools::checkId((int) $params['userid']);

        if ($userid === false) {
            throw new Exception(_('The id parameter is not valid!'));
        }

        $firstname = filter_var($params['firstname'], FILTER_SANITIZE_STRING);
        $lastname = filter_var($params['lastname'], FILTER_SANITIZE_STRING);
        $email = filter_var($params['email'], FILTER_SANITIZE_EMAIL);

        if ($params['validated'] == 1) {
            $validated = 1;
        } else {
            $validated = 0;
        }
        $usergroup = Tools::checkId((int) $params['usergroup']);
        if ($usergroup === false) {
            throw new Exception(_('The id parameter is not valid!'));
        }

        // a non sysadmin cannot put someone sysadmin
        if ($usergroup == 1 && $this->userData['is_sysadmin'] != 1) {
            throw new Exception(_('Only a sysadmin can put someone sysadmin.'));
        }

        if (\mb_strlen($params['password']) > 1) {
            $this->updatePassword($params['password'], $userid);
        }

        $sql = "UPDATE users SET
            firstname = :firstname,
            lastname = :lastname,
            email = :email,
            usergroup = :usergroup,
            validated = :validated
            WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':firstname', $firstname);
        $req->bindParam(':lastname', $lastname);
        $req->bindParam(':email', $email);
        $req->bindParam(':validated', $validated);
        $req->bindParam(':usergroup', $usergroup);
        $req->bindParam(':userid', $userid, PDO::PARAM_INT);

        return $req->execute();
    }

    /**
     * Update preferences from user control panel
     *
     * @param array $params
     * @return bool
     */
    public function updatePreferences(array $params): bool
    {
        // LIMIT
        $filter_options = array(
            'options' => array(
                'default' => 15,
                'min_range' => 1,
                'max_range' => 500
            ));
        $new_limit = filter_var($params['limit'], FILTER_VALIDATE_INT, $filter_options);

        // ORDER BY
        $new_orderby = null;
        $whitelistOrderby = array(null, 'cat', 'date', 'title', 'comment');
        if (isset($params['orderby']) && \in_array($params['orderby'], $whitelistOrderby, true)) {
            $new_orderby = $params['orderby'];
        }

        // SORT
        $new_sort = 'desc';
        if (isset($params['sort']) && ($params['sort'] === 'asc' || $params['sort'] === 'desc')) {
            $new_sort = $params['sort'];
        }

        // LAYOUT
        $new_layout = 0;
        if (isset($params['single_column_layout']) && $params['single_column_layout'] === 'on') {
            $new_layout = 1;
        }

        // KEYBOARD SHORTCUTS
        // only take first letter
        $new_sc_create = $params['sc_create'][0];
        if (!ctype_alpha($new_sc_create)) {
            $new_sc_create = 'c';
        }
        $new_sc_edit = $params['sc_edit'][0];
        if (!ctype_alpha($new_sc_edit)) {
            $new_sc_edit = 'e';
        }
        $new_sc_submit = $params['sc_submit'][0];
        if (!ctype_alpha($new_sc_submit)) {
            $new_sc_submit = 's';
        }
        $new_sc_todo = $params['sc_todo'][0];
        if (!ctype_alpha($new_sc_todo)) {
            $new_sc_todo = 't';
        }

        // SHOW TEAM
        $new_show_team = 0;
        if (isset($params['show_team']) && $params['show_team'] === 'on') {
            $new_show_team = 1;
        }

        // CLOSE WARNING
        $new_close_warning = 0;
        if (isset($params['close_warning']) && $params['close_warning'] === 'on') {
            $new_close_warning = 1;
        }

        // CJK FONTS
        $new_cjk_fonts = 0;
        if (isset($params['cjk_fonts']) && $params['cjk_fonts'] === 'on') {
            $new_cjk_fonts = 1;
        }

        // PDF/A
        $new_pdfa = 0;
        if (isset($params['pdfa']) && $params['pdfa'] === 'on') {
            $new_pdfa = 1;
        }

        // PDF format
        $new_pdf_format = 'A4';
        $formatsArr = array('A4', 'LETTER', 'ROYAL');
        if (\in_array($params['pdf_format'], $formatsArr, true)) {
            $new_pdf_format = $params['pdf_format'];
        }

        // USE MARKDOWN
        $new_use_markdown = 0;
        if (isset($params['use_markdown']) && $params['use_markdown'] === 'on') {
            $new_use_markdown = 1;
        }

        // INCLUDE FILES IN PDF
        $new_inc_files_pdf = 0;
        if (isset($params['inc_files_pdf']) && $params['inc_files_pdf'] === 'on') {
            $new_inc_files_pdf = 1;
        }

        // CHEM EDITOR
        $new_chem_editor = 0;
        if (isset($params['chem_editor']) && $params['chem_editor'] === 'on') {
            $new_chem_editor = 1;
        }

        // LANG
        $new_lang = 'en_GB';
        if (isset($params['lang']) && array_key_exists($params['lang'], Tools::getLangsArr())) {
            $new_lang = $params['lang'];
        }

        // ALLOW EDIT
        $new_allow_edit = 0;
        if (isset($params['allow_edit']) && $params['allow_edit'] === 'on') {
            $new_allow_edit = 1;
        }

        // DEFAULT VIS
        $new_default_vis = null;
        $Experiments = new Experiments($this);
        if (isset($params['default_vis']) && $Experiments->checkVisibility($params['default_vis'])) {
            $new_default_vis = $params['default_vis'];
        }

        // STREAM ZIP
        // only use cookie here because it's temporary code
        if (isset($params['stream_zip']) && $params['stream_zip'] === 'on') {
            \setcookie('stream_zip', '1', time() + 2592000, '/', '', true, true);
        } else {
            \setcookie('stream_zip', '0', time() - 3600, '/', '', true, true);
        }

        // Signature pdf
        // only use cookie here because it's temporary code
        if (isset($params['pdf_sig']) && $params['pdf_sig'] === 'on') {
            \setcookie('pdf_sig', '1', time() + 2592000, '/', '', true, true);
        } else {
            \setcookie('pdf_sig', '0', time() - 3600, '/', '', true, true);
        }

        $sql = "UPDATE users SET
            limit_nb = :new_limit,
            orderby = :new_orderby,
            sort = :new_sort,
            sc_create = :new_sc_create,
            sc_edit = :new_sc_edit,
            sc_submit = :new_sc_submit,
            sc_todo = :new_sc_todo,
            show_team = :new_show_team,
            close_warning = :new_close_warning,
            chem_editor = :new_chem_editor,
            lang = :new_lang,
            default_vis = :new_default_vis,
            single_column_layout = :new_layout,
            cjk_fonts = :new_cjk_fonts,
            pdfa = :new_pdfa,
            pdf_format = :new_pdf_format,
            use_markdown = :new_use_markdown,
            allow_edit = :new_allow_edit,
            inc_files_pdf = :new_inc_files_pdf
            WHERE userid = :userid;";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':new_limit', $new_limit);
        $req->bindParam(':new_orderby', $new_orderby);
        $req->bindParam(':new_sort', $new_sort);
        $req->bindParam(':new_sc_create', $new_sc_create);
        $req->bindParam(':new_sc_edit', $new_sc_edit);
        $req->bindParam(':new_sc_submit', $new_sc_submit);
        $req->bindParam(':new_sc_todo', $new_sc_todo);
        $req->bindParam(':new_show_team', $new_show_team);
        $req->bindParam(':new_close_warning', $new_close_warning);
        $req->bindParam(':new_chem_editor', $new_chem_editor);
        $req->bindParam(':new_lang', $new_lang);
        $req->bindParam(':new_default_vis', $new_default_vis);
        $req->bindParam(':new_layout', $new_layout);
        $req->bindParam(':new_cjk_fonts', $new_cjk_fonts);
        $req->bindParam(':new_pdfa', $new_pdfa);
        $req->bindParam(':new_pdf_format', $new_pdf_format);
        $req->bindParam(':new_use_markdown', $new_use_markdown);
        $req->bindParam(':new_allow_edit', $new_allow_edit);
        $req->bindParam(':new_inc_files_pdf', $new_inc_files_pdf);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);

        return $req->execute();
    }

    /**
     * Update things from UCP
     *
     * @param array $params
     * @return bool
     */
    public function updateAccount(array $params): bool
    {
        // check that we got the good password
        if (!$this->Auth->checkCredentials($this->userData['email'], $params['currpass'])) {
            throw new Exception(_("Please input your current password!"));
        }
        // PASSWORD CHANGE
        if (!empty($params['newpass'])) {
            if ($params['newpass'] != $params['cnewpass']) {
                throw new Exception(_('The passwords do not match!'));
            }

            if (!$this->updatePassword($params['newpass'])) {
                throw new Exception('Error updating password.');
            }
        }

        $params['firstname'] = filter_var($params['firstname'], FILTER_SANITIZE_STRING);
        $params['lastname'] = filter_var($params['lastname'], FILTER_SANITIZE_STRING);
        $params['email'] = filter_var($params['email'], FILTER_SANITIZE_EMAIL);

        if ($this->isDuplicateEmail($params['email']) && ($params['email'] != $this->userData['email'])) {
            throw new Exception(_('Someone is already using that email address!'));
        }

        // Check phone
        $params['phone'] = filter_var($params['phone'], FILTER_SANITIZE_STRING);
        // Check cellphone
        $params['cellphone'] = filter_var($params['cellphone'], FILTER_SANITIZE_STRING);
        // Check skype
        $params['skype'] = filter_var($params['skype'], FILTER_SANITIZE_STRING);

        // Check website
        $params['website'] = filter_var($params['website'], FILTER_VALIDATE_URL);

        $sql = "UPDATE users SET
            email = :email,
            firstname = :firstname,
            lastname = :lastname,
            phone = :phone,
            cellphone = :cellphone,
            skype = :skype,
            website = :website
            WHERE userid = :userid";
        $req = $this->Db->prepare($sql);

        $req->bindParam(':email', $params['email']);
        $req->bindParam(':firstname', $params['firstname']);
        $req->bindParam(':lastname', $params['lastname']);
        $req->bindParam(':phone', $params['phone']);
        $req->bindParam(':cellphone', $params['cellphone']);
        $req->bindParam(':skype', $params['skype']);
        $req->bindParam(':website', $params['website']);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);

        return $req->execute();
    }

    /**
     * Update the password for a user, or for ourself if none provided
     *
     * @param string $password The new password
     * @param int|null $userid The user we want to update
     * @throws Exception if invalid character length
     * @return bool True if password is updated
     */
    public function updatePassword(string $password, ?int $userid = null): bool
    {
        if ($userid === null) {
            $userid = $this->userid;
        }

        $this->Auth->checkPasswordLength($password);

        $salt = \hash("sha512", \bin2hex(\random_bytes(16)));
        $passwordHash = \hash("sha512", $salt . $password);

        $sql = "UPDATE users SET salt = :salt, password = :password, token = null WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':salt', $salt);
        $req->bindParam(':password', $passwordHash);
        $req->bindParam(':userid', $userid, PDO::PARAM_INT);

        return $req->execute();
    }

    /**
     * Validate a user
     *
     * @param int $userid
     * @return string
     */
    public function validate(int $userid): string
    {
        $this->setId($userid);

        $sql = "UPDATE users SET validated = 1 WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);

        if ($req->execute()) {
            $msg = sprintf(
                _('User %s (%s) now has an active account.'),
                $this->userData['fullname'],
                $this->userData['email']
            );
        } else {
            $msg = Tools::error();
        }

        // send an email to the user
        $Email = new Email($this->Config);
        $Email->alertUserIsValidated($this->userData['email']);

        return $msg;
    }

    /**
     * Archive a user
     *
     * @return bool
     */
    public function archive(): bool
    {
        $sql = "UPDATE users SET archived = 1, token = null WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);
        $res1 = $req->execute();

        $sql = "UPDATE experiments
            SET locked = :locked, lockedby = :lockedby, lockedwhen = CURRENT_TIMESTAMP WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindValue(':locked', 1);
        $req->bindParam(':lockedby', $this->userid, PDO::PARAM_INT);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);
        $res2 = $req->execute();

        return $res1 && $res2;
    }

    /**
     * Destroy user. Will completely remove everything from the user.
     *
     * @param string $email The email of the user we want to delete
     * @param string $password The confirmation password
     * @return bool
     */
    public function destroy(string $email, string $password): bool
    {
        // check that we got the good password
        if (!$this->Auth->checkCredentials($this->userData['email'], $password)) {
            throw new Exception(_("Wrong password!"));
        }

        // load data on the user to delete
        $userToDelete = $this->readFromEmail($email);
        // check we are in same team
        if ($this->userData['team'] !== $userToDelete['team']) {
            throw new Exception(_('No user with this email or user not in your team'));
        }

        $result = array();

        $sql = "DELETE FROM users WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userToDelete['userid'], PDO::PARAM_INT);
        $result[] = $req->execute();

        $sql = "DELETE FROM experiments_tags WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userToDelete['userid'], PDO::PARAM_INT);
        $result[] = $req->execute();

        $sql = "DELETE FROM experiments WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userToDelete['userid'], PDO::PARAM_INT);
        $result[] = $req->execute();

        // get all filenames
        $sql = "SELECT long_name FROM uploads WHERE userid = :userid AND type = :type";
        $req = $this->Db->prepare($sql);
        $req->execute(array(
            'userid' => $userToDelete['userid'],
            'type' => 'experiments'
        ));
        while ($uploads = $req->fetch()) {
            // Delete file
            $filepath = dirname(__DIR__, 2) . '/uploads/' . $uploads['long_name'];
            $result[] = unlink($filepath);
        }

        $sql = "DELETE FROM uploads WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userToDelete['userid'], PDO::PARAM_INT);
        $result[] = $req->execute();

        return !\in_array(false, $result, true);
    }

    /**
     * Generate an API key and store it
     *
     * @throws Exception
     * @return bool
     */
    public function generateApiKey(): bool
    {
        $apiKey = \bin2hex(\random_bytes(42));

        $sql = "UPDATE users SET api_key = :api_key WHERE userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':api_key', $apiKey);
        $req->bindParam(':userid', $this->userid, PDO::PARAM_INT);

        return $req->execute();
    }
}
