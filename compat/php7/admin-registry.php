<?php
/** Separate panel identities; legacy game administrators keep their subject IDs. */
declare(strict_types=1);
require_once __DIR__.'/admin-ui/AdminRbac.php';

function mucho7_admin_registry_ready(PDO $db) {
    static $ready;
    if($ready===null)$ready=new SplObjectStorage();
    if(isset($ready[$db]))return true;
    $ok=tableExists($db,'php7_admin_registry_state') && (int)$db->query('SELECT version FROM php7_admin_registry_state WHERE id=1')->fetchColumn()===1;
    if($ok)$ready[$db]=true;
    return $ok;
}

function mucho7_admin_registry_install(PDO $db) {
    if(mucho7_admin_registry_ready($db))return;
    $db->exec("CREATE TABLE IF NOT EXISTS php7_admin_users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NULL UNIQUE,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        role VARCHAR(32) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        password_must_set TINYINT NOT NULL DEFAULT 0,
        password_setup_token_hash CHAR(64) NULL UNIQUE,
        password_setup_expires_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS php7_admin_roles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL UNIQUE,name VARCHAR(64) NOT NULL,
        is_system TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS php7_admin_role_permissions (
        role_id BIGINT UNSIGNED NOT NULL,permission VARCHAR(128) NOT NULL,
        PRIMARY KEY(role_id,permission)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS php7_admin_registry_state (
        id INT NOT NULL PRIMARY KEY,version INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('INSERT IGNORE INTO php7_admin_registry_state(id,version) VALUES(1,0)');
    $db->beginTransaction();
    try {
        if((int)$db->query('SELECT version FROM php7_admin_registry_state WHERE id=1 FOR UPDATE')->fetchColumn()===1){$db->commit();return;}
        $roles=array('owner'=>'Owner','admin'=>'Administrator','moderator'=>'Moderator','viewer'=>'Viewer','elder_moderator'=>'Elder moderator');
        $permissions=array(
            'owner'=>array_keys(\MuchoCore\Admin\AdminRbac::permissions()),
            'admin'=>array('dashboard.view','players.view','players.manage','players.password_reset','levels.view','levels.manage','levels.rate','moderation.manage','comments.manage','messages.manage','music.manage','backups.view','audit.view','self.security'),
            'moderator'=>array('dashboard.view','players.view','levels.view','moderation.manage','comments.manage','self.security'),
            'elder_moderator'=>array('dashboard.view','players.view','levels.view','levels.manage','levels.rate','moderation.manage','comments.manage','self.security'),
            'viewer'=>array('dashboard.view','players.view','levels.view','audit.view','self.security')
        );
        foreach($roles as $code=>$name){
            $db->prepare('INSERT IGNORE INTO php7_admin_roles(code,name,is_system) VALUES(?,?,1)')->execute(array($code,$name));
            $q=$db->prepare('SELECT id FROM php7_admin_roles WHERE code=?');$q->execute(array($code));$rid=(int)$q->fetchColumn();
            foreach($permissions[$code] as $permission)$db->prepare('INSERT IGNORE INTO php7_admin_role_permissions(role_id,permission) VALUES(?,?)')->execute(array($rid,$permission));
        }
        // Import once, preserving factor, audit and existing session IDs. New
        // panel-only users do not create game accounts or grant game roles.
        $db->exec("INSERT INTO php7_admin_users(id,account_id,username,role,is_active)
            SELECT a.account_id,a.account_id,a.username,r.code,a.is_active
            FROM accounts a JOIN roles r ON r.id=a.role_id
            WHERE r.code IN ('owner','elder_moderator','moderator')");
        // Reserve historical game/factor/audit IDs, including demoted admins,
        // before allocating unrelated panel identities from this sequence.
        $max=(int)$db->query('SELECT COALESCE(MAX(account_id),0) FROM accounts')->fetchColumn();
        foreach(array('php7_admin_security','php7_admin_audit') as $table)if(tableExists($db,$table))$max=max($max,(int)$db->query('SELECT COALESCE(MAX(account_id),0) FROM '.$table)->fetchColumn());
        $db->prepare("INSERT INTO php7_admin_users(id,username,role,is_active,password_must_set) VALUES(?,?,'viewer',0,1)")->execute(array($max+1,'reserved_'.bin2hex(random_bytes(12))));
        $db->prepare('DELETE FROM php7_admin_users WHERE id=?')->execute(array($max+1));
        $db->exec('UPDATE php7_admin_registry_state SET version=1 WHERE id=1');
        $db->commit();
    }catch(Exception $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function mucho7_admin_identity(PDO $db,$id) {
    $q=$db->prepare("SELECT u.id,u.id AS account_id,u.account_id AS game_account_id,
        CASE WHEN u.account_id IS NULL THEN u.username ELSE a.username END AS username,
        u.role,u.is_active,u.password_must_set,
        CASE WHEN u.account_id IS NULL THEN u.password_hash ELSE a.password_hash END AS password_hash
        FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id
        JOIN php7_admin_roles r ON r.code=u.role
        WHERE u.id=? AND u.is_active=1 AND u.password_must_set=0
        AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))");
    $q->execute(array($id));$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    unset($row['password_must_set']);return $row;
}

function mucho7_admin_can(PDO $db,$user,$permission) {
    if(!$user)return false;
    if(mucho7_admin_registry_ready($db))return \MuchoCore\Admin\AdminRbac::can($db,$user,$permission);
    if($user['role']==='owner')return true;
    $legacy=array('dashboard.view','players.view','levels.view','moderation.manage','comments.manage','self.security');
    if($user['role']==='elder_moderator')$legacy=array_merge($legacy,array('levels.manage','levels.rate'));
    return in_array($permission,$legacy,true);
}

function mucho7_admin_password_login(PDO $db,$data,$ip,$settings) {
    $name=isset($data['userName'])&&is_string($data['userName'])?trim($data['userName']):'';
    $password=isset($data['password'])&&is_string($data['password'])?$data['password']:'';
    if(!mucho7_auth_event($db,'admin-login',$name,$ip,(string)$settings['auth_pepper']))throw new RuntimeException('Слишком много попыток. Повтори через 10 минут.');
    $q=$db->prepare('SELECT id FROM php7_admin_users WHERE (account_id IS NULL AND username=?) OR account_id=(SELECT account_id FROM accounts WHERE username=? LIMIT 1)');
    $q->execute(array($name,$name));$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    // Reject ambiguous names after a linked game account was renamed.
    $user=count($ids)===1?mucho7_admin_identity($db,(int)$ids[0]):null;
    $dummy='$2y$04$abcdefghijklmnopqrstuuQ1Oxt8SAsyZI9jY8e6mJ3Yxv2PqVu';
    if(!password_verify($password,$user?$user['password_hash']:$dummy)||!$user)throw new RuntimeException('Проверь логин, пароль и роль администратора.');
    return (int)$user['id'];
}

function mucho7_admin_audit_rows_sql(PDO $db) {
    $join=mucho7_admin_registry_ready($db)?'LEFT JOIN php7_admin_users u ON u.id=c.account_id LEFT JOIN accounts a ON a.account_id=u.account_id':'LEFT JOIN accounts a ON a.account_id=c.account_id';
    $name=mucho7_admin_registry_ready($db)?'COALESCE(a.username,u.username)':'a.username';
    return 'SELECT c.created_at,'.$name.' AS username,c.action,c.target_id AS target,NULL AS ip,c.details AS metadata FROM php7_admin_audit c '.$join.' ORDER BY c.id DESC';
}

function mucho7_admin_setup_row(PDO $db,$token,$lock=false) {
    if(!is_string($token)||!preg_match('/^[A-Za-z0-9_-]{64}$/D',$token))return null;
    $q=$db->prepare('SELECT * FROM php7_admin_users WHERE password_setup_token_hash=? AND password_must_set=1 AND is_active=1 AND password_setup_expires_at>UTC_TIMESTAMP()'.($lock?' FOR UPDATE':''));
    $q->execute(array(hash('sha256',$token)));return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function mucho7_admin_setup_password(PDO $db,$data,$settings) {
    $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
    if(!mucho7_auth_event($db,'admin-setup','setup',$ip,(string)$settings['auth_pepper']))throw new RuntimeException('Слишком много попыток. Повтори через 10 минут.');
    $pass=isset($data['password'])&&is_string($data['password'])?$data['password']:'';
    $confirm=isset($data['password_confirm'])&&is_string($data['password_confirm'])?$data['password_confirm']:'';
    if(strlen($pass)<10||strlen($pass)>72||!hash_equals($pass,$confirm))throw new RuntimeException('Пароль: 10–72 байта; подтверждение должно совпадать.');
    $hash=password_hash($pass,PASSWORD_BCRYPT,array('cost'=>12));
    $db->beginTransaction();
    try {
        $row=mucho7_admin_setup_row($db,isset($data['setup'])?$data['setup']:'',true);
        if(!$row)throw new RuntimeException('Ссылка недействительна или уже использована.');
        $db->prepare('UPDATE php7_admin_users SET password_hash=?,password_must_set=0,password_setup_token_hash=NULL,password_setup_expires_at=NULL WHERE id=?')->execute(array($hash,$row['id']));
        mucho7_admin_audit($db,(int)$row['id'],'admin.password_setup',(int)$row['id']);
        $db->commit();
    }catch(Exception $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function mucho7_admin_registry_action(PDO $db,$aid,$action,$data,$settings) {
    $actor=mucho7_admin_user($db,$aid);
    $permission=strpos($action,'role-')===0?'roles.manage':'admins.manage';
    if(!mucho7_admin_can($db,$actor,$permission))throw new RuntimeException('Insufficient permissions.');
    if(!mucho7_admin_registry_ready($db))throw new RuntimeException('Administrator registry is unavailable.');
    $rbac='\\MuchoCore\\Admin\\AdminRbac';
    if(strpos($action,'role-')===0){
        $permissions=isset($data['permissions'])&&is_array($data['permissions'])?$data['permissions']:array();
        foreach($permissions as $p)if(!is_string($p)||!isset(mucho7_admin_supported_permissions()[$p]))throw new RuntimeException('Unknown or unavailable permission.');
        if(!$rbac::canManagePermissionSet($db,$actor,$permissions))throw new RuntimeException('A role cannot grant permissions you do not have.');
        $name=isset($data['name'])&&is_string($data['name'])?trim($data['name']):'';
        $code=isset($data['code'])&&is_string($data['code'])?strtolower(trim($data['code'])):'';
        if($action!=='role-delete' && ($name===''||mucho7_admin_utf8_length($name)>64))throw new RuntimeException('Role name must be between 1 and 64 characters.');
        $db->beginTransaction();
        try {
            $db->query('SELECT version FROM php7_admin_registry_state WHERE id=1 FOR UPDATE')->fetchColumn();
            $actor=mucho7_admin_user($db,$aid);
            if(!mucho7_admin_can($db,$actor,'roles.manage'))throw new RuntimeException('Insufficient permissions.');
            if($action==='role-create'){
                if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/D',$code)||$rbac::isBuiltInRole($code))throw new RuntimeException('Invalid custom role code.');
                $db->prepare('INSERT INTO php7_admin_roles(code,name,is_system) VALUES(?,?,0)')->execute(array($code,$name));$id=(int)$db->lastInsertId();
            }else{
                $id=(int)(isset($data['id'])?$data['id']:0);
                $q=$db->prepare('SELECT * FROM php7_admin_roles WHERE id=? FOR UPDATE');$q->execute(array($id));$role=$q->fetch(PDO::FETCH_ASSOC);
                if(!$role || $role['is_system'])throw new RuntimeException('Built-in or missing role cannot be changed.');
                if(!$rbac::canManagePermissionSet($db,$actor,$rbac::rolePermissions($db,$role['code'])))throw new RuntimeException('You cannot manage this role.');
                if($action==='role-delete'){
                    $q=$db->prepare('SELECT COUNT(*) FROM php7_admin_users WHERE role=?');$q->execute(array($role['code']));
                    if((int)$q->fetchColumn()>0)throw new RuntimeException('This role is still assigned to administrators.');
                    $db->prepare('DELETE FROM php7_admin_roles WHERE id=?')->execute(array($id));
                }elseif($action==='role-update')$db->prepare('UPDATE php7_admin_roles SET name=? WHERE id=?')->execute(array($name,$id));
                else throw new RuntimeException('Invalid role action.');
            }
            $db->prepare('DELETE FROM php7_admin_role_permissions WHERE role_id=?')->execute(array($id));
            if($action!=='role-delete')foreach($rbac::normalizePermissionList($permissions) as $p)$db->prepare('INSERT INTO php7_admin_role_permissions(role_id,permission) VALUES(?,?)')->execute(array($id,$p));
            mucho7_admin_audit($db,$aid,$action,$id);$db->commit();return;
        }catch(Exception $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    // DDL must finish before the transaction: even CREATE IF NOT EXISTS
    // implicitly commits an active MySQL transaction.
    mucho7_admin_security_install($db);
    $db->beginTransaction();
    try {
        // Serialize creation, assignment, activation and role deletion against
        // the same row so the last active owner cannot disappear concurrently.
        $db->query('SELECT version FROM php7_admin_registry_state WHERE id=1 FOR UPDATE')->fetchColumn();
        $actor=mucho7_admin_user($db,$aid);
        if(!mucho7_admin_can($db,$actor,'admins.manage'))throw new RuntimeException('Insufficient permissions.');
        if($action==='admin-create'){
            $name=isset($data['username'])&&is_string($data['username'])?trim($data['username']):'';
            $role=isset($data['role'])&&is_string($data['role'])?$data['role']:'';
            if(!preg_match('/^[A-Za-z0-9_.-]{3,32}$/D',$name))throw new RuntimeException('Invalid username.');
            if(!$rbac::canAssignRole($db,$actor,$role))throw new RuntimeException('You cannot assign this role.');
            $q=$db->prepare('SELECT 1 FROM accounts WHERE username=?');$q->execute(array($name));
            if($q->fetchColumn())throw new RuntimeException('This name is already used by a game account.');
            $token=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
            $db->prepare('INSERT INTO php7_admin_users(username,role,password_must_set,password_setup_token_hash,password_setup_expires_at) VALUES(?,?,1,?,?)')->execute(array($name,$role,hash('sha256',$token),gmdate('Y-m-d H:i:s',time()+86400)));
            $id=(int)$db->lastInsertId();
        }else{
            $id=(int)(isset($data['id'])?$data['id']:0);
            $q=$db->prepare('SELECT * FROM php7_admin_users WHERE id=? FOR UPDATE');$q->execute(array($id));$target=$q->fetch(PDO::FETCH_ASSOC);
            if(!$target)throw new RuntimeException('Administrator not found.');
            if($id===$aid)throw new RuntimeException('You cannot change your own role or active status.');
            if(!$rbac::canAssignRole($db,$actor,$target['role']))throw new RuntimeException('You cannot manage this administrator.');
            $role=$target['role'];$active=(int)$target['is_active'];
            if($action==='admin-toggle')$active=1-$active;
            elseif($action==='admin-role'){
                $role=isset($data['role'])&&is_string($data['role'])?$data['role']:'';
                if(!$rbac::canAssignRole($db,$actor,$role))throw new RuntimeException('You cannot assign this role.');
            }else throw new RuntimeException('Invalid administrator action.');
            if($target['role']==='owner'&&$target['is_active']&&($role!=='owner'||!$active)){
                $count=(int)$db->query("SELECT COUNT(*) FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id WHERE u.role='owner' AND u.is_active=1 AND u.password_must_set=0 AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))")->fetchColumn();
                if($count<=1)throw new RuntimeException('At least one active owner must remain.');
            }
            $db->prepare('UPDATE php7_admin_users SET role=?,is_active=? WHERE id=?')->execute(array($role,$active,$id));
            $db->prepare('INSERT IGNORE INTO php7_admin_security(account_id) VALUES(?)')->execute(array($id));
            $db->prepare('UPDATE php7_admin_security SET auth_version=auth_version+1 WHERE account_id=?')->execute(array($id));
        }
        mucho7_admin_audit($db,$aid,$action,$id);
        $db->commit();
        if(isset($token)){
            $script=isset($_SERVER['SCRIPT_NAME'])?(string)$_SERVER['SCRIPT_NAME']:'/mc/admin.php';
            $_SESSION['admin_setup_invite']=array('username'=>$name,'url'=>rtrim($settings['site_url'],'/').$script.'?setup='.rawurlencode($token));
        }
    }catch(Exception $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
