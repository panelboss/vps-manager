<?php
session_start();
define('PASSWD_FILE', '/var/www/manager/.passwd');
define('USERS_FILE', '/var/www/manager/.users');
define('BACKUP_DIR', '/var/www/manager/backups');
define('MIGRATION_DIR', '/var/www/manager/backups/migration');
define('NGINX_AVAILABLE', '/etc/nginx/sites-available');
define('NGINX_ENABLED', '/etc/nginx/sites-enabled');
define('WWW_ROOT', '/var/www');

// Load users
function load_users() {
    if (!file_exists(USERS_FILE)) {
        $default = [['user' => 'admin', 'pass' => file_exists(PASSWD_FILE) ? trim(file_get_contents(PASSWD_FILE)) : 'admin123', 'role' => 'admin']];
        file_put_contents(USERS_FILE, json_encode($default, JSON_PRETTY_PRINT));
        chmod(USERS_FILE, 0600);
        return $default;
    }
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}
function save_users($users) { file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT)); chmod(USERS_FILE, 0600); }
function check_user($u, $p) { foreach (load_users() as $user) { if ($user['user'] === $u && $user['pass'] === $p) return $user; } return null; }
function current_user() { if (isset($_SESSION['user'])) return $_SESSION['user']; return null; }
function is_admin() { $u = current_user(); return $u && ($u['role'] ?? '') === 'admin'; }

$page = $_GET['page'] ?? 'dashboard';

// ===== PROGRESS ENDPOINT =====
if (($_GET['progress'] ?? '') !== '') {
    $pf = '/tmp/progress_' . basename($_GET['progress']) . '.log';
    if (file_exists($pf)) {
        header('Content-Type: text/plain');
        $log = file_get_contents($pf);
        echo $log;
        if (strpos($log, '___COMPLETE___') !== false) {
            echo "\n___DONE___\n";
            unlink($pf);
        }
        exit;
    }
    echo "Menunggu...\n";
    exit;
}

// ===== AUTH =====
if (isset($_POST['user']) && isset($_POST['pass'])) {
    $u = check_user($_POST['user'], $_POST['pass']);
    if ($u) { $_SESSION['auth'] = true; $_SESSION['user'] = $u; header('Location: ?'); exit; }
    $login_error = 'Username atau password salah!';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }
if (empty($_SESSION['auth'])) {
    ?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>VPS Manager</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(160deg,#dfd9cf 0%,#ebe5d9 40%,#f5f0e8 100%);color:#2d3748;display:flex;align-items:center;justify-content:center;min-height:100vh}.box{background:#fafaf7;padding:44px 40px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1),0 1px 3px rgba(0,0,0,.06);width:400px;max-width:92%;border-top:4px solid #1e3a5f}.box h1{text-align:center;color:#1a365d;margin-bottom:4px;font-size:24px;font-weight:800}.box p{text-align:center;color:#718096;font-size:13px;margin-bottom:28px}input[type=text],input[type=password]{width:100%;padding:14px 16px;border-radius:10px;border:2px solid #e2ddd5;background:#fdfcfb;color:#2d3748;font-size:15px;outline:none;margin-bottom:14px;transition:all .2s}input:focus{border-color:#2c5282;box-shadow:0 0 0 3px rgba(44,82,130,.12)}.btn{width:100%;padding:14px;border-radius:10px;background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;border:none;font-size:15px;font-weight:600;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(30,58,95,.25)}.btn:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(30,58,95,.35)}.btn:active{transform:translateY(0)}</style>
    </head><body><div class="box"><h1><span style="font-family:monospace;font-size:8px;display:block;line-height:1.15;color:#c8a96e;margin-bottom:6px">▄████████▄
██▀▌░░░░▐▀██
█▌░░▄████▄░░▐█
█░░▐█▌░░▐█▌░░█
▐▌░░████████░▐▌
▐▌░░░░▀▀▀▀░░░▐▌
█▄░░░░▐▌░░░▄█
▀█▄▄▄▄▄▄▄█▀</span>VPS Manager</h1><p>Server Management Panel</p><form method="POST"><input type="text" name="user" placeholder="Username" style="margin-bottom:10px;border-radius:10px;padding:14px 16px;width:100%;border:2px solid #e2ddd5;background:#fdfcfb;color:#2d3748;font-size:15px;outline:none;transition:all .2s" autofocus><input type="password" name="pass" placeholder="Password"><button class="btn" style="margin-top:4px">🔑 Login</button></form><?= isset($login_error) ? '<p style="color:#c53030;text-align:center;margin-top:14px;font-size:13px">'.$login_error.'</p>' : '' ?></div></body></html><?php exit;
}

// ===== HELPERS =====
function cmd($cmd) { return shell_exec($cmd . ' 2>&1'); }
function sanitize($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash_html($s) { return strip_tags($s, '<b><i><code><small><br><pre><span><details><summary><strong><em>'); }
function flash($m, $t = 'ok') { $_SESSION['flash'] = ['msg' => $m, 'type' => $t]; }
function get_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function redirect($p = '') { header('Location: ?page=' . $p); exit; }
function svc_status($name) { $o = cmd("systemctl is-active $name 2>/dev/null"); return trim($o ?? '') === 'active'; }
function pkg_installed($pkg) { $o = cmd("dpkg -l $pkg 2>/dev/null | grep '^ii'"); return !empty(trim($o ?? '')); }
function disk_free() { return round(disk_free_space('/') / 1073741824, 1); }
function disk_total() { return round(disk_total_space('/') / 1073741824, 1); }

// ===== ACTIONS =====
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // --- CREATE WEBSITE ---
    if ($act === 'create_site') {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dbname = strtolower(trim($_POST['dbname'] ?? ''));
        $dbuser = strtolower(trim($_POST['dbuser'] ?? ''));
        $dbpass = $_POST['dbpass'] ?? '';
        $create_db = isset($_POST['create_db']);
        $install_wp = isset($_POST['install_wp']);
        $php_ver = $_POST['php_ver'] ?? '8.1';

        if (empty($domain) || !preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            flash('Domain tidak valid!', 'err'); redirect('websites');
        }

        $root = WWW_ROOT . '/' . $domain;
        if (!is_dir($root)) { mkdir($root, 0755, true); chown($root, 'www-data'); chgrp($root, 'www-data'); }

        // Nginx config
        $nginx_conf = "server {\n    listen 80;\n    server_name $domain www.$domain;\n    root $root;\n    index index.php index.html index.htm;\n\n    client_max_body_size 100M;\n\n    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n\n    location ~ \\.php\$ {\n        include snippets/fastcgi-php.conf;\n        fastcgi_pass unix:/var/run/php/php$php_ver-fpm.sock;\n        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n        include fastcgi_params;\n    }\n\n    location ~ /\\. { deny all; }\n    gzip on;\n    gzip_types text/css application/javascript text/html;\n    gzip_min_length 256;\n}\n";
        file_put_contents(NGINX_AVAILABLE . "/$domain", $nginx_conf);
        symlink(NGINX_AVAILABLE . "/$domain", NGINX_ENABLED . "/$domain");
        
        // Create index.html placeholder
        file_put_contents("$root/index.html", "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>$domain</title><style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b}div{text-align:center}h1{color:#1e3a5f}</style></head><body><div><h1>🚀 $domain</h1><p>Website siap! Upload file Anda ke folder <code>/var/www/$domain</code></p></div></body></html>");
        chown("$root/index.html", 'www-data'); chgrp("$root/index.html", 'www-data');

        // Create database
        $db_created = false;
        if ($create_db) {
            $dn = $dbname ?: preg_replace('/[.-]/', '_', $domain);
            $du = $dbuser ?: substr($dn, 0, 16);
            $dp = $dbpass ?: substr(bin2hex(random_bytes(8)), 0, 16);
            $sql = "CREATE DATABASE IF NOT EXISTS $dn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
            $sql .= "CREATE USER IF NOT EXISTS '$du'@'localhost' IDENTIFIED BY '$dp';\n";
            $sql .= "GRANT ALL PRIVILEGES ON $dn.* TO '$du'@'localhost';\n";
            $sql .= "FLUSH PRIVILEGES;\n";
            file_put_contents('/tmp/db_create.sql', $sql);
            cmd('mysql -u root < /tmp/db_create.sql');
            unlink('/tmp/db_create.sql');
            $db_created = true;
        }

        // Install WordPress
        if ($install_wp && $db_created) {
            $dn = $dbname ?: preg_replace('/[.-]/', '_', $domain);
            $du = $dbuser ?: substr($dn, 0, 16);
            $dp = $dbpass ?: substr(bin2hex(random_bytes(8)), 0, 16);
            cmd("cd /tmp && rm -rf latest.tar.gz wordpress && wget -q https://wordpress.org/latest.tar.gz && tar xzf latest.tar.gz && cp -r wordpress/* $root/ && chown -R www-data:www-data $root && rm -rf wordpress latest.tar.gz");
            $wp_salt = file_get_contents('https://api.wordpress.org/secret-key/1.1/salt/');
            $wp_config = file_get_contents("$root/wp-config-sample.php");
            $wp_config = str_replace("define( 'DB_NAME', 'database_name_here' );", "define( 'DB_NAME', '$dn' );", $wp_config);
            $wp_config = str_replace("define( 'DB_USER', 'username_here' );", "define( 'DB_USER', '$du' );", $wp_config);
            $wp_config = str_replace("define( 'DB_PASSWORD', 'password_here' );", "define( 'DB_PASSWORD', '$dp' );", $wp_config);
            $wp_config = str_replace("define( 'AUTH_KEY',         'put your unique phrase here' );", $wp_salt, $wp_config);
            file_put_contents("$root/wp-config.php", $wp_config);
        }

        cmd("nginx -t && systemctl reload nginx");
        $info = "Domain: $domain | Root: $root";
        if ($db_created) {
            $dn2 = $dbname ?: preg_replace('/[.-]/', '_', $domain);
            $du2 = $dbuser ?: substr($dn2, 0, 16);
            $dp2 = $dbpass ?: substr(bin2hex(random_bytes(8)), 0, 16);
            $info .= " | DB: $dn2 | User: $du2 | Pass: $dp2";
        }
        flash("✅ Website berhasil dibuat! $info"); redirect('websites');
    }

    // --- DELETE WEBSITE ---
    if ($act === 'delete_site') {
        $domain = $_POST['domain'] ?? '';
        if (empty($domain)) { flash('Domain tidak valid', 'err'); redirect('websites'); }
        if (file_exists(NGINX_ENABLED . "/$domain")) unlink(NGINX_ENABLED . "/$domain");
        if (file_exists(NGINX_AVAILABLE . "/$domain")) unlink(NGINX_AVAILABLE . "/$domain");
        cmd("nginx -t && systemctl reload nginx");
        flash("✅ Site $domain dihapus! (File di /var/www/$domain TIDAK dihapus)"); redirect('websites');
    }

    // --- CREATE DATABASE ---
    if ($act === 'create_db') {
        $dbname = trim($_POST['dbname'] ?? '');
        $dbuser = trim($_POST['dbuser'] ?? '');
        $dbpass = $_POST['dbpass'] ?? '';
        if (empty($dbname) || !preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) { flash('Nama database tidak valid!', 'err'); redirect('databases'); }
        $du = $dbuser ?: $dbname . '_user';
        $dp = $dbpass ?: substr(bin2hex(random_bytes(8)), 0, 16);
        $sql = "CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE USER IF NOT EXISTS '$du'@'localhost' IDENTIFIED BY '$dp';\n";
        $sql .= "GRANT ALL PRIVILEGES ON $dbname.* TO '$du'@'localhost';\n";
        $sql .= "FLUSH PRIVILEGES;\n";
        file_put_contents('/tmp/db_create.sql', $sql);
        cmd('mysql -u root < /tmp/db_create.sql');
        unlink('/tmp/db_create.sql');
        flash("✅ Database dibuat! DB: $dbname | User: $du | Pass: $dp"); redirect('databases');
    }

    // --- DELETE DATABASE ---
    if ($act === 'delete_db') {
        $dbname = $_POST['dbname'] ?? '';
        cmd("mysql -u root -e \"DROP DATABASE IF EXISTS \\\`$dbname\\\`;\"");
        flash("✅ Database $dbname dihapus!"); redirect('databases');
    }

    // --- BACKUP SITE ---
    if ($act === 'backup_site') {
        $domain = $_POST['domain'] ?? '';
        if (empty($domain)) { flash('Pilih website!', 'err'); redirect('backups'); }
        $ts = date('Ymd_His');
        $bf = BACKUP_DIR . "/{$domain}_$ts.zip";
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        
        // Find actual root by scanning nginx configs (domain may differ from folder name)
        $root = null; $cfg_file = null;
        foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
            $cfg = file_get_contents($f);
            if (preg_match('/server_name\s+([^;]+);/', $cfg, $m)) {
                $names = preg_split('/\s+/', trim($m[1]));
                if (in_array($domain, $names)) {
                    preg_match('/root\s+([^;]+);/', $cfg, $rm);
                    $root = $rm[1] ?? null;
                    $cfg_file = $f;
                    break;
                }
            }
        }
        if (!$root) $root = WWW_ROOT . '/' . $domain; // fallback
        if (!is_dir($root)) { flash("Folder $root tidak ditemukan", 'err'); redirect('backups'); }
        $exclude = '';
        if (is_dir("$root/uploads")) $exclude = "-x '$root/uploads/*'";
        cmd("cd " . escapeshellarg(dirname($root)) . " && zip -rq " . escapeshellarg($bf) . " " . escapeshellarg(basename($root)) . " $exclude 2>&1");
        
        // Also backup DB if we can find it in nginx config
        $ngx = $cfg_file;
        $db_file = '';
        $db_names = [];
        if (file_exists($ngx)) {
            // Try to detect DB by scanning config
            $cfg = file_get_contents($ngx);
            if (preg_match('/fastcgi_pass.*php.*fpm/', $cfg)) {
                $dbs = cmd("mysql -u root -e 'SHOW DATABASES;' -N 2>/dev/null");
                foreach (explode("\n", $dbs) as $db) {
                    $db = trim($db);
                    if ($db && $db !== 'information_schema' && $db !== 'performance_schema' && $db !== 'mysql' && $db !== 'sys') {
                        $db_names[] = $db;
                    }
                }
            }
        }
        if (!empty($db_names)) {
            $db_file = BACKUP_DIR . "/{$domain}_$ts.sql";
            cmd("mysqldump -u root --databases " . implode(' ', array_map('escapeshellarg', $db_names)) . " > " . escapeshellarg($db_file) . " 2>&1");
        }
        $sz = file_exists($bf) ? round(filesize($bf) / 1024, 1) : 0;
        $msg2 = $db_file ? " + DB" : "";
        flash("✅ Backup berhasil! {$sz}KB$msg2"); redirect('backups');
    }

    // --- RESTORE SITE ---
    if ($act === 'restore_site') {
        $domain = trim($_POST['restore_domain'] ?? '');
        if (empty($domain) || !preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $domain)) { flash('Domain tidak valid!', 'err'); redirect('backups'); }
        
        $zip_uploaded = !empty($_FILES['zipfile']['tmp_name']);
        $sql_uploaded = !empty($_FILES['sqlfile']['tmp_name']);
        
        if ($zip_uploaded) {
            $root = WWW_ROOT . '/' . $domain;
            if (!is_dir($root)) mkdir($root, 0755, true);
            $tmp = '/tmp/restore_' . time() . '.zip';
            move_uploaded_file($_FILES['zipfile']['tmp_name'], $tmp);
            cmd("unzip -o " . escapeshellarg($tmp) . " -d /tmp/restore_extract/ 2>&1");
            $extracted = glob('/tmp/restore_extract/*');
            if (!empty($extracted)) {
                cmd("cp -rf /tmp/restore_extract/*/* $root/ 2>/dev/null || cp -rf /tmp/restore_extract/* $root/ 2>/dev/null");
            }
            cmd("chown -R www-data:www-data $root");
            cmd("rm -rf /tmp/restore_extract " . escapeshellarg($tmp));
        }
        
        if ($sql_uploaded) {
            $tmp_sql = '/tmp/restore_' . time() . '.sql';
            move_uploaded_file($_FILES['sqlfile']['tmp_name'], $tmp_sql);
            cmd("mysql -u root < " . escapeshellarg($tmp_sql) . " 2>&1");
            unlink($tmp_sql);
        }
        
        // Create nginx config if not exists
        if (!file_exists(NGINX_AVAILABLE . "/$domain")) {
            $root = WWW_ROOT . '/' . $domain;
            $php_ver = trim(cmd("php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;'"));
            $nginx_conf = "server {\n    listen 80;\n    server_name $domain www.$domain;\n    root $root;\n    index index.php index.html;\n    client_max_body_size 100M;\n    location / { try_files \$uri \$uri/ /index.php?\$query_string; }\n    location ~ \\.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/var/run/php/php$php_ver-fpm.sock; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; include fastcgi_params; }\n    location ~ /\\. { deny all; }\n}\n";
            file_put_contents(NGINX_AVAILABLE . "/$domain", $nginx_conf);
            symlink(NGINX_AVAILABLE . "/$domain", NGINX_ENABLED . "/$domain");
            cmd("nginx -t && systemctl reload nginx");
        }
        flash("✅ Website $domain berhasil di-restore!"); redirect('backups');
    }

    // --- INSTALL SERVICE ---
    if ($act === 'install_svc') {
        $svc = $_POST['svc'] ?? '';
        $pkgs = [
            'nginx' => 'nginx',
            'php' => 'php8.1-fpm php8.1-cli php8.1-mysql php8.1-curl php8.1-gd php8.1-mbstring php8.1-xml php8.1-zip php8.1-intl php8.1-bcmath',
            'mysql' => 'mariadb-server mariadb-client',
            'phpmyadmin' => 'phpmyadmin',
            'certbot' => 'certbot python3-certbot-nginx'
        ];
        if (isset($pkgs[$svc])) {
            cmd("DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq " . $pkgs[$svc] . " 2>&1");
            if ($svc === 'nginx') cmd("systemctl enable --now nginx");
            if ($svc === 'php') cmd("systemctl enable --now php8.1-fpm");
            if ($svc === 'mysql') cmd("systemctl enable --now mariadb");
            flash("✅ $svc berhasil diinstall & dijalankan!"); redirect('services');
        }
        flash('❌ Service tidak dikenal', 'err'); redirect('services');
    }

    // --- PHP: INSTALL VERSION ---
    if ($act === 'php_install') {
        $ver = $_POST['version'] ?? '';
        if (!preg_match('/^\d+\.\d+$/', $ver)) { flash('Versi tidak valid!', 'err'); redirect('php'); }
        
        $task_id = 'phpinstall_' . $ver . '_' . time();
        $logfile = '/tmp/progress_' . $task_id . '.log';
        file_put_contents($logfile, "🐘 Memulai install PHP $ver...\n📦 Menambah repository...\n");
        
        $pkgs = "php$ver-fpm php$ver-cli php$ver-mysql php$ver-curl php$ver-gd php$ver-mbstring php$ver-xml php$ver-zip php$ver-intl php$ver-bcmath";
        $cmd = "(apt-get install -y -qq software-properties-common >> $logfile 2>&1; ";
        $cmd .= "add-apt-repository -y ppa:ondrej/php >> $logfile 2>&1; ";
        $cmd .= "apt-get update -qq >> $logfile 2>&1; ";
        $cmd .= "echo '📦 Menginstall packages...' >> $logfile; ";
        $cmd .= "DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $pkgs >> $logfile 2>&1; ";
        $cmd .= "systemctl enable --now php$ver-fpm >> $logfile 2>&1; ";
        $cmd .= "echo '___COMPLETE___' >> $logfile) > /dev/null 2>&1 &";
        exec($cmd);
        
        ?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Installing PHP <?= $ver ?>...</title>
        <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(160deg,#dfd9cf,#f5f0e8);color:#2d3748;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fafaf7;padding:44px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);width:90%;max-width:650px;text-align:center;border-top:4px solid #1e3a5f}.spinner{width:48px;height:48px;border:4px solid #e2ddd5;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}h2{color:#1a365d;margin-bottom:8px;font-weight:700}pre{background:#fdfcfb;padding:16px;border-radius:8px;text-align:left;max-height:350px;overflow:auto;font-family:monospace;font-size:12px;color:#5a6170;line-height:1.6;margin-top:20px;border:1px solid #e2ddd5}.done{display:none;margin-top:16px}.btn-green{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;box-shadow:0 2px 8px rgba(30,58,95,.25)}.btn-green:hover{box-shadow:0 4px 14px rgba(30,58,95,.35)}</style></head><body>
        <div class="box"><div class="spinner" id="spinner"></div>
        <h2 id="title">🐘 Installing PHP <?= $ver ?>...</h2>
        <p style="color:#64748b;font-size:14px">Ini memakan waktu 2-5 menit. Sabar ya ☕</p>
        <pre id="log">Memulai...</pre>
        <div class="done" id="done"><a href="?page=php" class="btn-green">✅ Kembali ke PHP Manager</a></div></div>
        <script>
        var tid='<?= $task_id ?>',check=0;
        function poll(){fetch('?progress='+tid).then(r=>r.text()).then(t=>{document.getElementById('log').textContent=t;if(t.indexOf('___COMPLETE___')>-1){document.getElementById('spinner').style.display='none';document.getElementById('title').textContent='✅ PHP <?= $ver ?> Terinstall!';document.getElementById('done').style.display='block'}else{check++;var m=Math.floor(check*1.5/60),s=Math.ceil(check*1.5)%60;document.getElementById('title').textContent='🐘 Installing PHP <?= $ver ?>... ('+m+'m '+s+'s)';setTimeout(poll,1500)}})}poll();
        </script></body></html><?php exit;
    }

    // --- PHP: SET DEFAULT CLI ---
    if ($act === 'php_default') {
        $ver = $_POST['version'] ?? '';
        if (!preg_match('/^\d+\.\d+$/', $ver)) { flash('Versi tidak valid!', 'err'); redirect('php'); }
        cmd("update-alternatives --set php /usr/bin/php$ver 2>&1");
        flash("✅ PHP CLI default diubah ke $ver!"); redirect('php');
    }

    // --- PHP: REMOVE VERSION ---
    if ($act === 'php_remove') {
        $ver = $_POST['version'] ?? '';
        if (!preg_match('/^\d+\.\d+$/', $ver)) { flash('Versi tidak valid!', 'err'); redirect('php'); }
        cmd("systemctl stop php$ver-fpm 2>&1; systemctl disable php$ver-fpm 2>&1; apt-get remove -y -qq php$ver-* 2>&1");
        flash("✅ PHP $ver dihapus!"); redirect('php');
    }

    // --- PHP: SWITCH PER SITE ---
    if ($act === 'php_switch_site') {
        $domain = $_POST['domain'] ?? '';
        $ver = $_POST['version'] ?? '';
        if (empty($domain) || !preg_match('/^\d+\.\d+$/', $ver)) { flash('Input tidak valid!', 'err'); redirect('php'); }
        // Validate PHP version is actually installed and socket exists
        if (!file_exists("/usr/bin/php$ver")) { flash("❌ PHP $ver belum terinstall!", 'err'); redirect('php'); }
        $sock = "/var/run/php/php$ver-fpm.sock";
        if (!file_exists($sock)) $sock = "/run/php/php$ver-fpm.sock";
        if (!file_exists($sock)) { flash("❌ PHP $ver FPM socket tidak ditemukan! Jalankan: systemctl start php$ver-fpm", 'err'); redirect('php'); }
        // Ensure FPM is running
        if (trim(cmd("systemctl is-active php$ver-fpm 2>/dev/null")) !== 'active') {
            cmd("systemctl start php$ver-fpm 2>&1");
            sleep(1);
        }
        // Find the nginx config file for this domain
        $cfg_file = null;
        foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
            $cfg = file_get_contents($f);
            if (preg_match('/server_name\s+([^;]+);/', $cfg, $m)) {
                $names = preg_split('/\s+/', trim($m[1]));
                if (in_array($domain, $names)) { $cfg_file = $f; break; }
            }
        }
        if (!$cfg_file) { flash("Config untuk $domain tidak ditemukan!", 'err'); redirect('php'); }
        // Backup config before change
        $cfg_original = file_get_contents($cfg_file);
        $cfg = $cfg_original;
        // Replace any PHP socket with the new version
        $cfg = preg_replace(
            '/unix:(\/var\/run|\/run)\/php\/php[\d.]+-fpm\.sock/',
            "unix:$sock",
            $cfg
        );
        $cfg = preg_replace(
            '/fastcgi_pass\s+127\.0\.0\.1:\d+/',
            "fastcgi_pass unix:$sock",
            $cfg
        );
        file_put_contents($cfg_file, $cfg);
        // Test nginx config - rollback if fails
        $test = cmd("nginx -t 2>&1");
        if (strpos($test, 'successful') === false) {
            file_put_contents($cfg_file, $cfg_original);
            flash("❌ Nginx config error! Dikembalikan ke semula.<br><small>" . nl2br(sanitize(substr($test, 0, 300))) . "</small>", 'err');
            redirect('php');
        }
        cmd("systemctl reload nginx 2>&1");
        flash("✅ <b>$domain</b> sekarang pakai <b>PHP $ver</b>! 🔄"); redirect('php');
    }

    // --- INSTALL ALL (Full LEMP Stack) ---
    if ($act === 'install_all') {
        $log = '';
        $steps = [
            ['name' => 'Nginx', 'pkgs' => 'nginx'],
            ['name' => 'PHP 8.1', 'pkgs' => 'php8.1-fpm php8.1-cli php8.1-mysql php8.1-curl php8.1-gd php8.1-mbstring php8.1-xml php8.1-zip php8.1-intl php8.1-bcmath'],
            ['name' => 'MariaDB', 'pkgs' => 'mariadb-server mariadb-client'],
            ['name' => 'phpMyAdmin', 'pkgs' => 'phpmyadmin'],
            ['name' => 'Certbot (SSL)', 'pkgs' => 'certbot python3-certbot-nginx'],
        ];
        
        cmd("DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>&1");
        foreach ($steps as $step) {
            $r = cmd("DEBIAN_FRONTEND=noninteractive apt-get install -y -qq " . $step['pkgs'] . " 2>&1");
            $log .= "✅ {$step['name']}: installed\n";
        }
        
        // Enable & start services
        cmd("systemctl enable --now nginx 2>&1");
        cmd("systemctl enable --now php8.1-fpm 2>&1");
        cmd("systemctl enable --now mariadb 2>&1");
        cmd("systemctl enable --now certbot.timer 2>&1");
        
        // Link phpMyAdmin to manager panel if panel exists
        if (is_dir('/usr/share/phpmyadmin') && is_dir('/var/www/manager')) {
            symlink('/usr/share/phpmyadmin', '/var/www/manager/pma');
        }
        
        flash("🚀 Full LEMP Stack terinstall!\n$log"); redirect('services');
    }

    // --- SSL: INSTALL CERTBOT ---
    if ($act === 'install_certbot') {
        cmd("DEBIAN_FRONTEND=noninteractive apt-get update -qq && apt-get install -y -qq certbot python3-certbot-nginx 2>&1");
        flash('✅ Certbot terinstall!'); redirect('ssl');
    }

    // --- SSL: INSTALL FOR DOMAIN ---
    if ($act === 'ssl_install') {
        $domain = $_POST['domain'] ?? '';
        $email = $_POST['email'] ?? 'admin@' . $domain;
        if (empty($domain)) { flash('Domain tidak valid!', 'err'); redirect('ssl'); }
        
        // 1. Check DNS
        $dns = trim(cmd("dig +short $domain 2>/dev/null | head -1"));
        if (empty($dns)) $dns = trim(cmd("nslookup $domain 2>/dev/null | grep -o 'Address: .*' | tail -1 | awk '{print \$2}'"));
        $server_ip = trim(cmd("curl -s4 ifconfig.me 2>/dev/null"));
        if (empty($dns) || $dns !== $server_ip) {
            flash("❌ DNS domain <b>$domain</b> belum mengarah ke VPS ini ($server_ip).<br>Setting dulu DNS A record ke $server_ip, tunggu propagate, lalu coba lagi.", 'err');
            redirect('ssl');
        }
        
        // 2. Ensure nginx HTTP config exists + has acme-challenge access
        $cfg_path = NGINX_AVAILABLE . '/' . $domain;
        if (!file_exists($cfg_path)) {
            $root = WWW_ROOT . '/' . $domain;
            if (!is_dir($root)) { mkdir($root, 0755, true); chown($root, 'www-data'); chgrp($root, 'www-data'); }
            if (!is_dir("$root/.well-known/acme-challenge")) mkdir("$root/.well-known/acme-challenge", 0755, true);
            $nginx_conf = "server {\n    listen 80;\n    server_name $domain www.$domain;\n    root $root;\n    index index.html;\n    location ^~ /.well-known/acme-challenge/ { root $root; }\n    location / { try_files \$uri \$uri/ =404; }\n}\n";
            file_put_contents($cfg_path, $nginx_conf);
            symlink($cfg_path, NGINX_ENABLED . '/' . $domain);
        } else {
            // Patch existing config: add acme-challenge before deny rules
            $cfg = file_get_contents($cfg_path);
            if (strpos($cfg, '.well-known/acme-challenge') === false) {
                // Get document root for this config
                preg_match('/root\s+([^;]+);/', $cfg, $rm_existing);
                $acme_docroot = $rm_existing[1] ?? (WWW_ROOT . '/' . $domain);
                // Insert acme-challenge location after server_name line
                $cfg = preg_replace(
                    '/(server_name\s+[^;]+;)/',
                    '\$1' . "\n    location ^~ /.well-known/acme-challenge/ { root $acme_docroot; }",
                    $cfg
                );
                file_put_contents($cfg_path, $cfg);
            }
            // Ensure acme dir exists
            preg_match('/root\s+([^;]+);/', $cfg, $rm);
            $acme_root = ($rm[1] ?? (WWW_ROOT . '/' . $domain)) . '/.well-known/acme-challenge';
            if (!is_dir($acme_root)) mkdir($acme_root, 0755, true);
        }
        cmd("nginx -t 2>&1 && systemctl reload nginx");
        sleep(2);
        
        // 3. Run certbot with live progress
        $task_id = 'ssl_' . substr(md5($domain . time()), 0, 8);
        $logfile = '/tmp/progress_' . $task_id . '.log';
        file_put_contents($logfile, "🔒 Memulai install SSL untuk $domain...\n📡 Menghubungi Let's Encrypt...\n");
        $cmd = "certbot --nginx -d $domain -d www.$domain --non-interactive --agree-tos -m $email --redirect >> $logfile 2>&1; echo '___COMPLETE___' >> $logfile";
        exec("$cmd > /dev/null 2>&1 &");
        
        ?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Installing SSL...</title>
        <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(160deg,#dfd9cf,#f5f0e8);color:#2d3748;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fafaf7;padding:44px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);width:90%;max-width:650px;text-align:center;border-top:4px solid #1e3a5f}.spinner{width:48px;height:48px;border:4px solid #e2ddd5;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}h2{color:#1a365d;margin-bottom:8px;font-weight:700}pre{background:#fdfcfb;padding:16px;border-radius:8px;text-align:left;max-height:350px;overflow:auto;font-family:monospace;font-size:12px;color:#5a6170;line-height:1.6;margin-top:20px;border:1px solid #e2ddd5}.done{display:none;margin-top:16px}.btn-green{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;box-shadow:0 2px 8px rgba(30,58,95,.25)}.btn-green:hover{box-shadow:0 4px 14px rgba(30,58,95,.35)}</style></head><body>
        <div class="box"><div class="spinner" id="spinner"></div>
        <h2 id="title">🔒 Installing SSL...</h2>
        <p style="color:#64748b;font-size:14px">Domain: <b><?= sanitize($domain) ?></b></p>
        <pre id="log">Memulai...</pre>
        <div class="done" id="done"><a href="?page=ssl" class="btn-green">✅ Kembali ke SSL</a></div></div>
        <script>
        var tid='<?= $task_id ?>',check=0;
        function poll(){fetch('?progress='+tid).then(r=>r.text()).then(t=>{document.getElementById('log').textContent=t;if(t.indexOf('___COMPLETE___')>-1){document.getElementById('spinner').style.display='none';var ok=t.indexOf('Congratulations')>-1||t.indexOf('Successfully')>-1;document.getElementById('title').textContent=ok?'✅ SSL Berhasil!':'❌ SSL Gagal';document.getElementById('title').style.color=ok?'#10b981':'#ef4444';document.getElementById('done').style.display='block'}else{check++;document.getElementById('title').textContent='🔒 Installing SSL... ('+Math.ceil(check*1.5)+'s)';setTimeout(poll,1500)}})}poll();
        </script></body></html><?php exit;
    }

    // --- SSL: RENEW MANUAL ---
    if ($act === 'ssl_renew') {
        $out = cmd("certbot renew --non-interactive 2>&1");
        if (strpos($out, 'No renewals') !== false) {
            flash('ℹ️ Semua sertifikat masih valid. Belum perlu renew.');
        } else {
            cmd("nginx -s reload 2>&1");
            flash('✅ SSL renewed!');
        }
        redirect('ssl');
    }

    // --- SSL: ENABLE AUTO-RENEW ---
    if ($act === 'ssl_autorenew') {
        cmd("systemctl enable --now certbot.timer 2>&1");
        flash('✅ Auto-renew SSL diaktifkan! (Cek setiap 12 jam)');
        redirect('ssl');
    }

    // --- FIREWALL: ENABLE/DISABLE ---
    if ($act === 'fw_toggle') {
        $enable = $_POST['enable'] ?? '0';
        if ($enable === '1') {
            cmd("ufw --force enable 2>&1");
            cmd("ufw default deny incoming 2>&1");
            cmd("ufw default allow outgoing 2>&1");
            flash('✅ Firewall diaktifkan! (Default: deny incoming, allow outgoing)');
        } else {
            cmd("ufw disable 2>&1");
            flash('⚠️ Firewall dinonaktifkan!');
        }
        redirect('firewall');
    }

    // --- FIREWALL: ADD RULE ---
    if ($act === 'fw_add') {
        $port = intval($_POST['port'] ?? 0);
        $proto = $_POST['proto'] ?? 'tcp';
        $action = $_POST['fw_action'] ?? 'allow';
        if ($port < 1 || $port > 65535) { flash('Port tidak valid!', 'err'); redirect('firewall'); }
        cmd("ufw $action $port/$proto 2>&1");
        flash("✅ Rule: $action $port/$proto ditambahkan!");
        redirect('firewall');
    }

    // --- FIREWALL: DELETE RULE ---
    if ($act === 'fw_del') {
        $num = intval($_POST['num'] ?? 0);
        if ($num > 0) {
            cmd("yes | ufw delete $num 2>&1");
            flash('✅ Rule dihapus!');
        }
        redirect('firewall');
    }

    // --- CRON: ADD JOB ---
    if ($act === 'cron_add') {
        $expr = $_POST['expr'] ?? '';
        $cmd = $_POST['cmd'] ?? '';
        if (empty($expr) || empty($cmd)) { flash('Cron expression dan command wajib!', 'err'); redirect('cron'); }
        $current = cmd('crontab -l 2>/dev/null') ?: '';
        $new = $current . "$expr $cmd\n";
        file_put_contents('/tmp/crontab_tmp', $new);
        cmd('crontab /tmp/crontab_tmp 2>&1');
        unlink('/tmp/crontab_tmp');
        flash("✅ Cron job ditambahkan!<br><code>$expr $cmd</code>");
        redirect('cron');
    }

    // --- CRON: DELETE JOB ---
    if ($act === 'cron_del') {
        $num = intval($_POST['num'] ?? 0);
        $current = cmd('crontab -l 2>/dev/null') ?: '';
        $lines = explode("\n", trim($current));
        if (isset($lines[$num - 1])) {
            unset($lines[$num - 1]);
            $new = implode("\n", $lines) . "\n";
            file_put_contents('/tmp/crontab_tmp', $new);
            cmd('crontab /tmp/crontab_tmp 2>&1');
            unlink('/tmp/crontab_tmp');
            flash('✅ Cron job dihapus!');
        }
        redirect('cron');
    }

    // --- CHANGE PASSWORD (multi-user) ---
    if ($act === 'change_pass') {
        $old = $_POST['old_pass'] ?? '';
        $new = $_POST['new_pass'] ?? '';
        $confirm = $_POST['confirm_pass'] ?? '';
        $cu = current_user();
        if (!$cu || $cu['pass'] !== $old) { flash('❌ Password lama salah!', 'err'); redirect('settings'); }
        if (strlen($new) < 6) { flash('❌ Password baru minimal 6 karakter!', 'err'); redirect('settings'); }
        if ($new !== $confirm) { flash('❌ Konfirmasi password tidak cocok!', 'err'); redirect('settings'); }
        $users = load_users();
        foreach ($users as &$u) { if ($u['user'] === $cu['user']) { $u['pass'] = $new; $_SESSION['user']['pass'] = $new; break; } }
        save_users($users);
        file_put_contents(PASSWD_FILE, $new); chmod(PASSWD_FILE, 0600);
        flash('✅ Password berhasil diubah!');
        redirect('settings');
    }

    // --- ADD USER (admin only) ---
    if ($act === 'add_user' && is_admin()) {
        $uname = trim($_POST['uname'] ?? '');
        $upass = $_POST['upass'] ?? '';
        $urole = in_array($_POST['urole'] ?? '', ['admin','user']) ? $_POST['urole'] : 'user';
        if (empty($uname) || strlen($upass) < 6) { flash('Username wajib, password min 6 karakter!', 'err'); redirect('users'); }
        $users = load_users();
        foreach ($users as $u) { if ($u['user'] === $uname) { flash('❌ Username sudah ada!', 'err'); redirect('users'); } }
        $users[] = ['user' => $uname, 'pass' => $upass, 'role' => $urole];
        save_users($users);
        flash("✅ User <b>$uname</b> ($urole) ditambahkan!"); redirect('users');
    }

    // --- DELETE USER (admin only) ---
    if ($act === 'del_user' && is_admin()) {
        $uname = trim($_POST['duser'] ?? '');
        $users = load_users();
        if (count($users) <= 1) { flash('❌ Tidak bisa hapus user terakhir!', 'err'); redirect('users'); }
        $users = array_values(array_filter($users, fn($u) => $u['user'] !== $uname));
        save_users($users);
        flash("✅ User <b>$uname</b> dihapus!"); redirect('users');
    }

    // --- OPTIMIZE ---
    if ($act === 'optimize') {
        $target = $_POST['target'] ?? '';
        $ram_mb = intval(trim(cmd("free -m | grep Mem | awk '{print \$2}'")));
        $cpu_cores = intval(trim(cmd('nproc')));
        $backup_dir = '/var/www/manager/backups/optimize/' . date('Ymd_His');
        mkdir($backup_dir, 0755, true);
        
        $optimized = [];
        
        if ($target === 'nginx' || $target === 'all') {
            // Backup nginx config
            copy('/etc/nginx/nginx.conf', "$backup_dir/nginx.conf.bak");
            $nconf = file_get_contents('/etc/nginx/nginx.conf');
            $wconn = $ram_mb >= 2048 ? 4096 : ($ram_mb >= 1024 ? 4096 : 2048);
            $nconf = preg_replace('/worker_connections\s+\d+;/', "worker_connections $wconn;", $nconf);
            if (strpos($nconf, 'worker_processes auto;') === false) {
                $nconf = preg_replace('/worker_processes\s+\d+;/', 'worker_processes auto;', $nconf);
            }
            // Add gzip if missing
            if (strpos($nconf, 'gzip on;') === false) {
                $nconf .= "\n# Gzip compression\ngzip on;\ngzip_vary on;\ngzip_comp_level 5;\ngzip_types text/css application/javascript application/json text/html text/xml image/svg+xml;\ngzip_min_length 256;\n";
            }
            // Add fastcgi cache inside http block
            if (strpos($nconf, 'fastcgi_cache_path') === false) {
                $cache_section = "\tfastcgi_cache_path /var/cache/nginx levels=1:2 keys_zone=WORDPRESS:32m max_size=64m inactive=60m;\n\tfastcgi_cache_key \"\$scheme\$request_method\$host\$request_uri\";\n\tfastcgi_cache_use_stale error timeout invalid_header http_500;\n\tfastcgi_ignore_headers Cache-Control Expires Set-Cookie;\n";
                $nconf = preg_replace('/(http\s*\{)/', "\$1\n$cache_section", $nconf);
                if (!is_dir('/var/cache/nginx')) { mkdir('/var/cache/nginx', 0755, true); chown('/var/cache/nginx', 'www-data'); chgrp('/var/cache/nginx', 'www-data'); }
            }
            file_put_contents('/etc/nginx/nginx.conf', $nconf);
            $optimized[] = 'Nginx';
        }
        
        if ($target === 'php' || $target === 'all') {
            $php_ver = trim(cmd("php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;'"));
            $pool_file = glob("/etc/php/$php_ver/fpm/pool.d/www.conf")[0] ?? '';
            if ($pool_file) {
                copy($pool_file, "$backup_dir/www.conf.bak");
                $pconf = file_get_contents($pool_file);
                $max_children = $ram_mb >= 4096 ? 50 : ($ram_mb >= 2048 ? 35 : ($ram_mb >= 1024 ? 25 : 12));
                $start = max(2, intval($max_children / 4));
                $min_spare = max(1, intval($max_children / 6));
                $max_spare = max(2, intval($max_children / 3));
                $pconf = preg_replace('/^pm\s*=\s*\w+/m', 'pm = ondemand', $pconf);
                $pconf = preg_replace('/^pm\.max_children\s*=\s*\d+/m', "pm.max_children = $max_children", $pconf);
                $pconf = preg_replace('/^pm\.start_servers\s*=\s*\d+/m', "pm.start_servers = $start", $pconf);
                $pconf = preg_replace('/^pm\.min_spare_servers\s*=\s*\d+/m', "pm.min_spare_servers = $min_spare", $pconf);
                $pconf = preg_replace('/^pm\.max_spare_servers\s*=\s*\d+/m', "pm.max_spare_servers = $max_spare", $pconf);
                file_put_contents($pool_file, $pconf);
            }
            // PHP ini - opcache
            $ini_file = "/etc/php/$php_ver/fpm/php.ini";
            copy($ini_file, "$backup_dir/php.ini.bak");
            $ini = file_get_contents($ini_file);
            $ini = preg_replace('/^opcache\.enable\s*=\s*\d/m', 'opcache.enable = 1', $ini);
            if (strpos($ini, 'opcache.enable = 1') === false) $ini .= "\nopcache.enable = 1\n";
            $opcache_mem = $ram_mb >= 2048 ? 256 : ($ram_mb >= 1024 ? 128 : 64);
            $ini = preg_replace('/^opcache\.memory_consumption\s*=\s*\d+/m', "opcache.memory_consumption = $opcache_mem", $ini);
            $ini = preg_replace('/^opcache\.max_accelerated_files\s*=\s*\d+/m', 'opcache.max_accelerated_files = 10000', $ini);
            $ini = preg_replace('/^opcache\.validate_timestamps\s*=\s*\d/m', 'opcache.validate_timestamps = 0', $ini);
            file_put_contents($ini_file, $ini);
            // Also optimize CLI php.ini
            $cli_ini = "/etc/php/$php_ver/cli/php.ini";
            if (file_exists($cli_ini)) {
                copy($cli_ini, "$backup_dir/php-cli.ini.bak");
                $cini = file_get_contents($cli_ini);
                $cini = preg_replace('/memory_limit\s*=\s*\d+M/', 'memory_limit = 256M', $cini);
                file_put_contents($cli_ini, $cini);
            }
            $optimized[] = 'PHP-FPM + OPCache';
        }
        
        if ($target === 'mysql' || $target === 'all') {
            $mconf_file = '/etc/mysql/mariadb.conf.d/50-server.cnf';
            if (!file_exists($mconf_file)) $mconf_file = '/etc/mysql/my.cnf';
            copy($mconf_file, "$backup_dir/" . basename($mconf_file) . '.bak');
            $mconf = file_get_contents($mconf_file);
            $innodb_bp = $ram_mb >= 4096 ? '2048M' : ($ram_mb >= 2048 ? '1024M' : ($ram_mb >= 1024 ? '256M' : '128M'));
            $max_conn = $ram_mb >= 2048 ? 150 : ($ram_mb >= 1024 ? 80 : 40);
            $tuning = "\n# === VPS Manager Tuning ===\ninnodb_buffer_pool_size = $innodb_bp\ninnodb_log_file_size = 64M\ninnodb_flush_method = O_DIRECT\ninnodb_flush_log_at_trx_commit = 2\nmax_connections = $max_conn\nquery_cache_type = 0\ntmp_table_size = 32M\nmax_heap_table_size = 32M\ntable_open_cache = 2000\n";
            if (strpos($mconf, 'VPS Manager Tuning') === false) {
                $mconf .= $tuning;
            }
            file_put_contents($mconf_file, $mconf);
            $optimized[] = 'MySQL';
        }
        
        if ($target === 'system' || $target === 'all') {
            // Sysctl - swappiness, file limits
            copy('/etc/sysctl.conf', "$backup_dir/sysctl.conf.bak");
            $sysctl = file_get_contents('/etc/sysctl.conf');
            $sys_tuning = "\n# === VPS Manager Tuning ===\nvm.swappiness = 10\nvm.vfs_cache_pressure = 50\nnet.core.somaxconn = 1024\nnet.ipv4.tcp_fastopen = 3\nfs.file-max = 65535\n";
            if (strpos($sysctl, 'VPS Manager Tuning') === false) {
                $sysctl .= $sys_tuning;
            }
            file_put_contents('/etc/sysctl.conf', $sysctl);
            cmd('sysctl -p 2>&1');
            // Limits
            $limits = "/etc/security/limits.conf";
            copy($limits, "$backup_dir/limits.conf.bak");
            $lim = file_get_contents($limits);
            if (strpos($lim, 'www-data soft nofile 65535') === false) {
                $lim .= "\nwww-data soft nofile 65535\nwww-data hard nofile 65535\n";
                file_put_contents($limits, $lim);
            }
            $optimized[] = 'System Kernel + Limits';
        }
        
        // Reload services
        cmd('nginx -t 2>&1 && systemctl reload nginx 2>&1');
        cmd('systemctl restart php' . ($php_ver ?? '8.1') . '-fpm 2>&1');
        cmd('systemctl restart mariadb 2>&1');
        
        if (empty($optimized)) { flash('❌ Pilih target optimasi!', 'err'); redirect('optimize'); }
        flash('✅ Optimized: ' . implode(', ', $optimized) . '!<br><small>Backup tersimpan di ' . $backup_dir . '</small>');
        redirect('optimize');
    }

    // --- SERVICE ACTION ---
    if ($act === 'svc_action') {
        $svc = $_POST['svc'] ?? '';
        $action = $_POST['svc_action'] ?? '';
        $map = ['nginx' => 'nginx', 'php' => 'php8.1-fpm', 'mysql' => 'mariadb'];
        $real = $map[$svc] ?? $svc;
        if (in_array($action, ['start', 'stop', 'restart', 'reload'])) {
            cmd("systemctl $action $real");
            flash("✅ $svc: $action berhasil!"); redirect('services');
        }
    }

    // --- DELETE BACKUP ---
    if ($act === 'delete_backup') {
        $f = $_POST['file'] ?? '';
        $fp = BACKUP_DIR . '/' . basename($f);
        if (file_exists($fp)) { unlink($fp); flash('✅ Backup dihapus!'); }
        redirect('backups');
    }

    // --- FILE UPLOAD ---
    if ($act === 'upload_file') {
        $up_dir = $_POST['up_dir'] ?? '';
        $upload_dir = realpath(WWW_ROOT . '/' . ltrim($up_dir, '/'));
        if (!$upload_dir || strpos($upload_dir, realpath(WWW_ROOT)) !== 0) $upload_dir = WWW_ROOT;
        if (!empty($_FILES['upfile']['tmp_name']) && $_FILES['upfile']['error'] === UPLOAD_ERR_OK) {
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['upfile']['name']);
            $dest = $upload_dir . '/' . $name;
            if (move_uploaded_file($_FILES['upfile']['tmp_name'], $dest)) {
                chown($dest, 'www-data'); chgrp($dest, 'www-data');
                flash("✅ File <b>$name</b> berhasil diupload!");
            } else { flash('❌ Gagal upload file!', 'err'); }
        } else { flash('❌ Pilih file dulu!', 'err'); }
        $d = urlencode($up_dir); redirect("files&dir=$d");
    }

    // --- NEW FOLDER ---
    if ($act === 'new_folder') {
        $fd = $_POST['fd_dir'] ?? '';
        $target_dir = realpath(WWW_ROOT . '/' . ltrim($fd, '/'));
        if (!$target_dir || strpos($target_dir, realpath(WWW_ROOT)) !== 0) $target_dir = WWW_ROOT;
        $name = trim(preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['fname'] ?? ''));
        if ($name) {
            $new = $target_dir . '/' . $name;
            if (!file_exists($new)) { mkdir($new, 0755); chown($new, 'www-data'); chgrp($new, 'www-data'); flash("✅ Folder <b>$name</b> dibuat!"); }
            else { flash('❌ Folder sudah ada!', 'err'); }
        }
        $d = urlencode($fd); redirect("files&dir=$d");
    }

    // --- DELETE FOLDER ---
    if ($act === 'delete_folder') {
        $fd = $_POST['fd_dir'] ?? '';
        $target_dir = realpath(WWW_ROOT . '/' . ltrim($fd, '/'));
        if (!$target_dir || strpos($target_dir, realpath(WWW_ROOT)) !== 0) $target_dir = WWW_ROOT;
        $fname = $_POST['fname'] ?? '';
        $fp = $target_dir . '/' . basename($fname);
        if (is_dir($fp) && strpos(realpath($fp), realpath(WWW_ROOT)) === 0) {
            $empty = count(scandir($fp)) <= 2;
            if ($empty) { rmdir($fp); flash("✅ Folder dihapus!"); }
            else { flash('❌ Folder tidak kosong!', 'err'); }
        } else { flash('❌ Tidak bisa menghapus!', 'err'); }
        $d = urlencode($fd); redirect("files&dir=$d");
    }

    // --- ADD REDIRECT ---
    if ($act === 'add_redirect') {
        $domain = trim($_POST['rdomain'] ?? '');
        $from = trim($_POST['rfrom'] ?? '');
        $to = trim($_POST['rto'] ?? '');
        $type = in_array($_POST['rtype'] ?? '', ['301','302']) ? $_POST['rtype'] : '301';
        if (empty($domain) || empty($from) || empty($to)) { flash('Semua field wajib!', 'err'); redirect('redirects'); }
        $conf = '/etc/nginx/conf.d/panel-redirects.conf';
        if (!file_exists($conf)) { file_put_contents($conf, "# VPS Manager Redirects\n"); cmd('chmod 644 ' . $conf); }
        $rule = "\n# $domain | $from -> $to | $type | " . date('Y-m-d H:i') . "\n";
        $rule .= "server {\n    listen 80;\n    server_name $domain;\n";
        if ($from === '/') $rule .= "    return $type $to;\n";
        else $rule .= "    location $from { return $type $to; }\n";
        $rule .= "}\n";
        file_put_contents($conf, file_get_contents($conf) . $rule);
        $test = cmd('nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            $c = file_get_contents($conf);
            $c = str_replace($rule, '', $c);
            file_put_contents($conf, $c);
            flash('❌ Nginx error: ' . sanitize(substr($test, 0, 250)), 'err');
        } else { cmd('systemctl reload nginx'); flash("✅ Redirect <b>$from → $to</b> ($type) untuk <b>$domain</b>!"); }
        redirect('redirects');
    }

    // --- DELETE REDIRECT ---
    if ($act === 'delete_redirect') {
        $num = intval($_POST['rnum'] ?? -1);
        $conf = '/etc/nginx/conf.d/panel-redirects.conf';
        if ($num >= 0 && file_exists($conf)) {
            $blocks = preg_split('/\n(?=# )/', file_get_contents($conf));
            if (isset($blocks[$num])) {
                unset($blocks[$num]);
                file_put_contents($conf, implode("\n", array_values($blocks)));
                cmd('nginx -t 2>&1 && systemctl reload nginx');
                flash('✅ Redirect dihapus!');
            }
        }
        redirect('redirects');
    }

    // --- PURGE CACHE ---
    if ($act === 'purge_cache') {
        $target = $_POST['ctarget'] ?? 'all';
        $cp = '/var/cache/nginx/';
        $deleted = 0;
        if ($target === 'all') {
            $o = cmd("find $cp -type f -delete 2>&1 && echo OK");
            if (strpos($o, 'OK') !== false) $deleted = 1;
        } else {
            $dm = $_POST['cdomain'] ?? '';
            if ($dm && is_dir($cp)) {
                cmd("rm -rf " . escapeshellarg($cp) . " 2>/dev/null");
                $deleted = 1;
            }
        }
        flash($deleted ? '🧹 Cache berhasil dihapus!' : '❌ Tidak ada cache atau cache path tidak ditemukan', $deleted ? 'ok' : 'err');
        redirect('cache');
    }

    // --- INSTALL FAIL2BAN ---
    if ($act === 'install_f2b') {
        cmd('DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>&1');
        cmd('DEBIAN_FRONTEND=noninteractive apt-get install -y -qq fail2ban 2>&1');
        $jl = "/etc/fail2ban/jail.local";
        $jc = "[DEFAULT]\nbantime = 3600\nfindtime = 600\nmaxretry = 5\nignoreip = 127.0.0.1/8\n\n[sshd]\nenabled = true\nport = ssh\nlogpath = %(sshd_log)s\nbackend = %(sshd_backend)s\n\n[nginx-http-auth]\nenabled = true\nlogpath = /var/log/nginx/error.log\nmaxretry = 3\n\n[nginx-botsearch]\nenabled = true\nlogpath = /var/log/nginx/access.log\nmaxretry = 2\n\n[phpmyadmin]\nenabled = true\nlogpath = /var/log/nginx/access.log\nmaxretry = 3\n";
        file_put_contents($jl, $jc);
        $fd2 = '/etc/fail2ban/filter.d';
        if (!is_dir($fd2)) mkdir($fd2, 0755, true);
        file_put_contents("$fd2/phpmyadmin.conf", "[Definition]\nfailregex = ^<HOST>.*(GET|POST).*(pma|phpmyadmin|phpMyAdmin).* 403\nignoreregex =\n");
        cmd('systemctl enable --now fail2ban 2>&1');
        flash('✅ Fail2Ban terinstall! Jails: sshd, nginx-http-auth, nginx-botsearch, phpmyadmin');
        redirect('security');
    }

    // --- FAIL2BAN UNBAN ---

    // --- PHP PER-SITE SETTINGS ---
    if ($act === 'php_per_site_save') {
        $dm = $_POST['ps_domain'] ?? '';
        $settings_file = '/var/www/manager/.site_php.json';
        $all = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
        $all[$dm] = [
            'upload_max_filesize' => $_POST['ps_upload'] ?? '20M',
            'post_max_size' => $_POST['ps_post'] ?? '25M',
            'max_execution_time' => intval($_POST['ps_exec'] ?? 120),
            'memory_limit' => $_POST['ps_memory'] ?? '256M',
            'display_errors' => $_POST['ps_errors'] ?? 'Off',
            'max_input_vars' => intval($_POST['ps_vars'] ?? 3000),
        ];
        file_put_contents($settings_file, json_encode($all, JSON_PRETTY_PRINT));
        // Apply to site root
        $sites_all = [];
        foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
            $cn = basename($f); if ($cn === 'default') continue;
            $cfg = file_get_contents($f);
            preg_match('/server_name\s+([^;]+);/', $cfg, $sn);
            if (strpos($sn[1] ?? '', $dm) !== false) {
                preg_match('/root\s+([^;]+);/', $cfg, $rm);
                $rt = $rm[1] ?? (WWW_ROOT . '/' . $dm);
                $ini = "upload_max_filesize = {$all[$dm]['upload_max_filesize']}\npost_max_size = {$all[$dm]['post_max_size']}\nmax_execution_time = {$all[$dm]['max_execution_time']}\nmemory_limit = {$all[$dm]['memory_limit']}\ndisplay_errors = {$all[$dm]['display_errors']}\nmax_input_vars = {$all[$dm]['max_input_vars']}\n";
                file_put_contents("$rt/.user.ini", $ini);
                break;
            }
        }
        flash("✅ PHP settings untuk <b>$dm</b> disimpan! Restart PHP-FPM untuk apply.");
        redirect('phpsite');
    }

    // --- CACHE PER-SITE ENABLE/DISABLE ---
    if ($act === 'cache_toggle_site') {
        $dm = $_POST['cd_domain'] ?? '';
        $enable = ($_POST['cd_enable'] ?? '0') === '1';
        $cache_conf = '/etc/nginx/conf.d/cache-per-site.conf';
        $rules = file_exists($cache_conf) ? file_get_contents($cache_conf) : '';
        if ($enable) {
            if (strpos($rules, "# cache:$dm") === false) {
                $rules .= "\n# cache:$dm\nset \$skip_cache 0;\nif (\$host = $dm) { set \$skip_cache 0; }\n";
                file_put_contents($cache_conf, $rules);
            }
        } else {
            $rules = preg_replace("/# cache:$dm.*?(?=# cache:|\$)/s", '', $rules);
            file_put_contents($cache_conf, $rules);
        }
        cmd('nginx -t 2>&1 && systemctl reload nginx');
        flash("✅ Cache " . ($enable ? 'diaktifkan' : 'dinonaktifkan') . " untuk <b>$dm</b>!");
        redirect('cache');
    }

    // --- CLOUD BACKUP ---
    if ($act === 'cloud_backup') {
        $dm = $_POST['cb_domain'] ?? '';
        if (empty($dm)) { flash('Pilih website!', 'err'); redirect('cloudbackup'); }
        $ts = date('Ymd_His');
        $bf = BACKUP_DIR . "/{$dm}_$ts.zip";
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        $root = WWW_ROOT . '/' . $dm;
        if (!is_dir($root)) $root = WWW_ROOT . '/' . $dm;
        $exclude = is_dir("$root/uploads") ? "-x '$root/uploads/*'" : '';
        cmd("cd " . escapeshellarg(dirname($root)) . " && zip -rq " . escapeshellarg($bf) . " " . escapeshellarg(basename($root)) . " $exclude 2>&1");
        $sz = file_exists($bf) ? round(filesize($bf) / 1024, 1) : 0;
        // Try rclone sync if configured (prefer mega, then gdrive, then first remote)
        $rclone_msg = '';
        $rclone = is_executable('/usr/bin/rclone');
        $rclone_remotes = $rclone ? trim(cmd('rclone listremotes 2>/dev/null')) : '';
        if ($rclone && $rclone_remotes) {
            $rlist = array_map('trim', explode("\n", trim($rclone_remotes)));
            // Priority: mega first
            $remote = in_array('mega:', $rlist) ? 'mega:' : (in_array('gdrive:', $rlist) ? 'gdrive:' : $rlist[0]);
            if ($remote) {
                $sync = cmd("rclone copy " . escapeshellarg($bf) . " " . escapeshellarg($remote . 'vps-backups/') . " 2>&1");
                $rclone_msg = " + Synced ke cloud: $remote";
            }
        }
        flash("✅ Backup {$sz}KB untuk <b>$dm</b> selesai!$rclone_msg"); redirect('cloudbackup');
    }

    // --- INSTALL RCLONE ---
    if ($act === 'install_rclone') {
        cmd('curl -s https://rclone.org/install.sh | bash 2>&1');
        mkdir('/root/.config/rclone', 0700, true);
        flash('✅ Rclone terinstall! Silakan setup Google Drive di bawah.');
        redirect('cloudbackup');
    }

    // --- RCLONE GDRIVE SETUP STEP 1: Get auth URL ---
    if ($act === 'rclone_gdrive_step1') {
        $cid = trim($_POST['gdrive_cid'] ?? '');
        $csec = trim($_POST['gdrive_csec'] ?? '');
        if (empty($cid) || empty($csec)) { flash('❌ Client ID & Secret wajib!', 'err'); redirect('cloudbackup'); }
        $_SESSION['rclone_cid'] = $cid;
        $_SESSION['rclone_csec'] = $csec;
        // Generate auth URL - rclone outputs the URL then waits for token
        $cmd = "echo '' | timeout 3 rclone authorize \"drive\" \"$cid\" \"$csec\" --auth-no-open-browser 2>&1 || true";
        $out = cmd($cmd);
        preg_match('/(https:\/\/accounts\.google\.com\/o\/oauth2\/auth[^\s]+)/', $out, $m);
        $_SESSION['rclone_auth_url'] = $m[1] ?? '';
        if (empty($_SESSION['rclone_auth_url'])) {
            // Try alternative parsing
            preg_match('/(https:\/\/[^\s]+accounts\.google\.com[^\s]+)/', $out, $m2);
            $_SESSION['rclone_auth_url'] = $m2[1] ?? '';
        }
        flash('🔗 Auth URL generated! Ikuti langkah selanjutnya.');
        redirect('cloudbackup');
    }

    // --- RCLONE GDRIVE SETUP STEP 2: Exchange token ---
    if ($act === 'rclone_gdrive_step2') {
        $code = trim($_POST['gdrive_code'] ?? '');
        $cid = $_SESSION['rclone_cid'] ?? '';
        $csec = $_SESSION['rclone_csec'] ?? '';
        if (empty($code) || empty($cid)) { flash('❌ Verification code wajib!', 'err'); redirect('cloudbackup'); }
        // Exchange code for token via rclone
        $cmd = "echo '" . addslashes($code) . "' | rclone authorize \"drive\" \"$cid\" \"$csec\" --auth-no-open-browser 2>&1";
        $out = cmd($cmd);
        // Parse the token JSON from rclone output
        preg_match('/\{[^{]*"access_token"[^}]+\}/s', $out, $tm);
        $token_json = $tm[0] ?? '';
        if (empty($token_json)) {
            // Try broader match
            preg_match('/\{.*?"access_token".*?\}/s', $out, $tm2);
            $token_json = $tm2[0] ?? '';
        }
        if (empty($token_json)) {
            flash('❌ Gagal exchange token!<br><small>' . sanitize(substr($out, 0, 300)) . '</small>', 'err');
            redirect('cloudbackup');
        }
        // Create rclone config
        $rclone_conf = "/root/.config/rclone/rclone.conf";
        if (!is_dir(dirname($rclone_conf))) mkdir(dirname($rclone_conf), 0700, true);
        $conf = "[gdrive]\ntype = drive\nscope = drive\nclient_id = $cid\nclient_secret = $csec\ntoken = " . addslashes($token_json) . "\n";
        file_put_contents($rclone_conf, $conf);
        chmod($rclone_conf, 0600);
        // Test connection
        $test = trim(cmd('rclone about gdrive: 2>&1'));
        if (strpos($test, 'Used:') !== false || strpos($test, 'Total:') !== false) {
            flash('✅ Google Drive berhasil terkoneksi! 🎉<br><small>' . sanitize(substr($test, 0, 200)) . '</small>');
        } else {
            flash('⚠️ Config tersimpan, tapi test gagal: <small>' . sanitize(substr($test, 0, 200)) . '</small>');
        }
        unset($_SESSION['rclone_cid'], $_SESSION['rclone_csec'], $_SESSION['rclone_auth_url']);
        redirect('cloudbackup');
    }

    // --- RCLONE MEGA CONNECT ---
    if ($act === 'rclone_mega_connect') {
        $email = trim($_POST['mega_email'] ?? '');
        $pass = $_POST['mega_pass'] ?? '';
        if (empty($email) || empty($pass)) { flash('❌ Email & password wajib!', 'err'); redirect('cloudbackup'); }
        $rconf = '/root/.config/rclone/rclone.conf';
        if (!is_dir(dirname($rconf))) mkdir(dirname($rconf), 0700, true);
        $out = cmd('rclone config create mega mega user=' . escapeshellarg($email) . ' pass=' . escapeshellarg($pass) . ' 2>&1');
        if (strpos($out, 'Already have') !== false || strpos($out, 'Storage') !== false || strpos($out, 'Used') !== false) {
            $about = trim(cmd('rclone about mega: 2>&1'));
            flash('✅ Mega.nz berhasil terkoneksi! 🎉<br><small>' . sanitize(substr($about, 0, 200)) . '</small>');
        } else {
            flash('❌ Gagal konek Mega: <small>' . sanitize(substr($out, 0, 300)) . '</small>', 'err');
        }
        redirect('cloudbackup');
    }

    // --- RCLONE REMOVE REMOTE ---
    if ($act === 'rclone_remove') {
        $remote = trim($_POST['rr_remote'] ?? '');
        if ($remote) {
            $conf = "/root/.config/rclone/rclone.conf";
            if (file_exists($conf)) {
                $c = file_get_contents($conf);
                // Remove the [remote] section
                $c = preg_replace('/\[' . preg_quote(rtrim($remote, ':'), '/') . '\].*?(?=\[|$)/s', '', $c);
                file_put_contents($conf, trim($c) . "\n");
                flash("✅ Remote <b>$remote</b> dihapus!");
            }
        }
        redirect('cloudbackup');
    }

    // --- DNS: ADD RECORD ---
    if ($act === 'dns_add') {
        $zone = trim($_POST['d_zone'] ?? '');
        $name = trim($_POST['d_name'] ?? '');
        $type = strtoupper(trim($_POST['d_type'] ?? 'A'));
        $content = trim($_POST['d_content'] ?? '');
        $ttl = intval($_POST['d_ttl'] ?? 120);
        $token = trim($_POST['d_token'] ?? '');
        if (empty($zone) || empty($name) || empty($content)) { flash('Field wajib diisi!', 'err'); redirect('dns'); }
        // Save token
        if ($token) file_put_contents('/var/www/manager/.cf_token', $token);
        else $token = file_exists('/var/www/manager/.cf_token') ? trim(file_get_contents('/var/www/manager/.cf_token')) : '';
        if (!$token) { flash('❌ Cloudflare API Token diperlukan!', 'err'); redirect('dns'); }
        // Get zone ID
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones?name=$zone");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json']]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $zone_id = $resp['result'][0]['id'] ?? '';
        if (!$zone_id) { flash('❌ Zone tidak ditemukan! Cek domain & token.', 'err'); redirect('dns'); }
        // Create record
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl, 'proxied' => false])
        ]);
        $resp2 = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if ($resp2['success'] ?? false) flash("✅ DNS record <b>$name $type $content</b> ditambahkan!");
        else flash('❌ Gagal: ' . ($resp2['errors'][0]['message'] ?? 'Unknown'), 'err');
        redirect('dns');
    }

    // --- DNS: DELETE RECORD ---
    if ($act === 'dns_del') {
        $zone = trim($_POST['d_zone'] ?? '');
        $rec_id = trim($_POST['d_recid'] ?? '');
        $token = file_exists('/var/www/manager/.cf_token') ? trim(file_get_contents('/var/www/manager/.cf_token')) : '';
        if (!$token || !$zone || !$rec_id) { flash('Invalid!', 'err'); redirect('dns'); }
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones?name=$zone");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json']]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $zone_id = $resp['result'][0]['id'] ?? '';
        if (!$zone_id) { flash('Zone tidak ditemukan!', 'err'); redirect('dns'); }
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records/$rec_id");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]]);
        $resp2 = json_decode(curl_exec($ch), true);
        curl_close($ch);
        flash(($resp2['success'] ?? false) ? '✅ DNS record dihapus!' : ('❌ ' . ($resp2['errors'][0]['message'] ?? 'Error')));
        redirect('dns');
    }

    if ($act === 'unban_ip') {
        $ip = trim($_POST['uip'] ?? '');
        $jail = trim($_POST['ujail'] ?? '');
        if ($ip && $jail) {
            cmd("fail2ban-client set $jail unbanip $ip 2>&1");
            flash("✅ IP <b>$ip</b> di-unban dari <b>$jail</b>");
        } else { flash('❌ Isi IP dan jail!', 'err'); }
        redirect('security');
    }

    // --- UPTIME CHECK NOW ---
    if ($act === 'uptime_check') {
        // We need $sites from later in the file, but the POST handler runs before that
        // Re-gather sites here
        $_sites = [];
        foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
            $cn = basename($f); if ($cn === 'default') continue;
            $cfg = file_get_contents($f);
            preg_match('/server_name\s+([^;]+);/', $cfg, $sn);
            $dms = preg_split('/\s+/', trim($sn[1] ?? $cn));
            $dm = $dms[0];
            $_sites[$dm] = ['domain' => $dm, 'ssl' => strpos($cfg, 'ssl_certificate') !== false];
        }
        $up = 0; $total = count($_sites);
        foreach ($_sites as $dm => $s) {
            $url = ($s['ssl'] ? 'https' : 'http') . '://' . $dm;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code > 0 && $code < 500) $up++;
            file_put_contents('/var/www/manager/uptime.log', date('Y-m-d H:i:s') . "|$dm|$code\n", FILE_APPEND);
        }
        flash("✅ Check selesai: <b>$up/$total</b> sites UP");
        redirect('uptime');
    }

    // --- UPTIME CLEAR LOG ---
    if ($act === 'clear_uptime') {
        file_put_contents('/var/www/manager/uptime.log', '');
        flash('✅ Log uptime direset!');
        redirect('uptime');
    }

}

    // --- MIGRATION: CREATE FULL BACKUP ---
    if ($act === 'migration_backup') {
        $include_ssl = isset($_POST['include_ssl']) && $_POST['include_ssl'] === '1';
        $ts = date('Ymd_His');
        $hostname = trim(cmd('hostname'));
        $bfname = "migration-VPS-{$hostname}-{$ts}";
        $migdir = MIGRATION_DIR;
        if (!is_dir($migdir)) mkdir($migdir, 0755, true);
        
        $task_id = 'migbackup_' . $ts;
        $logfile = '/tmp/progress_' . $task_id . '.log';
        $tmpdir = '/tmp/' . $bfname;
        mkdir($tmpdir, 0755, true);
        mkdir("$tmpdir/www", 0755, true);
        mkdir("$tmpdir/nginx", 0755, true);
        mkdir("$tmpdir/panel", 0755, true);
        mkdir("$tmpdir/ssl", 0755, true);
        
        file_put_contents($logfile, "Memulai System Migration Backup...\nHostname: $hostname\nTanggal: " . date('Y-m-d H:i:s') . "\n");
        
        $cmd = "(";
        // 1. System info
        $cmd .= "echo '📊 Mengumpulkan system info...' >> $logfile; ";
        $sysinfo = [];
        $sysinfo['hostname'] = trim(cmd('hostname'));
        $sysinfo['date'] = date('Y-m-d H:i:s');
        $sysinfo['panel_version'] = '1.0';
        $sysinfo['os'] = trim(cmd("lsb_release -d 2>/dev/null | cut -f2") ?: cmd("cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d '\"'"));
        $sysinfo['kernel'] = trim(cmd('uname -r'));
        $sysinfo['cpu'] = trim(cmd("grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2"));
        $sysinfo['memory'] = trim(cmd("free -h | grep '^Mem:' | awk '{print \$2}'"));
        $sysinfo['disk_total'] = disk_total();
        $sysinfo['disk_free'] = disk_free();
        $sysinfo['php_version'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        file_put_contents("$tmpdir/system_info.json", json_encode($sysinfo, JSON_PRETTY_PRINT));
        $cmd .= "echo '✅ System info collected' >> $logfile; ";
        
        // 2. MySQL dump --all-databases
        $cmd .= "echo '🗄️ Dumping semua database MySQL...' >> $logfile; ";
        $cmd .= "mysqldump -u root --all-databases --events --routines --triggers > $tmpdir/all_databases.sql 2>> $logfile; ";
        $cmd .= "echo '✅ Database dump selesai' >> $logfile; ";
        
        // 3. Website files (exclude manager)
        $cmd .= "echo '🌐 Menyalin website files...' >> $logfile; ";
        $www_dirs = glob('/var/www/*');
        if (is_array($www_dirs)) {
            foreach ($www_dirs as $d) {
                if (!is_dir($d)) continue;
                $bn = basename($d);
                if ($bn === 'manager') continue;
                $cmd .= "cp -a " . escapeshellarg($d) . " $tmpdir/www/ >> $logfile 2>&1; ";
            }
        }
        $cmd .= "echo '✅ Website files disalin' >> $logfile; ";
        
        // 4. Nginx configs
        $cmd .= "echo '🔧 Menyalin Nginx configs...' >> $logfile; ";
        $cmd .= "cp -a /etc/nginx/sites-available/* $tmpdir/nginx/ 2>/dev/null; ";
        $cmd .= "cp -a /etc/nginx/nginx.conf $tmpdir/nginx/ 2>/dev/null; ";
        $cmd .= "[ -d /etc/nginx/conf.d ] && cp -a /etc/nginx/conf.d $tmpdir/nginx/conf.d 2>/dev/null; ";
        $cmd .= "echo '✅ Nginx configs disalin' >> $logfile; ";
        
        // 5. Panel settings
        $cmd .= "echo '⚙️ Menyalin panel settings...' >> $logfile; ";
        $cmd .= "cp /var/www/manager/.users $tmpdir/panel/ 2>/dev/null; ";
        $cmd .= "cp /var/www/manager/.passwd $tmpdir/panel/ 2>/dev/null; ";
        $cmd .= "cp /var/www/manager/.site_php.json $tmpdir/panel/ 2>/dev/null; ";
        $cmd .= "cp /var/www/manager/.cf_token $tmpdir/panel/ 2>/dev/null; ";
        $cmd .= "cp /var/www/manager/uptime.log $tmpdir/panel/ 2>/dev/null; ";
        $cmd .= "echo '✅ Panel settings disalin' >> $logfile; ";
        
        // 6. SSL certificates (optional)
        if ($include_ssl) {
            $cmd .= "echo '🔒 Menyalin SSL certificates (ini bisa lama)...' >> $logfile; ";
            $cmd .= "[ -d /etc/letsencrypt ] && cp -a /etc/letsencrypt $tmpdir/ssl/letsencrypt 2>> $logfile; ";
            $cmd .= "echo '✅ SSL certificates disalin' >> $logfile; ";
        } else {
            $cmd .= "echo '⏭️ SSL certificates di-skip (opsional)' >> $logfile; ";
        }
        
        // 7. Cron jobs
        $cmd .= "echo '⏰ Menyalin cron jobs...' >> $logfile; ";
        $cmd .= "crontab -l > $tmpdir/crontab.txt 2>/dev/null; ";
        $cmd .= "echo '✅ Cron jobs disalin' >> $logfile; ";
        
        // 8. UFW rules
        $cmd .= "echo '🛡 Menyalin UFW rules...' >> $logfile; ";
        $cmd .= "ufw status numbered > $tmpdir/ufw_rules.txt 2>/dev/null; ";
        $cmd .= "echo '✅ UFW rules disalin' >> $logfile; ";
        
        // 9. PHP versions info
        $cmd .= "echo '🐘 Mengumpulkan PHP versions info...' >> $logfile; ";
        $phpinfo = [];
        foreach (glob('/usr/bin/php[0-9]*') as $p) {
            if (preg_match('/php([\d.]+)$/', basename($p), $m)) {
                $pv = $m[1];
                $fpmok = svc_status("php$pv-fpm") ? 'running' : 'stopped';
                $phpinfo[] = ['version' => $pv, 'fpm' => $fpmok, 'path' => $p];
            }
        }
        $default_php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $phpall = ['default' => $default_php, 'versions' => $phpinfo];
        file_put_contents("$tmpdir/php_versions.json", json_encode($phpall, JSON_PRETTY_PRINT));
        $cmd .= "echo '✅ PHP versions info collected' >> $logfile; ";
        
        // 10. Create tar.gz
        $cmd .= "echo '📦 Membuat arsip tar.gz...' >> $logfile; ";
        $tgz = MIGRATION_DIR . '/' . $bfname . '.tar.gz';
        $cmd .= "cd /tmp && tar czf " . escapeshellarg($tgz) . " " . escapeshellarg($bfname) . " 2>> $logfile; ";
        $cmd .= "rm -rf $tmpdir; ";
        $cmd .= "echo '___COMPLETE___' >> $logfile; ";
        $cmd .= ") > /dev/null 2>&1 &";
        exec($cmd);
        
        $_SESSION['mig_task_id'] = $task_id;
        $_SESSION['mig_backup_file'] = $bfname . '.tar.gz';
        
        ?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Creating Backup...</title>
        <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(160deg,#dfd9cf,#f5f0e8);color:#2d3748;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fafaf7;padding:44px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);width:90%;max-width:650px;text-align:center;border-top:4px solid #1e3a5f}.spinner{width:48px;height:48px;border:4px solid #e2ddd5;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}h2{color:#1a365d;margin-bottom:8px;font-weight:700}pre{background:#fdfcfb;padding:16px;border-radius:8px;text-align:left;max-height:350px;overflow:auto;font-family:monospace;font-size:12px;color:#5a6170;line-height:1.6;margin-top:20px;border:1px solid #e2ddd5}.progress-bar{width:100%;height:10px;background:#e2ddd5;border-radius:99px;overflow:hidden;margin-top:16px}.progress-fill{height:100%;background:linear-gradient(90deg,#1e3a5f,#2c5282);border-radius:99px;width:0%;transition:width .5s}.done{display:none;margin-top:16px}.btn-green2{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;box-shadow:0 2px 8px rgba(30,58,95,.25)}.btn-green2:hover{box-shadow:0 4px 14px rgba(30,58,95,.35)}</style></head><body>
        <div class="box"><div class="spinner" id="spinner"></div>
        <h2 id="title">Creating System Backup...</h2>
        <p style="color:#64748b;font-size:14px">File: <b><?= $bfname ?>.tar.gz</b></p>
        <div class="progress-bar"><div class="progress-fill" id="pfill"></div></div>
        <pre id="log">Memulai...</pre>
        <div class="done" id="done"><a href="?page=migration" class="btn-green2">Kembali ke Migration</a></div></div>
        <script>
        var tid='<?= $task_id ?>',check=0,steps=10;
        function poll(){fetch('?progress='+tid).then(r=>r.text()).then(t=>{document.getElementById('log').textContent=t;var done=t.indexOf('___COMPLETE___')>-1;var pct=done?100:Math.min(((t.match(/✅/g)||[]).length/steps)*100,95);document.getElementById('pfill').style.width=pct+'%';if(done){document.getElementById('spinner').style.display='none';document.getElementById('title').textContent='Backup Selesai!';document.getElementById('done').style.display='block'}else{check++;document.getElementById('title').textContent='Creating Backup... ('+Math.ceil(check*1.5)+'s)';setTimeout(poll,1500)}})}poll();
        </script></body></html><?php exit;
    }

    // --- MIGRATION: RESTORE ---
    if ($act === 'migration_restore') {
        $restore_ssl = isset($_POST['restore_ssl']) && $_POST['restore_ssl'] === '1';
        $restore_cron = isset($_POST['restore_cron']) && $_POST['restore_cron'] === '1';
        $restore_ufw = isset($_POST['restore_ufw']) && $_POST['restore_ufw'] === '1';
        $restore_panel = isset($_POST['restore_panel']) && $_POST['restore_panel'] === '1';
        
        if (empty($_FILES['migfile']['tmp_name']) || $_FILES['migfile']['error'] !== UPLOAD_ERR_OK) {
            flash('Upload file backup dulu!', 'err'); redirect('migration');
        }
        
        $ts = date('Ymd_His');
        $task_id = 'migrestore_' . $ts;
        $logfile = '/tmp/progress_' . $task_id . '.log';
        
        $uploaded = '/tmp/migrestore_' . $ts . '.tar.gz';
        move_uploaded_file($_FILES['migfile']['tmp_name'], $uploaded);
        
        $extract_dir = '/tmp/migrestore_extract_' . $ts;
        mkdir($extract_dir, 0755, true);
        
        file_put_contents($logfile, "Memulai System Migration Restore...\n" . date('Y-m-d H:i:s') . "\nMengextract backup...\n");
        
        $cmd = "(";
        // 1. Extract
        $cmd .= "cd /tmp && tar xzf " . escapeshellarg($uploaded) . " -C " . escapeshellarg($extract_dir) . " 2>> $logfile; ";
        $cmd .= "echo '✅ Backup diextract' >> $logfile; ";
        
        // Find the first subdirectory
        $cmd .= 'BD=$(ls -d ' . escapeshellarg($extract_dir) . '/*/ 2>/dev/null | head -1); ';
        $cmd .= '[ -z "$BD" ] && BD=' . escapeshellarg($extract_dir) . '/; ';
        
        // 2. Check system_info
        $cmd .= "echo '📊 Verifikasi kompatibilitas...' >> $logfile; ";
        $cmd .= 'if [ -f "$BD/system_info.json" ]; then echo "✅ System info ditemukan" >> ' . escapeshellarg($logfile) . '; else echo "⚠ Tidak ada system info" >> ' . escapeshellarg($logfile) . '; fi; ';
        
        // 3. Import MySQL
        $cmd .= "echo '🗄 Import database MySQL...' >> $logfile; ";
        $cmd .= 'if [ -f "$BD/all_databases.sql" ]; then mysql -u root < "$BD/all_databases.sql" 2>> ' . escapeshellarg($logfile) . ' && echo "✅ Database diimport" >> ' . escapeshellarg($logfile) . ' || echo "⚠ Database import error" >> ' . escapeshellarg($logfile) . '; else echo "⏭ Tidak ada database dump" >> ' . escapeshellarg($logfile) . '; fi; ';
        
        // 4. Restore websites  
        $cmd .= "echo '🌐 Merestore websites...' >> $logfile; ";
        $cmd .= 'if [ -d "$BD/www" ]; then for d in "$BD/www"/*/; do DN=$(basename "$d"); if [ "$DN" != "manager" ]; then cp -a "$d" /var/www/ 2>> ' . escapeshellarg($logfile) . ' && chown -R www-data:www-data "/var/www/$DN" 2>/dev/null; fi; done; echo "✅ Websites direstore" >> ' . escapeshellarg($logfile) . '; else echo "⏭ Tidak ada website data" >> ' . escapeshellarg($logfile) . '; fi; ';
        
        // 5. Restore Nginx configs
        $cmd .= "echo '🔧 Merestore Nginx configs...' >> $logfile; ";
        $cmd .= 'if [ -d "$BD/nginx" ]; then ';
        $cmd .= 'ls "$BD/nginx/"* 2>/dev/null | while read f; do BN=$(basename "$f"); ';
        $cmd .= 'if [ "$BN" != "conf.d" ] && [ -f "$f" ]; then ';
        $cmd .= 'cp "$f" "/etc/nginx/sites-available/$BN" 2>> ' . escapeshellarg($logfile) . '; ';
        $cmd .= 'if echo "$BN" | grep -qEv "^default$"; then ln -sf "/etc/nginx/sites-available/$BN" "/etc/nginx/sites-enabled/$BN" 2>/dev/null; fi; fi; done; ';
        $cmd .= 'if [ -d "$BD/nginx/conf.d" ]; then cp -a "$BD/nginx/conf.d/"* /etc/nginx/conf.d/ 2>/dev/null; fi; ';
        $cmd .= 'fi; ';
        $cmd .= "echo '✅ Nginx configs direstore' >> $logfile; ";
        $cmd .= "nginx -t >> $logfile 2>&1 && systemctl reload nginx 2>> $logfile; ";
        $cmd .= "echo '✅ Nginx tested & reloaded' >> $logfile; ";
        
        // 6. Restore panel settings
        if ($restore_panel) {
            $cmd .= "echo '⚙ Merestore panel settings...' >> $logfile; ";
            $cmd .= 'if [ -d "$BD/panel" ]; then cp "$BD/panel/".* /var/www/manager/ 2>> ' . escapeshellarg($logfile) . '; ';
            $cmd .= 'cp "$BD/panel/"* /var/www/manager/ 2>/dev/null; ';
            $cmd .= "chmod 600 /var/www/manager/.passwd /var/www/manager/.users 2>/dev/null; fi; ";
            $cmd .= "echo '✅ Panel settings direstore' >> $logfile; ";
        } else {
            $cmd .= "echo '⏭ Panel settings di-skip' >> $logfile; ";
        }
        
        // 7. Restore SSL
        if ($restore_ssl) {
            $cmd .= "echo '🔒 Merestore SSL certificates...' >> $logfile; ";
            $cmd .= 'if [ -d "$BD/ssl/letsencrypt" ]; then cp -a "$BD/ssl/letsencrypt/" /etc/ 2>> ' . escapeshellarg($logfile) . '; ';
            $cmd .= "echo '✅ SSL certificates direstore' >> $logfile; else echo '⏭ Tidak ada SSL data' >> $logfile; fi; ";
        } else {
            $cmd .= "echo '⏭ SSL certificates di-skip' >> $logfile; ";
        }
        
        // 8. Restore cron
        if ($restore_cron) {
            $cmd .= "echo '⏰ Merestore cron jobs...' >> $logfile; ";
            $cmd .= 'if [ -f "$BD/crontab.txt" ]; then crontab "$BD/crontab.txt" 2>> ' . escapeshellarg($logfile) . ' && echo "✅ Cron jobs direstore" >> ' . escapeshellarg($logfile) . ' || echo "⚠ Cron restore error" >> ' . escapeshellarg($logfile) . '; else echo "⏭ Tidak ada cron data" >> ' . escapeshellarg($logfile) . '; fi; ';
        } else {
            $cmd .= "echo '⏭ Cron jobs di-skip' >> $logfile; ";
        }
        
        // 9. Restore UFW
        if ($restore_ufw) {
            $cmd .= "echo '🛡 Merestore UFW rules...' >> $logfile; ";
            $cmd .= 'if [ -f "$BD/ufw_rules.txt" ]; then echo "⚠ UFW rules tidak di-restore otomatis untuk keamanan. Backup tersedia." >> ' . escapeshellarg($logfile) . '; fi; ';
        } else {
            $cmd .= "echo '⏭ UFW rules di-skip' >> $logfile; ";
        }
        
        // Cleanup
        $cmd .= "rm -rf " . escapeshellarg($extract_dir) . " " . escapeshellarg($uploaded) . " 2>/dev/null; ";
        $cmd .= "echo '___COMPLETE___' >> $logfile; ";
        $cmd .= ") > /dev/null 2>&1 &";
        exec($cmd);
        
        $_SESSION['mig_restore_task'] = $task_id;
        
        ?><!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Restoring Backup...</title>
        <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(160deg,#dfd9cf,#f5f0e8);color:#2d3748;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fafaf7;padding:44px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.1);width:90%;max-width:650px;text-align:center;border-top:4px solid #1e3a5f}.spinner{width:48px;height:48px;border:4px solid #e2ddd5;border-top:4px solid #1e3a5f;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px}@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}h2{color:#1a365d;margin-bottom:8px;font-weight:700}pre{background:#fdfcfb;padding:16px;border-radius:8px;text-align:left;max-height:350px;overflow:auto;font-family:monospace;font-size:12px;color:#5a6170;line-height:1.6;margin-top:20px;border:1px solid #e2ddd5}.progress-bar{width:100%;height:10px;background:#e2ddd5;border-radius:99px;overflow:hidden;margin-top:16px}.progress-fill{height:100%;background:linear-gradient(90deg,#1e3a5f,#2c5282);border-radius:99px;width:0%;transition:width .5s}.done{display:none;margin-top:16px}.btn-green2{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;box-shadow:0 2px 8px rgba(30,58,95,.25)}.btn-green2:hover{box-shadow:0 4px 14px rgba(30,58,95,.35)}</style></head><body>
        <div class="box"><div class="spinner" id="spinner"></div>
        <h2 id="title">Restoring System Backup...</h2>
        <p style="color:#64748b;font-size:14px">Jangan tutup halaman ini</p>
        <div class="progress-bar"><div class="progress-fill" id="pfill"></div></div>
        <pre id="log">Memulai...</pre>
        <div class="done" id="done"><a href="?page=migration" class="btn-green2">Kembali ke Migration</a></div></div>
        <script>
        var tid='<?= $task_id ?>',check=0,steps=10;
        function poll(){fetch('?progress='+tid).then(r=>r.text()).then(t=>{document.getElementById('log').textContent=t;var done=t.indexOf('___COMPLETE___')>-1;var pct=done?100:Math.min(((t.match(/✅/g)||[]).length/steps)*100,95);document.getElementById('pfill').style.width=pct+'%';if(done){document.getElementById('spinner').style.display='none';document.getElementById('title').textContent='Restore Selesai!';document.getElementById('done').style.display='block'}else{check++;document.getElementById('title').textContent='Restoring... ('+Math.ceil(check*1.5)+'s)';setTimeout(poll,1500)}})}poll();
        </script></body></html><?php exit;
    }

    // --- MIGRATION: DELETE BACKUP ---
    if ($act === 'migration_delete') {
        $f = $_POST['file'] ?? '';
        $fp = MIGRATION_DIR . '/' . basename($f);
        if (file_exists($fp)) { unlink($fp); flash('Migration backup dihapus!'); }
        else { flash('File tidak ditemukan!', 'err'); }
        redirect('migration');
    }

$flash = get_flash();
$msg = $flash ? "<div class='{$flash['type']}'>" . flash_html($flash['msg']) . "</div>" : '';

// ===== SYSTEM INFO =====
$nginx_ok = svc_status('nginx');
$mysql_ok = svc_status('mariadb');
$php_ok = svc_status('php8.1-fpm');
$uptime = trim(cmd('uptime -p'));
$load = sys_getloadavg();
$mem_info = cmd("free -h | grep '^Mem:' | awk '{print $3\"/\"$2}'");
$disk_pct = round(100 - (disk_free() / disk_total() * 100));

// Get websites list (parse domain from server_name, not filename)
$sites = [];
$sites_by_cfg = [];
foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
    $cfg_name = basename($f);
    if ($cfg_name === 'default') continue;
    $enabled = file_exists(NGINX_ENABLED . "/$cfg_name");
    $cfg = file_get_contents($f);
    
    // Extract primary domain from server_name
    preg_match('/server_name\s+([^;]+);/', $cfg, $sn);
    $server_names = $sn[1] ?? $cfg_name;
    $domains = preg_split('/\s+/', trim($server_names));
    $domain = $domains[0];
    
    // Extract document root
    preg_match('/root\s+([^;]+);/', $cfg, $rm);
    $root = $rm[1] ?? (WWW_ROOT . '/' . $domain);
    
    $ssl = strpos($cfg, 'ssl_certificate') !== false;
    $root_exists = is_dir($root);
    
    $sites[$domain] = [
        'domain' => $domain,
        'cfg_name' => $cfg_name,
        'enabled' => $enabled,
        'root' => $root,
        'root_exists' => $root_exists,
        'ssl' => $ssl
    ];
    $sites_by_cfg[$cfg_name] = $domain;
}
ksort($sites);

// Get databases
$dbs = [];
$raw = cmd("mysql -u root -e 'SHOW DATABASES;' -N 2>/dev/null");
foreach (explode("\n", $raw) as $db) {
    $db = trim($db);
    if ($db && !in_array($db, ['information_schema', 'performance_schema', 'mysql', 'sys', 'Database'])) {
        $dbs[] = $db;
    }
}
sort($dbs);

// Get backups
$backups = [];
if (is_dir(BACKUP_DIR)) {
    foreach (glob(BACKUP_DIR . '/*.{zip,sql,gz,tar}', GLOB_BRACE) as $f) {
        $backups[] = ['name' => basename($f), 'size' => round(filesize($f) / 1024, 1), 'time' => filemtime($f)];
    }
}
usort($backups, fn($a, $b) => $b['time'] - $a['time']);

// PHP versions
$php_versions = [];
foreach (glob('/usr/bin/php[0-9]*') as $p) {
    $v = basename($p);
    if (preg_match('/^php(\d+\.\d+)$/', $v, $m)) $php_versions[] = $m[1];
}
if (empty($php_versions)) $php_versions = ['8.1'];

// ===== PAGE CONTENT FUNCTIONS =====
function nav_link($p, $icon, $label, $current) {
    $active = $current === $p ? ' active' : '';
    return "<a href='?page=$p' class='nav-link$active'>$icon $label</a>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VPS Manager</title>
<style>
:root {
  --bg: #f5f0e8; --surface: #fafaf7; --surface2: #f0ebe0;
  --border: #e2ddd5; --text: #2d3748; --text2: #718096;
  --green: #1e3a5f; --blue: #2c5282; --red: #c53030; --yellow: #b7791f;
  --primary: #1e3a5f; --primary-light: #2c5282; --primary-dark: #1a365d;
  --radius: 10px; --shadow-sm: 0 1px 3px rgba(0,0,0,.06); --shadow: 0 4px 16px rgba(0,0,0,.08); --shadow-lg: 0 8px 32px rgba(0,0,0,.12);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(180deg,#ebe5d9 0%,#f5f0e8 100%);color:var(--text);min-height:100vh}
a{color:var(--blue);text-decoration:none;transition:color .15s}a:hover{color:var(--primary-dark)}
.topbar{background:linear-gradient(135deg,#1a365d,#1e3a5f,#2c5282);padding:14px 28px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;box-shadow:0 2px 12px rgba(26,54,93,.25);position:sticky;top:0;z-index:100}
.topbar .logo{font-size:19px;font-weight:800;color:#fff;letter-spacing:-.3px}
.topbar .sysinfo{font-size:11px;color:rgba(255,255,255,.8);display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.topbar .sysinfo span{padding:4px 12px;background:rgba(255,255,255,.12);border-radius:99px;color:#fff;backdrop-filter:blur(4px)}
.topbar .sysinfo .dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px}
.dot-ok{background:#68d391;box-shadow:0 0 6px rgba(104,211,145,.4)}.dot-err{background:#fc8181;box-shadow:0 0 6px rgba(252,129,129,.4)}
.layout{display:flex;min-height:calc(100vh - 61px)}
.sidebar{width:230px;background:var(--surface);padding:20px 10px;border-right:1px solid var(--border);flex-shrink:0;box-shadow:2px 0 12px rgba(0,0,0,.04)}
.sidebar .nav-section{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:#a0a5b0;padding:18px 14px 8px;font-weight:700}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 14px;color:#5a6170;border-radius:8px;margin-bottom:2px;font-size:13px;transition:all .2s;text-decoration:none;font-weight:500}
.nav-link:hover{background:#eae3d6;color:#1a365d;transform:translateX(2px)}
.nav-link.active{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;font-weight:600;box-shadow:0 2px 8px rgba(30,58,95,.3)}
.content{flex:1;padding:28px 32px;overflow:auto}
.card{background:var(--surface);border-radius:var(--radius);padding:22px;margin-bottom:18px;border:1px solid var(--border);box-shadow:var(--shadow-sm);transition:box-shadow .2s}
.card:hover{box-shadow:var(--shadow)}
.card h2{font-size:16px;margin-bottom:14px;color:var(--primary);display:flex;align-items:center;gap:8px;font-weight:700}
.card h3{font-size:14px;margin-bottom:12px;color:var(--text);font-weight:600}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:14px;margin-bottom:22px}
.stat{background:var(--surface);padding:22px 18px;border-radius:var(--radius);border:1px solid var(--border);text-align:center;box-shadow:var(--shadow-sm);transition:all .25s;cursor:default}
.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.stat .val{font-size:28px;font-weight:800;color:var(--primary)}
.stat .val.warn{color:var(--yellow)}.stat .val.danger{color:var(--red)}
.stat .lbl{font-size:11px;color:var(--text2);margin-top:6px;text-transform:uppercase;letter-spacing:.5px;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:12px 14px;background:var(--surface2);color:var(--text2);font-weight:700;border-bottom:2px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.5px}
td{padding:11px 14px;border-bottom:1px solid var(--border)}
tr:hover td{background:#efe9db}
.btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s;letter-spacing:.2px}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.14)}
.btn:active{transform:translateY(0)}
.btn-sm{padding:5px 11px;font-size:11px;border-radius:6px}.btn-xs{padding:3px 9px;font-size:10px;border-radius:5px}
.btn-green{background:linear-gradient(135deg,#1e3a5f,#2c5282);color:#fff;box-shadow:0 2px 6px rgba(30,58,95,.25)}.btn-green:hover{background:linear-gradient(135deg,#1a365d,#2c5282);box-shadow:0 4px 14px rgba(30,58,95,.35)}
.btn-blue{background:#2c5282;color:#fff}.btn-blue:hover{background:#1e3a5f}
.btn-red{background:#c53030;color:#fff}.btn-red:hover{background:#9b2c2c}
.btn-yellow{background:#b7791f;color:#fff}.btn-yellow:hover{background:#975a16}
.btn-gray{background:#eae3d6;color:#5a6170;border:1px solid #d5cec0}.btn-gray:hover{background:#ddd5c5}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.ok{background:#f0f5ee;color:#2d5016;padding:14px 18px;border-radius:8px;margin-bottom:16px;font-size:13px;border-left:4px solid #5a9e3e}
.err{background:#fdf2f2;color:#822727;padding:14px 18px;border-radius:8px;margin-bottom:16px;font-size:13px;border-left:4px solid #c53030}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px;outline:none;transition:all .2s}
.form-group input:focus,.form-group select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(30,58,95,.08)}
.form-group small{font-size:11px;color:var(--text2)}
.help{font-size:11px;color:var(--text2);margin-top:4px}
.badge{display:inline-block;padding:3px 12px;border-radius:99px;font-size:10px;font-weight:700;letter-spacing:.3px}
.badge-ok{background:#e3eed9;color:#2d5016}.badge-err{background:#fde8e8;color:#822727}
.badge-blue{background:#dce4f0;color:#1a365d}.badge-yellow{background:#f5eedb;color:#6b4d14}
.service-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
.service-card{background:var(--surface);border-radius:var(--radius);padding:24px;border:1px solid var(--border);text-align:center;box-shadow:var(--shadow-sm);transition:all .25s}
.service-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.service-card .svc-icon{font-size:40px;margin-bottom:10px}
.service-card .svc-name{font-weight:700;margin-bottom:4px;font-size:15px}
.service-card .svc-status{font-size:12px;margin-bottom:14px}
.dashed{background:var(--bg);border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;color:var(--text2);cursor:pointer;transition:all 0.2s}
.dashed:hover{border-color:var(--primary);color:var(--text)}
@media(max-width:768px){.layout{flex-direction:column}.sidebar{width:100%;padding:8px;display:flex;flex-wrap:wrap;gap:4px;box-shadow:none}.sidebar .nav-section{display:none}.nav-link{font-size:12px;padding:6px 10px;margin:0}.content{padding:16px}}
</style>
</head>
<body>

<div class="topbar">
  <div class="logo"><span style="font-family:monospace;font-size:7px;display:block;line-height:1.15;color:#c8a96e;margin-bottom:2px">▄████████▄
██▀▌░░░░▐▀██
█▌░░▄████▄░░▐█
█░░▐█▌░░▐█▌░░█
▐▌░░████████░▐▌
▐▌░░░░▀▀▀▀░░░▐▌
█▄░░░░▐▌░░░▄█
▀█▄▄▄▄▄▄▄█▀</span>VPS Manager</div>
  <div class="sysinfo">
    <span><span class="dot <?= $nginx_ok?'dot-ok':'dot-err' ?>"></span>Nginx</span>
    <span><span class="dot <?= $mysql_ok?'dot-ok':'dot-err' ?>"></span>MySQL</span>
    <span><span class="dot <?= $php_ok?'dot-ok':'dot-err' ?>"></span>PHP</span>
    <span>💾 <?= disk_free() ?>GB</span>
    <span>⏱ <?= $uptime ?></span>
    <a href="?logout" class="btn btn-red btn-sm" style="margin-left:8px">🚪</a>
          <span style="background:rgba(255,255,255,.2)">👤 <?= sanitize(current_user()['user'] ?? 'admin') ?> (<?= is_admin() ? 'Admin' : 'User' ?>)</span>
  </div>
</div>

<div class="layout">
<div class="sidebar">
  <div class="nav-section">Main</div>
  <?= nav_link('dashboard', '📊', 'Dashboard', $page) ?>
  <?= nav_link('websites', '🌐', 'Websites', $page) ?>
  <?= nav_link('databases', '🗄️', 'Databases', $page) ?>
  <a href="/pma/" target="_blank" class="nav-link">🛢️ phpMyAdmin</a>
  <div class="nav-section">Management</div>
  <?= nav_link('backups', '💾', 'Backup & Restore', $page) ?>
  <?= nav_link('services', '⚙️', 'Services', $page) ?>
  <?= nav_link('php', '🐘', 'PHP', $page) ?>
  <?= nav_link('ssl', '🔒', 'SSL', $page) ?>
  <?= nav_link('monitor', '📊', 'Monitor', $page) ?>
  <?= nav_link('firewall', '🛡️', 'Firewall', $page) ?>
  <?= nav_link('files', '📁', 'File Manager', $page) ?>
  <?= nav_link('logs', '📝', 'Logs', $page) ?>
  <?= nav_link('cron', '⏰', 'Cron Jobs', $page) ?>
  <?= nav_link('optimize', '🚀', 'Optimize', $page) ?>
  <?= nav_link('redirects', '🔄', 'Redirects', $page) ?>
  <?= nav_link('cache', '🧹', 'Cache', $page) ?>
  <?= nav_link('uptime', '📡', 'Uptime', $page) ?>
  <?= nav_link('security', '🛡️', 'Security', $page) ?>
  <?= nav_link('cloudbackup', '☁️', 'Cloud Backup', $page) ?>
  <?= nav_link('migration', '🔄', 'Migration', $page) ?>
  <?= nav_link('terminal', '💻', 'Terminal', $page) ?>
  <?= nav_link('phpsite', '⚡', 'PHP per Site', $page) ?>
  <?= nav_link('dns', '🌍', 'DNS', $page) ?>
  <?php if (is_admin()): ?><?= nav_link('users', '👥', 'Users', $page) ?><?php endif; ?>
  <div class="nav-section">System</div>
  <?= nav_link('settings', '⚙️', 'Settings', $page) ?>
</div>

<div class="content">
<?= $msg ?>

<?php
// ===== DASHBOARD =====
if ($page === 'dashboard'):
?>
<div class="stats">
  <div class="stat"><div class="val <?= $disk_pct>80?'danger':($disk_pct>60?'warn':'') ?>"><?= disk_free() ?> GB</div><div class="lbl">Disk Free (<?= disk_total() ?> GB)</div></div>
  <div class="stat"><div class="val"><?= round($load[0], 2) ?></div><div class="lbl">CPU Load (1m)</div></div>
  <div class="stat"><div class="val"><?= count($sites) ?></div><div class="lbl">Websites</div></div>
  <div class="stat"><div class="val"><?= count($dbs) ?></div><div class="lbl">Databases</div></div>
  <div class="stat"><div class="val"><?= count($backups) ?></div><div class="lbl">Backups</div></div>
  <div class="stat"><div class="val" style="font-size:16px"><?= $uptime ?></div><div class="lbl">System Uptime</div></div>
</div>

<div class="service-grid">
  <div class="service-card">
    <div class="svc-icon">🌐</div>
    <div class="svc-name">Nginx</div>
    <div class="svc-status"><span class="badge <?= $nginx_ok?'badge-ok':'badge-err' ?>"><?= $nginx_ok?'Running':'Stopped' ?></span></div>
    <?php if($nginx_ok): ?><span style="font-size:12px;color:var(--text2)"><?= trim(cmd("nginx -v 2>&1")) ?></span><?php endif; ?>
  </div>
  <div class="service-card">
    <div class="svc-icon">🗄️</div>
    <div class="svc-name">MySQL / MariaDB</div>
    <div class="svc-status"><span class="badge <?= $mysql_ok?'badge-ok':'badge-err' ?>"><?= $mysql_ok?'Running':'Stopped' ?></span></div>
    <?php if($mysql_ok): ?><span style="font-size:12px;color:var(--text2)"><?= trim(cmd("mysql -V 2>/dev/null | head -1")) ?></span><?php endif; ?>
  </div>
  <div class="service-card">
    <div class="svc-icon">🐘</div>
    <div class="svc-name">PHP-FPM</div>
    <div class="svc-status"><span class="badge <?= $php_ok?'badge-ok':'badge-err' ?>"><?= $php_ok?'Running':'Stopped' ?></span></div>
    <?php if($php_ok): ?><span style="font-size:12px;color:var(--text2)"><?= trim(cmd("php -v 2>/dev/null | head -1")) ?></span><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>📋 Quick Actions</h2>
  <div class="actions">
    <a href="?page=websites" class="btn btn-green">➕ New Website</a>
    <a href="?page=databases" class="btn btn-blue">🗄️ New Database</a>
    <a href="?page=backups" class="btn btn-yellow">💾 Create Backup</a>
    <a href="?page=services" class="btn btn-gray">⚙️ Manage Services</a>
  </div>
</div>

<?php if(!empty($sites)): ?>
<div class="card">
  <h2>🌐 Active Websites</h2>
  <table>
    <thead><tr><th>Domain</th><th>Status</th><th>SSL</th><th>Root</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach(array_slice($sites, 0, 10) as $s): ?>
    <tr>
      <td><strong><?= sanitize($s['domain']) ?></strong></td>
      <td><span class="badge <?= $s['enabled']?'badge-ok':'badge-err' ?>"><?= $s['enabled']?'Active':'Disabled' ?></span></td>
      <td><?= $s['ssl']?'🔒 SSL':'—' ?></td>
      <td style="font-size:12px;color:var(--text2)"><?= sanitize($s['root']) ?></td>
      <td>
        <a href="http://<?= $s['domain'] ?>" target="_blank" class="btn btn-blue btn-xs">🔗 Visit</a>
        <?php if($s['root_exists']): ?>
        <a href="?page=backups" class="btn btn-yellow btn-xs">💾 Backup</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
// ===== WEBSITES =====
elseif ($page === 'websites'):
?>
<div class="actions">
  <button onclick="showModal('createSiteModal')" class="btn btn-green">➕ Create Website</button>
  <button onclick="showModal('deleteSiteModal')" class="btn btn-red">🗑️ Delete Site</button>
</div>

<div class="card">
  <h2>🌐 All Websites</h2>
  <?php if(empty($sites)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada website. Klik "Create Website" untuk membuat.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Domain</th><th>Status</th><th>SSL</th><th>Document Root</th><th>Folder Exists</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($sites as $s): $exists = $s['root_exists']; ?>
    <tr>
      <td><strong><?= sanitize($s['domain']) ?></strong></td>
      <td><span class="badge <?= $s['enabled']?'badge-ok':'badge-err' ?>"><?= $s['enabled']?'Active':'Disabled' ?></span></td>
      <td><?= $s['ssl']?'🔒 SSL':'—' ?></td>
      <td style="font-size:12px;color:var(--text2)"><?= sanitize($s['root']) ?></td>
      <td><?= $exists ? '✅' : '❌' ?></td>
      <td>
        <a href="http://<?= $s['domain'] ?>" target="_blank" class="btn btn-blue btn-xs">🔗</a>
        <a href="?page=backups" class="btn btn-yellow btn-xs">💾</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus Nginx config untuk <?= sanitize($s['domain']) ?>? File tidak akan dihapus.')">
          <input type="hidden" name="action" value="delete_site">
          <input type="hidden" name="domain" value="<?= sanitize($s['domain']) ?>">
          <button class="btn btn-red btn-xs">✕</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Create Site Modal -->
<div id="createSiteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:flex-start;justify-content:center;padding-top:5vh;overflow-y:auto" onclick="if(event.target===this)hideModal('createSiteModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:650px;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,0.5);max-height:90vh;overflow-y:auto">
    <div style="padding:24px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <h3 style="font-size:18px;color:var(--green)">➕ Create New Website</h3>
      <button onclick="hideModal('createSiteModal')" style="background:none;border:none;color:var(--text2);font-size:24px;cursor:pointer">&times;</button>
    </div>
    <form method="POST" style="padding:24px">
      <input type="hidden" name="action" value="create_site">
      <div class="form-grid">
        <div class="form-group">
          <label>Domain *</label>
          <input name="domain" placeholder="contoh: mysite.com" required>
          <small>Tanpa http/https, cukup domain saja</small>
        </div>
        <div class="form-group">
          <label>PHP Version</label>
          <select name="php_ver">
            <?php foreach($php_versions as $v): ?><option value="<?= $v ?>" <?= $v==='8.1'?'selected':'' ?>>PHP <?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div style="margin:20px 0;padding:16px;background:var(--bg);border-radius:8px;display:flex;align-items:center;gap:12px">
        <input type="checkbox" name="create_db" id="create_db" checked style="width:18px;height:18px">
        <label for="create_db" style="font-weight:600">🗄️ Create Database</label>
      </div>
      
      <div class="form-grid" id="dbFields">
        <div class="form-group">
          <label>Database Name</label>
          <input name="dbname" placeholder="auto: dari domain">
          <small>Kosongkan untuk auto-generate dari domain</small>
        </div>
        <div class="form-group">
          <label>DB Username</label>
          <input name="dbuser" placeholder="auto: dari db name">
        </div>
        <div class="form-group">
          <label>DB Password</label>
          <input name="dbpass" placeholder="auto: random 16 chars">
        </div>
      </div>
      
      <div style="margin:20px 0;padding:16px;background:var(--bg);border-radius:8px;display:flex;align-items:center;gap:12px">
        <input type="checkbox" name="install_wp" id="install_wp" style="width:18px;height:18px">
        <label for="install_wp" style="font-weight:600">📦 Auto-install WordPress (requires database)</label>
      </div>
      
      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px">
        <button type="button" onclick="hideModal('createSiteModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-green">🚀 Create Website</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Site Modal -->
<div id="deleteSiteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('deleteSiteModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:400px;border:1px solid var(--border);padding:24px">
    <h3 style="margin-bottom:16px">🗑️ Delete Nginx Config</h3>
    <form method="POST">
      <input type="hidden" name="action" value="delete_site">
      <div class="form-group" style="margin-bottom:16px">
        <label>Domain</label>
        <select name="domain" style="width:100%;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px">
          <?php foreach($sites as $s): ?><option value="<?= sanitize($s['domain']) ?>"><?= sanitize($s['domain']) ?></option><?php endforeach; ?>
        </select>
        <small style="color:var(--red)">Hanya hapus Nginx config, file website TIDAK dihapus</small>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="hideModal('deleteSiteModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-red" onclick="return confirm('Yakin hapus config?')">🗑️ Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
document.getElementById('create_db').addEventListener('change', function() {
  document.getElementById('dbFields').style.opacity = this.checked ? '1' : '0.4';
  document.getElementById('install_wp').disabled = !this.checked;
});
</script>

<?php
// ===== DATABASES =====
elseif ($page === 'databases'):
?>
<div class="actions">
  <button onclick="showModal('createDbModal')" class="btn btn-green">🗄️ Create Database</button>
</div>

<div class="card">
  <h2>🗄️ Databases (<?= count($dbs) ?>)</h2>
  <?php if(empty($dbs)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada database user.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Database</th><th>Tables</th><th>Size</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($dbs as $db):
      $tbl_count = trim(cmd("mysql -u root -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$db\";' -N 2>/dev/null") ?: '0');
      $db_size = trim(cmd("mysql -u root -e \"SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables WHERE table_schema='$db';\" -N 2>/dev/null") ?: '0');
    ?>
    <tr>
      <td><strong>🗄️ <?= sanitize($db) ?></strong></td>
      <td><?= $tbl_count ?> tables</td>
      <td><?= $db_size ?> MB</td>
      <td>
        <a href="?page=backups&db=<?= urlencode($db) ?>" class="btn btn-yellow btn-xs">💾 Backup</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('HAPUS database <?= sanitize($db) ?>? Data tidak bisa dikembalikan!')">
          <input type="hidden" name="action" value="delete_db">
          <input type="hidden" name="dbname" value="<?= sanitize($db) ?>">
          <button class="btn btn-red btn-xs">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Create DB Modal -->
<div id="createDbModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('createDbModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:450px;border:1px solid var(--border);padding:24px">
    <h3 style="font-size:18px;color:var(--green);margin-bottom:20px">🗄️ Create Database</h3>
    <form method="POST">
      <input type="hidden" name="action" value="create_db">
      <div class="form-group" style="margin-bottom:12px">
        <label>Database Name *</label>
        <input name="dbname" placeholder="my_database" required>
        <small>Hanya huruf, angka, underscore</small>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Username</label>
        <input name="dbuser" placeholder="auto: dbname_user">
      </div>
      <div class="form-group" style="margin-bottom:20px">
        <label>Password</label>
        <input name="dbpass" placeholder="auto: random password">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="hideModal('createDbModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-green">Create Database</button>
      </div>
    </form>
  </div>
</div>

<script>
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<?php
// ===== BACKUPS =====
elseif ($page === 'backups'):
?>
<div class="actions">
  <button onclick="showModal('backupSiteModal')" class="btn btn-yellow">💾 Backup Website</button>
  <button onclick="showModal('restoreSiteModal')" class="btn btn-green">📥 Restore Website</button>
</div>

<div class="card">
  <h2>📦 Backup History (<?= count($backups) ?>)</h2>
  <?php if(empty($backups)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada backup. Pilih website untuk dibackup.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>File</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($backups as $b): ?>
    <tr>
      <td><strong><?= sanitize($b['name']) ?></strong></td>
      <td><?= $b['size'] ?> KB</td>
      <td style="font-size:12px;color:var(--text2)"><?= date('d/m/Y H:i', $b['time']) ?></td>
      <td>
        <a href="/backups/<?= urlencode($b['name']) ?>" download class="btn btn-blue btn-xs">⬇️ Download</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus backup?')">
          <input type="hidden" name="action" value="delete_backup">
          <input type="hidden" name="file" value="<?= sanitize($b['name']) ?>">
          <button class="btn btn-red btn-xs">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Backup Modal -->
<div id="backupSiteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('backupSiteModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:450px;border:1px solid var(--border);padding:24px">
    <h3 style="font-size:18px;color:var(--yellow);margin-bottom:20px">💾 Backup Website</h3>
    <form method="POST">
      <input type="hidden" name="action" value="backup_site">
      <div class="form-group" style="margin-bottom:20px">
        <label>Select Website</label>
        <select name="domain" style="width:100%;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px">
          <?php foreach($sites as $s): if($s['root_exists']): ?>
            <option value="<?= sanitize($s['domain']) ?>"><?= sanitize($s['domain']) ?></option>
          <?php endif; endforeach; ?>
        </select>
        <small>Backup file website + database (jika terdeteksi)</small>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="hideModal('backupSiteModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-yellow">💾 Create Backup</button>
      </div>
    </form>
  </div>
</div>

<!-- Restore Modal -->
<div id="restoreSiteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:flex-start;justify-content:center;padding-top:5vh;overflow-y:auto" onclick="if(event.target===this)hideModal('restoreSiteModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:550px;border:1px solid var(--border);padding:24px;max-height:90vh;overflow-y:auto">
    <h3 style="font-size:18px;color:var(--green);margin-bottom:20px">📥 Restore Website</h3>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="restore_site">
      <div class="form-group" style="margin-bottom:14px">
        <label>Target Domain *</label>
        <input name="restore_domain" placeholder="mysite.com" required>
        <small>Nginx config akan dibuat otomatis jika belum ada</small>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label>Upload ZIP (Website Files)</label>
        <input type="file" name="zipfile" accept=".zip" style="padding:8px">
        <small>Optional: upload backup .zip website</small>
      </div>
      <div class="form-group" style="margin-bottom:20px">
        <label>Upload SQL (Database)</label>
        <input type="file" name="sqlfile" accept=".sql" style="padding:8px">
        <small>Optional: upload backup .sql database</small>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="hideModal('restoreSiteModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-green">📥 Restore</button>
      </div>
    </form>
  </div>
</div>

<script>
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<?php
// ===== SERVICES =====
elseif ($page === 'services'):
  $nginx_installed = pkg_installed('nginx');
  $php_installed = pkg_installed('php8.1-fpm');
  $mysql_installed = pkg_installed('mariadb-server');
  $pma_installed = is_dir('/usr/share/phpmyadmin');
  $all_installed = $nginx_installed && $php_installed && $mysql_installed && $pma_installed;
?>

<?php if(!$all_installed): ?>
<div class="card" style="border:2px solid var(--primary);background:linear-gradient(135deg,#dce4f0,#eae3d6)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <h2 style="color:#1a365d;margin-bottom:4px">🚀 VPS Belum Siap?</h2>
      <p style="color:#94a3b8;font-size:13px">Install semua yang dibutuhkan dalam satu klik: Nginx, PHP 8.1, MariaDB, phpMyAdmin</p>
      <p style="color:#fbbf24;font-size:12px;margin-top:4px">⚠️ Proses install memakan 2-5 menit, tergantung kecepatan internet VPS</p>
    </div>
    <form method="POST" onsubmit="return confirm('Install semua service? Proses 2-5 menit.')">
      <input type="hidden" name="action" value="install_all">
      <button class="btn btn-green" style="font-size:16px;padding:14px 28px;white-space:nowrap">⚡ Install All (LEMP Stack)</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>⚙️ Service Status & Control</h2>
</div>

<div class="service-grid">
  <!-- Nginx -->
  <div class="service-card">
    <div class="svc-icon">🌐</div>
    <div class="svc-name">Nginx Web Server</div>
    <small style="color:var(--text2);display:block;margin-bottom:8px">v1.18+ | Reverse proxy & static files</small>
    <?php if($nginx_installed): ?>
      <div class="svc-status"><span class="badge <?= $nginx_ok?'badge-ok':'badge-err' ?>"><?= $nginx_ok?'🟢 Running':'🔴 Stopped' ?></span></div>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
        <?php if(!$nginx_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="nginx"><input type="hidden" name="svc_action" value="start"><button class="btn btn-green btn-sm">▶ Start</button></form><?php endif; ?>
        <?php if($nginx_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="nginx"><input type="hidden" name="svc_action" value="stop"><button class="btn btn-red btn-sm">⏹ Stop</button></form><?php endif; ?>
        <form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="nginx"><input type="hidden" name="svc_action" value="restart"><button class="btn btn-yellow btn-sm">🔄 Restart</button></form>
        <form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="nginx"><input type="hidden" name="svc_action" value="reload"><button class="btn btn-blue btn-sm">↻ Reload</button></form>
      </div>
    <?php else: ?>
      <div class="svc-status"><span class="badge badge-err">❌ Not Installed</span></div>
      <form method="POST"><input type="hidden" name="action" value="install_svc"><input type="hidden" name="svc" value="nginx"><button class="btn btn-green btn-sm">📦 Install Nginx</button></form>
    <?php endif; ?>
  </div>

  <!-- PHP -->
  <div class="service-card">
    <div class="svc-icon">🐘</div>
    <div class="svc-name">PHP 8.1-FPM</div>
    <small style="color:var(--text2);display:block;margin-bottom:8px">mysql, curl, gd, mbstring, xml, zip, intl</small>
    <?php if($php_installed): ?>
      <div class="svc-status"><span class="badge <?= $php_ok?'badge-ok':'badge-err' ?>"><?= $php_ok?'🟢 Running':'🔴 Stopped' ?></span></div>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
        <?php if(!$php_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="php"><input type="hidden" name="svc_action" value="start"><button class="btn btn-green btn-sm">▶ Start</button></form><?php endif; ?>
        <?php if($php_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="php"><input type="hidden" name="svc_action" value="stop"><button class="btn btn-red btn-sm">⏹ Stop</button></form><?php endif; ?>
        <form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="php"><input type="hidden" name="svc_action" value="restart"><button class="btn btn-yellow btn-sm">🔄 Restart</button></form>
      </div>
    <?php else: ?>
      <div class="svc-status"><span class="badge badge-err">❌ Not Installed</span></div>
      <form method="POST"><input type="hidden" name="action" value="install_svc"><input type="hidden" name="svc" value="php"><button class="btn btn-green btn-sm">📦 Install PHP 8.1</button></form>
    <?php endif; ?>
  </div>

  <!-- MySQL -->
  <div class="service-card">
    <div class="svc-icon">🗄️</div>
    <div class="svc-name">MariaDB / MySQL</div>
    <small style="color:var(--text2);display:block;margin-bottom:8px">Database server v10.6+</small>
    <?php if($mysql_installed): ?>
      <div class="svc-status"><span class="badge <?= $mysql_ok?'badge-ok':'badge-err' ?>"><?= $mysql_ok?'🟢 Running':'🔴 Stopped' ?></span></div>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
        <?php if(!$mysql_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="mysql"><input type="hidden" name="svc_action" value="start"><button class="btn btn-green btn-sm">▶ Start</button></form><?php endif; ?>
        <?php if($mysql_ok): ?><form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="mysql"><input type="hidden" name="svc_action" value="stop"><button class="btn btn-red btn-sm">⏹ Stop</button></form><?php endif; ?>
        <form method="POST"><input type="hidden" name="action" value="svc_action"><input type="hidden" name="svc" value="mysql"><input type="hidden" name="svc_action" value="restart"><button class="btn btn-yellow btn-sm">🔄 Restart</button></form>
      </div>
    <?php else: ?>
      <div class="svc-status"><span class="badge badge-err">❌ Not Installed</span></div>
      <form method="POST"><input type="hidden" name="action" value="install_svc"><input type="hidden" name="svc" value="mysql"><button class="btn btn-green btn-sm">📦 Install MariaDB</button></form>
    <?php endif; ?>
  </div>

  <!-- phpMyAdmin -->
  <div class="service-card">
    <div class="svc-icon">🛢️</div>
    <div class="svc-name">phpMyAdmin</div>
    <small style="color:var(--text2);display:block;margin-bottom:8px">Database management via web UI</small>
    <?php if($pma_installed): ?>
      <div class="svc-status"><span class="badge badge-ok">✅ Installed</span></div>
      <a href="/pma/" target="_blank" class="btn btn-blue btn-sm">🔗 Buka phpMyAdmin</a>
    <?php else: ?>
      <div class="svc-status"><span class="badge badge-err">❌ Not Installed</span></div>
      <form method="POST"><input type="hidden" name="action" value="install_svc"><input type="hidden" name="svc" value="phpmyadmin"><button class="btn btn-green btn-sm">📦 Install phpMyAdmin</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:20px">
  <h2>📋 System Info</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;font-size:13px">
    <div><strong style="color:var(--text2)">OS:</strong> <?= trim(cmd('lsb_release -d 2>/dev/null | cut -f2') ?: cmd('cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\'')) ?></div>
    <div><strong style="color:var(--text2)">Kernel:</strong> <?= trim(cmd('uname -r')) ?></div>
    <div><strong style="color:var(--text2)">CPU:</strong> <?= trim(cmd("grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2")) ?></div>
    <div><strong style="color:var(--text2)">Memory:</strong> <?= trim($mem_info) ?></div>
    <div><strong style="color:var(--text2)">Disk:</strong> <?= disk_free() ?>GB free / <?= disk_total() ?>GB</div>
    <div><strong style="color:var(--text2)">Uptime:</strong> <?= $uptime ?></div>
  </div>
</div>

<?php
// ===== PHP VERSIONS =====
elseif ($page === 'php'):
  $all_versions = ['5.6','7.0','7.1','7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4'];
  $installed = [];
  foreach ($all_versions as $v) {
    if (file_exists("/usr/bin/php$v")) {
      $fpm_ok = svc_status("php$v-fpm");
      $ext_count = count(explode("\n", trim(cmd("php$v -m 2>/dev/null") ?: '')));
      $installed[$v] = ['fpm' => $fpm_ok, 'extensions' => $ext_count];
    }
  }
  $default_cli = trim(cmd("php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;' 2>/dev/null") ?: '?');
?>
<div class="card"><h2>🐘 PHP Version Manager</h2></div>

<div class="stats">
  <div class="stat"><div class="val"><?= count($installed) ?></div><div class="lbl">Versions Installed</div></div>
  <div class="stat"><div class="val" style="font-size:18px"><?= $default_cli ?></div><div class="lbl">Default CLI</div></div>
  <div class="stat"><div class="val"><?= count(array_filter($installed, fn($i) => $i['fpm'])) ?></div><div class="lbl">FPM Running</div></div>
</div>

<div class="card"><h2>📦 Installed Versions</h2>
  <?php if(empty($installed)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada PHP terinstall. Install dari daftar di bawah.</p>
  <?php else: ?>
  <table><thead><tr><th>Version</th><th>FPM Status</th><th>Extensions</th><th>CLI</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($installed as $v => $info): ?>
  <tr>
    <td><strong style="font-size:16px">🐘 PHP <?= $v ?></strong></td>
    <td><span class="badge <?= $info['fpm']?'badge-ok':'badge-err' ?>"><?= $info['fpm']?'🟢 Running':'🔴 Stopped' ?></span></td>
    <td><?= $info['extensions'] ?> modules</td>
    <td><?= $v === $default_cli ? '<span class="badge badge-ok">✅ Default</span>' : '' ?></td>
    <td>
      <?php if($v !== $default_cli): ?><form method="POST" style="display:inline"><input type="hidden" name="action" value="php_default"><input type="hidden" name="version" value="<?= $v ?>"><button class="btn btn-blue btn-xs">⭐ Set Default</button></form><?php endif; ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Hapus PHP <?= $v ?>?')"><input type="hidden" name="action" value="php_remove"><input type="hidden" name="version" value="<?= $v ?>"><button class="btn btn-red btn-xs">🗑️</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<div class="card"><h2>📥 Install PHP Version</h2>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach($all_versions as $v): $is_installed = isset($installed[$v]); ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Install PHP <?= $v ?>? (5-10 menit)')">
      <input type="hidden" name="action" value="php_install">
      <input type="hidden" name="version" value="<?= $v ?>">
      <button class="btn <?= $is_installed?'btn-gray':'btn-green' ?> btn-sm" <?= $is_installed?'disabled title="Sudah terinstall"':'' ?>>
        🐘 PHP <?= $v ?> <?= $is_installed?'✅':'' ?>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
  <p style="font-size:11px;color:var(--text2);margin-top:12px">
    ⚠️ Setiap versi include: FPM, CLI, MySQL, cURL, GD, MBString, XML, ZIP, Intl, BCMath<br>
    📦 Sumber: ondrej/php PPA (auto-add)
  </p>
</div>

<div class="card"><h2>🔄 Website PHP Versions</h2>
  <p style="font-size:12px;color:var(--yellow);margin-bottom:12px">⚠️ Sebelum switch, pastikan kode website kompatibel dengan versi PHP tujuan.<br>Contoh: arrow function <code>fn()</code> hanya support PHP 7.4+. Kalau error 500 setelah switch, balikkan ke versi sebelumnya.</p>
  <?php
  // Scan all nginx configs for PHP socket version
  $site_php = [];
  foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
    $cfg_name = basename($f);
    if ($cfg_name === 'default') continue;
    $cfg = file_get_contents($f);
    preg_match('/server_name\s+([^;]+);/', $cfg, $sn);
    $domain = trim(explode(' ', $sn[1] ?? $cfg_name)[0]);
    preg_match('/unix:\/var\/run\/php\/php([\d.]+)-fpm\.sock/', $cfg, $phpm);
    $php_ver = $phpm[1] ?? '?';
    $site_php[] = ['domain' => $domain, 'php' => $php_ver, 'cfg_name' => $cfg_name];
  }
  if (empty($site_php)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada website.</p>
  <?php else: ?>
  <table><thead><tr><th>Website</th><th>PHP Version</th><th>Switch To</th></tr></thead><tbody>
  <?php foreach($site_php as $sp): ?>
  <tr>
    <td><strong><?= sanitize($sp['domain']) ?></strong></td>
    <td><span class="badge <?= isset($installed[$sp['php']])?'badge-ok':'badge-err' ?>">🐘 PHP <?= $sp['php'] ?></span></td>
    <td>
      <form method="POST" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="action" value="php_switch_site">
        <input type="hidden" name="domain" value="<?= sanitize($sp['domain']) ?>">
        <select name="version" style="padding:6px 10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:6px;font-size:12px">
          <?php foreach($installed as $v => $info): ?><option value="<?= $v ?>" <?= $v===$sp['php']?'selected':'' ?>>PHP <?= $v ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-green btn-xs" onclick="return confirm('Ganti <?= sanitize($sp['domain']) ?> ke PHP versi ini?')">🔄 Switch</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<?php
// ===== SSL =====
elseif ($page === 'ssl'):
  $certbot_ok = is_executable('/usr/bin/certbot');
  $timer_ok = trim(cmd("systemctl is-active certbot.timer 2>/dev/null")) === 'active';
  
  // Get SSL status for each site
  $ssl_sites = [];
  foreach (glob(NGINX_AVAILABLE . '/*') as $f) {
    $cfg_name = basename($f);
    if ($cfg_name === 'default') continue;
    $cfg = file_get_contents($f);
    preg_match('/server_name\s+([^;]+);/', $cfg, $sn);
    $domains = preg_split('/\s+/', trim($sn[1] ?? $cfg_name));
    $domain = $domains[0];
    $has_ssl = strpos($cfg, 'ssl_certificate') !== false;
    $cert_path = '/etc/letsencrypt/live/' . $domain;
    $cert_exists = is_dir($cert_path);
    $expiry = '';
    if ($cert_exists) {
      $expiry_out = cmd("openssl x509 -enddate -noout -in $cert_path/fullchain.pem 2>/dev/null");
      preg_match('/notAfter=(.+)/', $expiry_out ?? '', $em);
      $expiry = $em[1] ?? '';
      if ($expiry) {
        $exp = strtotime($expiry);
        $days = ceil(($exp - time()) / 86400);
        $expiry = date('d/m/Y', $exp) . " ($days days left)";
      }
    }
    $ssl_sites[] = ['domain' => $domain, 'ssl' => $has_ssl, 'cert_exists' => $cert_exists, 'expiry' => $expiry, 'cfg_name' => $cfg_name];
  }
?>

<div class="card">
  <h2>🔒 SSL Certificate Management</h2>
</div>

<?php if(!$certbot_ok): ?>
<div class="card" style="border:2px solid var(--yellow)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <strong style="color:var(--yellow)">⚠️ Certbot belum terinstall</strong>
      <p style="font-size:13px;color:var(--text2)">Certbot diperlukan untuk install & renew SSL dari Let's Encrypt</p>
    </div>
    <form method="POST"><input type="hidden" name="action" value="install_certbot"><button class="btn btn-green">📦 Install Certbot</button></form>
  </div>
</div>
<?php else: ?>

<div class="stats">
  <div class="stat">
    <div class="val <?= $timer_ok?'':'warn' ?>"><?= $timer_ok?'🟢 Active':'⚠️ Off' ?></div>
    <div class="lbl">Auto-Renew Timer</div>
  </div>
  <div class="stat">
    <div class="val"><?= count($ssl_sites) ?></div>
    <div class="lbl">Sites Monitored</div>
  </div>
  <div class="stat">
    <div class="val"><?= count(array_filter($ssl_sites, fn($s) => $s['ssl'])) ?>/<?= count($ssl_sites) ?></div>
    <div class="lbl">SSL Active</div>
  </div>
</div>

<div class="actions">
  <button onclick="showModal('installSslModal')" class="btn btn-green">🔒 Install SSL</button>
  <form method="POST" style="display:inline" onsubmit="return confirm('Renew semua sertifikat SSL sekarang?')">
    <input type="hidden" name="action" value="ssl_renew">
    <button class="btn btn-blue">🔄 Renew All Now</button>
  </form>
  <?php if(!$timer_ok): ?>
  <form method="POST" style="display:inline">
    <input type="hidden" name="action" value="ssl_autorenew">
    <button class="btn btn-yellow">⏰ Enable Auto-Renew</button>
  </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<div class="card">
  <h2>🌐 Website SSL Status</h2>
  <?php if(empty($ssl_sites)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada website. Buat dulu di menu 🌐 Websites.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Domain</th><th>SSL</th><th>Certificate</th><th>Expiry</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($ssl_sites as $s): ?>
    <tr>
      <td><strong><?= sanitize($s['domain']) ?></strong></td>
      <td><span class="badge <?= $s['ssl']?'badge-ok':'badge-err' ?>"><?= $s['ssl']?'🔒 Active':'❌ No SSL' ?></span></td>
      <td><span class="badge <?= $s['cert_exists']?'badge-ok':'badge-err' ?>"><?= $s['cert_exists']?'✅ Valid':'❌ None' ?></span></td>
      <td style="font-size:12px;color:<?= (strpos($s['expiry'],'days')!==false && (int)$s['expiry']<30)?'var(--red)':'var(--text2)' ?>"><?= $s['expiry'] ?: '—' ?></td>
      <td>
        <?php if(!$s['ssl']): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Install SSL untuk <?= sanitize($s['domain']) ?>?')">
          <input type="hidden" name="action" value="ssl_install">
          <input type="hidden" name="domain" value="<?= sanitize($s['domain']) ?>">
          <input type="hidden" name="email" value="admin@<?= sanitize($s['domain']) ?>">
          <button class="btn btn-green btn-xs">🔒 Install SSL</button>
        </form>
        <?php else: ?>
        <a href="https://<?= $s['domain'] ?>" target="_blank" class="btn btn-blue btn-xs">🔗 Test</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Install SSL Modal -->
<div id="installSslModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('installSslModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:450px;border:1px solid var(--border);padding:24px">
    <h3 style="font-size:18px;color:var(--green);margin-bottom:20px">🔒 Install SSL Certificate</h3>
    <form method="POST">
      <input type="hidden" name="action" value="ssl_install">
      <div class="form-group" style="margin-bottom:14px">
        <label>Domain *</label>
        <select name="domain" style="width:100%;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px">
          <?php foreach($ssl_sites as $s): if(!$s['ssl']): ?><option value="<?= sanitize($s['domain']) ?>"><?= sanitize($s['domain']) ?></option><?php endif; endforeach; ?>
        </select>
        <small>Pilih domain yg belum ada SSL</small>
      </div>
      <div class="form-group" style="margin-bottom:20px">
        <label>Email (Let's Encrypt)</label>
        <input name="email" placeholder="admin@domain.com">
        <small>Untuk notifikasi expiry</small>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="hideModal('installSslModal')" class="btn btn-gray">Batal</button>
        <button type="submit" class="btn btn-green">🔒 Install SSL</button>
      </div>
    </form>
  </div>
</div>

<script>
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<?php
// ===== MONITOR =====
elseif ($page === 'monitor'):
  $cpu = trim(cmd("top -bn1 | grep 'Cpu(s)' | awk '{print \$2+\$4}' 2>/dev/null") ?: '0');
  $mem_total = trim(cmd("free -m | grep Mem | awk '{print \$2}'"));
  $mem_used = trim(cmd("free -m | grep Mem | awk '{print \$3}'"));
  $mem_pct = $mem_total > 0 ? round($mem_used / $mem_total * 100) : 0;
  $disk_info = [];
  $df = cmd("df -h --type=ext4 --type=xfs 2>/dev/null || df -h / 2>/dev/null");
  foreach (explode("\n", trim($df)) as $i => $line) {
    if ($i === 0) continue;
    $p = preg_split('/\s+/', trim($line));
    if (count($p) >= 6) $disk_info[] = ['fs' => $p[0], 'size' => $p[1], 'used' => $p[2], 'avail' => $p[3], 'pct' => $p[4], 'mount' => $p[5]];
  }
  $net_rx = trim(cmd("cat /sys/class/net/eth0/statistics/rx_bytes 2>/dev/null || cat /sys/class/net/ens3/statistics/rx_bytes 2>/dev/null || echo 0"));
  $net_tx = trim(cmd("cat /sys/class/net/eth0/statistics/tx_bytes 2>/dev/null || cat /sys/class/net/ens3/statistics/tx_bytes 2>/dev/null || echo 0"));
  $load = sys_getloadavg();
  $uptime = trim(cmd('uptime -p'));
  $procs = trim(cmd("ps aux --sort=-%mem | head -11 | awk '{print \$3,\$4,\$11}'") ?: '');
?>
<div class="card"><h2>📊 System Monitor</h2></div>
<div class="stats">
  <div class="stat"><div class="val" style="color:<?= $cpu>80?'var(--red)':($cpu>50?'var(--yellow)':'var(--green)') ?>"><?= $cpu ?>%</div><div class="lbl">CPU Usage</div></div>
  <div class="stat"><div class="val" style="color:<?= $mem_pct>80?'var(--red)':($mem_pct>50?'var(--yellow)':'var(--green)') ?>"><?= $mem_pct ?>%</div><div class="lbl">Memory (<?= $mem_used ?>M/<?= $mem_total ?>M)</div></div>
  <div class="stat"><div class="val"><?= round($load[0], 2) ?></div><div class="lbl">Load Average</div></div>
  <div class="stat"><div class="val" style="font-size:14px"><?= $uptime ?></div><div class="lbl">Uptime</div></div>
</div>
<div class="card"><h2>💾 Disk</h2>
  <table><thead><tr><th>Filesystem</th><th>Size</th><th>Used</th><th>Avail</th><th>Use%</th><th>Mount</th></tr></thead><tbody>
  <?php foreach($disk_info as $d): ?>
  <tr><td><?= $d['fs'] ?></td><td><?= $d['size'] ?></td><td><?= $d['used'] ?></td><td><?= $d['avail'] ?></td><td><?= $d['pct'] ?></td><td><?= $d['mount'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<div class="card"><h2>🌐 Network</h2>
  <div class="stats">
    <div class="stat"><div class="val" style="font-size:18px"><?= round($net_rx/1073741824, 2) ?> GB</div><div class="lbl">Total Downloaded</div></div>
    <div class="stat"><div class="val" style="font-size:18px"><?= round($net_tx/1073741824, 2) ?> GB</div><div class="lbl">Total Uploaded</div></div>
  </div>
</div>
<div class="card"><h2>🔝 Top Processes</h2>
  <table><thead><tr><th>CPU%</th><th>MEM%</th><th>Process</th></tr></thead><tbody>
  <?php foreach(array_slice(explode("\n", trim($procs)), 1) as $pl): $pp = preg_split('/\s+/', trim($pl)); if(count($pp)>=3): ?>
  <tr><td><?= $pp[0] ?></td><td><?= $pp[1] ?></td><td style="font-family:monospace;font-size:12px"><?= sanitize($pp[2]) ?></td></tr>
  <?php endif; endforeach; ?>
  </tbody></table>
</div>

<?php
// ===== FIREWALL =====
elseif ($page === 'firewall'):
  $fw_active = trim(cmd('ufw status 2>/dev/null | head -1 | grep -o active')) === 'active';
  $fw_rules = [];
  $raw = cmd('ufw status numbered 2>/dev/null');
  if ($raw) {
    foreach (explode("\n", $raw) as $line) {
      if (preg_match('/^\[\s*(\d+)\]\s+(.+)/', trim($line), $m)) {
        $fw_rules[] = ['num' => $m[1], 'rule' => trim($m[2])];
      }
    }
  }
?>
<div class="card"><h2>🛡️ Firewall (UFW)</h2></div>

<div class="card" style="border:2px solid <?= $fw_active?'var(--green)':'var(--red)' ?>">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <strong style="color:<?= $fw_active?'var(--green)':'var(--red)' ?>"><?= $fw_active?'🟢 Firewall AKTIF':'🔴 Firewall MATI' ?></strong>
      <p style="font-size:13px;color:var(--text2)"><?= $fw_active?'Semua port masuk ditolak, keluar diizinkan':'Port tidak difilter — VPS rentan!' ?></p>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="fw_toggle">
      <input type="hidden" name="enable" value="<?= $fw_active?'0':'1' ?>">
      <button class="btn <?= $fw_active?'btn-red':'btn-green' ?>" onclick="return confirm('<?= $fw_active?'Nonaktifkan firewall?':'Aktifkan firewall?' ?>')"><?= $fw_active?'⏹ Disable':'▶ Enable Firewall' ?></button>
    </form>
  </div>
</div>

<?php if($fw_active): ?>
<div class="actions">
  <button onclick="showModal('addRuleModal')" class="btn btn-green">➕ Add Rule</button>
  <button onclick="showModal('quickPresets')" class="btn btn-blue">⚡ Quick Presets</button>
</div>

<div class="card"><h2>📋 Rules</h2>
  <?php if(empty($fw_rules)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada rule. Tambahkan rule untuk allow port yang dibutuhkan.</p>
  <?php else: ?>
  <table><thead><tr><th>#</th><th>Rule</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($fw_rules as $r): ?>
  <tr><td><?= $r['num'] ?></td><td><code style="font-size:13px"><?= sanitize($r['rule']) ?></code></td>
    <td><form method="POST" style="display:inline" onsubmit="return confirm('Hapus rule #<?= $r['num'] ?>?')"><input type="hidden" name="action" value="fw_del"><input type="hidden" name="num" value="<?= $r['num'] ?>"><button class="btn btn-red btn-xs">🗑️</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add Rule Modal -->
<div id="addRuleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('addRuleModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:400px;border:1px solid var(--border);padding:24px">
    <h3 style="margin-bottom:16px">➕ Add Firewall Rule</h3>
    <form method="POST">
      <input type="hidden" name="action" value="fw_add">
      <div class="form-group" style="margin-bottom:12px"><label>Action</label><select name="fw_action" style="width:100%;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px"><option value="allow">✅ Allow</option><option value="deny">❌ Deny</option></select></div>
      <div class="form-group" style="margin-bottom:12px"><label>Port</label><input name="port" placeholder="80" required></div>
      <div class="form-group" style="margin-bottom:16px"><label>Protocol</label><select name="proto" style="width:100%;padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px"><option value="tcp">TCP</option><option value="udp">UDP</option></select></div>
      <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" onclick="hideModal('addRuleModal')" class="btn btn-gray">Batal</button><button class="btn btn-green">Add Rule</button></div>
    </form>
  </div>
</div>
<!-- Quick Presets Modal -->
<div id="quickPresets" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('quickPresets')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:400px;border:1px solid var(--border);padding:24px">
    <h3 style="margin-bottom:16px">⚡ Quick Presets</h3>
    <p style="font-size:12px;color:var(--text2);margin-bottom:12px">Klik untuk allow port penting:</p>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach([22=>'SSH',80=>'HTTP',443=>'HTTPS',8080=>'Panel',3306=>'MySQL',21=>'FTP',25=>'SMTP',53=>'DNS'] as $p=>$l): ?>
      <form method="POST" style="display:inline"><input type="hidden" name="action" value="fw_add"><input type="hidden" name="fw_action" value="allow"><input type="hidden" name="port" value="<?= $p ?>"><input type="hidden" name="proto" value="tcp"><button class="btn btn-blue btn-sm"><?= $p ?> (<?= $l ?>)</button></form>
      <?php endforeach; ?>
    </div>
    <button onclick="hideModal('quickPresets')" class="btn btn-gray" style="margin-top:16px;width:100%">Tutup</button>
  </div>
</div>
<script>function showModal(id){document.getElementById(id).style.display='flex'}function hideModal(id){document.getElementById(id).style.display='none'}</script>

<?php
// ===== LOGS =====
elseif ($page === 'logs'):
  $log_type = $_GET['type'] ?? 'access';
  $log_site = $_GET['site'] ?? '';
  $log_lines = intval($_GET['lines'] ?? 50);
  $log_content = '';
  $log_file = '';
  
  $sites_list = [];
  foreach (glob('/var/log/nginx/*access*') as $f) $sites_list[] = basename($f);
  foreach (glob('/var/log/nginx/*error*') as $f) $sites_list[] = basename($f);
  $sites_list = array_unique($sites_list);
  
  if ($log_type === 'access' && $log_site) $log_file = "/var/log/nginx/$log_site";
  elseif ($log_type === 'error' && $log_site) $log_file = "/var/log/nginx/$log_site";
  elseif ($log_type === 'sys') $log_file = '/var/log/syslog';
  elseif ($log_type === 'php') $log_file = '/var/log/php8.1-fpm.log';
  elseif ($log_type === 'mysql') $log_file = '/var/log/mysql/error.log';
  
  if ($log_file && file_exists($log_file)) {
    $log_content = cmd("tail -n $log_lines " . escapeshellarg($log_file) . " 2>/dev/null");
  }
?>
<div class="card"><h2>📝 Logs Viewer</h2></div>

<div class="card">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="page" value="logs">
    <div class="form-group"><label>Type</label><select name="type" style="padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px"><option value="access" <?= $log_type==='access'?'selected':'' ?>>Nginx Access</option><option value="error" <?= $log_type==='error'?'selected':'' ?>>Nginx Error</option><option value="sys" <?= $log_type==='sys'?'selected':'' ?>>System (syslog)</option><option value="php" <?= $log_type==='php'?'selected':'' ?>>PHP-FPM</option><option value="mysql" <?= $log_type==='mysql'?'selected':'' ?>>MySQL</option></select></div>
    <?php if(in_array($log_type, ['access','error'])): ?>
    <div class="form-group"><label>Site</label><select name="site" style="padding:10px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px"><option value="">-- pilih --</option><?php foreach($sites_list as $s): ?><option value="<?= $s ?>" <?= $log_site===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="form-group"><label>Lines</label><input name="lines" value="<?= $log_lines ?>" style="width:80px;padding:10px"></div>
    <button class="btn btn-green">🔍 View</button>
  </form>
</div>

<?php if($log_file && file_exists($log_file)): ?>
<div class="card"><h3>📄 <?= basename($log_file) ?> (last <?= $log_lines ?> lines)</h3>
  <pre style="background:var(--bg);padding:16px;border-radius:8px;overflow:auto;max-height:600px;font-family:monospace;font-size:12px;line-height:1.6;color:#94a3b8"><?= sanitize($log_content) ?></pre>
</div>
<?php elseif($log_file): ?>
<div class="card"><p style="color:var(--text2);text-align:center;padding:40px">📂 File tidak ditemukan: <?= sanitize($log_file) ?></p></div>
<?php else: ?>
<div class="card"><p style="color:var(--text2);text-align:center;padding:40px">👆 Pilih tipe log dan site untuk melihat.</p></div>
<?php endif; ?>

<?php
// ===== CRON JOBS =====
elseif ($page === 'cron'):
  $crontab = cmd('crontab -l 2>/dev/null') ?: '';
  $cron_jobs = [];
  foreach (explode("\n", trim($crontab)) as $line) {
    $line = trim($line);
    if (empty($line) || $line[0] === '#') continue;
    if (preg_match('/^(@\w+|[\d*,/\-]+\s+[\d*,/\-]+\s+[\d*,/\-]+\s+[\d*,/\-]+\s+[\d*,/\-]+)\s+(.+)/', $line, $m)) {
      $cron_jobs[] = ['schedule' => $m[1], 'command' => $m[2]];
    }
  }
?>
<div class="card"><h2>⏰ Cron Jobs</h2></div>

<div class="actions">
  <button onclick="showModal('cronAddModal')" class="btn btn-green">➕ Add Cron Job</button>
  <button onclick="showModal('cronPresets')" class="btn btn-blue">⚡ Common Presets</button>
</div>

<div class="card"><h2>📋 Scheduled Jobs (<?= count($cron_jobs) ?>)</h2>
  <?php if(empty($cron_jobs)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada cron job. Tambahkan untuk otomatisasi.</p>
  <?php else: ?>
  <table><thead><tr><th>#</th><th>Schedule</th><th>Command</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($cron_jobs as $i => $j): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><code style="background:var(--bg);padding:3px 8px;border-radius:4px;font-size:12px"><?= sanitize($j['schedule']) ?></code></td>
    <td><code style="font-size:12px;color:var(--green)"><?= sanitize($j['command']) ?></code></td>
    <td><form method="POST" style="display:inline" onsubmit="return confirm('Hapus cron job #<?= $i+1 ?>?')"><input type="hidden" name="action" value="cron_del"><input type="hidden" name="num" value="<?= $i+1 ?>"><button class="btn btn-red btn-xs">🗑️</button></form></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<!-- Add Cron Modal -->
<div id="cronAddModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('cronAddModal')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:550px;border:1px solid var(--border);padding:24px;max-height:90vh;overflow-y:auto">
    <h3 style="margin-bottom:16px">➕ Add Cron Job</h3>
    <form method="POST">
      <input type="hidden" name="action" value="cron_add">
      <div class="form-group" style="margin-bottom:12px"><label>Schedule (cron expression) *</label><input name="expr" placeholder="*/5 * * * *  (setiap 5 menit)" required><small style="font-size:11px;color:var(--text2)">Format: menit jam hari bulan hari-minggu | <b>* * * * *</b> = setiap menit</small></div>
      <div class="form-group" style="margin-bottom:16px"><label>Command *</label><input name="cmd" placeholder="php /var/www/manager/cron.php" required></div>
      <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" onclick="hideModal('cronAddModal')" class="btn btn-gray">Batal</button><button class="btn btn-green">Add Cron</button></div>
    </form>
  </div>
</div>

<!-- Cron Presets Modal -->
<div id="cronPresets" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999;display:none;align-items:center;justify-content:center" onclick="if(event.target===this)hideModal('cronPresets')">
  <div style="background:var(--surface);border-radius:var(--radius);width:90%;max-width:550px;border:1px solid var(--border);padding:24px">
    <h3 style="margin-bottom:16px">⚡ Common Presets</h3>
    <div style="display:flex;flex-direction:column;gap:6px">
      <?php
      $presets = [
        ['0 2 * * *', 'certbot renew --quiet && nginx -s reload', '🔄 Renew SSL setiap jam 2 pagi'],
        ['0 3 * * 0', 'mysqldump -u root --all-databases > /var/backups/db_$(date +\%Y\%m\%d).sql', '💾 Backup semua DB setiap Minggu jam 3'],
        ['*/30 * * * *', 'find /tmp -type f -mmin +60 -delete', '🧹 Bersihkan /tmp setiap 30 menit'],
        ['0 4 * * *', 'apt-get update -qq && apt-get upgrade -y -qq', '📦 Auto-update packages jam 4 pagi'],
        ['0 0 1 * *', 'nginx -t && systemctl reload nginx', '🔄 Reload Nginx tiap tanggal 1'],
      ];
      foreach($presets as $p): ?>
      <form method="POST" style="display:inline"><input type="hidden" name="action" value="cron_add"><input type="hidden" name="expr" value="<?= $p[0] ?>"><input type="hidden" name="cmd" value="<?= sanitize($p[1]) ?>"><button class="btn btn-blue btn-sm" style="text-align:left;width:100%"><?= $p[2] ?><br><small style="opacity:0.7"><?= $p[0] ?> <?= sanitize($p[1]) ?></small></button></form>
      <?php endforeach; ?>
    </div>
    <button onclick="hideModal('cronPresets')" class="btn btn-gray" style="margin-top:16px;width:100%">Tutup</button>
  </div>
</div>
<script>function showModal(id){document.getElementById(id).style.display='flex'}function hideModal(id){document.getElementById(id).style.display='none'}</script>

<?php
// ===== OPTIMIZE =====
elseif ($page === 'optimize'):
  $ram_mb = intval(trim(cmd("free -m | grep Mem | awk '{print \$2}'")));
  $cpu = intval(trim(cmd('nproc')));
  $tier = $ram_mb >= 4096 ? 'High (4GB+)' : ($ram_mb >= 2048 ? 'Medium (2GB+)' : ($ram_mb >= 1024 ? 'Standard (1GB+)' : 'Low (512MB)'));
  $nginx_opt = (strpos(file_get_contents('/etc/nginx/nginx.conf'), 'gzip on;') !== false);
?>
<div class="card"><h2>🚀 Performance Optimizer</h2></div>

<div class="stats">
  <div class="stat"><div class="val"><?= $ram_mb ?> MB</div><div class="lbl">Total RAM</div></div>
  <div class="stat"><div class="val"><?= $cpu ?> Core</div><div class="lbl">CPU</div></div>
  <div class="stat"><div class="val"><?= $tier ?></div><div class="lbl">Tier</div></div>
</div>

<div class="card" style="border:2px solid var(--yellow)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <strong style="color:var(--yellow)">⚡ Optimize All</strong>
      <p style="font-size:13px;color:var(--text2)">Auto-tune Nginx + PHP + MySQL + System untuk handle 1000+ user di <?= $ram_mb ?>MB RAM</p>
      <p style="font-size:11px;color:var(--text2)">✅ Backup config otomatis sebelum ubah</p>
    </div>
    <form method="POST" onsubmit="return confirm('Optimasi semua service? Config akan di-backup dulu.')">
      <input type="hidden" name="action" value="optimize">
      <input type="hidden" name="target" value="all">
      <button class="btn btn-green" style="padding:14px 28px;font-size:15px">🚀 Optimize All</button>
    </form>
  </div>
</div>

<div class="service-grid">
  <!-- Nginx -->
  <div class="service-card">
    <div class="svc-icon">🌐</div>
    <div class="svc-name">Nginx</div>
    <small style="color:var(--text2)">worker_processes auto · gzip · fastcgi cache</small>
    <div style="margin-top:12px">
      <span class="badge <?= $nginx_opt?'badge-ok':'badge-err' ?>"><?= $nginx_opt?'Optimized':'Default' ?></span>
    </div>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="optimize"><input type="hidden" name="target" value="nginx"><button class="btn btn-blue btn-sm">🔧 Optimize</button></form>
  </div>
  <!-- PHP -->
  <div class="service-card">
    <div class="svc-icon">🐘</div>
    <div class="svc-name">PHP-FPM + OPCache</div>
    <small style="color:var(--text2)">pm ondemand · max_children auto · opcache</small>
    <div style="margin-top:12px">
      <span class="badge badge-err">Default</span>
    </div>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="optimize"><input type="hidden" name="target" value="php"><button class="btn btn-blue btn-sm">🔧 Optimize</button></form>
  </div>
  <!-- MySQL -->
  <div class="service-card">
    <div class="svc-icon">🗄️</div>
    <div class="svc-name">MySQL / MariaDB</div>
    <small style="color:var(--text2)">innodb_buffer_pool · connections · tmp_table</small>
    <div style="margin-top:12px">
      <span class="badge badge-err">Default</span>
    </div>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="optimize"><input type="hidden" name="target" value="mysql"><button class="btn btn-blue btn-sm">🔧 Optimize</button></form>
  </div>
  <!-- System -->
  <div class="service-card">
    <div class="svc-icon">⚙️</div>
    <div class="svc-name">System Kernel</div>
    <small style="color:var(--text2)">swappiness · file limits · TCP tuning</small>
    <div style="margin-top:12px">
      <span class="badge badge-err">Default</span>
    </div>
    <form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="optimize"><input type="hidden" name="target" value="system"><button class="btn btn-blue btn-sm">🔧 Optimize</button></form>
  </div>
</div>

<div class="card"><h2>📊 What Gets Optimized (Target: 1000+ Users)</h2>
  <table><thead><tr><th>Service</th><th>Setting</th><th>512MB</th><th>1GB</th><th>2GB+</th></tr></thead><tbody>
    <tr><td><strong>Nginx</strong></td><td>worker_connections</td><td>2048</td><td>4096</td><td>4096</td></tr>
    <tr><td><strong>Nginx</strong></td><td>gzip + fastcgi_cache</td><td colspan="3" style="text-align:center">✅ Enabled (level 5, 64MB cache)</td></tr>
    <tr><td><strong>PHP-FPM</strong></td><td>max_children (ondemand)</td><td>12</td><td>25</td><td>50</td></tr>
    <tr><td><strong>PHP</strong></td><td>memory_limit</td><td>128M</td><td>192M</td><td>256M</td></tr>
    <tr><td><strong>OPCache</strong></td><td>memory_consumption</td><td>64MB</td><td>128MB</td><td>256MB</td></tr>
    <tr><td><strong>MySQL</strong></td><td>innodb_buffer_pool_size</td><td>128M</td><td>256M</td><td>512M</td></tr>
    <tr><td><strong>MySQL</strong></td><td>max_connections</td><td>50</td><td>100</td><td>200</td></tr>
    <tr><td><strong>System</strong></td><td>swappiness / file-max / TCP</td><td colspan="3" style="text-align:center">swappiness=10, file-max=65535, TCP fastopen</td></tr>
  </tbody></table>
</div>

<div class="card"><h2>💾 Backup & Restore</h2>
  <p style="font-size:13px;color:var(--text2);margin-bottom:12px">Setiap optimasi otomatis backup config asli ke:</p>
  <code style="background:var(--bg);padding:8px;border-radius:4px;display:block;font-size:12px">/var/www/manager/backups/optimize/YYYYMMDD_HHMMSS/</code>
</div>

<?php
// ===== SETTINGS =====
elseif ($page === 'settings'):
  $pw_set = file_exists(PASSWD_FILE);
?>
<div class="card"><h2>⚙️ Settings</h2></div>

<div class="card">
  <h2>🔐 Change Password</h2>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
    <?= $pw_set ? 'Password saat ini disimpan di <code>/var/www/manager/.passwd</code>' : '⚠️ Masih pakai password default! Segera ganti.' ?>
  </p>
  <form method="POST" style="max-width:400px">
    <input type="hidden" name="action" value="change_pass">
    <div class="form-group" style="margin-bottom:12px">
      <label>Password Lama</label>
      <input type="password" name="old_pass" placeholder="Masukkan password saat ini" required>
    </div>
    <div class="form-group" style="margin-bottom:12px">
      <label>Password Baru</label>
      <input type="password" name="new_pass" placeholder="Minimal 6 karakter" required>
    </div>
    <div class="form-group" style="margin-bottom:16px">
      <label>Konfirmasi Password Baru</label>
      <input type="password" name="confirm_pass" placeholder="Ulangi password baru" required>
    </div>
    <button class="btn btn-green">💾 Simpan Password</button>
  </form>
</div>

<div class="card">
  <h2>📋 System Info</h2>
  <table>
    <tr><td style="width:200px;color:var(--text2)">Panel Version</td><td><strong>v2.2</strong></td></tr>
    <tr><td style="color:var(--text2)">Panel Path</td><td><code style="font-size:12px">/var/www/manager/index.php</code></td></tr>
    <tr><td style="color:var(--text2)">Password File</td><td><code style="font-size:12px"><?= $pw_set ? '✅ .passwd exists' : '❌ Not set (using default)' ?></code></td></tr>
    <tr><td style="color:var(--text2)">PHP Version</td><td><?= phpversion() ?></td></tr>
    <tr><td style="color:var(--text2)">Backups</td><td><code style="font-size:12px">/var/www/manager/backups/</code></td></tr>
    <tr><td style="color:var(--text2)">GitHub</td><td><a href="https://github.com/panelboss/vps-manager" target="_blank">panelboss/vps-manager</a></td></tr>
  </table>
</div>

<?php
// ===== FILE MANAGER =====
elseif ($page === 'files'):
  $ROOT = '/var/www';
  $dir = $_GET['dir'] ?? '';
  $current = realpath($ROOT . '/' . ltrim($dir, '/'));
  if (!$current || strpos($current, realpath($ROOT)) !== 0) $current = $ROOT;
  
  function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes/1073741824,1).' GB';
    if ($bytes >= 1048576) return round($bytes/1048576,1).' MB';
    if ($bytes >= 1024) return round($bytes/1024,1).' KB';
    return $bytes.' B';
  }
  
  // Handle file view/edit
  $file_action = $_GET['fa'] ?? '';
  $file_name = $_GET['file'] ?? '';
  $file_content = '';
  if ($file_action === 'view' && $file_name) {
    $fp = $current . '/' . basename($file_name);
    if (file_exists($fp) && !is_dir($fp)) {
      $file_content = htmlspecialchars(file_get_contents($fp));
    }
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fa'] ?? '') === 'save') {
    $fp = $current . '/' . basename($_POST['file'] ?? '');
    if (file_exists($fp) && is_writable($fp)) {
      file_put_contents($fp, $_POST['content'] ?? '');
      $files_msg = '<div class=.ok.>✅ File disimpan!</div>';
    }
    $file_action = 'view';
    $file_name = $_POST['file'] ?? '';
    $file_content = htmlspecialchars(file_get_contents($fp));
  }
  if ($file_action === 'delete' && $file_name) {
    $fp = $current . '/' . basename($file_name);
    if (file_exists($fp) && !is_dir($fp)) {
      unlink($fp);
      $files_msg = '<div class=.ok.>✅ Dihapus!</div>';
    }
  }
  
  // Breadcrumb
  $parts = explode('/', trim(str_replace($ROOT, '', $current), '/'));
  $bc = '<a href="?page=files">📁 /var/www</a>';
  $build = '';
  foreach ($parts as $p) {
    if (empty($p)) continue;
    $build .= '/' . $p;
    $bc .= ' / <a href="?page=files&dir=' . urlencode(ltrim($build, '/')) . '">' . sanitize($p) . '</a>';
  }
  
  echo $files_msg;
  
  if ($file_action !== 'view'):
  ?>
  <div class="card" style="padding:14px 20px">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="upload_file">
        <input type="hidden" name="up_dir" value="<?= sanitize($dir) ?>">
        <div style="margin-bottom:0">
          <label style="display:block;margin-bottom:3px;font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase">📤 Upload File</label>
          <input type="file" name="upfile" required style="padding:7px 10px;font-size:12px;border-radius:6px;border:1px solid var(--border)">
        </div>
        <button class="btn btn-blue btn-sm" style="margin-top:18px">⬆️ Upload</button>
      </form>
      <form method="POST" style="display:flex;gap:8px;align-items:flex-end">
        <input type="hidden" name="action" value="new_folder">
        <input type="hidden" name="fd_dir" value="<?= sanitize($dir) ?>">
        <div style="margin-bottom:0">
          <label style="display:block;margin-bottom:3px;font-size:11px;color:var(--text2);font-weight:700;text-transform:uppercase">📁 New Folder</label>
          <input type="text" name="fname" placeholder="folder-name" required style="padding:7px 10px;font-size:12px;border-radius:6px;border:1px solid var(--border);width:160px">
        </div>
        <button class="btn btn-gray btn-sm" style="margin-top:18px">➕ Create</button>
      </form>
    </div>
    <p style="font-size:11px;color:var(--text2);margin-top:8px">Max upload: <?= ini_get('upload_max_filesize') ?>. Nama file otomatis disanitasi.</p>
  </div>
  <?php endif; ?>
  <?php
  
  if ($file_action === 'view'):
  ?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3>📝 Edit: <?= sanitize($file_name) ?></h3>
      <a href="?page=files&dir=<?= urlencode($dir) ?>" class="btn btn-gray btn-sm">← Kembali</a>
    </div>
    <form method="POST">
      <input type="hidden" name="fa" value="save">
      <input type="hidden" name="file" value="<?= sanitize($file_name) ?>">
      <textarea name="content" style="width:100%;min-height:500px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:16px;font-family:monospace;font-size:13px;resize:vertical"><?= $file_content ?></textarea>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="btn btn-green">💾 Simpan</button>
        <a href="?page=files&dir=<?= urlencode($dir) ?>" class="btn btn-gray">Batal</a>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div class="card">
    <h2><?= $bc ?></h2>
    <table>
      <thead><tr><th>Nama</th><th style="width:100px">Size</th><th style="width:180px">Modified</th><th style="width:160px">Actions</th></tr></thead>
      <tbody>
      <?php
      $items = scandir($current);
      $dirs = []; $files = [];
      foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir($current . '/' . $item)) $dirs[] = $item; else $files[] = $item;
      }
      sort($dirs); sort($files);
      $all = array_merge($dirs, $files);
      
      if ($current !== $ROOT) {
        $parent = dirname(str_replace($ROOT, '', $current));
        echo "<tr><td><a href='?page=files&dir=" . urlencode(ltrim($parent, '/')) . "'>📂 ..</a></td><td></td><td></td><td></td></tr>";
      }
      
      foreach ($all as $item) {
        $fp = $current . '/' . $item;
        $isdir = is_dir($fp);
        $size = $isdir ? '-' : formatSize(filesize($fp));
        $mtime = date('d/m/Y H:i', filemtime($fp));
        $rel = ltrim(str_replace($ROOT, '', $current), '/');
        $rel = $rel ? urlencode($rel) : '';
        $ext = pathinfo($item, PATHINFO_EXTENSION);
        $icon = $isdir ? '📁' : match($ext) {
          'php' => '🐘', 'html' => '🌐', 'css' => '🎨', 'js' => '📜',
          'sql' => '🗄️', 'jpg','png','gif','svg','webp' => '🖼️',
          'zip','tar','gz' => '📦', 'pdf' => '📄', 'txt','md' => '📝',
          default => '📄'
        };
        
        $editable = !$isdir && in_array($ext, ['php','html','css','js','txt','md','sql','json','xml','yml','env','conf','htaccess','log','sh']);
        
        echo "<tr>
          <td><a href='" . ($isdir ? "?page=files&dir=" . ($rel ? "$rel/" : "") . urlencode($item) : "?page=files&fa=view&dir=$rel&file=" . urlencode($item)) . "'>$icon " . sanitize($item) . "</a></td>
          <td style='font-size:12px;color:var(--text2)'>$size</td>
          <td style='font-size:12px;color:var(--text2)'>$mtime</td>
          <td>";
        if ($editable) echo "<a href='?page=files&fa=view&dir=$rel&file=" . urlencode($item) . "' class='btn btn-blue btn-xs'>✏️ Edit</a> ";
        echo "<a href='?page=files&fa=delete&dir=$rel&file=" . urlencode($item) . "' class='btn btn-red btn-xs' onclick=\"return confirm('Hapus $item?')\">🗑️</a>";
        echo "</td></tr>";
      }
      ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  

<?php
// ===== REDIRECT MANAGER =====
elseif ($page === 'redirects'):
  $rconf = '/etc/nginx/conf.d/panel-redirects.conf';
  $redirects = [];
  if (file_exists($rconf)) {
    $blocks = preg_split('/\n(?=# )/', trim(file_get_contents($rconf)));
    foreach ($blocks as $idx => $block) {
      if (empty(trim($block)) || strpos($block, '# VPS Manager') === 0) continue;
      preg_match('/# (.+?) \| (.+?) -> (.+?) \| (.+?) \|/', $block, $m);
      if ($m) $redirects[] = ['domain' => $m[1], 'from' => $m[2], 'to' => $m[3], 'type' => $m[4], 'date' => $m[5], 'num' => $idx];
    }
  }
?>
<div class="card"><h2>🔄 Redirect Manager</h2></div>

<div class="card">
  <h3>➕ Add Redirect</h3>
  <form method="POST">
    <input type="hidden" name="action" value="add_redirect">
    <div class="form-grid">
      <div class="form-group">
        <label>Domain</label>
        <select name="rdomain" style="width:100%;padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          <?php foreach($sites as $s): ?><option value="<?= $s['domain'] ?>"><?= $s['domain'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Redirect Type</label>
        <select name="rtype" style="width:100%;padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          <option value="301">301 Permanen</option>
          <option value="302">302 Sementara</option>
        </select>
      </div>
      <div class="form-group">
        <label>Dari (path)</label>
        <input name="rfrom" placeholder="/lama atau /" required>
        <small>/ untuk redirect seluruh domain</small>
      </div>
      <div class="form-group">
        <label>Ke (URL tujuan)</label>
        <input name="rto" placeholder="https://tujuan.com/path" required>
        <small>URL lengkap dengan http/https</small>
      </div>
    </div>
    <button class="btn btn-green" style="margin-top:16px">➕ Tambah Redirect</button>
  </form>
</div>

<div class="card">
  <h3>📋 Daftar Redirect (<?= count($redirects) ?>)</h3>
  <?php if(empty($redirects)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada redirect. File: <code>/etc/nginx/conf.d/panel-redirects.conf</code></p>
  <?php else: ?>
  <table>
    <thead><tr><th>Domain</th><th>Dari</th><th>Ke</th><th>Type</th><th>Tanggal</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach($redirects as $r): ?>
    <tr>
      <td><strong><?= sanitize($r['domain']) ?></strong></td>
      <td><code style="font-size:12px"><?= sanitize($r['from']) ?></code></td>
      <td><code style="font-size:12px"><?= sanitize($r['to']) ?></code></td>
      <td><span class="badge badge-blue"><?= $r['type'] ?></span></td>
      <td style="font-size:12px;color:var(--text2)"><?= $r['date'] ?></td>
      <td>
        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus redirect <?= sanitize($r['from']) ?> → <?= sanitize($r['to']) ?>?')">
          <input type="hidden" name="action" value="delete_redirect">
          <input type="hidden" name="rnum" value="<?= $r['num'] ?>">
          <button class="btn btn-red btn-xs">🗑️</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
// ===== CACHE MANAGER =====
elseif ($page === 'cache'):
  $cache_path = '/var/cache/nginx/';
  $cache_exists = is_dir($cache_path);
  $cache_size = $cache_exists ? trim(cmd("du -sh $cache_path 2>/dev/null | awk '{print $1}'")) : 'N/A';
?>
<div class="card"><h2>🧹 Cache Manager</h2></div>

<div class="stats">
  <div class="stat">
    <div class="val" style="font-size:20px"><?= $cache_exists ? '✅ Active' : '❌ Not Found' ?></div>
    <div class="lbl">FastCGI Cache</div>
  </div>
  <div class="stat">
    <div class="val" style="font-size:20px"><?= $cache_size ?></div>
    <div class="lbl">Cache Size</div>
  </div>
</div>

<div class="card">
  <h3>🧹 Purge Cache</h3>
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <form method="POST" onsubmit="return confirm('Hapus SEMUA Nginx cache?')">
      <input type="hidden" name="action" value="purge_cache">
      <input type="hidden" name="ctarget" value="all">
      <button class="btn btn-red">🗑️ Purge All Cache</button>
    </form>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end" onsubmit="return confirm('Hapus cache untuk domain ini?')">
      <input type="hidden" name="action" value="purge_cache">
      <input type="hidden" name="ctarget" value="site">
      <select name="cdomain" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
        <?php foreach($sites as $s): ?><option value="<?= $s['domain'] ?>"><?= $s['domain'] ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-yellow">🧹 Purge Per Site</button>
    </form>
  </div>
  <p style="font-size:11px;color:var(--text2);margin-top:12px">
    📍 Cache path: <code>/var/cache/nginx/</code> — Purge setelah update konten agar perubahan langsung terlihat.
  </p>
</div>

<?php
// ===== UPTIME MONITOR =====
elseif ($page === 'uptime'):
  $ulog = '/var/www/manager/uptime.log';
  $uhistory = [];
  if (file_exists($ulog)) {
    $lines = array_reverse(file($ulog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $uhistory = array_slice($lines, 0, 50);
  }
  // Quick status for each site
  $ustatus = [];
  foreach ($sites as $dm => $s) {
    $url = ($s['ssl'] ? 'https' : 'http') . '://' . $dm;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
    $start = microtime(true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = round((microtime(true) - $start) * 1000);
    curl_close($ch);
    $ustatus[] = ['domain' => $dm, 'code' => $code, 'time' => $time, 'up' => $code > 0 && $code < 500, 'ssl' => $s['ssl']];
  }
?>
<div class="card"><h2>📡 Uptime Monitor</h2></div>

<div class="actions">
  <form method="POST" style="display:inline">
    <input type="hidden" name="action" value="uptime_check">
    <button class="btn btn-green">🔄 Check All Now</button>
  </form>
  <form method="POST" style="display:inline" onsubmit="return confirm('Reset log uptime?')">
    <input type="hidden" name="action" value="clear_uptime">
    <button class="btn btn-gray">🗑️ Clear Log</button>
  </form>
</div>

<div class="card">
  <h3>🌐 Website Status</h3>
  <?php if(empty($ustatus)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada website.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Domain</th><th>Status</th><th>HTTP Code</th><th>Response</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($ustatus as $us): 
      $color = $us['up'] ? ($us['time'] > 1000 ? 'var(--yellow)' : 'var(--green)') : 'var(--red)';
    ?>
    <tr>
      <td><strong><?= sanitize($us['domain']) ?></strong></td>
      <td><span class="badge <?= $us['up'] ? 'badge-ok' : 'badge-err' ?>"><?= $us['up'] ? '🟢 UP' : '🔴 DOWN' ?></span></td>
      <td><?= $us['code'] ?: 'N/A' ?></td>
      <td style="color:<?= $color ?>;font-weight:600"><?= $us['time'] ?>ms</td>
      <td>
        <a href="<?= $us['ssl'] ? 'https' : 'http' ?>://<?= $us['domain'] ?>" target="_blank" class="btn btn-blue btn-xs">🔗 Visit</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>📋 Riwayat Uptime (<?= count($uhistory) ?> entries)</h3>
  <?php if(empty($uhistory)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada data. Klik "Check All Now".</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Waktu</th><th>Domain</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($uhistory as $h): 
      $parts = explode('|', $h);
      if (count($parts) >= 3):
    ?>
    <tr>
      <td style="font-size:12px;color:var(--text2)"><?= $parts[0] ?></td>
      <td><strong><?= sanitize($parts[1]) ?></strong></td>
      <td><span class="badge <?= ($parts[2] > 0 && $parts[2] < 500) ? 'badge-ok' : 'badge-err' ?>"><?= $parts[2] ?: 'ERR' ?></span></td>
    </tr>
    <?php endif; endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
// ===== SECURITY (FAIL2BAN) =====
elseif ($page === 'security'):
  $f2b_installed = is_executable('/usr/bin/fail2ban-client');
  $f2b_active = $f2b_installed && trim(cmd("systemctl is-active fail2ban 2>/dev/null")) === 'active';
  
  // Get jail status
  $jails = [];
  if ($f2b_active) {
    $raw = cmd("fail2ban-client status 2>/dev/null");
    preg_match_all('/`- ([a-zA-Z0-9_-]+)/', $raw, $jm);
    foreach (($jm[1] ?? []) as $j) {
      $js = cmd("fail2ban-client status $j 2>/dev/null");
      preg_match('/Currently banned:\s+(\d+)/', $js, $bm);
      preg_match('/Total banned:\s+(\d+)/', $js, $tm);
      $banned_ips = [];
      preg_match_all('/\t([0-9.]+)/', $js, $ips);
      $jails[] = ['name' => $j, 'banned' => intval($bm[1] ?? 0), 'total' => intval($tm[1] ?? 0), 'ips' => $ips[1] ?? []];
    }
  }
?>
<div class="card"><h2>🛡️ Security (Fail2Ban)</h2></div>

<?php if(!$f2b_installed): ?>
<div class="card" style="border:2px solid var(--yellow)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <strong style="font-size:16px;color:var(--yellow)">⚠️ Fail2Ban belum terinstall</strong>
      <p style="font-size:13px;color:var(--text2);margin-top:4px">Fail2Ban melindungi VPS dari serangan bruteforce SSH, Nginx, dan phpMyAdmin</p>
    </div>
    <form method="POST" onsubmit="return confirm('Install & konfigurasi Fail2Ban? (1-2 menit)')">
      <input type="hidden" name="action" value="install_f2b">
      <button class="btn btn-green">⚡ Install Fail2Ban</button>
    </form>
  </div>
</div>
<?php else: ?>

<div class="stats">
  <div class="stat">
    <div class="val" style="font-size:22px;color:<?= $f2b_active ? 'var(--green)' : 'var(--red)' ?>"><?= $f2b_active ? '🟢 Aktif' : '🔴 Mati' ?></div>
    <div class="lbl">Fail2Ban Status</div>
  </div>
  <div class="stat">
    <div class="val"><?= count($jails) ?></div>
    <div class="lbl">Jails Aktif</div>
  </div>
  <?php $tb = array_sum(array_column($jails, 'banned')); ?>
  <div class="stat">
    <div class="val" style="color:<?= $tb > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $tb ?></div>
    <div class="lbl">IP Dibanned</div>
  </div>
</div>

<?php if(!empty($jails)): ?>
<div class="card">
  <h3>🔒 Jail Status</h3>
  <table>
    <thead><tr><th>Jail</th><th>Banned Now</th><th>Total Banned</th><th>Banned IPs</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($jails as $j): ?>
    <tr>
      <td><strong><?= sanitize($j['name']) ?></strong></td>
      <td><span class="badge <?= $j['banned'] > 0 ? 'badge-err' : 'badge-ok' ?>"><?= $j['banned'] ?></span></td>
      <td><?= $j['total'] ?></td>
      <td style="font-size:11px;font-family:monospace">
        <?php if(empty($j['ips'])): echo '<span style="color:var(--text2)">—</span>'; else: ?>
        <?php foreach($j['ips'] as $ip): echo sanitize($ip) . ' '; endforeach; ?>
        <?php endif; ?>
      </td>
      <td>
        <?php foreach($j['ips'] as $ip): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Unban <?= $ip ?>?')">
          <input type="hidden" name="action" value="unban_ip">
          <input type="hidden" name="uip" value="<?= $ip ?>">
          <input type="hidden" name="ujail" value="<?= $j['name'] ?>">
          <button class="btn btn-green btn-xs">🔓 Unban</button>
        </form>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>


<?php
// ===== CLOUD BACKUP =====
elseif ($page === 'cloudbackup'):
  $rclone_ok = is_executable('/usr/bin/rclone');
  $rclone_remotes = $rclone_ok ? trim(cmd('rclone listremotes 2>/dev/null')) : '';
  $rclone_remote_list = $rclone_remotes ? explode("\n", trim($rclone_remotes)) : [];
  $gdrive_connected = $rclone_ok && in_array('gdrive:', $rclone_remote_list);
  $gdrive_info = $gdrive_connected ? trim(cmd('rclone about gdrive: 2>&1')) : '';
  $auth_url = $_SESSION['rclone_auth_url'] ?? '';
  $setup_step = $auth_url ? 2 : 1;
?>
<div class="card"><h2>☁️ Cloud Backup</h2></div>

<?php if(!$rclone_ok): ?>
<div class="card" style="border:2px solid var(--yellow)">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <strong style="font-size:16px">⚠️ Rclone belum terinstall</strong>
      <p style="font-size:13px;color:var(--text2);margin-top:4px">Rclone perlu diinstall dulu untuk koneksi ke cloud storage.</p>
    </div>
    <form method="POST" onsubmit="return confirm('Install rclone? (30 detik)')">
      <input type="hidden" name="action" value="install_rclone">
      <button class="btn btn-green">📦 Install Rclone</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if($rclone_ok): ?>
<div class="stats">
  <div class="stat">
    <div class="val" style="font-size:20px;color:var(--green)">✅ Installed</div>
    <div class="lbl">Rclone Status</div>
  </div>
  <div class="stat">
    <div class="val"><?= count($rclone_remote_list) ?></div>
    <div class="lbl">Cloud Remotes</div>
  </div>
  <?php $mega_connected = in_array('mega:', $rclone_remote_list); ?>
  <?php if($mega_connected): ?>
  <div class="stat">
    <div class="val" style="font-size:14px;color:var(--green)">✅ Mega.nz</div>
    <div class="lbl">20GB Free</div>
  </div>
  <?php endif; ?>
  <?php if($gdrive_connected): ?>
  <div class="stat">
    <div class="val" style="font-size:14px;color:var(--green)">✅ Connected</div>
    <div class="lbl">Google Drive</div>
  </div>
  <?php endif; ?>
</div>

<!-- ===== MEGA.NZ SETUP (SUPER SIMPLE) ===== -->
<?php if(!$mega_connected): ?>
<div class="card" style="border-left:4px solid #e83737">
  <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
    <div style="font-size:40px">🅼</div>
    <div style="flex:1;min-width:250px">
      <h3 style="color:#e83737;margin-bottom:4px">⚡ Mega.nz — Paling Mudah!</h3>
      <p style="font-size:12px;color:var(--text2);margin-bottom:12px">Gratis 20GB. Cukup email + password. Gak perlu OAuth, gak perlu bikin API key, gak ribet.</p>
      <form method="POST">
        <input type="hidden" name="action" value="rclone_mega_connect">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <div class="form-group" style="margin-bottom:0">
            <label>Email Mega.nz</label>
            <input name="mega_email" placeholder="email@example.com" required style="width:220px;padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label>Password</label>
            <input type="password" name="mega_pass" placeholder="password mega" required style="width:180px;padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          </div>
          <button class="btn" style="background:#e83737;color:#fff;padding:10px 20px">⚡ Connect Mega</button>
        </div>
      </form>
      <p style="font-size:10px;color:var(--text2);margin-top:8px">🔒 Password tidak disimpan di file, cuma dipakai rclone config. Kalau belum punya akun: <a href="https://mega.nz/register" target="_blank" style="color:var(--blue)">daftar gratis</a>.</p>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card">
  <h3>🅼 Mega.nz Storage</h3>
  <p style="color:var(--text2);font-size:12px"><?= sanitize(trim(cmd('rclone about mega: 2>&1'))) ?></p>
</div>
<?php endif; ?>

<!-- ===== GOOGLE DRIVE SETUP WIZARD ===== -->
<?php if(!$gdrive_connected): ?>
<div class="card" style="border-left:4px solid #2563eb">
  <h3>🔗 Setup Google Drive — Step <?= $setup_step ?> dari 2</h3>
  
  <?php if($setup_step === 1): ?>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
    Hubungkan Google Drive dalam 2 langkah mudah. Ikuti instruksi di bawah.
  </p>
  
  <div style="background:var(--bg);padding:16px;border-radius:8px;margin-bottom:16px">
    <strong style="color:var(--primary)">📋 Persiapan: Buat OAuth Credentials di Google Cloud Console</strong>
    <ol style="font-size:12px;color:var(--text2);margin-top:8px;padding-left:20px;line-height:2">
      <li>Buka <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--blue)">Google Cloud Console → Credentials</a></li>
      <li>Klik <b>+ CREATE CREDENTIALS → OAuth client ID</b></li>
      <li>Pilih <b>Application type: Desktop app</b>, beri nama bebas</li>
      <li>Klik <b>CREATE</b> — akan muncul <b>Client ID</b> dan <b>Client Secret</b></li>
      <li>Copy-paste keduanya ke form di bawah 👇</li>
    </ol>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="rclone_gdrive_step1">
    <div class="form-grid">
      <div class="form-group">
        <label>🔑 Google OAuth Client ID</label>
        <input name="gdrive_cid" placeholder="xxxxx.apps.googleusercontent.com" required style="font-family:monospace;font-size:12px">
      </div>
      <div class="form-group">
        <label>🔒 Google OAuth Client Secret</label>
        <input name="gdrive_csec" placeholder="GOCSPX-xxxxx" required style="font-family:monospace;font-size:12px">
      </div>
    </div>
    <button class="btn btn-green" style="margin-top:14px">➡️ Generate Auth URL</button>
  </form>

  <?php else: ?>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
    Step 2: Buka URL di bawah di browser, login ke Google, lalu copy-paste verification code.
  </p>

  <div style="background:var(--bg);padding:16px;border-radius:8px;margin-bottom:16px">
    <strong style="color:var(--primary)">🔗 Step 2a: Buka link ini di browser Anda (HP/Desktop)</strong>
    <div style="word-break:break-all;padding:12px;background:#fdfcfb;border:1px solid var(--border);border-radius:6px;margin-top:8px;font-size:11px;font-family:monospace;max-height:80px;overflow:auto">
      <a href="<?= htmlspecialchars($auth_url) ?>" target="_blank" style="color:var(--blue)"><?= htmlspecialchars($auth_url) ?></a>
    </div>
    <p style="font-size:11px;color:var(--text2);margin-top:8px">
      ⚠️ Pilih akun Google yang sama dengan yang dipakai buat OAuth credentials.<br>
      Klik <b>Allow</b> / <b>Lanjutkan</b> sampai muncul kode verifikasi.
    </p>
  </div>

  <div style="background:var(--bg);padding:16px;border-radius:8px;margin-bottom:16px">
    <strong style="color:var(--primary)">📋 Step 2b: Paste verification code dari Google</strong>
    <form method="POST">
      <input type="hidden" name="action" value="rclone_gdrive_step2">
      <div class="form-group" style="margin-top:8px">
        <input name="gdrive_code" placeholder="4/0AanRRr..." required style="font-family:monospace;font-size:12px">
        <small>Code biasanya diawali dengan <b>4/</b></small>
      </div>
      <button class="btn btn-green" style="margin-top:10px">✅ Hubungkan Google Drive</button>
    </form>
  </div>

  <form method="POST" style="display:inline">
    <input type="hidden" name="action" value="rclone_remove">
    <input type="hidden" name="rr_remote" value="">
    <button class="btn btn-gray btn-sm" onclick="return confirm('Batalkan setup?')">↩️ Batalkan Setup</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===== CONNECTED REMOTES ===== -->
<?php if(!empty($rclone_remote_list)): ?>
<div class="card">
  <h3>🔗 Connected Cloud Remotes</h3>
  <table><thead><tr><th>Remote</th><th>Status</th><th>Info</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($rclone_remote_list as $rm): $rn = rtrim($rm, ':'); ?>
  <tr>
    <td><strong><?= sanitize($rm) ?></strong></td>
    <td><span class="badge badge-ok">✅ Connected</span></td>
    <td style="font-size:11px;color:var(--text2)">
      <?php if($rn === 'gdrive' && $gdrive_info): ?>
        <?= sanitize(substr($gdrive_info, 0, 120)) ?>
      <?php else: ?>
        <?= sanitize(trim(cmd("rclone about $rm 2>/dev/null | head -1"))) ?>
      <?php endif; ?>
    </td>
    <td>
      <form method="POST" style="display:inline" onsubmit="return confirm('Putuskan koneksi <?= sanitize($rn) ?>?')">
        <input type="hidden" name="action" value="rclone_remove">
        <input type="hidden" name="rr_remote" value="<?= sanitize($rn) ?>">
        <button class="btn btn-red btn-xs">🗑️ Disconnect</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ===== BACKUP FORM ===== -->
<?php if($mega_connected || $gdrive_connected || !empty($rclone_remote_list)): ?>
<div class="card">
  <h3>💾 Backup & Sync ke Cloud</h3>
  <form method="POST">
    <input type="hidden" name="action" value="cloud_backup">
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group">
        <label>Website</label>
        <select name="cb_domain" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          <?php foreach($sites as $s): if($s['root_exists']): ?><option value="<?= $s['domain'] ?>"><?= $s['domain'] ?></option><?php endif; endforeach; ?>
        </select>
      </div>
      <button class="btn btn-green" style="margin-bottom:0">💾 Backup + Sync ke Cloud</button>
    </div>
  </form>
  <p style="font-size:11px;color:var(--text2);margin-top:8px">
    Backup file website dikirim ke <code>/var/www/manager/backups/</code> lalu disync ke Google Drive folder <b>vps-backups/</b>
  </p>
</div>
<?php endif; ?>

<?php
// ===== PHP SETTINGS PER SITE =====
elseif ($page === 'phpsite'):
  $ps_file = '/var/www/manager/.site_php.json';
  $ps_all = file_exists($ps_file) ? json_decode(file_get_contents($ps_file), true) : [];
  $ps_domain = $_GET['domain'] ?? '';
  $ps_current = $ps_domain && isset($ps_all[$ps_domain]) ? $ps_all[$ps_domain] : ['upload_max_filesize' => '20M', 'post_max_size' => '25M', 'max_execution_time' => 120, 'memory_limit' => '256M', 'display_errors' => 'Off', 'max_input_vars' => 3000];
?>
<div class="card"><h2>⚡ PHP Settings per Website</h2></div>

<div class="card">
  <h3>📋 Konfigurasi per Website</h3>
  <?php if(empty($ps_all)): ?>
    <p style="color:var(--text2);text-align:center;padding:20px">Belum ada konfigurasi per-site. Pilih website di bawah.</p>
  <?php else: ?>
  <table><thead><tr><th>Website</th><th>Upload Max</th><th>Memory</th><th>Execution</th><th>Errors</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($ps_all as $dm => $cfg): ?>
  <tr>
    <td><strong><?= sanitize($dm) ?></strong></td>
    <td><?= $cfg['upload_max_filesize'] ?></td>
    <td><?= $cfg['memory_limit'] ?></td>
    <td><?= $cfg['max_execution_time'] ?>s</td>
    <td><span class="badge <?= $cfg['display_errors'] === 'On' ? 'badge-err' : 'badge-ok' ?>"><?= $cfg['display_errors'] ?></span></td>
    <td><a href="?page=phpsite&domain=<?= urlencode($dm) ?>" class="btn btn-blue btn-xs">✏️ Edit</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<div class="card">
  <h3><?= $ps_domain ? '✏️ Edit: ' . sanitize($ps_domain) : '➕ Konfigurasi Website Baru' ?></h3>
  <form method="POST">
    <input type="hidden" name="action" value="php_per_site_save">
    <div class="form-grid">
      <div class="form-group">
        <label>Website</label>
        <select name="ps_domain" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px">
          <?php foreach($sites as $s): $sel = $s['domain'] === $ps_domain ? 'selected' : ''; ?>
          <option value="<?= $s['domain'] ?>" <?= $sel ?>><?= $s['domain'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Upload Max Filesize</label><input name="ps_upload" value="<?= $ps_current['upload_max_filesize'] ?>"><small>Contoh: 2M, 20M, 100M</small></div>
      <div class="form-group"><label>Post Max Size</label><input name="ps_post" value="<?= $ps_current['post_max_size'] ?>"><small>Harus ≥ upload max</small></div>
      <div class="form-group"><label>Max Execution Time (detik)</label><input name="ps_exec" type="number" value="<?= $ps_current['max_execution_time'] ?>"></div>
      <div class="form-group"><label>Memory Limit</label><input name="ps_memory" value="<?= $ps_current['memory_limit'] ?>"><small>128M, 256M, 512M</small></div>
      <div class="form-group"><label>Display Errors</label><select name="ps_errors" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px"><option value="Off" <?= $ps_current['display_errors']==='Off'?'selected':'' ?>>Off (Production)</option><option value="On" <?= $ps_current['display_errors']==='On'?'selected':'' ?>>On (Debug)</option></select></div>
      <div class="form-group"><label>Max Input Vars</label><input name="ps_vars" type="number" value="<?= $ps_current['max_input_vars'] ?>"></div>
    </div>
    <button class="btn btn-green" style="margin-top:16px">💾 Simpan & Apply ke .user.ini</button>
  </form>
  <p style="font-size:11px;color:var(--text2);margin-top:12px">
    ⚠️ Setting disimpan ke <code>.user.ini</code> di root folder website. Restart PHP-FPM untuk apply perubahan.
  </p>
</div>

<?php
// ===== MIGRATION (SYSTEM BACKUP & RESTORE) =====
elseif ($page === 'migration'):
  if (!is_dir(MIGRATION_DIR)) mkdir(MIGRATION_DIR, 0755, true);
  $migrations = [];
  foreach (glob(MIGRATION_DIR . '/*.tar.gz') as $f) {
      $migrations[] = ['name' => basename($f), 'size' => round(filesize($f) / 1024, 1), 'time' => filemtime($f)];
  }
  usort($migrations, function($a, $b) { return $b['time'] - $a['time']; });
  $hostname = trim(cmd('hostname'));
  $php_versions_installed = [];
  foreach (glob('/usr/bin/php[0-9]*') as $p) {
      if (preg_match('/php([\d.]+)$/', basename($p), $m)) $php_versions_installed[] = $m[1];
  }
  if (empty($php_versions_installed)) $php_versions_installed = ['8.1'];
  
  // Count items to backup
  $www_count = 0;
  $www_dirs = glob('/var/www/*');
  if (is_array($www_dirs)) {
      foreach ($www_dirs as $d) { if (is_dir($d) && basename($d) !== 'manager') $www_count++; }
  }
  $ssl_exists = is_dir('/etc/letsencrypt/live') && count(glob('/etc/letsencrypt/live/*')) > 0;
?>
<div class="card"><h2>🔄 System Migration</h2></div>

<div class="stats">
  <div class="stat"><div class="val"><?= $www_count ?></div><div class="lbl">Websites to Backup</div></div>
  <div class="stat"><div class="val"><?= count($dbs) ?></div><div class="lbl">Databases</div></div>
  <div class="stat"><div class="val"><?= count($migrations) ?></div><div class="lbl">Migration Files</div></div>
  <div class="stat"><div class="val" style="font-size:14px"><?= sanitize($hostname) ?></div><div class="lbl">VPS Hostname</div></div>
</div>

<!-- BACKUP SECTION -->
<div class="card" style="border-left:4px solid #1e3a5f">
  <h3>📦 Create Full System Backup</h3>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
    Backup mencakup: semua website, semua database MySQL, Nginx configs, panel settings, cron jobs, UFW rules, PHP versions, system info.
  </p>
  
  <details style="margin-bottom:16px;font-size:12px;color:var(--text2)">
    <summary style="cursor:pointer;font-weight:600;color:var(--primary)">📋 Detail yang akan dibackup</summary>
    <div style="margin-top:8px;padding-left:16px;line-height:2">
      ✅ Semua website di /var/www/ (kecuali panel manager)<br>
      ✅ Semua database MySQL (full dump --all-databases)<br>
      ✅ Nginx configs: sites-available, sites-enabled, conf.d<br>
      ✅ Panel settings: .users, .passwd, .site_php.json, .cf_token, uptime.log<br>
      ✅ SSL certificates (opsional, centang di bawah)<br>
      ✅ Cron jobs (crontab -l root)<br>
      ✅ UFW firewall rules<br>
      ✅ Daftar PHP versions + version default<br>
      ✅ System info (untuk verifikasi kompatibilitas restore)
    </div>
  </details>
  
  <form method="POST" onsubmit="return confirm('Buat full system backup? Proses 1-5 menit tergantung jumlah data.')">
    <input type="hidden" name="action" value="migration_backup">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
      <input type="checkbox" name="include_ssl" id="include_ssl" value="1" <?= $ssl_exists ? '' : 'disabled' ?> style="width:18px;height:18px">
      <label for="include_ssl" style="font-weight:600;font-size:14px">🔒 Include SSL certificates (/etc/letsencrypt/) <?= !$ssl_exists ? '(tidak ada SSL)' : '' ?></label>
    </div>
    <small style="color:var(--text2);display:block;margin-bottom:14px">
      ⚠ SSL size bisa besar (50-500MB). Tanpa SSL, backup biasanya 5-200MB.
    </small>
    <button class="btn btn-green" style="font-size:15px;padding:12px 24px">📦 Create Full Backup</button>
  </form>
</div>

<!-- RESTORE SECTION -->
<div class="card" style="border-left:4px solid #2c5282">
  <h3>📥 Restore from Backup</h3>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
    Upload file backup <b>.tar.gz</b> hasil Migration. File <b>.zip</b> dari menu Backup biasa TIDAK bisa dipakai di sini.
  </p>
  
  <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Restore dari backup? Data existing mungkin ditimpa. Pastikan Anda yakin!')">
    <input type="hidden" name="action" value="migration_restore">
    <div class="form-group" style="margin-bottom:14px">
      <label>Upload Migration Backup (*.tar.gz)</label>
      <input type="file" name="migfile" accept=".tar.gz,.tgz" required style="padding:10px">
      <small>File format: migration-VPS-HOSTNAME-YYYYMMDD-HHMMSS.tar.gz</small>
    </div>
    
    <div style="background:var(--bg);padding:16px;border-radius:8px;margin-bottom:16px">
      <strong style="font-size:13px;color:var(--primary)">Opsi Restore:</strong>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:8px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="restore_panel" value="1" checked style="width:16px;height:16px"> ⚙ Panel Settings
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="restore_ssl" value="1" style="width:16px;height:16px"> 🔒 SSL Certs
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="restore_cron" value="1" checked style="width:16px;height:16px"> ⏰ Cron Jobs
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="restore_ufw" value="1" style="width:16px;height:16px"> 🛡 UFW Rules
        </label>
      </div>
    </div>
    
    <button class="btn btn-blue" style="font-size:15px;padding:12px 24px">📥 Restore from Backup</button>
  </form>
</div>

<!-- MIGRATION FILES LIST -->
<div class="card">
  <h3>📋 Migration Backup Files (<?= count($migrations) ?>)</h3>
  <?php if(empty($migrations)): ?>
    <p style="color:var(--text2);text-align:center;padding:40px">Belum ada file migration backup. Klik "Create Full Backup" di atas.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>File</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($migrations as $m): ?>
    <tr>
      <td><strong style="font-size:12px">📦 <?= sanitize($m['name']) ?></strong></td>
      <td><span class="badge badge-blue"><?= $m['size'] >= 1024 ? round($m['size']/1024,1).' MB' : $m['size'].' KB' ?></span></td>
      <td style="font-size:12px;color:var(--text2)"><?= date('d/m/Y H:i', $m['time']) ?></td>
      <td>
        <a href="/backups/migration/<?= urlencode($m['name']) ?>" download class="btn btn-blue btn-xs">⬇ Download</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus migration backup <?= sanitize($m['name']) ?>?')">
          <input type="hidden" name="action" value="migration_delete">
          <input type="hidden" name="file" value="<?= sanitize($m['name']) ?>">
          <button class="btn btn-red btn-xs">🗑</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
// ===== TERMINAL =====
elseif ($page === 'terminal'):
?>
<div class="card">
  <h2>💻 Web Terminal</h2>
  <p style="color:#888;margin-bottom:16px">Full terminal access via browser. Type <code>exit</code> to close session, <code>Ctrl+C</code> to interrupt.</p>
  <div style="border:2px solid #1e3a5f;border-radius:12px;overflow:hidden;background:#1a1a2e">
    <iframe id="terminal-frame" style="width:100%;height:520px;border:0" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals"></iframe>
  </div>
  <script>
  document.getElementById("terminal-frame").src = "http://" + location.hostname + ":8081";
  </script>
</div>
<?php
// ===== USER MANAGEMENT =====
elseif ($page === 'users'):
  if (!is_admin()) { flash('Akses ditolak! Hanya admin.', 'err'); redirect('dashboard'); }
  $all_users = load_users();
?>
<div class="card"><h2>👥 User Management (<?= count($all_users) ?> users)</h2></div>

<div class="card">
  <h3>➕ Add User</h3>
  <form method="POST" style="max-width:500px">
    <input type="hidden" name="action" value="add_user">
    <div class="form-grid">
      <div class="form-group"><label>Username</label><input name="uname" placeholder="username" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="upass" placeholder="Min 6 karakter" required></div>
      <div class="form-group"><label>Role</label><select name="urole" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px"><option value="admin">Admin (Full Access)</option><option value="user" selected>User (Terbatas)</option></select></div>
    </div>
    <button class="btn btn-green" style="margin-top:14px">➕ Tambah User</button>
  </form>
</div>

<div class="card">
  <h3>📋 Daftar Users</h3>
  <table><thead><tr><th>Username</th><th>Role</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($all_users as $u): ?>
  <tr>
    <td><strong><?= sanitize($u['user']) ?></strong> <?= ($_SESSION['user']['user'] ?? '') === $u['user'] ? '<span class="badge badge-blue">You</span>' : '' ?></td>
    <td><span class="badge <?= $u['role']==='admin'?'badge-ok':'badge-yellow' ?>"><?= $u['role'] ?></span></td>
    <td>
      <?php if(($u['user'] ?? '') !== ($_SESSION['user']['user'] ?? '')): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Hapus user <?= sanitize($u['user']) ?>?')">
        <input type="hidden" name="action" value="del_user">
        <input type="hidden" name="duser" value="<?= sanitize($u['user']) ?>">
        <button class="btn btn-red btn-xs">🗑️ Hapus</button>
      </form>
      <?php else: ?>
      <span style="font-size:11px;color:var(--text2)">Current user</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>

<?php
// ===== DNS MANAGEMENT =====
elseif ($page === 'dns'):
  $cf_token = file_exists('/var/www/manager/.cf_token') ? trim(file_get_contents('/var/www/manager/.cf_token')) : '';
  $dns_zone = $_GET['zone'] ?? '';
  $dns_records = [];
  $dns_zones = [];
  if ($cf_token && $dns_zone) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones?name=$dns_zone");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $cf_token", 'Content-Type: application/json']]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $zone_id = $resp['result'][0]['id'] ?? '';
    if ($zone_id) {
      $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records?per_page=100");
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $cf_token", 'Content-Type: application/json']]);
      $resp2 = json_decode(curl_exec($ch), true);
      curl_close($ch);
      $dns_records = $resp2['result'] ?? [];
    }
  }
  if ($cf_token) {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones?per_page=50");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $cf_token", 'Content-Type: application/json']]);
    $resp3 = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $dns_zones = $resp3['result'] ?? [];
  }
?>
<div class="card"><h2>🌍 DNS Management (Cloudflare)</h2></div>

<?php if(!$cf_token): ?>
<div class="card" style="border:2px solid var(--yellow)">
  <h3>⚙️ Setup Cloudflare API Token</h3>
  <form method="POST" style="max-width:500px">
    <input type="hidden" name="action" value="dns_add">
    <input type="hidden" name="d_zone" value="">
    <input type="hidden" name="d_name" value="">
    <input type="hidden" name="d_type" value="A">
    <input type="hidden" name="d_content" value="1.1.1.1">
    <div class="form-group"><label>Cloudflare API Token</label><input name="d_token" placeholder="Masukkan API token..." required><small>Dapatkan dari Cloudflare Dashboard → Profile → API Tokens</small></div>
    <button class="btn btn-green" style="margin-top:12px">💾 Simpan Token & Lanjut</button>
  </form>
  <p style="font-size:11px;color:var(--text2);margin-top:12px">
    Token disimpan ke <code>/var/www/manager/.cf_token</code>. Permission: Zone:DNS:Edit.
  </p>
</div>
<?php else: ?>
<div class="stats">
  <div class="stat"><div class="val" style="font-size:20px;color:var(--green)">✅ Connected</div><div class="lbl">Cloudflare API</div></div>
  <div class="stat"><div class="val"><?= count($dns_zones) ?></div><div class="lbl">Zones</div></div>
</div>

<div class="card"><h3>📋 Cloudflare Zones</h3>
  <?php if(empty($dns_zones)): ?><p style="color:var(--text2);padding:20px">Tidak ada zones. Cek token.</p>
  <?php else: ?>
  <table><thead><tr><th>Domain</th><th>Status</th><th>Plan</th><th>Name Servers</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($dns_zones as $z): ?>
  <tr>
    <td><strong><?= sanitize($z['name']) ?></strong></td>
    <td><span class="badge <?= $z['status']==='active'?'badge-ok':'badge-err' ?>"><?= $z['status'] ?></span></td>
    <td><?= $z['plan']['name'] ?></td>
    <td style="font-size:11px;color:var(--text2)"><?= sanitize(implode(', ', $z['name_servers'] ?? [])) ?></td>
    <td><a href="?page=dns&zone=<?= urlencode($z['name']) ?>" class="btn btn-blue btn-xs">📋 Records</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>

<?php if($dns_zone): ?>
<div class="card">
  <h3>➕ Add Record - <?= sanitize($dns_zone) ?></h3>
  <form method="POST">
    <input type="hidden" name="action" value="dns_add">
    <input type="hidden" name="d_zone" value="<?= sanitize($dns_zone) ?>">
    <input type="hidden" name="d_token" value="">
    <div class="form-grid">
      <div class="form-group"><label>Name</label><input name="d_name" placeholder="@ atau www atau sub.domain.com" required></div>
      <div class="form-group"><label>Type</label><select name="d_type" style="padding:10px 14px;border-radius:8px;border:2px solid var(--border);background:var(--surface);color:var(--text);font-size:14px"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option></select></div>
      <div class="form-group"><label>Content</label><input name="d_content" placeholder="IP address atau domain tujuan" required></div>
      <div class="form-group"><label>TTL</label><input name="d_ttl" type="number" value="120"><small>Auto (1 = auto)</small></div>
    </div>
    <button class="btn btn-green" style="margin-top:14px">➕ Add Record</button>
  </form>
</div>

<div class="card">
  <h3>📋 DNS Records (<?= count($dns_records) ?>)</h3>
  <?php if(empty($dns_records)): ?><p style="color:var(--text2);padding:20px">Tidak ada records.</p>
  <?php else: ?>
  <table><thead><tr><th>Type</th><th>Name</th><th>Content</th><th>TTL</th><th>Proxied</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($dns_records as $r): ?>
  <tr>
    <td><span class="badge badge-blue"><?= $r['type'] ?></span></td>
    <td><strong><?= sanitize($r['name']) ?></strong></td>
    <td><code style="font-size:12px"><?= sanitize($r['content']) ?></code></td>
    <td><?= $r['ttl'] === 1 ? 'Auto' : $r['ttl'] ?></td>
    <td><?= ($r['proxied'] ?? false) ? '☁️' : '➡️' ?></td>
    <td>
      <form method="POST" style="display:inline" onsubmit="return confirm('Hapus record <?= sanitize($r['name']) ?> (<?= $r['type'] ?>)?')">
        <input type="hidden" name="action" value="dns_del">
        <input type="hidden" name="d_zone" value="<?= sanitize($dns_zone) ?>">
        <input type="hidden" name="d_recid" value="<?= $r['id'] ?>">
        <button class="btn btn-red btn-xs">🗑️</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>
</div></div>

</body></html>
