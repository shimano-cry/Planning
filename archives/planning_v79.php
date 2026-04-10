<?php
/* =====================================================================
   PLANNING v65 — C.C.P.D. Tournai
   Script SQL d'installation : voir install_planning.sql
   ===================================================================== */

/* ── CONFIGURATION ─────────────────────────────────────────────────────
   Créez un fichier config.php dans le même dossier avec ce contenu :

   <?php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'votre_utilisateur_mysql');
   define('DB_PASS', 'votre_mot_de_passe');
   define('DB_NAME', 'planning');
   define('APP_ENV', 'production'); // 'production' ou 'developpement'
   define('RESET_PASSWORD', 'MotDePasseResetAChanger!'); // À changer absolument !

   Si config.php n'existe pas, les valeurs ci-dessous sont utilisées.
   ──────────────────────────────────────────────────────────────────── */
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
    // S'assurer que RESET_PASSWORD est défini même si config.php ancien
    if (!defined('RESET_PASSWORD')) define('RESET_PASSWORD', 'RESET2026');
} else {
    // Valeurs par défaut — À MODIFIER pour la production
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'planning');
    define('APP_ENV', 'development');
    // Mot de passe de reset des données (à définir dans config.php en production !)
    define('RESET_PASSWORD', 'RESET2026');
}

// Affichage des erreurs selon l'environnement
if (defined('APP_ENV') && APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    // En production, les erreurs sont loguées (configurez error_log dans php.ini)
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Sécurisation des cookies de session
session_set_cookie_params([
    'lifetime' => 0,           // Expire à la fermeture du navigateur
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']), // Cookie uniquement sur HTTPS si disponible
    'httponly' => true,        // Inaccessible au JavaScript (protection XSS)
    'samesite' => 'Strict',    // Protection CSRF : cookie non envoyé depuis d'autres sites
]);
session_start();

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    // Message d'erreur sécurisé : ne pas afficher les détails en production
    if (defined('APP_ENV') && APP_ENV === 'production') {
        die('<div style="padding:20px;font-family:sans-serif;color:#c00">Erreur de connexion à la base de données. Contactez l\'administrateur.</div>');
    } else {
        die('Erreur DB : ' . $mysqli->connect_error);
    }
}
$mysqli->set_charset("utf8mb4");

// ── Migrations de schéma (exécutées une seule fois via table app_meta) ─────
// Chaque migration est identifiée par une clé unique et ne tourne qu'une seule fois.
$mysqli->query("CREATE TABLE IF NOT EXISTS app_meta (
  cle   VARCHAR(100) PRIMARY KEY,
  valeur TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function runMigrationOnce(mysqli $db, string $key, callable $fn): void {
    $res = $db->query("SELECT cle FROM app_meta WHERE cle='".$db->real_escape_string($key)."'");
    if ($res && $res->num_rows > 0) return; // Déjà exécutée
    $fn($db);
    $db->query("INSERT IGNORE INTO app_meta (cle,valeur) VALUES ('".$db->real_escape_string($key)."','done')");
}

runMigrationOnce($mysqli, 'create_user_rights_v1', function(mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS user_rights (
      id        INT AUTO_INCREMENT PRIMARY KEY,
      user_id   INT NOT NULL,
      can_conge TINYINT(1) DEFAULT 1,
      can_perm  TINYINT(1) DEFAULT 0,
      UNIQUE KEY uk_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

runMigrationOnce($mysqli, 'create_tir_notes_v1', function(mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS tir_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent VARCHAR(100) NOT NULL,
        annee SMALLINT NOT NULL,
        note TEXT NOT NULL DEFAULT '',
        UNIQUE KEY uk_agent_annee (agent, annee)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

runMigrationOnce($mysqli, 'alter_permanences_type_v1', function(mysqli $db) {
    $db->query("ALTER TABLE permanences MODIFY COLUMN type VARCHAR(10) NOT NULL DEFAULT 'M'");
    $db->query("ALTER TABLE permanences MODIFY COLUMN cycle_orig VARCHAR(10) NOT NULL DEFAULT ''");
});

runMigrationOnce($mysqli, 'add_conges_maladie_v1', function(mysqli $db) {
    // Ajout CMO, CLM, CLD dans types_conges si absents
    $db->query("INSERT IGNORE INTO types_conges (code,libelle,couleur_bg,couleur_txt,actif) VALUES
      ('CMO','Congé Maladie Ordinaire','#ff6666','#ffffff',1),
      ('CLM','Congé Longue Maladie','#cc0000','#ffffff',1),
      ('CLD','Congé Longue Durée','#990000','#ffffff',1)");
});

runMigrationOnce($mysqli, 'add_type_prev_v1', function(mysqli $db) {
    // Ajout du type PREV (Prévisionnel congé) et AUT (Autres absences)
    $db->query("INSERT IGNORE INTO types_conges (code,libelle,couleur_bg,couleur_txt,actif) VALUES
      ('PREV','Prévisionnel congé','#ffcc80','#7a3e00',1),
      ('AUT','Autres absences','#b0bec5','#212121',1)");
});

runMigrationOnce($mysqli, 'add_type_aut_v1', function(mysqli $db) {
    // Migration séparée pour AUT — s'exécute même si add_type_prev_v1 est déjà passée
    $db->query("INSERT IGNORE INTO types_conges (code,libelle,couleur_bg,couleur_txt,actif) VALUES
      ('AUT','Autres absences','#b0bec5','#212121',1)");
});

runMigrationOnce($mysqli, 'add_locks_mois_v1', function(mysqli $db) {
    // Migration initiale — peut avoir échoué sur certaines versions MySQL
    $res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locks' AND COLUMN_NAME='mois'");
    if (!$res || $res->num_rows === 0) {
        $db->query("ALTER TABLE locks ADD COLUMN mois VARCHAR(7) DEFAULT NULL");
    }
    $db->query("CREATE INDEX IF NOT EXISTS idx_locks_agent_mois ON locks(scope,agent,mois)");
});

runMigrationOnce($mysqli, 'add_locks_mois_v2', function(mysqli $db) {
    // Migration de rattrapage
    $res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locks' AND COLUMN_NAME='mois'");
    if (!$res || $res->num_rows === 0) {
        $db->query("ALTER TABLE locks ADD COLUMN mois VARCHAR(7) DEFAULT NULL");
    }
    $db->query("DELETE FROM locks WHERE scope='agent_mois' AND mois IS NULL");
});

// Création table locks_mois dédiée — indépendante des migrations, créée à chaque démarrage si absente
$mysqli->query("CREATE TABLE IF NOT EXISTS locks_mois (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    agent     VARCHAR(80) NOT NULL,
    mois      VARCHAR(7)  NOT NULL,
    locked_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_agent_mois (agent, mois)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

runMigrationOnce($mysqli, 'update_couleurs_conges_v1', function(mysqli $db) {
    // Congés annuels : violet → bleu roi (différenciation visuelle avec vacation gendarmes)
    $db->query("UPDATE types_conges SET couleur_bg='#1565c0',couleur_txt='#ffffff' WHERE code IN ('CA','CAA','CAM')");
    // CET : violet moyen → bleu foncé
    $db->query("UPDATE types_conges SET couleur_bg='#0d47a1',couleur_txt='#ffffff' WHERE code='CET'");
    // Douane CA : même bleu roi
    $db->query("UPDATE types_conges_douane SET couleur_bg='#1565c0',couleur_txt='#ffffff' WHERE code='CA'");
});

runMigrationOnce($mysqli, 'create_vacation_overrides_v1', function(mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS vacation_overrides (
      id         INT AUTO_INCREMENT PRIMARY KEY,
      agent      VARCHAR(80) NOT NULL,
      date       DATE NOT NULL,
      vacation   ENUM('J','M','AM','NUIT') NOT NULL,
      created_by INT DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_agent_date (agent,date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

runMigrationOnce($mysqli, 'create_agent_quotas_v2', function(mysqli $db) {
    // Supprimer l'ancienne table (v1 sans type_conge) et recréer la v2
    $db->query("DROP TABLE IF EXISTS agent_quotas");
    $db->query("CREATE TABLE agent_quotas (
      id         INT AUTO_INCREMENT PRIMARY KEY,
      agent      VARCHAR(80) NOT NULL,
      annee      INT NOT NULL,
      type_conge VARCHAR(10) NOT NULL,
      quota      DECIMAL(6,2) NOT NULL DEFAULT 0,
      unite      ENUM('jours','heures') NOT NULL DEFAULT 'jours',
      UNIQUE KEY uk_agent_annee_type (agent,annee,type_conge)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Pré-remplir les quotas Police pour l'année courante
    $yr = (int)date('Y');
    $quotas = [
      // agent, type, quota, unite
      ['CoGe ROUSSEL','CA',25,'jours'],['CoGe ROUSSEL','HP',2,'jours'],['CoGe ROUSSEL','RTT',16,'jours'],
      ['Cne MOKADEM','CA',25,'jours'],['Cne MOKADEM','HP',2,'jours'],['Cne MOKADEM','RTT',21,'jours'],
      ['BC MASSON','CA',23,'jours'],['BC MASSON','HP',2,'jours'],['BC MASSON','RTC',41.75,'heures'],['BC MASSON','CF',109.20,'heures'],
      ['BC SIGAUD','CA',23,'jours'],['BC SIGAUD','HP',2,'jours'],['BC SIGAUD','RTC',41.75,'heures'],['BC SIGAUD','CF',109.20,'heures'],
      ['BC DAINOTTI','CA',23,'jours'],['BC DAINOTTI','HP',2,'jours'],['BC DAINOTTI','RTC',41.75,'heures'],['BC DAINOTTI','CF',109.20,'heures'],
      ['BC BOUXOM','CA',25,'jours'],['BC BOUXOM','HP',2,'jours'],['BC BOUXOM','RTT',16,'jours'],
      ['BC ARNAULT','CA',25,'jours'],['BC ARNAULT','HP',2,'jours'],['BC ARNAULT','RTT',16,'jours'],
      ['BC HOCHARD','CA',25,'jours'],['BC HOCHARD','HP',2,'jours'],['BC HOCHARD','RTT',16,'jours'],
      ['BC DUPUIS','CA',25,'jours'],['BC DUPUIS','HP',2,'jours'],['BC DUPUIS','RTT',16,'jours'],
      ['BC BASTIEN','CA',25,'jours'],['BC BASTIEN','HP',2,'jours'],['BC BASTIEN','RTT',16,'jours'],
      ['BC ANTHONY','CA',25,'jours'],['BC ANTHONY','HP',2,'jours'],['BC ANTHONY','RTT',16,'jours'],
      ['GP DHALLEWYN','CA',25,'jours'],['GP DHALLEWYN','HP',2,'jours'],['GP DHALLEWYN','RTT',16,'jours'],
      ['BC DELCROIX','CA',25,'jours'],['BC DELCROIX','HP',2,'jours'],['BC DELCROIX','RTT',16,'jours'],
      ['AA MAES','CA',25,'jours'],['AA MAES','HP',2,'jours'],['AA MAES','RTT',29,'jours'],
      ['BC DRUEZ','CA',25,'jours'],['BC DRUEZ','HP',2,'jours'],['BC DRUEZ','RTT',16,'jours'],
    ];
    $st = $db->prepare("INSERT IGNORE INTO agent_quotas (agent,annee,type_conge,quota,unite) VALUES (?,?,?,?,?)");
    foreach($quotas as $q){
        $quota_str = number_format((float)$q[2], 2, '.', '');
        $st->bind_param('sisss', $q[0], $yr, $q[1], $quota_str, $q[3]);
        $st->execute();
    }
});

/* ======================
   AUTHENTIFICATION
====================== */
// Action login/logout sans X-Requested-With
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_auth'])) {
    $act = $_POST['action_auth'];
    if ($act==='forgot_password') {
        $login=trim($_POST['login']??'');
        $stmt=$mysqli->prepare("SELECT id FROM users WHERE login=? AND actif=1 AND role='user'");
        $stmt->bind_param('s',$login);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        if($row){
            // Générer un mot de passe temporaire aléatoire (plus sécurisé que 'password')
            $tempPass = strtoupper(substr(bin2hex(random_bytes(3)),0,4)).'-'.rand(100,999);
            $hash=password_hash($tempPass,PASSWORD_BCRYPT);
            $stUpd=$mysqli->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?");
            $stUpd->bind_param('si',$hash,(int)$row['id']);
            $stUpd->execute();
            // Mot de passe et message construits sans interpolation du login (évite XSS)
            $resetMsg = '✅ Mot de passe temporaire : <strong>' . htmlspecialchars($tempPass, ENT_QUOTES, 'UTF-8') . '</strong> — Notez-le et changez-le dès la connexion.';
        } else {
            $resetMsg = '❌ Login non reconnu ou compte administrateur (contacter l\'admin).';
        }
    } elseif ($act==='login') {
        $login = trim($_POST['login'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $stmt  = $mysqli->prepare("SELECT id,login,nom,role,password,must_change_password FROM users WHERE login=? AND actif=1");
        $stmt->bind_param('s',$login);
        $stmt->execute();
        $user  = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($pass,$user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_login']= $user['login'];
            $_SESSION['user_nom']  = $user['nom'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['must_change']= !empty($user['must_change_password']);
            header('Location: '.$_SERVER['PHP_SELF']); exit;
        } else {
            $loginError = "Login ou mot de passe incorrect.";
        }
    } elseif ($act==='logout') {
        session_destroy();
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
}

// Vérifier session
$isLogged  = !empty($_SESSION['user_id']);
$userRole  = $_SESSION['user_role'] ?? '';
$userId    = $_SESSION['user_id']   ?? 0;
$isAdmin   = $isLogged && $userRole==='admin';

// Charger agents autorisés pour l'utilisateur connecté
$userAgents = []; // vide = accès à tout (admin)
$userRights = ['can_conge'=>true,'can_perm'=>true,'can_tir'=>false]; // défaut user
if ($isLogged && !$isAdmin) {
    // Charger droits spécifiques
    $res3 = $mysqli->query("SELECT can_conge,can_perm FROM user_rights WHERE user_id=".(int)$_SESSION['user_id']);
    if ($res3 && $r3=$res3->fetch_assoc()) {
        $userRights['can_conge'] = (bool)$r3['can_conge'];
        $userRights['can_perm']  = (bool)$r3['can_perm'];
    } else {
        // Pas de ligne → droits par défaut (can_conge=true, can_perm=false)
        $userRights['can_conge'] = true;
        $userRights['can_perm']  = false;
    }
}
if ($isAdmin) $userRights = ['can_conge'=>true,'can_perm'=>true,'can_tir'=>true];
if ($isLogged && !$isAdmin) {
    $stUA=$mysqli->prepare("SELECT agent FROM user_agents WHERE user_id=?");
    $stUA->bind_param('i',$userId);
    $stUA->execute();
    $res2=$stUA->get_result();
    while ($r=$res2->fetch_assoc()) $userAgents[] = $r['agent'];
    // GIE : accès mutuel automatique LEFEBVRE ↔ CORRARD
    $gieCouple = ['ADJ LEFEBVRE'=>'ADJ CORRARD', 'ADJ CORRARD'=>'ADJ LEFEBVRE'];
    foreach ($gieCouple as $self => $other) {
        if (in_array($self, $userAgents) && !in_array($other, $userAgents)) {
            $userAgents[] = $other;
        }
    }
    // DOUANE : IR MOREAU accède aussi à ACP1 DEMERVAL et ACP1 LOIN (unilatéral)
    if (in_array('IR MOREAU', $userAgents)) {
        foreach (['ACP1 DEMERVAL', 'ACP1 LOIN'] as $acp) {
            if (!in_array($acp, $userAgents)) $userAgents[] = $acp;
        }
    }
    // LCL PARENT : accède au GIE (ADJ LEFEBVRE, ADJ CORRARD) et à ADC LAMBERT (unilatéral)
    if (in_array('LCL PARENT', $userAgents)) {
        foreach (['ADJ LEFEBVRE', 'ADJ CORRARD', 'ADC LAMBERT'] as $ag) {
            if (!in_array($ag, $userAgents)) $userAgents[] = $ag;
        }
    }
}

// Détection groupe GIE : les agents LEFEBVRE et CORRARD appartiennent au groupe GIE
$gieAgents = ['ADJ LEFEBVRE', 'ADJ CORRARD'];
$isGie = !$isAdmin && !empty($userAgents) && count(array_diff($userAgents, $gieAgents)) === 0 && count(array_intersect($userAgents, $gieAgents)) > 0;

// Détection groupe DOUANE : agents dont le groupe est 'douane' ou 'douane_j'
$douaneAgents = ['IR MOREAU', 'ACP1 LOIN', 'ACP1 DEMERVAL'];
$isDouane = !$isAdmin && !empty($userAgents) && count(array_diff($userAgents, $douaneAgents)) === 0 && count(array_intersect($userAgents, $douaneAgents)) > 0;

// Détection groupe NUIT
$nuitAgents = ['BC MASSON', 'BC SIGAUD', 'BC DAINOTTI'];
$isNuit = !$isAdmin && !empty($userAgents) && count(array_diff($userAgents, $nuitAgents)) === 0 && count(array_intersect($userAgents, $nuitAgents)) > 0;

// Charger verrous actifs
function loadLocks(mysqli $db): array {
    $locks = ['global'=>false,'agents'=>[],'mois'=>[]];
    // Verrous globaux et annuels (table locks existante)
    $res = $db->query("SELECT scope,agent FROM locks");
    if ($res) {
        while ($r=$res->fetch_assoc()) {
            if ($r['scope']==='global') $locks['global']=true;
            elseif ($r['scope']==='agent') $locks['agents'][$r['agent']]=true;
        }
    }
    // Verrous mensuels (table locks_mois dédiée — toujours présente)
    $res2 = $db->query("SELECT agent,mois FROM locks_mois");
    if ($res2) {
        while ($r=$res2->fetch_assoc()) {
            $locks['mois'][$r['agent']][$r['mois']] = true;
        }
    }
    return $locks;
}
$locks = loadLocks($mysqli);
$globalLocked = $locks['global'];

// Fonction : cet agent est-il modifiable par l'utilisateur courant ?
function canEdit(string $agent, bool $isAdmin, bool $globalLocked, array $locks, array $userAgents, string $moisCourant=''): bool {
    if ($globalLocked) return false;
    if (isset($locks['agents'][$agent])) return false;
    // Verrou mensuel : bloque si le mois courant est verrouillé pour cet agent
    if ($moisCourant && isset($locks['mois'][$agent][$moisCourant])) return false;
    if ($isAdmin) return true;
    return in_array($agent,$userAgents);
}
function canEditNotes(bool $isAdmin, array $userAgents): bool {
    return $isAdmin || count($userAgents) > 0;
}

// Page login si non connecté — v20 design institutionnel CCPD
if (!$isLogged) { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>C.C.P.D. Tournai — Planning</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}

/* ── FOND INSTITUTIONNEL ── */
body{
  font-family:'Segoe UI',Arial,sans-serif;
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  /* Dégradé bleu institutionnel CCPD */
  background:linear-gradient(135deg,#0d2461 0%,#1a3a8f 40%,#1565c0 70%,#0d47a1 100%);
  position:relative;
  overflow:hidden;
}

/* ── FILIGRANE LOGO ── */
body::before{
  content:'';
  position:fixed;
  inset:0;
  background:url('data:image/png;base64,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') center center / 70vmin no-repeat;
  opacity:.18;
  pointer-events:none;
  z-index:0;
}

/* ── ÉTOILES DÉCORATIVES (cercle européen) ── */
body::after{
  content:'';
  position:fixed;
  inset:0;
  background:
    radial-gradient(circle at 50% 50%, transparent 34vmin, rgba(255,215,0,.06) 34vmin, rgba(255,215,0,.06) 36vmin, transparent 36vmin);
  pointer-events:none;
  z-index:0;
}

/* ── CARTE LOGIN ── */
.login-wrap{
  position:relative;z-index:1;
  display:flex;flex-direction:column;align-items:center;
  gap:0;width:100%;padding:20px;
}

/* Ligne centrale : logo | carte | logo */
.login-center-row{
  display:flex;
  flex-direction:row;
  align-items:center;
  justify-content:center;
  gap:48px;
  width:100%;
  max-width:900px;
}

.login-side-logo{
  width:170px;
  height:170px;
  min-width:170px;
  min-height:170px;
  border-radius:50%;
  box-shadow:0 4px 28px rgba(0,0,0,.5);
  object-fit:cover;
  flex-shrink:0;
  display:block;
}
@media(max-width:800px){
  .login-side-logo{display:none !important;}
  .login-center-row{gap:0;}
}

/* En-tête titre au dessus de la carte */
.login-header{
  text-align:center;margin-bottom:22px;
}
.login-header .org-name{
  font-size:1.05rem;font-weight:700;
  color:#ffd600;letter-spacing:.12em;text-transform:uppercase;
  text-shadow:0 2px 8px rgba(0,0,0,.4);
}
.login-header .org-sub{
  font-size:.72rem;color:rgba(255,255,255,.75);
  margin-top:4px;letter-spacing:.06em;
}

.login-box{
  background:rgba(255,255,255,.97);
  border-radius:16px;
  box-shadow:0 8px 40px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.15);
  padding:36px 38px 30px;
  width:360px;max-width:95vw;
}

.login-box h1{
  font-size:1.05rem;font-weight:700;color:#1a2742;
  text-align:center;margin-bottom:4px;
}
.login-box .sub{
  font-size:.74rem;color:#7a8aaa;text-align:center;margin-bottom:24px;
}

/* Séparateur */
.login-divider{
  height:1px;background:linear-gradient(90deg,transparent,#dde3f0,transparent);
  margin:0 -38px 22px;
}

label{display:block;font-size:.75rem;font-weight:700;color:#3a4a6a;margin-bottom:5px;letter-spacing:.03em}
input{
  width:100%;padding:10px 13px;
  border:1.5px solid #d0d8ee;border-radius:8px;
  font-size:.88rem;margin-bottom:15px;outline:none;
  transition:border .15s,box-shadow .15s;
  color:#1a2742;background:#f8faff;
}
input:focus{border-color:#1565c0;box-shadow:0 0 0 3px rgba(21,101,192,.12);background:#fff}

.btn-login{
  width:100%;padding:11px;
  background:linear-gradient(135deg,#0d2461,#1565c0);
  color:#fff;border:none;border-radius:8px;
  font-size:.9rem;font-weight:700;cursor:pointer;
  transition:opacity .15s,transform .1s;
  letter-spacing:.04em;
  box-shadow:0 3px 12px rgba(13,36,97,.3);
}
.btn-login:hover{opacity:.9;transform:translateY(-1px)}
.btn-login:active{transform:translateY(0)}

.err{
  background:#fde8e8;color:#c0392b;
  border-radius:7px;padding:9px 13px;
  font-size:.76rem;margin-bottom:14px;text-align:center;
  border-left:3px solid #e74c3c;
}
.msg-ok{
  background:#e8f5e9;color:#1b5e20;
  border-radius:7px;padding:9px 13px;
  font-size:.76rem;margin-bottom:14px;text-align:center;
  border-left:3px solid #43a047;
}

/* Lien bas de carte */
.login-footer-link{
  text-align:center;margin-top:14px;
}
.login-footer-link a{font-size:.72rem;color:#7a8aaa;text-decoration:none}
.login-footer-link a:hover{color:#1565c0}

/* Version */
.login-version{
  margin-top:18px;font-size:.65rem;
  color:rgba(255,255,255,.4);text-align:center;
  letter-spacing:.08em;
}
</style>
</head>
<body>
<div class="login-wrap">

  <!-- Ligne : logo gauche | carte | logo droit -->
  <div class="login-center-row">

    <!-- Logo gauche -->
    <img class="login-side-logo" src="CCPD_TOURNAI.jpg" alt="Logo CCPD Tournai" onerror="this.style.display='none'">

    <!-- Carte connexion -->
    <div class="login-box">
      <!-- Titre institutionnel -->
      <div class="login-header" style="text-align:center;margin-bottom:18px;">
        <div class="org-name" style="font-size:1rem;font-weight:700;color:#1a2742;letter-spacing:.1em;text-transform:uppercase;">C.C.P.D. &mdash; C.P.D.S.</div>
        <div class="org-sub" style="font-size:.75rem;color:#7a8aaa;margin-top:3px;letter-spacing:.05em;">Tournai / Doornik &mdash; Planning des effectifs</div>
      </div>
      <h1>Connexion</h1>
      <p class="sub">Accès réservé au personnel autorisé</p>
      <div class="login-divider"></div>

    <?php if(!empty($loginError)):?>
      <div class="err">⚠️ <?=htmlspecialchars($loginError)?></div>
    <?php endif;?>
    <?php if(!empty($resetMsg)):?>
      <div class="msg-ok"><?=$resetMsg /* contenu construit en PHP, sans données utilisateur brutes */ ?></div>
    <?php endif;?>

    <div id="form-login">
      <form method="POST">
        <input type="hidden" name="action_auth" value="login">
        <label>Identifiant</label>
        <input type="text" name="login" autofocus autocomplete="off" required placeholder="Votre identifiant" readonly onfocus="this.removeAttribute('readonly')">
        <label>Mot de passe</label>
        <input type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
        <button type="submit" class="btn-login">🔓 Se connecter</button>
      </form>
      <div class="login-footer-link">
        <a href="#" id="lnk-forgot">Mot de passe oublié ?</a>
      </div>
    </div>

    <div id="form-forgot" style="display:none">
      <form method="POST">
        <input type="hidden" name="action_auth" value="forgot_password">
        <p style="font-size:.74rem;color:#7a8aaa;margin-bottom:14px">
          Saisissez votre identifiant. Un mot de passe temporaire sera généré. Notez-le bien et changez-le immédiatement après connexion.
        </p>
        <label>Identifiant</label>
        <input type="text" name="login" autocomplete="off" required placeholder="Votre identifiant" readonly onfocus="this.removeAttribute('readonly')">
        <button type="submit" class="btn-login" style="background:linear-gradient(135deg,#b71c1c,#e53935)">
          Réinitialiser le mot de passe
        </button>
      </form>
      <div class="login-footer-link" style="margin-top:12px">
        <a href="#" id="lnk-back">← Retour à la connexion</a>
      </div>
    </div>
  </div><!-- fin login-box -->

    <!-- Logo droit -->
    <img class="login-side-logo" src="CCPD_TOURNAI.jpg" alt="Logo CCPD Tournai" onerror="this.style.display='none'">

  </div><!-- fin login-center-row -->

  <div class="login-version">C.C.P.D. Tournai</div>
</div>

<script>
  document.getElementById('lnk-forgot').onclick=e=>{e.preventDefault();
    document.getElementById('form-login').style.display='none';
    document.getElementById('form-forgot').style.display='block';};
  document.getElementById('lnk-back').onclick=e=>{e.preventDefault();
    document.getElementById('form-forgot').style.display='none';
    document.getElementById('form-login').style.display='block';};
  <?php if(!empty($resetMsg)):?>
  document.getElementById('form-forgot').style.display='block';
  document.getElementById('form-login').style.display='none';
  <?php endif;?>

// ── Tooltip custom sur toutes les cellules ──────────────────────────────────
(function(){
  const tip=document.createElement('div');
  tip.className='cell-tip';
  document.body.appendChild(tip);

  const CYCLE_LABELS={
    'J':'Journée','M':'Matin','AM':'Après-midi','NUIT':'Nuit',
    'RC':'Repos compensatoire (samedi)','RL':'Repos légal (dimanche)','FERIE':'Jour férié'
  };
  const PERM_LABELS={
    'M':'Permanence Matin','AM':'Permanence Après-midi',
    'IM':'Indisponibilité Matin','IAM':'Indisponibilité Après-midi','IJ':'Indisponibilité Journée'
  };
  const CONGE_SYM={'M':'☀ Matin','AM':'🌙 Après-midi','J':''};

  function getLabel(td){
    const permType=td.dataset.permType;
    const congeType=td.dataset.congeType;
    const cycle=td.dataset.cycleOrig||td.dataset.cycle||'';
    const tirId=td.dataset.tirId;
    const tirPer=td.dataset.tirPer;
    const per=td.dataset.congePer||'J';
    const heure=td.dataset.congeHeure||'';

    let lines=[];

    // TIR
    if(tirId&&tirId!=='0'){
      const perLbl={M:'Matin',AM:'Après-midi',NUIT:'Nuit',J:'Journée'}[tirPer]||tirPer;
      lines.push('🎯 TIR — '+perLbl);
    }
    // Permanence / Indisponibilité
    if(permType&&permType!==''){
      lines.push('📋 '+(PERM_LABELS[permType]||permType));
    }
    // Congé
    if(congeType&&congeType!==''){
      const sym=CONGE_SYM[per]||'';
      const hLbl=heure?' à '+heure.replace(':','h'):'';
      lines.push('📅 '+congeType+hLbl+(sym?' — '+sym:''));
    }
    // Cycle de base
    if(lines.length===0&&cycle){
      lines.push(CYCLE_LABELS[cycle]||cycle);
    }
    // Jour du mois
    const date=td.dataset.date;
    if(date){
      const d=new Date(date+'T00:00:00');
      const jours=['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
      const mois=['jan','fév','mar','avr','mai','juin','juil','aoû','sep','oct','nov','déc'];
      lines.unshift(jours[d.getDay()]+' '+d.getDate()+' '+mois[d.getMonth()]+' '+d.getFullYear());
    }
    return lines.join('<br>');
  }

  let hideTimer;
  document.addEventListener('mouseover',e=>{
    const td=e.target.closest('td[data-date]');
    if(!td){
      clearTimeout(hideTimer);
      hideTimer=setTimeout(()=>tip.classList.remove('show'),80);
      return;
    }
    clearTimeout(hideTimer);
    const label=getLabel(td);
    if(!label){tip.classList.remove('show');return;}
    tip.innerHTML=label;
    tip.classList.add('show');
  });
  document.addEventListener('mousemove',e=>{
    if(!tip.classList.contains('show'))return;
    let x=e.clientX+14, y=e.clientY+14;
    if(x+tip.offsetWidth>window.innerWidth-10) x=e.clientX-tip.offsetWidth-10;
    if(y+tip.offsetHeight>window.innerHeight-10) y=e.clientY-tip.offsetHeight-10;
    tip.style.left=x+'px'; tip.style.top=y+'px';
  });
  document.addEventListener('mouseout',e=>{
    const td=e.target.closest('td[data-date]');
    if(td){hideTimer=setTimeout(()=>tip.classList.remove('show'),80);}
  });
  // Masquer si modal ouvre
  document.addEventListener('click',()=>tip.classList.remove('show'));
})();

// ── Reset données ────────────────────────────────────────────────────────────
const btnResetData = document.getElementById('btn-reset-data');
if(btnResetData){
  btnResetData.addEventListener('click', async()=>{
    const pwd = document.getElementById('reset-pwd').value.trim();
    if(!pwd){ alert('Saisissez le mot de passe de confirmation.'); return; }
    const scope = document.querySelector('input[name="reset-scope"]:checked')?.value || 'mois';
    const tables = [...document.querySelectorAll('.reset-table:checked')].map(c=>c.value);
    if(!tables.length){ alert('Sélectionnez au moins une table à effacer.'); return; }
    const scopeLbl = scope==='mois' ? 'le mois en cours' : "l'année entière";
    if(!confirm('⚠️ Effacer ' + tables.join(', ') + ' pour ' + scopeLbl + ' ?\nCette action est IRRÉVERSIBLE.')) return;
    btnResetData.disabled=true;
    const result = document.getElementById('reset-result');
    result.textContent = 'Suppression en cours...';
    try{
      const r = await ajax({action:'reset_data', reset_pwd:pwd, scope, tables:JSON.stringify(tables)});
      if(r.ok){
        let msg = '✅ Supprimé : ';
        msg += Object.entries(r.deleted).map(([t,n])=>t+' ('+n+' lignes)').join(', ');
        result.style.color='#27ae60'; result.textContent=msg;
        setTimeout(()=>location.replace(location.pathname+'?annee='+ANNEE+'&mois='+MOIS+'&vue='+VUE), 1500);
      } else {
        result.style.color='#c0392b'; result.textContent='❌ '+r.msg;
        btnResetData.disabled=false;
      }
    }catch(e){
      result.style.color='#c0392b'; result.textContent='❌ Erreur réseau';
      btnResetData.disabled=false;
    }
  });
}
</script>
</body>
</html>
<?php exit; }

// Année : min = année courante - 1, défaut = année courante
$annee = isset($_GET['annee']) ? max((int)date('Y')-1,(int)$_GET['annee']) : (int)date('Y');
$mois  = isset($_GET['mois'])  ? max(1,min(12,(int)$_GET['mois'])) : (int)date('n');
$moisStr = sprintf('%04d-%02d', $annee, $mois); // ex: "2026-01" — utilisé pour les verrous mensuels
$vue   = $_GET['vue'] ?? 'mois';

$agentsPermanence = [
  'BC BOUXOM','BC ARNAULT','BC HOCHARD',
  'BC DUPUIS','BC BASTIEN','BC ANTHONY',
  'ADJ LEFEBVRE','ADJ CORRARD',
  'GP DHALLEWYN','BC DELCROIX',
  'BC DRUEZ',
  // Note : LCL PARENT et ADC LAMBERT gérés via groupes 'lcl_parent'/'adc_lambert'
];


// Agents concernés par le RTT Police
$agentsRTT = [
  'CoGe ROUSSEL','Cne MOKADEM',
  'BC MASSON','BC SIGAUD','BC DAINOTTI',
  'BC BOUXOM','BC ARNAULT','BC HOCHARD',
  'BC DUPUIS','BC BASTIEN','BC ANTHONY',
  'BC DRUEZ','BC DELCROIX','GP DHALLEWYN',
];

// Agents EXCLUS du TIR
$agentsExclusTir = [
  // Douane
  'IR MOREAU','ACP1 DEMERVAL','ACP1 LOIN',
  // Gendarmerie (GIE + non-police)
  'LCL PARENT','ADJ LEFEBVRE','ADJ CORRARD','ADC LAMBERT',
  // Secrétariat
  'AA MAES',
];
// Agents AUTORISÉS TIR : police uniquement
// Exclus douane : IR MOREAU, ACP1 DEMERVAL, ACP1 LOIN
// Exclus gendarmes : LCL PARENT, ADJ LEFEBVRE, ADJ CORRARD, ADC LAMBERT
// Exclus autres : AA MAES (secrétariat)
$agentsTir = array_values(array_diff(
  ['CoGe ROUSSEL','Cne MOKADEM',
   'BC MASSON','BC SIGAUD','BC DAINOTTI',
   'BC BOUXOM','BC ARNAULT','BC HOCHARD',
   'BC DUPUIS','BC BASTIEN','BC ANTHONY',
   'GP DHALLEWYN','BC DELCROIX','BC DRUEZ'],
  $agentsExclusTir
));

$moisFR = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
           7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
$joursFR = [1=>'Lun',2=>'Mar',3=>'Mer',4=>'Jeu',5=>'Ven',6=>'Sam',7=>'Dim'];


/* ======================
   RENDU CELLULE AJAX
====================== */
function renderCellsHtml(mysqli $db, array $agentsList, array $datesList,
    array $groupeAgent, array $agentsTir, array $agentsPermanence,
    bool $isAdmin, bool $globalLocked, array $locks, array $userAgents): array {

    if (empty($agentsList) || empty($datesList)) return [];
    $feriesAll = calculerFeries((int)substr($datesList[0],0,4));

    $agPh   = implode(',', array_fill(0, count($agentsList), '?'));
    $dtPh   = implode(',', array_fill(0, count($datesList),  '?'));
    $types  = str_repeat('s', count($agentsList) + count($datesList));
    $params = array_merge($agentsList, $datesList);
    $dtMin  = min($datesList); $dtMax = max($datesList);

    // Congés
    $cg = []; $cgId = []; $cgMeta = [];
    $typesCg  = str_repeat('s', count($agentsList)).'ss';
    $paramsCg = array_merge($agentsList, [$dtMin, $dtMax]);
    $stCg = $db->prepare("SELECT c.id,c.agent,c.date_debut,c.date_fin,c.type_conge,c.periode,c.heure,
                                  COALESCE(tc.libelle,tcd.libelle,c.type_conge) as libelle,
                                  COALESCE(tc.couleur_bg,tcd.couleur_bg,'#1565c0') as couleur_bg,
                                  COALESCE(tc.couleur_txt,tcd.couleur_txt,'#ffffff') as couleur_txt
                           FROM conges c
                           LEFT JOIN types_conges tc ON tc.code=c.type_conge
                           LEFT JOIN types_conges_douane tcd ON tcd.code=c.type_conge
                           WHERE c.agent IN ($agPh) AND c.date_fin>=? AND c.date_debut<=?");
    $stCg->bind_param($typesCg, ...$paramsCg);
    $stCg->execute();
    $res = $stCg->get_result();
    while ($c = $res->fetch_assoc()) {
        $a = $c['agent'];
        $d = new DateTime($c['date_debut']);
        $fi = new DateTime($c['date_fin']);
        while ($d <= $fi) {
            $ds = $d->format('Y-m-d');
            if (in_array($ds, $datesList)) {
                $cg[$a][$ds]     = $c['type_conge'];
                $cgId[$a][$ds]   = $c['id'];
                $cgMeta[$a][$ds] = ['periode'=>$c['periode']??'J','libelle'=>$c['libelle'],'heure'=>$c['heure']??'','couleur_bg'=>$c['couleur_bg']??'#1565c0','couleur_txt'=>$c['couleur_txt']??'#ffffff'];
            }
            $d->modify('+1 day');
        }
    }

    // Permanences
    $pm = []; $pmId = [];
    $stPm = $db->prepare("SELECT id,agent,date,type,cycle_orig FROM permanences WHERE agent IN ($agPh) AND date IN ($dtPh)");
    $stPm->bind_param($types, ...$params);
    $stPm->execute();
    $res = $stPm->get_result();
    while ($r = $res->fetch_assoc()) {
        $pm[$r['agent']][$r['date']]   = ['type'=>$r['type'],'cycle_orig'=>$r['cycle_orig']];
        $pmId[$r['agent']][$r['date']] = $r['id'];
    }

    // TIR
    $tir = []; $tirId = []; $tirAn = [];
    $stTir = $db->prepare("SELECT id,agent,date,periode FROM tir WHERE agent IN ($agPh) AND date IN ($dtPh)");
    $stTir->bind_param($types, ...$params);
    $stTir->execute();
    $res = $stTir->get_result();
    while ($r = $res->fetch_assoc()) {
        $tir[$r['agent']][$r['date']]   = $r['periode'];
        $tirId[$r['agent']][$r['date']] = $r['id'];
    }
    $stAn = $db->prepare("SELECT id,agent,date FROM tir_annulations WHERE agent IN ($agPh) AND date IN ($dtPh)");
    $stAn->bind_param($types, ...$params);
    $stAn->execute();
    $res = $stAn->get_result();
    while ($r = $res->fetch_assoc()) {
        $tirAn[$r['agent']][$r['date']] = $r['id'];
    }

    $agentsCombine = ['CoGe ROUSSEL','Cne MOKADEM','GP DHALLEWYN','BC DELCROIX','BC DRUEZ'];
    $cells = [];

    // Charger les overrides vacation pour les agents/dates demandés (éviter prepare dans la boucle)
    $vacOvrAjax = [];
    try {
        $stVaxAll = $db->prepare("SELECT agent,date,id,vacation FROM vacation_overrides WHERE agent IN ($agPh) AND date IN ($dtPh)");
        $stVaxAll->bind_param($types, ...$params);
        $stVaxAll->execute();
        $resVaxAll = $stVaxAll->get_result();
        while ($rv = $resVaxAll->fetch_assoc()) {
            $vacOvrAjax[$rv['agent']][$rv['date']] = ['id'=>$rv['id'],'vacation'=>$rv['vacation']];
        }
    } catch (Throwable $e) { /* table vacation_overrides pas encore créée */ }

    foreach ($agentsList as $ag) {
        $ag   = trim($ag);
        $grp  = $groupeAgent[$ag] ?? 'standard_j';
        $masq = in_array($grp, ['standard_police','nuit','equipe','gie']);
        $pTir = in_array($ag, $agentsTir);
        $pP   = in_array($ag, $agentsPermanence);

        foreach ($datesList as $dt) {
            $dt      = trim($dt);
            $isFe    = isset($feriesAll[$dt]);
            $cgCode  = $cg[$ag][$dt]   ?? null;
            $cgId2   = $cgId[$ag][$dt] ?? 0;
            $pmData  = $pm[$ag][$dt]   ?? null;
            $pmId2   = $pmId[$ag][$dt] ?? 0;
            $tirPer  = $tir[$ag][$dt]  ?? null;
            $tirId2  = $tirId[$ag][$dt]?? 0;
            $annId   = $tirAn[$ag][$dt]?? 0;
            $cycleVal = getCycleAgent($ag, $dt, $feriesAll, $groupeAgent);
            // Override vacation (get_cell AJAX) - chargé via $vacOvrAjax pré-calculé
            $vacValAj = $vacOvrAjax[$ag][$dt]['vacation'] ?? null;
            $vacIdAj  = $vacOvrAjax[$ag][$dt]['id']      ?? 0;
            if($vacValAj && !in_array($cycleVal,['RC','RL','FERIE'])) $cycleVal=$vacValAj;
            $lockCls  = (!$isAdmin && !canEdit($ag,$isAdmin,$globalLocked,$locks,$userAgents,substr($dt,0,7))) ? ' locked-agent' : '';
            $agEsc   = htmlspecialchars($ag, ENT_QUOTES);
            $da      = " data-agent='$agEsc' data-date='$dt' data-cycle='$cycleVal' data-groupe='$grp' data-vac-id='$vacIdAj'";

            ob_start();

            if ($annId > 0) {
                // L'annulation prime toujours sur le TIR
                echo "<td class='tir-annule$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-tir-id='".($tirId2??0)."' data-annul-id='$annId' title='TIR annulé'>🎯❌</td>";

            } elseif ($tirPer !== null) {
                $symTir = match($tirPer){'M'=>'☀','AM'=>'🌙','NUIT'=>'🌙',default=>''};
                $tipTir = 'TIR '.match($tirPer){'M'=>'Matin','AM'=>'Après-midi','NUIT'=>'Nuit',default=>$tirPer};
                if ($cgCode && in_array($ag, $agentsCombine)) {
                    $meta   = $cgMeta[$ag][$dt] ?? ['periode'=>'J','libelle'=>$cgCode,'heure'=>'','couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'];
                    $per    = $meta['periode'] ?? 'J';
                    $hre    = $meta['heure']   ?? '';
                    $symCg  = match($per){'M'=>'🔆','AM'=>'🌙',default=>''};
                    $hD     = ($hre && in_array($cgCode,['DA','PR'])) ? ' '.substr($hre,0,5) : '';
                    $tip    = $tipTir.' + '.$cgCode.$hD.' '.match($per){'M'=>'Matin','AM'=>'Après-midi',default=>''};
                    $txt    = 'TIR'.$symTir.'+'.$cgCode.$symCg;
                    if ($cgCode === 'PREV') {
                        $clsTirCg = 'tir tir-ok prev-conge';
                        $sTirCg   = 'font-size:7px';
                    } else {
                        $clsTirCg = 'tir tir-ok conge';
                        $bgTc     = $meta['couleur_bg'] ?? '#1565c0';
                        $txtTc    = $meta['couleur_txt'] ?? '#ffffff';
                        $sTirCg   = "font-size:7px;background:$bgTc;color:$txtTc";
                    }
                    echo "<td class='$clsTirCg$lockCls'$da data-ferie='0' data-conge-id='$cgId2' data-conge-type='$cgCode' data-perm-id='0' data-tir-id='$tirId2' data-tir-per='$tirPer' data-conge-per='$per' data-conge-heure='".htmlspecialchars($hre)."' data-annul-id='0' title='".htmlspecialchars($tip)."' style='$sTirCg'>$txt</td>";
                } else {
                    echo "<td class='tir tir-ok$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-tir-id='$tirId2' data-tir-per='$tirPer' data-annul-id='0' title='".htmlspecialchars($tipTir)."'>TIR$symTir</td>";
                }

            } elseif ($pmData) {
                $pmType = trim($pmData['type']);
                $pmCyc  = trim($pmData['cycle_orig']);
                $isInd  = in_array($pmType, ['IM','IAM','IJ']);
                $clsPm  = match($pmType){'M'=>'m','AM'=>'am','IM'=>'perm-indispo-m','IAM'=>'perm-indispo-am','IJ'=>'perm-indispo-j',default=>'m'};
                $disp   = $isInd ? '<b style=\'color:#e53935\'>✖</b>' : $pmType;
                $sym    = match($pmType){'M'=>'☀','IM'=>'☀','AM'=>'🌙','IAM'=>'🌙',default=>''};
                $tip    = 'Permanence '.match($pmType){'M'=>'Matin','AM'=>'Après-midi','IM'=>'Indisp M','IAM'=>'Indisp AM','IJ'=>'Indisp Journée',default=>$pmType};
                echo "<td class='$clsPm perm-ok$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='$pmId2' data-perm-type='$pmType' data-cycle-orig='$pmCyc' data-tir-id='0' title='".htmlspecialchars($tip)."'>$disp$sym</td>";

            } elseif ($cgCode) {
                $meta  = $cgMeta[$ag][$dt] ?? ['periode'=>'J','libelle'=>$cgCode,'heure'=>'','couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'];
                $per   = $meta['periode'] ?? 'J';
                $hre   = $meta['heure']   ?? '';
                $sym   = match($per){'M'=>'☀','AM'=>'🌙',default=>''};
                $hD    = ($hre && in_array($cgCode,['DA','PR'])) ? ' '.substr($hre,0,5) : '';
                $txt   = $cgCode.$hD.$sym;
                $tip   = $meta['libelle'] ?: $cgCode;
                if ($hre && in_array($cgCode,['DA','PR'])) $tip .= ' à '.substr($hre,0,5);
                if ($per !== 'J') $tip .= ' ('.match($per){'M'=>'Matin','AM'=>'Après-midi',default=>$per}.')';
                if ($cgCode === 'PREV') {
                    $clsCg  = 'prev-conge';
                    $styleCg = '';
                } else {
                    $clsCg   = 'conge';
                    $bgCg    = $meta['couleur_bg'] ?? '#1565c0';
                    $txtCg   = $meta['couleur_txt'] ?? '#ffffff';
                    $styleCg = " style='background:$bgCg;color:$txtCg'";
                }
                echo "<td class='$clsCg$lockCls'$da$styleCg data-ferie='0' data-conge-id='$cgId2' data-conge-type='$cgCode' data-perm-id='0' data-tir-id='0' data-conge-per='$per' data-conge-heure='".htmlspecialchars($hre)."' title='".htmlspecialchars($tip)."'>$txt</td>";

            } elseif ($isFe) {
                echo "<td class='ferie$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='FERIE' data-tir-id='0' title='Jour férié'>FERIE</td>";

            } else {
                $val        = $cycleVal;
                $isTirCase  = $pTir && in_array($val, ['J','NUIT','M','AM']);
                if ($masq && in_array($val, ['RC','RL'])) {
                    $cls  = $pP ? 'rc-masque perm-ok' : 'rc-masque';
                    $tipM = $val === 'RC' ? 'Repos compensatoire' : 'Repos légal (dimanche)';
                    echo "<td class='$cls$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='$val' data-masque='1' data-tir-id='0' title='".htmlspecialchars($tipM)."'></td>";
                } else {
                    $cls  = cb($val);
                    if ($pP && in_array($val,['RC','RL'])) $cls .= ' perm-ok';
                    // Note : pas de perm-ok permanent sur J pour lcl_parent/adc_lambert
                    if ($isTirCase) $cls .= ' tir-ok';
                    $tipV = match($val){'J'=>'Journée','M'=>'Matin','AM'=>'Après-midi','NUIT'=>'Nuit','RC'=>'Repos compensatoire','RL'=>'Repos légal (dimanche)','FERIE'=>'Jour férié',default=>$val};
                    $dispValAj = $val.($vacValAj&&!in_array($val,['RC','RL','FERIE'])?'*':'');
                    $tipVFull  = $tipV.($vacValAj&&!in_array($val,['RC','RL','FERIE'])?' ✏ (modifié)':'');
                    echo "<td class='".trim($cls.$lockCls)."'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='$val' data-tir-id='0' title='".htmlspecialchars($tipVFull)."'>$dispValAj</td>";
                }
            }
            $cells[] = ['agent'=>$ag, 'date'=>$dt, 'html'=>ob_get_clean()];
        }
    }
    return $cells;
}

$groupeAgent=[];
$groupeAgent['CoGe ROUSSEL']='direction_police';
$groupeAgent['Cne MOKADEM'] ='direction_mokadem';
$groupeAgent['LCL PARENT']  ='lcl_parent';
$groupeAgent['IR MOREAU']   ='douane_j';
foreach (['BC MASSON','BC SIGAUD','BC DAINOTTI'] as $a) $groupeAgent[$a]='nuit';
foreach (['BC BOUXOM','BC ARNAULT','BC HOCHARD','BC ANTHONY','BC BASTIEN','BC DUPUIS'] as $a) $groupeAgent[$a]='equipe';
foreach (['ADJ LEFEBVRE','ADJ CORRARD'] as $a) $groupeAgent[$a]='gie';
$groupeAgent['ACP1 LOIN']='douane';
$groupeAgent['GP DHALLEWYN']='standard_police'; $groupeAgent['BC DELCROIX']='standard_police';
$groupeAgent['ADC LAMBERT']='adc_lambert'; $groupeAgent['ACP1 DEMERVAL']='douane_j';
$groupeAgent['AA MAES']='standard_j'; $groupeAgent['BC DRUEZ']='standard_police';

/* ======================
   AJAX — SÉCURITÉ
====================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    // Vérifier session AJAX
    if (!$isLogged) { echo json_encode(['ok'=>false,'msg'=>'Non connecté']); exit; }

    $action = $_POST['action'] ?? '';

    /* ── Verrou global ── */
    if ($action==='lock_global') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $mysqli->query("DELETE FROM locks WHERE scope='global'");
        $stmt=$mysqli->prepare("INSERT INTO locks (scope,locked_by) VALUES ('global',?)");
        $stmt->bind_param('i',$userId);
        $ok=$stmt->execute();
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Planning verrouillé':'Erreur : '.$mysqli->error]);
        exit;

    /* ── Déverrouillage global ── */
    } elseif ($action==='unlock_global') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $mysqli->query("DELETE FROM locks");
        echo json_encode(['ok'=>true,'msg'=>'Planning déverrouillé']);
        exit;

    /* ── Verrou agent ── */
    } elseif ($action==='lock_agent') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $ag=trim($_POST['agent']??'');
        // Suppression sécurisée avec prepared statement
        $stDel=$mysqli->prepare("DELETE FROM locks WHERE scope='agent' AND agent=?");
        $stDel->bind_param('s',$ag);
        $stDel->execute();
        $stmt=$mysqli->prepare("INSERT INTO locks (scope,agent,locked_by) VALUES ('agent',?,?)");
        $stmt->bind_param('si',$ag,$userId);
        $ok=$stmt->execute();
        echo json_encode(['ok'=>$ok]);
        exit;

    /* ── Déverrouillage agent ── */
    } elseif ($action==='unlock_agent') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $ag=trim($_POST['agent']??'');
        // Suppression sécurisée avec prepared statement
        $stDel=$mysqli->prepare("DELETE FROM locks WHERE scope='agent' AND agent=?");
        $stDel->bind_param('s',$ag);
        $ok=$stDel->execute();
        echo json_encode(['ok'=>(bool)$ok]);
        exit;

    /* ── Verrouillage agent/mois ── */
    } elseif ($action==='lock_agent_mois') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $ag  = trim($_POST['agent'] ?? '');
        $mo  = trim($_POST['mois']  ?? '');
        if (!$ag || !preg_match('/^\d{4}-\d{2}$/',$mo)) { echo json_encode(['ok'=>false,'msg'=>'Paramètre invalide']); exit; }
        $stmt=$mysqli->prepare("INSERT INTO locks_mois (agent,mois,locked_by) VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE locked_by=VALUES(locked_by),created_at=NOW()");
        if (!$stmt) { echo json_encode(['ok'=>false,'msg'=>'Erreur prepare: '.$mysqli->error]); exit; }
        $stmt->bind_param('ssi',$ag,$mo,$userId);
        $ok=$stmt->execute();
        if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'Erreur SQL: '.$stmt->error]); exit; }
        echo json_encode(['ok'=>true,'agent'=>$ag,'mois'=>$mo]);
        exit;

    /* ── Déverrouillage agent/mois ── */
    } elseif ($action==='unlock_agent_mois') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $ag  = trim($_POST['agent'] ?? '');
        $mo  = trim($_POST['mois']  ?? '');
        if (!$ag || !preg_match('/^\d{4}-\d{2}$/',$mo)) { echo json_encode(['ok'=>false,'msg'=>'Paramètre invalide']); exit; }
        $stmt=$mysqli->prepare("DELETE FROM locks_mois WHERE agent=? AND mois=?");
        if (!$stmt) { echo json_encode(['ok'=>false,'msg'=>'Erreur prepare: '.$mysqli->error]); exit; }
        $stmt->bind_param('ss',$ag,$mo);
        $ok=$stmt->execute();
        echo json_encode(['ok'=>(bool)$ok,'agent'=>$ag,'mois'=>$mo]);
        exit;

    /* ── Debug verrous (admin uniquement) ── */
    } elseif ($action==='debug_locks') {
        if (!$isAdmin) { echo json_encode(['ok'=>false]); exit; }
        $info = [];
        // Table locks
        $r=$mysqli->query("SHOW COLUMNS FROM locks"); $info['locks_cols']=[];
        if($r) while($c=$r->fetch_assoc()) $info['locks_cols'][]=$c['Field'];
        $r=$mysqli->query("SELECT * FROM locks"); $info['locks']=[];
        if($r) while($c=$r->fetch_assoc()) $info['locks'][]=$c;
        // Table locks_mois
        $r=$mysqli->query("SHOW TABLES LIKE 'locks_mois'"); $info['locks_mois_exists']=($r&&$r->num_rows>0);
        $r=$mysqli->query("SELECT * FROM locks_mois"); $info['locks_mois']=[];
        if($r) while($c=$r->fetch_assoc()) $info['locks_mois'][]=$c;
        $info['loaded']=$locks;
        echo json_encode(['ok'=>true,'info'=>$info]);
        exit;

    /* ── Liste users (admin) ── */
    } elseif ($action==='list_users') {
        if (!$isAdmin) { echo json_encode(['ok'=>false]); exit; }
        $users=[];
        $res=$mysqli->query("SELECT u.id,u.login,u.nom,u.role,u.actif,u.must_change_password,COALESCE(r.can_conge,1) as can_conge,COALESCE(r.can_perm,0) as can_perm FROM users u LEFT JOIN user_rights r ON r.user_id=u.id ORDER BY u.role DESC,u.login");
        while($r=$res->fetch_assoc()) $users[]=$r;
        // Agents par user
        $agMap=[];
        $res2=$mysqli->query("SELECT user_id,agent FROM user_agents");
        while($r=$res2->fetch_assoc()) $agMap[$r['user_id']][]=$r['agent'];
        foreach($users as &$u) $u['agents']=$agMap[$u['id']]??[];
        echo json_encode(['ok'=>true,'users'=>$users]);
        exit;

    /* ── Créer/Modifier user ── */
    } elseif ($action==='save_user') {
        if (!$isAdmin) { echo json_encode(['ok'=>false]); exit; }
        $uid    =(int)($_POST['uid']??0);
        $login  =trim($_POST['ulogin']??'');
        $nom    =trim($_POST['unom']??'');
        $role   =in_array($_POST['urole']??'',['admin','user'])?$_POST['urole']:'user';
        $pass   =trim($_POST['upass']??'');
        $agents =json_decode($_POST['uagents']??'[]',true);
        if (!$login) { echo json_encode(['ok'=>false,'msg'=>'Login requis']); exit; }
        if ($uid>0) {
            if ($pass) {
                $hash=password_hash($pass,PASSWORD_BCRYPT);
                $stmt=$mysqli->prepare("UPDATE users SET login=?,nom=?,role=?,password=? WHERE id=?");
                $stmt->bind_param('ssssi',$login,$nom,$role,$hash,$uid);
            } else {
                $stmt=$mysqli->prepare("UPDATE users SET login=?,nom=?,role=? WHERE id=?");
                $stmt->bind_param('sssi',$login,$nom,$role,$uid);
            }
        } else {
            if (!$pass) { echo json_encode(['ok'=>false,'msg'=>'Mot de passe requis']); exit; }
            $hash=password_hash($pass,PASSWORD_BCRYPT);
            $stmt=$mysqli->prepare("INSERT INTO users (login,nom,role,password) VALUES (?,?,?,?)");
            $stmt->bind_param('ssss',$login,$nom,$role,$hash);
        }
        $ok=$stmt->execute();
        if (!$ok) { echo json_encode(['ok'=>false,'msg'=>$stmt->error]); exit; }
        $nuid=$uid>0?$uid:(int)$mysqli->insert_id;
        // Mettre à jour les agents autorisés
        $stDelUA=$mysqli->prepare("DELETE FROM user_agents WHERE user_id=?");
        $stDelUA->bind_param('i',$nuid);
        $stDelUA->execute();
        if ($role==='user' && !empty($agents)) {
            $st2=$mysqli->prepare("INSERT INTO user_agents (user_id,agent) VALUES (?,?)");
            foreach($agents as $ag) { $ag=trim($ag); if($ag){$st2->bind_param('is',$nuid,$ag);$st2->execute();} }
        }
        // Mettre à jour les droits granulaires
        $canConge=(int)(($_POST['can_conge']??0)==='1');
        $canPerm =(int)(($_POST['can_perm'] ??0)==='1');
        $stRights=$mysqli->prepare("INSERT INTO user_rights (user_id,can_conge,can_perm) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE can_conge=VALUES(can_conge), can_perm=VALUES(can_perm)");
        $stRights->bind_param('iii',$nuid,$canConge,$canPerm);
        $stRights->execute();
        echo json_encode(['ok'=>true,'id'=>$nuid]);
        exit;

    /* ── Supprimer user ── */
    } elseif ($action==='delete_user') {
        if (!$isAdmin) { echo json_encode(['ok'=>false]); exit; }
        $uid=(int)($_POST['uid']??0);
        if ($uid===$userId) { echo json_encode(['ok'=>false,'msg'=>'Impossible de supprimer votre propre compte']); exit; }
        $stDel=$mysqli->prepare("DELETE FROM users WHERE id=?");
        $stDel->bind_param('i',$uid);
        $ok=$stDel->execute();
        echo json_encode(['ok'=>(bool)$ok]);
        exit;

    /* ── Réinitialiser mot de passe (admin) ── */
    } elseif ($action==='reset_password') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $uid=(int)($_POST['uid']??0);
        // Mot de passe temporaire aléatoire (plus sécurisé que 'password')
        $tempPass = strtoupper(substr(bin2hex(random_bytes(3)),0,4)).'-'.rand(100,999);
        $hash=password_hash($tempPass,PASSWORD_BCRYPT);
        $stReset=$mysqli->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?");
        $stReset->bind_param('si',$hash,$uid);
        $ok=$stReset->execute();
        echo json_encode(['ok'=>(bool)$ok,'msg'=>$ok?"Mot de passe temporaire : $tempPass":'Erreur']);
        exit;

    /* ── Corriger agents GIE (LEFEBVRE ↔ CORRARD mutuels) ── */
    } elseif ($action==='fix_gie_agents') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $gie = ['LEFEBVRE'=>['ADJ LEFEBVRE','ADJ CORRARD'], 'CORRARD'=>['ADJ CORRARD','ADJ LEFEBVRE']];
        $updated = 0;
        $stFind = $mysqli->prepare("SELECT id FROM users WHERE login=?");
        foreach ($gie as $login => $agents) {
            $stFind->bind_param('s', $login);
            $stFind->execute();
            $res = $stFind->get_result();
            if ($res && $row=$res->fetch_assoc()) {
                $uid = (int)$row['id'];
                $stDelUA2=$mysqli->prepare("DELETE FROM user_agents WHERE user_id=?");
                $stDelUA2->bind_param('i',$uid);
                $stDelUA2->execute();
                $st2 = $mysqli->prepare("INSERT INTO user_agents (user_id,agent) VALUES (?,?)");
                foreach ($agents as $ag) { $st2->bind_param('is',$uid,$ag); $st2->execute(); }
                $updated++;
            }
        }
        echo json_encode(['ok'=>true,'msg'=>"$updated compte(s) GIE mis à jour (accès mutuel activé)"]);
        exit;

    /* ── Créer comptes pour tous les agents ── */
    } elseif ($action==='create_agent_users') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
        $agents=[
          'CoGe ROUSSEL'=>['login'=>'ROUSSEL','nom'=>'CoGe ROUSSEL','agents'=>['CoGe ROUSSEL']],
          'LCL PARENT'  =>['login'=>'PARENT',  'nom'=>'LCL PARENT',  'agents'=>['LCL PARENT']],
          'IR MOREAU'   =>['login'=>'MOREAU',   'nom'=>'IR MOREAU',   'agents'=>['IR MOREAU']],
          'Cne MOKADEM' =>['login'=>'MOKADEM',  'nom'=>'Cne MOKADEM', 'agents'=>['Cne MOKADEM']],
          'BC MASSON'   =>['login'=>'MASSON',   'nom'=>'BC MASSON',   'agents'=>['BC MASSON']],
          'BC SIGAUD'   =>['login'=>'SIGAUD',   'nom'=>'BC SIGAUD',   'agents'=>['BC SIGAUD']],
          'BC DAINOTTI' =>['login'=>'DAINOTTI', 'nom'=>'BC DAINOTTI', 'agents'=>['BC DAINOTTI']],
          'BC BOUXOM'   =>['login'=>'BOUXOM',   'nom'=>'BC BOUXOM',   'agents'=>['BC BOUXOM']],
          'BC ARNAULT'  =>['login'=>'ARNAULT',  'nom'=>'BC ARNAULT',  'agents'=>['BC ARNAULT']],
          'BC HOCHARD'  =>['login'=>'HOCHARD',  'nom'=>'BC HOCHARD',  'agents'=>['BC HOCHARD']],
          'BC ANTHONY'  =>['login'=>'ANTHONY',  'nom'=>'BC ANTHONY',  'agents'=>['BC ANTHONY']],
          'BC BASTIEN'  =>['login'=>'BASTIEN',  'nom'=>'BC BASTIEN',  'agents'=>['BC BASTIEN']],
          'BC DUPUIS'   =>['login'=>'DUPUIS',   'nom'=>'BC DUPUIS',   'agents'=>['BC DUPUIS']],
          'ADJ LEFEBVRE'=>['login'=>'LEFEBVRE', 'nom'=>'ADJ LEFEBVRE','agents'=>['ADJ LEFEBVRE','ADJ CORRARD']],
          'ADJ CORRARD' =>['login'=>'CORRARD',  'nom'=>'ADJ CORRARD', 'agents'=>['ADJ CORRARD','ADJ LEFEBVRE']],
          'GP DHALLEWYN'=>['login'=>'DHALLEWYN','nom'=>'GP DHALLEWYN','agents'=>['GP DHALLEWYN']],
          'BC DELCROIX' =>['login'=>'DELCROIX', 'nom'=>'BC DELCROIX', 'agents'=>['BC DELCROIX']],
          'ADC LAMBERT' =>['login'=>'LAMBERT',  'nom'=>'ADC LAMBERT', 'agents'=>['ADC LAMBERT']],
          'ACP1 DEMERVAL'=>['login'=>'DEMERVAL','nom'=>'ACP1 DEMERVAL','agents'=>['ACP1 DEMERVAL']],
          'ACP1 LOIN'   =>['login'=>'LOIN',     'nom'=>'ACP1 LOIN',   'agents'=>['ACP1 LOIN']],
          'AA MAES'     =>['login'=>'MAES',     'nom'=>'AA MAES',     'agents'=>['AA MAES']],
          'BC DRUEZ'    =>['login'=>'DRUEZ',    'nom'=>'BC DRUEZ',    'agents'=>['BC DRUEZ']],
        ];
        // Chaque agent reçoit un mot de passe temporaire aléatoire unique
        $created=0; $skipped=0; $createdList=[];
        $stChk=$mysqli->prepare("SELECT id FROM users WHERE login=?");
        foreach($agents as $data) {
            $login=$data['login']; $nom=$data['nom'];
            $stChk->bind_param('s',$login);
            $stChk->execute();
            $chkRes=$stChk->get_result();
            if($chkRes->num_rows>0){$skipped++;continue;}
            $tempPass=strtoupper(substr(bin2hex(random_bytes(3)),0,4)).'-'.rand(100,999);
            $hash=password_hash($tempPass,PASSWORD_BCRYPT);
            $stmt=$mysqli->prepare("INSERT INTO users (login,nom,role,password,must_change_password) VALUES (?,?,'user',?,1)");
            $stmt->bind_param('sss',$login,$nom,$hash);
            if($stmt->execute()){
                $nuid=(int)$mysqli->insert_id;
                $st2=$mysqli->prepare("INSERT INTO user_agents (user_id,agent) VALUES (?,?)");
                foreach($data['agents'] as $ag){ $st2->bind_param('is',$nuid,$ag); $st2->execute(); }
                $createdList[]=['login'=>$login,'mdp'=>$tempPass];
                $created++;
            }
        }
        echo json_encode(['ok'=>true,'msg'=>"$created compte(s) créé(s), $skipped déjà existant(s)",'comptes'=>$createdList]);
        exit;

    /* ── Changer son propre mot de passe ── */
    } elseif ($action==='save_vacation_override') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — réservé à l\'administrateur']); exit; }
        $agent   = trim($_POST['agent']   ?? '');
        $date    = trim($_POST['date']    ?? '');
        $vacation= trim($_POST['vacation']?? '');
        if (!$agent||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!in_array($vacation,['J','M','AM','NUIT'])){
            echo json_encode(['ok'=>false,'msg'=>'Paramètre invalide']); exit;
        }
        $stmt=$mysqli->prepare("INSERT INTO vacation_overrides (agent,date,vacation,created_by) VALUES (?,?,?,?)
                                ON DUPLICATE KEY UPDATE vacation=VALUES(vacation),created_by=VALUES(created_by)");
        $stmt->bind_param('sssi',$agent,$date,$vacation,$userId);
        $ok=$stmt->execute();
        $newId=(int)$mysqli->insert_id;
        if(!$newId){ // UPDATE → récupérer l'id existant
            $s2=$mysqli->prepare("SELECT id FROM vacation_overrides WHERE agent=? AND date=?");
            $s2->bind_param('ss',$agent,$date);$s2->execute();
            $newId=(int)$s2->get_result()->fetch_assoc()['id'];
        }
        // Renvoyer la cellule mise à jour via get_cell
        $_POST['agents']=json_encode([$agent]);
        $_POST['dates']=json_encode([$date]);
        $action='get_cell'; // Réutiliser get_cell pour générer le HTML
        // On ne peut pas appeler get_cell directement ici sans restructurer
        // On renvoie simplement les infos — le JS appellera get_cell ensuite
        echo json_encode(['ok'=>$ok,'vac_id'=>$newId,'agent'=>$agent,'date'=>$date]);
        exit;

    } elseif ($action==='delete_vacation_override') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — réservé à l\'administrateur']); exit; }
        $vacId=(int)($_POST['vac_id']??0);
        if(!$vacId){ echo json_encode(['ok'=>false,'msg'=>'ID manquant']); exit; }
        // Récupérer agent+date avant suppression
        $sInfo=$mysqli->prepare("SELECT agent,date FROM vacation_overrides WHERE id=?");
        $sInfo->bind_param('i',$vacId);$sInfo->execute();
        $rInfo=$sInfo->get_result()->fetch_assoc();
        $agentDel=$rInfo['agent']??'';$dateDel=$rInfo['date']??'';
        $stmt=$mysqli->prepare("DELETE FROM vacation_overrides WHERE id=?");
        $stmt->bind_param('i',$vacId);
        $ok=$stmt->execute();
        echo json_encode(['ok'=>$ok,'agent'=>$agentDel,'date'=>$dateDel]);
        exit;

    } elseif ($action==='get_quotas') {
        // Admin : retourne tous les agents policiers
        // Non-admin : retourne uniquement son propre agent
        $yr = (int)($_POST['annee'] ?? date('Y'));
        $agentsPolice=['CoGe ROUSSEL','Cne MOKADEM',
          'BC MASSON','BC SIGAUD','BC DAINOTTI',
          'BC BOUXOM','BC ARNAULT','BC HOCHARD',
          'BC DUPUIS','BC BASTIEN','BC ANTHONY',
          'GP DHALLEWYN','BC DELCROIX','AA MAES','BC DRUEZ'];
        $stmt = $mysqli->prepare("SELECT agent,type_conge,quota,unite FROM agent_quotas WHERE annee=?");
        $stmt->bind_param('i',$yr);
        $stmt->execute();
        $res = $stmt->get_result();
        $quotas = [];
        while ($r=$res->fetch_assoc()) {
            $quotas[$r['agent']][$r['type_conge']] = ['quota'=>(float)$r['quota'],'unite'=>$r['unite']];
        }
        // Filtrage selon le rôle
        $result = [];
        if ($isAdmin) {
            foreach($agentsPolice as $ag) $result[$ag] = $quotas[$ag] ?? [];
        } else {
            // Non-admin : uniquement son propre agent s'il est dans la liste police
            $monAgent = $userAgents[0] ?? '';
            if ($monAgent && in_array($monAgent, $agentsPolice)) {
                $result[$monAgent] = $quotas[$monAgent] ?? [];
            }
        }
        echo json_encode(['ok'=>true,'quotas'=>$result,'annee'=>$yr]);
        exit;

    } elseif ($action==='set_quota') {
        if (!$isAdmin) { echo json_encode(['ok'=>false]); exit; }
        $agent     = trim($_POST['agent']      ?? '');
        $yr        = (int)($_POST['annee']     ?? date('Y'));
        $type      = trim($_POST['type_conge'] ?? '');
        $quota     = max(0, (float)($_POST['quota'] ?? 0));
        $unite     = in_array($_POST['unite']??'jours',['jours','heures'])?$_POST['unite']:'jours';
        if (!$agent||!$type) { echo json_encode(['ok'=>false,'msg'=>'Paramètre manquant']); exit; }
        $quota_s = (string)$quota;
        $stmt = $mysqli->prepare("INSERT INTO agent_quotas (agent,annee,type_conge,quota,unite)
                                  VALUES (?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE quota=VALUES(quota),unite=VALUES(unite)");
        $stmt->bind_param('sisss',$agent,$yr,$type,$quota_s,$unite);
        $ok = $stmt->execute();
        echo json_encode(['ok'=>$ok]);
        exit;

    } elseif ($action==='get_conges_count') {
        // Compte les congés posés PAR TYPE (hors WE et fériés) pour les agents Police
        $yr = (int)($_POST['annee'] ?? date('Y'));
        // Charger les fériés
        $feriesAnnee = [];
        $stF = $mysqli->prepare("SELECT date FROM feries WHERE YEAR(date)=?");
        $stF->bind_param('i',$yr);
        $stF->execute();
        $resF = $stF->get_result();
        while ($r=$resF->fetch_assoc()) $feriesAnnee[$r['date']] = true;

        // Récupérer congés avec leur type et période (matin/AM = 0.5 jour)
        $stC = $mysqli->prepare("SELECT agent,date_debut,date_fin,type_conge,COALESCE(periode,'J') as periode FROM conges WHERE YEAR(date_debut)=? OR YEAR(date_fin)=?");
        $stC->bind_param('ii',$yr,$yr);
        $stC->execute();
        $resC = $stC->get_result();
        $counts = []; // [agent][type] = nb jours ouvrés (0.5 pour demi-journées)
        $ca_hors_periode = []; // [agent] = nb jours CA posés en janv-avr et oct-déc
        while ($c=$resC->fetch_assoc()) {
            $ag     = $c['agent'];
            $type   = $c['type_conge'];
            $per    = $c['periode']; // J, M, AM
            // Valeur : 0.5 pour demi-journée (M ou AM), 1 pour journée entière
            $val    = ($per === 'M' || $per === 'AM') ? 0.5 : 1.0;
            $d      = new DateTime($c['date_debut']);
            $fin    = new DateTime($c['date_fin']);
            while ($d <= $fin) {
                $ds  = $d->format('Y-m-d');
                $yr2 = (int)$d->format('Y');
                if ($yr2 === $yr) {
                    $dow = (int)$d->format('N');
                    if ($dow < 6 && !isset($feriesAnnee[$ds])) {
                        if (!isset($counts[$ag])) $counts[$ag]=[];
                        $counts[$ag][$type] = ($counts[$ag][$type]??0) + $val;
                        // Compter les CA posés en périodes basses (jan-avr et oct-déc)
                        if ($type === 'CA') {
                            $mois = (int)$d->format('n');
                            if ($mois <= 4 || $mois >= 10) {
                                $ca_hors_periode[$ag] = ($ca_hors_periode[$ag]??0) + $val;
                            }
                        }
                    }
                }
                $d->modify('+1 day');
            }
        }
        echo json_encode(['ok'=>true,'counts'=>$counts,'ca_hors_periode'=>$ca_hors_periode,'annee'=>$yr]);
        exit;

    } elseif ($action==='change_password') {
        $old=trim($_POST['old_pass']??'');
        $new=trim($_POST['new_pass']??'');
        if (strlen($new)<8) { echo json_encode(['ok'=>false,'msg'=>'Mot de passe trop court (min 8 car.)']); exit; }
        $stSel=$mysqli->prepare("SELECT password FROM users WHERE id=?");
        $stSel->bind_param('i',$userId);
        $stSel->execute();
        $row=$stSel->get_result()->fetch_assoc();
        if (!password_verify($old,$row['password'])) { echo json_encode(['ok'=>false,'msg'=>'Ancien mot de passe incorrect']); exit; }
        $hash=password_hash($new,PASSWORD_BCRYPT);
        $stUpd=$mysqli->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?");
        $stUpd->bind_param('si',$hash,$userId);
        $stUpd->execute();
        $_SESSION['must_change']=false;
        echo json_encode(['ok'=>true,'msg'=>'Mot de passe modifié']);
        exit;
    }

    // Suite des actions planning normales ci-dessous...
    $agent      = trim($_POST['agent']      ?? '');
    $date_debut = trim($_POST['date_debut'] ?? '');
    $date_fin   = trim($_POST['date_fin']   ?? '');
    $type       = trim($_POST['type']       ?? '');
    $demi_jour  = trim($_POST['demi_jour']  ?? 'NONE');
    $periode    = trim($_POST['periode']    ?? 'J');
    $heure      = trim($_POST['heure']      ?? '');
    $heure      = preg_match('/^\d{2}:\d{2}$/',$heure) ? $heure : null;

    $conge_id   = (int)($_POST['conge_id']  ?? 0);
    $perm_id    = (int)($_POST['perm_id']   ?? 0);
    $cycle_orig = trim($_POST['cycle_orig'] ?? '');
    $dateReg    = '/^\d{4}-\d{2}-\d{2}$/';

    // Vérifier droits de modification pour cet agent
    if (in_array($action,['save_conge','delete_conge','save_perm','delete_perm','save_tir','delete_tir'])) {
        if (!canEdit($agent,$isAdmin,$globalLocked,$locks,$userAgents,$moisStr)) {
            echo json_encode(['ok'=>false,'msg'=>'Ligne verrouillée ou accès non autorisé']);
            exit;
        }
        // Vérification droits granulaires
        if (in_array($action,['save_tir','delete_tir']) && !$isAdmin) {
            echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — TIR réservé à l\'administrateur']);
            exit;
        }
        // Sur FERIE/WE : M/AM/IM/IAM autorisés même sans can_perm
        // Sur jours normaux : M/AM nécessitent can_perm
        if (in_array($action,['save_perm','delete_perm']) && !$isAdmin && !($userRights['can_perm']??false)) {
            $cycleOrig = trim($_POST['cycle_orig'] ?? '');
            $isFerieWE = in_array($cycleOrig, ['FERIE','RC','RL']);
            if (!$isFerieWE) {
                // Jour normal : vérifier si c'est une indispo (IM/IAM)
                if ($action === 'save_perm') {
                    $isIndispoAction = in_array(($_POST['type']??''),['IM','IAM','IJ']);
                } else {
                    $typePost = trim($_POST['perm_type'] ?? '');
                    if (in_array($typePost, ['IM','IAM','IJ'])) {
                        $isIndispoAction = true;
                    } else {
                        $pid = (int)($_POST['perm_id'] ?? 0);
                        $stPT=$mysqli->prepare("SELECT type FROM permanences WHERE id=?");
                        $stPT->bind_param('i',$pid);
                        $stPT->execute();
                        $rType=$stPT->get_result();
                        $tRow  = ($rType && $rType->num_rows > 0) ? $rType->fetch_assoc() : null;
                        $isIndispoAction = $tRow && in_array($tRow['type'], ['IM','IAM','IJ']);
                    }
                }
                if (!$isIndispoAction) {
                    echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — permanences non autorisées']);
                    exit;
                }
            }
            // Sur FERIE/WE : tout est autorisé → on laisse passer
        }
        if (in_array($action,['save_conge','delete_conge']) && !$isAdmin && !($userRights['can_conge']??false) && !$isDouane) {
            // PREV (prévisionnel) est autorisé à tous les utilisateurs authentifiés
            $typePosted = trim($_POST['type'] ?? '');
            $congeIdPosted = (int)($_POST['conge_id'] ?? 0);
            $isPrevAction = ($typePosted === 'PREV');
            // Pour delete_conge, vérifier le type en BDD
            if (!$isPrevAction && $action === 'delete_conge' && $congeIdPosted > 0) {
                $stPrev = $mysqli->prepare("SELECT type FROM conges WHERE id=?");
                $stPrev->bind_param('i', $congeIdPosted); $stPrev->execute();
                $rPrev = $stPrev->get_result();
                $rowPrev = $rPrev ? $rPrev->fetch_assoc() : null;
                $isPrevAction = ($rowPrev && $rowPrev['type'] === 'PREV');
            }
            if (!$isPrevAction) {
                echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — congés non autorisés']);
                exit;
            }
        }
    }

    if ($action==='reset_data') {
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé']); exit; }
        $reset_pwd = trim($_POST['reset_pwd'] ?? '');
        if ($reset_pwd !== RESET_PASSWORD) { echo json_encode(['ok'=>false,'msg'=>'Mot de passe incorrect']); exit; }
        $scope  = trim($_POST['scope']  ?? 'mois'); // 'mois' ou 'annee'
        $tables = json_decode($_POST['tables'] ?? '[]', true) ?: [];
        // Valeurs entières sûres (castées depuis $annee/$mois déjà validés en haut du script)
        $yr  = (int)$annee;
        $mo  = (int)$mois;
        // Bornes de dates construites à partir d'entiers uniquement — pas d'injection possible
        $dtMoisDeb = sprintf('%04d-%02d-01', $yr, $mo);
        $dtMoisFin = sprintf('%04d-%02d-31', $yr, $mo);
        $dtAnDeb   = sprintf('%04d-01-01', $yr);
        $dtAnFin   = sprintf('%04d-12-31', $yr);
        $deleted = [];
        $tableMap = [
            'conges'         => ['conges',          'date_debut'],
            'permanences'    => ['permanences',     'date'],
            'tir'            => ['tir',              'date'],
            'tir_annulations'=> ['tir_annulations', 'date'],
        ];
        foreach ($tableMap as $key => [$tbl, $col]) {
            if (!in_array($key, $tables)) continue;
            // Noms de tables/colonnes validés ci-dessus — jamais de l'entrée utilisateur
            $deb = ($scope==='mois') ? $dtMoisDeb : $dtAnDeb;
            $fin = ($scope==='mois') ? $dtMoisFin : $dtAnFin;
            $st = $mysqli->prepare("DELETE FROM `$tbl` WHERE `$col`>=? AND `$col`<=?");
            $st->bind_param('ss', $deb, $fin);
            $st->execute();
            $deleted[$key] = $st->affected_rows;
        }
        echo json_encode(['ok'=>true,'deleted'=>$deleted]);
        exit;
    }

    /* ── Polling : dernier changement ── */
    if ($action==='get_last_change') {
        $r1 = $mysqli->query("SELECT MAX(id) as m FROM permanences");
        $r2 = $mysqli->query("SELECT MAX(id) as m FROM conges");
        $m1 = ($r1 && $row=$r1->fetch_assoc()) ? (int)$row['m'] : 0;
        $m2 = ($r2 && $row=$r2->fetch_assoc()) ? (int)$row['m'] : 0;
        echo json_encode(['token' => $m1.'-'.$m2]);
        exit;
    }

    /* ── Rendu AJAX d'une ou plusieurs cellules ── */
    if ($action==='get_cell') {
        $agents_raw = trim($_POST['agents'] ?? '');
        $dates_raw  = trim($_POST['dates']  ?? '');
        if (!$agents_raw || !$dates_raw) { echo json_encode(['ok'=>false,'msg'=>'agents/dates manquants']); exit; }
        $agentsList = array_map('trim', json_decode($agents_raw, true) ?: [$agents_raw]);
        $datesList  = array_values(array_filter(array_map('trim', json_decode($dates_raw, true) ?: [$dates_raw]),
                        fn($d)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)));
        // Recharger les verrous depuis la BDD (ils peuvent avoir changé depuis le chargement initial)
        $locks        = loadLocks($mysqli);
        $globalLocked = $locks['global'];
        $cells = renderCellsHtml($mysqli,$agentsList,$datesList,$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents);
        $r1=$mysqli->query("SELECT MAX(id) as m FROM permanences");
        $r2=$mysqli->query("SELECT MAX(id) as m FROM conges");
        $m1=($r1&&$row=$r1->fetch_assoc())?(int)$row['m']:0;
        $m2=($r2&&$row=$r2->fetch_assoc())?(int)$row['m']:0;
        echo json_encode(['ok'=>true,'cells'=>$cells,'token'=>$m1.'-'.$m2]);
        exit;
    }

        /* ── Sauvegarde CONGÉ ── */
    if ($action==='save_conge') {
        if (!preg_match($dateReg,$date_debut)||!preg_match($dateReg,$date_fin))
            { echo json_encode(['ok'=>false,'msg'=>'Dates invalides']); exit; }
        if ($date_fin < $date_debut)
            { echo json_encode(['ok'=>false,'msg'=>'Date fin < date début']); exit; }

        // ── Blocage PREV si congé admin déjà posé sur la plage de dates ──────────
        if (!$isAdmin && $type === 'PREV' && $conge_id === 0) {
            // Vérifier TOUTE la plage date_debut → date_fin (pas seulement date_debut)
            $stCheck = $mysqli->prepare(
                "SELECT type, type_conge FROM conges
                  WHERE agent = ?
                    AND date_debut <= ?
                    AND date_fin   >= ?
                    AND type_conge <> 'PREV'
                  LIMIT 1"
            );
            $stCheck->bind_param('sss', $agent, $date_fin, $date_debut);
            $stCheck->execute();
            $rCheck = $stCheck->get_result();
            if ($rCheck && $rCheck->num_rows > 0) {
                $rowCheck = $rCheck->fetch_assoc();
                $codeAffiche = htmlspecialchars($rowCheck['type_conge'] ?: $rowCheck['type'], ENT_QUOTES, 'UTF-8');
                echo json_encode([
                    'ok'  => false,
                    'msg' => "⛔ Un congé (" . $codeAffiche . ") a déjà été posé sur cette période par l'administrateur. Veuillez contacter l'administrateur pour toute modification."
                ]);
                exit;
            }
        }
        // ────────────────────────────────────────────────────────────────────────

        if ($conge_id > 0) {
            $stmt = $mysqli->prepare("UPDATE conges SET date_debut=?,date_fin=?,type_conge=?,demi_jour=?,periode=?,heure=? WHERE id=?");
            $stmt->bind_param('ssssssi',$date_debut,$date_fin,$type,$demi_jour,$periode,$heure,$conge_id);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO conges (agent,agent_nom,date_debut,date_fin,type,type_conge,demi_jour,periode,heure) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssss',$agent,$agent,$date_debut,$date_fin,$type,$type,$demi_jour,$periode,$heure);
        }
        $ok  = $stmt->execute();
        if (!$ok) {
            echo json_encode(['ok'=>false,'msg'=>'Erreur SQL : '.$stmt->error]);
            exit;
        }
        $nid = $conge_id>0 ? $conge_id : (int)$mysqli->insert_id;
        $dts=[];$d=new DateTime($date_debut);$fi=new DateTime($date_fin);
        while($d<=$fi){$dts[]=$d->format('Y-m-d');$d->modify('+1 day');}
        $cells=renderCellsHtml($mysqli,[$agent],$dts,$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents);
        echo json_encode(['ok'=>true,'msg'=>'Congé sauvegardé','id'=>$nid,'date_debut'=>$date_debut,'date_fin'=>$date_fin,'cells'=>$cells]);

    /* ── Suppression CONGÉ ── */
    } elseif ($action==='delete_conge') {
        $stmt = $mysqli->prepare("DELETE FROM conges WHERE id=?");
        $stmt->bind_param('i',$conge_id);
        $ok = $stmt->execute();
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Congé supprimé':'Erreur : '.$mysqli->error,'cells'=>$cells]);

    /* ── Sauvegarde PERMANENCE ── */
    } elseif ($action==='save_perm') {
        if (!preg_match($dateReg,$date_debut))
            { echo json_encode(['ok'=>false,'msg'=>'Date invalide']); exit; }
        if (!in_array($type,['M','AM','IM','IAM','IJ']))
            { echo json_encode(['ok'=>false,'msg'=>'Type invalide']); exit; }
        if (!in_array($cycle_orig,['RC','RL','FERIE','M','AM','J','NUIT','']))
            { echo json_encode(['ok'=>false,'msg'=>'Cycle invalide']); exit; }
        if ($perm_id > 0) {
            $stmt = $mysqli->prepare("UPDATE permanences SET type=?,cycle_orig=? WHERE id=?");
            $stmt->bind_param('ssi',$type,$cycle_orig,$perm_id);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO permanences (agent,date,type,cycle_orig) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE type=VALUES(type),cycle_orig=VALUES(cycle_orig)");
            $stmt->bind_param('ssss',$agent,$date_debut,$type,$cycle_orig);
        }
        $ok  = $stmt->execute();
        if (!$ok) {
            echo json_encode(['ok'=>false,'msg'=>'Erreur SQL : '.$stmt->error]); exit;
        }
        if ($perm_id > 0) {
            $nid = $perm_id;
        } else {
            $nid = (int)$mysqli->insert_id;
            if ($nid === 0) {
                // ON DUPLICATE KEY UPDATE sans changement : récupérer l'ID existant
                $stId = $mysqli->prepare("SELECT id FROM permanences WHERE agent=? AND date=?");
                $stId->bind_param('ss', $agent, $date_debut);
                $stId->execute();
                $rId = $stId->get_result();
                $nid = $rId ? (int)($rId->fetch_assoc()['id']??0) : 0;
            }
        }
        $cells=renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents);
        echo json_encode(['ok'=>true,'msg'=>'Permanence sauvegardée','id'=>$nid,'cells'=>$cells]);

    /* ── Suppression PERMANENCE ── */
    } elseif ($action==='delete_perm') {
        $stmt = $mysqli->prepare("DELETE FROM permanences WHERE id=?");
        $stmt->bind_param('i',$perm_id);
        $ok = $stmt->execute();
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Permanence supprimée':'Erreur : '.$mysqli->error,'cells'=>$cells]);

    /* ── Sauvegarde TIR ── */
    } elseif ($action==='save_tir') {
        $tir_id  = (int)($_POST['tir_id'] ?? 0);
        $periode = trim($_POST['periode'] ?? 'J');
        if (!preg_match($dateReg,$date_debut))
            { echo json_encode(['ok'=>false,'msg'=>'Date invalide']); exit; }
        if (!in_array($periode,['M','AM','NUIT']))
            { echo json_encode(['ok'=>false,'msg'=>'Période invalide']); exit; }
        if (!in_array($agent,$agentsTir))
            { echo json_encode(['ok'=>false,'msg'=>'Cet agent ne peut pas avoir de séance TIR']); exit; }
        if ($tir_id > 0) {
            $stmt = $mysqli->prepare("UPDATE tir SET periode=? WHERE id=?");
            $stmt->bind_param('si',$periode,$tir_id);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO tir (agent,date,periode) VALUES (?,?,?) ON DUPLICATE KEY UPDATE periode=VALUES(periode)");
            $stmt->bind_param('sss',$agent,$date_debut,$periode);
        }
        $ok  = $stmt->execute();
        $nid = $tir_id>0 ? $tir_id : (int)$mysqli->insert_id;
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'TIR sauvegardé':'Erreur : '.$mysqli->error,'id'=>$nid,'cells'=>$cells]);

    /* ── Suppression TIR ── */
    } elseif ($action==='delete_tir') {
        $tir_id = (int)($_POST['tir_id'] ?? 0);
        $stmt = $mysqli->prepare("DELETE FROM tir WHERE id=?");
        $stmt->bind_param('i',$tir_id);
        $ok = $stmt->execute();
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'TIR supprimé':'Erreur : '.$mysqli->error,'cells'=>$cells]);

    /* ── Annulation TIR ── */
    } elseif ($action==='save_tir_annul') {
        if (!preg_match($dateReg,$date_debut))
            { echo json_encode(['ok'=>false,'msg'=>'Date invalide']); exit; }
        $motif = trim($_POST['motif'] ?? 'Indisponibilité stand');
        $motif = $motif ?: 'Indisponibilité stand';
        $stmt = $mysqli->prepare("INSERT INTO tir_annulations (agent,date,motif,annule_par)
                                  VALUES (?,?,?,?)
                                  ON DUPLICATE KEY UPDATE motif=VALUES(motif)");
        $stmt->bind_param('sssi',$agent,$date_debut,$motif,$userId);
        $ok = $stmt->execute();
        $nid = (int)$mysqli->insert_id;
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'id'=>$nid,'msg'=>$ok?'Annulation enregistrée':'Erreur : '.$mysqli->error,'cells'=>$cells]);

    } elseif ($action==='delete_tir_annul') {
        $aid = (int)($_POST['annul_id'] ?? 0);
        $stmt = $mysqli->prepare("DELETE FROM tir_annulations WHERE id=?");
        $stmt->bind_param('i',$aid);
        $ok = $stmt->execute();
        $cells=$ok?renderCellsHtml($mysqli,[$agent],[$date_debut],$groupeAgent,$agentsTir,$agentsPermanence,$isAdmin,$globalLocked,$locks,$userAgents):[];
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Annulation supprimée':'Erreur','cells'=>$cells]);

    /* ── Compteur TIR ── */
    } elseif ($action==='save_tir_note') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $agent = trim($_POST['agent'] ?? '');
        $yr    = (int)($_POST['annee'] ?? $annee);
        $note  = trim($_POST['note'] ?? '');
        if(!$agent){ echo json_encode(['ok'=>false,'msg'=>'Agent manquant']); exit; }
        $stmt=$mysqli->prepare("INSERT INTO tir_notes (agent,annee,note) VALUES (?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note)");
        $stmt->bind_param('sis',$agent,$yr,$note);
        $ok=$stmt->execute();
        echo json_encode(['ok'=>$ok]);
        exit;

    } elseif ($action==='compteur_tir') {
        $yr  = (int)($_POST['annee'] ?? $annee);
        $mo  = (int)($_POST['mois']  ?? 0);
        $qr  = (int)($_POST['quad']  ?? 0); // 1,2,3 pour quadrimestre

        // Tout récupérer d'un coup
        $stTirAll=$mysqli->prepare("SELECT agent, date, periode, MONTH(date) as mois FROM tir WHERE YEAR(date)=? ORDER BY agent, date");
        $stTirAll->bind_param('i',$yr);
        $stTirAll->execute();
        $res=$stTirAll->get_result();
        $annuel=[]; $parMois=[]; $quadri=[];
        while($r=$res->fetch_assoc()){
            $ag  = $r['agent'];
            $per = $r['periode'];
            $m   = (int)$r['mois'];
            $q   = $m<=4 ? 1 : ($m<=8 ? 2 : 3);
            // Annuel par période
            $annuel[$ag][$per] = ($annuel[$ag][$per]??0)+1;
            // Par mois
            $parMois[$ag][$m][] = ['date'=>$r['date'],'periode'=>$per];
            // Par quadrimestre
            if(!isset($quadri[$ag][$q])) $quadri[$ag][$q]=['nb'=>0,'dates'=>[]];
            $quadri[$ag][$q]['nb']++;
            $quadri[$ag][$q]['dates'][] = $r['date'];
        }
        // Détail mensuel si mois demandé
        $mensuel=[];
        if($mo>0){
            $stTirMo=$mysqli->prepare("SELECT agent,date,periode FROM tir WHERE YEAR(date)=? AND MONTH(date)=? ORDER BY date,agent");
            $stTirMo->bind_param('ii',$yr,$mo);
            $stTirMo->execute();
            $res2=$stTirMo->get_result();
            while($r=$res2->fetch_assoc()) $mensuel[]=$r;
        }
        // Détail quadrimestre si demandé
        $detailQuad=[];
        if($qr>0){
            $mD=$qr===1?1:($qr===2?5:9); $mF=$qr===1?4:($qr===2?8:12);
            $stTirQ=$mysqli->prepare("SELECT agent,date,periode FROM tir WHERE YEAR(date)=? AND MONTH(date) BETWEEN ? AND ? ORDER BY date,agent");
            $stTirQ->bind_param('iii',$yr,$mD,$mF);
            $stTirQ->execute();
            $res3=$stTirQ->get_result();
            while($r=$res3->fetch_assoc()) $detailQuad[]=$r;
        }
        // Annulations TIR par agent
        $annulations=[];
        $stTirAn=$mysqli->prepare("SELECT id,agent,date FROM tir_annulations WHERE YEAR(date)=? ORDER BY agent,date");
        $stTirAn->bind_param('i',$yr);
        $stTirAn->execute();
        $res4=$stTirAn->get_result();
        while($r=$res4->fetch_assoc()){
            $annulations[$r['agent']][] = ['id'=>(int)$r['id'],'date'=>$r['date']];
        }
        // Notes TIR par agent (admin uniquement)
        $tirNotes=[];
        if($isAdmin){
            $stNotes=$mysqli->prepare("SELECT agent,note FROM tir_notes WHERE annee=?");
            $stNotes->bind_param('i',$yr);
            $stNotes->execute();
            $resN=$stNotes->get_result();
            while($rn=$resN->fetch_assoc()) $tirNotes[$rn['agent']]=$rn['note'];
        }
        echo json_encode(['ok'=>true,'annuel'=>$annuel,'parMois'=>$parMois,'quadri'=>$quadri,
                          'mensuel'=>$mensuel,'detailQuad'=>$detailQuad,
                          'annulations'=>$annulations,'tirNotes'=>$tirNotes,'annee'=>$yr,'mois'=>$mo,'quad'=>$qr]);

    } elseif ($action==='compteur_perm') {
        $yr = (int)($_POST['annee'] ?? $annee);
        $mo = (int)($_POST['mois'] ?? 0);

        // Annuel : totaux par agent et type
        $stPermAll=$mysqli->prepare("SELECT agent,date,type,cycle_orig FROM permanences WHERE YEAR(date)=? ORDER BY agent,date");
        $stPermAll->bind_param('i',$yr);
        $stPermAll->execute();
        $res=$stPermAll->get_result();
        $data = []; $mensuel = [];
        while ($r=$res->fetch_assoc()) {
            $ag  = $r['agent'];
            $key = $r['cycle_orig'].'_'.$r['type'];
            if (!isset($data[$ag])) $data[$ag]=[];
            $data[$ag][$key] = ($data[$ag][$key]??0)+1;
            // Par mois
            $m = (int)substr($r['date'],5,2);
            if (!isset($data[$ag]['mois'][$m])) $data[$ag]['mois'][$m]=[];
            $data[$ag]['mois'][$m][$key] = ($data[$ag]['mois'][$m][$key]??0)+1;
            // Détail mensuel si mois demandé
            if ($mo > 0 && $m === $mo) $mensuel[] = $r;
        }
        echo json_encode(['ok'=>true,'data'=>$data,'mensuel'=>$mensuel,'annee'=>$yr,'mois'=>$mo]);

    /* ── Notes mois : chargement ── */
    } elseif ($action==='get_notes') {
        $yr = (int)($_POST['annee'] ?? $annee);
        $mo = (int)($_POST['mois']  ?? $mois);
        $today = date('Y-m-d');
        $evts = [];
        $stEvt=$mysqli->prepare("SELECT id,date,libelle FROM notes_evenements WHERE annee=? AND mois=? ORDER BY date,id");
        $stEvt->bind_param('ii',$yr,$mo);
        $stEvt->execute();
        $resEvt=$stEvt->get_result();
        while ($r=$resEvt->fetch_assoc()) $evts[] = $r;
        // Messages actifs (date_fin >= aujourd'hui)
        $msgs = [];
        $stMsg=$mysqli->prepare("SELECT id,texte,date_fin,created_at FROM notes_messages WHERE annee=? AND mois=? AND date_fin>=? ORDER BY date_fin ASC");
        $stMsg->bind_param('iis',$yr,$mo,$today);
        $stMsg->execute();
        $resMsg=$stMsg->get_result();
        while ($r=$resMsg->fetch_assoc()) $msgs[] = $r;
        // Messages archivés (date_fin < aujourd'hui)
        $arch = [];
        $stArch=$mysqli->prepare("SELECT id,texte,date_fin,created_at FROM notes_messages WHERE annee=? AND mois=? AND date_fin<? ORDER BY date_fin DESC");
        $stArch->bind_param('iis',$yr,$mo,$today);
        $stArch->execute();
        $resArch=$stArch->get_result();
        while ($r=$resArch->fetch_assoc()) $arch[] = $r;
        echo json_encode(['ok'=>true,'evenements'=>$evts,'messages'=>$msgs,'archives'=>$arch]);

    /* ── Messages : ajout ── */
    } elseif ($action==='save_note_msg') {
        $yr      = (int)($_POST['annee']    ?? $annee);
        $mo      = (int)($_POST['mois']     ?? $mois);
        $texte   = trim($_POST['texte']     ?? '');
        $datefin = trim($_POST['date_fin']  ?? '');
        $msgId   = (int)($_POST['msg_id']   ?? 0);
        if (!$texte)   { echo json_encode(['ok'=>false,'msg'=>'Texte vide']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datefin)) { echo json_encode(['ok'=>false,'msg'=>'Date de fin invalide']); exit; }
        if ($msgId > 0) {
            $stmt = $mysqli->prepare("UPDATE notes_messages SET texte=?,date_fin=? WHERE id=?");
            $stmt->bind_param('ssi',$texte,$datefin,$msgId);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO notes_messages (annee,mois,texte,date_fin,created_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iissi',$yr,$mo,$texte,$datefin,$userId);
        }
        $ok  = $stmt->execute();
        $nid = $msgId>0 ? $msgId : (int)$mysqli->insert_id;
        echo json_encode(['ok'=>$ok,'id'=>$nid]);

    /* ── Messages : suppression ── */
    } elseif ($action==='delete_note_msg') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        $stmt  = $mysqli->prepare("DELETE FROM notes_messages WHERE id=?");
        $stmt->bind_param('i',$msgId);
        $ok = $stmt->execute();
        echo json_encode(['ok'=>$ok]);

    /* ── Notes mois : ajout événement ── */
    } elseif ($action==='save_note_evt') {
        $date   = trim($_POST['evt_date']   ?? '');
        $libelle= trim($_POST['evt_libelle']?? '');
        $evtId  = (int)($_POST['evt_id'] ?? 0);
        if (!$libelle) { echo json_encode(['ok'=>false,'msg'=>'Libellé vide']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { echo json_encode(['ok'=>false,'msg'=>'Date invalide']); exit; }
        // Extraire année et mois depuis la date réelle (permet saisie hors mois courant)
        $yr = (int)substr($date,0,4);
        $mo = (int)substr($date,5,2);
        if ($evtId > 0) {
            $stmt = $mysqli->prepare("UPDATE notes_evenements SET date=?,libelle=? WHERE id=?");
            $stmt->bind_param('ssi',$date,$libelle,$evtId);
        } else {
            $stmt = $mysqli->prepare("INSERT INTO notes_evenements (annee,mois,date,libelle,created_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iissi',$yr,$mo,$date,$libelle,$userId);
        }
        $ok  = $stmt->execute();
        $nid = $evtId>0 ? $evtId : (int)$mysqli->insert_id;
        echo json_encode(['ok'=>$ok,'id'=>$nid,'annee'=>$yr,'mois'=>$mo]);

    /* ── Notes mois : suppression événement ── */
    } elseif ($action==='delete_note_evt') {
        $evtId = (int)($_POST['evt_id'] ?? 0);
        $stmt  = $mysqli->prepare("DELETE FROM notes_evenements WHERE id=?");
        $stmt->bind_param('i',$evtId);
        $ok = $stmt->execute();
        echo json_encode(['ok'=>$ok]);

    } else {
        echo json_encode(['ok'=>false,'msg'=>'Action inconnue']);
    }
    exit;
}

/* ======================
   CALENDRIER / FERIES
====================== */
$nbJours = cal_days_in_month(CAL_GREGORIAN,$mois,$annee);

/* ── Calcul des jours fériés français pour une année donnée ── */
function calculerFeries(int $yr): array {
    // Algorithme de Meeus/Jones/Butcher pour la date de Pâques
    $a = $yr % 19;
    $b = intdiv($yr, 100);
    $c = $yr % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $moisP = intdiv($h + $l - 7 * $m + 114, 31);
    $jourP = (($h + $l - 7 * $m + 114) % 31) + 1;
    $paques = new DateTime("$yr-".sprintf('%02d',$moisP)."-".sprintf('%02d',$jourP));

    $feries = [];
    // Fériés fixes
    foreach ([
        '01-01' => 'Jour de l\'An',
        '05-01' => 'Fête du Travail',
        '05-08' => 'Victoire 1945',
        '07-14' => 'Fête Nationale',
        '08-15' => 'Assomption',
        '11-01' => 'Toussaint',
        '11-11' => 'Armistice',
        '12-25' => 'Noël',
    ] as $md => $label) {
        $feries["$yr-$md"] = $label;
    }
    // Fériés mobiles (basés sur Pâques)
    $lundi_paques = (clone $paques)->modify('+1 day');
    $ascension    = (clone $paques)->modify('+39 days');
    $lundi_pente  = (clone $paques)->modify('+50 days');
    $feries[$lundi_paques->format('Y-m-d')] = 'Lundi de Pâques';
    $feries[$ascension->format('Y-m-d')]    = 'Ascension';
    $feries[$lundi_pente->format('Y-m-d')]  = 'Lundi de Pentecôte';

    return $feries;
}

/* ── Auto-insertion si l'année n'existe pas en base ── */
function autoInsertFeries(mysqli $db, int $yr): void {
    $count = (int)$db->query("SELECT COUNT(*) as n FROM feries WHERE YEAR(date)=$yr")->fetch_assoc()['n'];
    if ($count > 0) return; // Déjà présents
    $feries = calculerFeries($yr);
    $stmt = $db->prepare("INSERT IGNORE INTO feries (date) VALUES (?)");
    foreach ($feries as $date => $label) {
        $stmt->bind_param('s', $date);
        $stmt->execute();
    }
}

autoInsertFeries($mysqli, $annee);

$feriesAll = [];
$res = $mysqli->query("SELECT date FROM feries WHERE YEAR(date)=$annee");
while ($f=$res->fetch_assoc()) $feriesAll[$f['date']] = true;
$feries = array_filter($feriesAll,fn($k)=>(int)substr($k,5,2)===$mois,ARRAY_FILTER_USE_KEY);

/* ======================
   CONGÉS
====================== */
function loadConges(mysqli $db,int $annee,int $moisD,int $moisF): array {
    $conges=[]; $congesIds=[]; $congesMeta=[];
    $mp=sprintf('%02d',$moisD); $mf=sprintf('%02d',$moisF);
    $sql="SELECT c.id,c.agent,c.date_debut,c.date_fin,c.type_conge,c.periode,c.heure,
                 COALESCE(tc.libelle,tcd.libelle,c.type_conge) as libelle,
                 COALESCE(tc.couleur_bg,tcd.couleur_bg,'#1565c0') as couleur_bg,
                 COALESCE(tc.couleur_txt,tcd.couleur_txt,'#ffffff') as couleur_txt
          FROM conges c
          LEFT JOIN types_conges tc ON tc.code=c.type_conge
          LEFT JOIN types_conges_douane tcd ON tcd.code=c.type_conge
          WHERE c.date_fin>='$annee-$mp-01'
            AND c.date_debut<='$annee-$mf-31'";
    $res=$db->query($sql);
    while ($c=$res->fetch_assoc()) {
        $ag=$c['agent']; $d=new DateTime($c['date_debut']); $fi=new DateTime($c['date_fin']);
        while ($d<=$fi) {
            $ds=$d->format('Y-m-d');
            $conges[$ag][$ds]    = $c['type_conge'];
            $congesIds[$ag][$ds] = $c['id'];
            $congesMeta[$ag][$ds]= ['periode'=>$c['periode']??'J','libelle'=>$c['libelle'],'heure'=>$c['heure']??'','couleur_bg'=>$c['couleur_bg']??'#1565c0','couleur_txt'=>$c['couleur_txt']??'#ffffff'];
            $d->modify('+1 day');
        }
    }
    return [$conges,$congesIds,$congesMeta];
}

/* ======================
   PERMANENCES
====================== */
function loadPerms(mysqli $db,int $annee,int $moisD,int $moisF): array {
    $perms=[]; $permsIds=[];
    $mp=sprintf('%02d',$moisD); $mf=sprintf('%02d',$moisF);
    $res=$db->query("SELECT id,agent,date,type,cycle_orig FROM permanences
                     WHERE date>='$annee-$mp-01' AND date<='$annee-$mf-31'");
    while ($r=$res->fetch_assoc()) {
        $perms[$r['agent']][$r['date']]    = ['type'=>$r['type'],'cycle_orig'=>$r['cycle_orig']];
        $permsIds[$r['agent']][$r['date']] = $r['id'];
    }
    return [$perms,$permsIds];
}

/* ======================
   TIR
====================== */
function loadTirs(mysqli $db,int $annee,int $moisD,int $moisF): array {
    $tirs=[]; $tirsIds=[]; $tirsAnnul=[];
    $mp=sprintf('%02d',$moisD); $mf=sprintf('%02d',$moisF);
    $res=$db->query("SELECT id,agent,date,periode FROM tir
                     WHERE date>='$annee-$mp-01' AND date<='$annee-$mf-31'");
    while ($r=$res->fetch_assoc()) {
        $tirs[$r['agent']][$r['date']]    = $r['periode'];
        $tirsIds[$r['agent']][$r['date']] = $r['id'];
    }
    // Annulations du mois
    $res2=$db->query("SELECT id,agent,date FROM tir_annulations
                      WHERE date>='$annee-$mp-01' AND date<='$annee-$mf-31'");
    while ($r=$res2->fetch_assoc()) {
        $tirsAnnul[$r['agent']][$r['date']] = $r['id'];
    }
    return [$tirs,$tirsIds,$tirsAnnul];
}

[$conges,$congesIds,$congesMeta]           = loadConges($mysqli,$annee,$mois,$mois);
[$congesAnnee,$congesIdsAnnee,$congesMetaAnnee] = loadConges($mysqli,$annee,1,12);
[$perms,$permsIds]             = loadPerms($mysqli,$annee,$mois,$mois);
[$permsAnnee,$permsIdsAnnee]   = loadPerms($mysqli,$annee,1,12);
[$tirs,$tirsIds,$tirsAnnul]               = loadTirs($mysqli,$annee,$mois,$mois);
[$tirsAnnee,$tirsIdsAnnee,$tirsAnnulAnnee] = loadTirs($mysqli,$annee,1,12);

// Charger les overrides de vacation pour le mois (table peut ne pas exister si migration pas encore passée)
$vacOvr=[]; $vacOvrId=[];
try {
    $stV=$mysqli->prepare("SELECT id,agent,date,vacation FROM vacation_overrides WHERE YEAR(date)=? AND MONTH(date)=?");
    $stV->bind_param('ii',$annee,$mois);
    $stV->execute();
    $resV=$stV->get_result();
    while($r=$resV->fetch_assoc()){
        $vacOvr[$r['agent']][$r['date']]   = $r['vacation'];
        $vacOvrId[$r['agent']][$r['date']] = $r['id'];
    }
} catch (Throwable $e) { /* table vacation_overrides pas encore créée */ }

/* ======================
   CODES
   Les valeurs par défaut ci-dessous sont utilisées si la table DB est vide
   (ex: install_planning.sql pas encore exécuté).
   Une fois le script SQL exécuté, les valeurs DB prennent le dessus.
====================== */

// Valeurs par défaut intégrées — utilisées si la DB est vide
$CODES_POLICE_DEFAUT = [
  'CA'  =>['code'=>'CA',  'libelle'=>'Congé annuel',             'couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'],
  'CAA' =>['code'=>'CAA', 'libelle'=>'Congé annuel antérieur',    'couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'],
  'CAM' =>['code'=>'CAM', 'libelle'=>'Congé annuel maladie',    'couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'],
  'RTT' =>['code'=>'RTT', 'libelle'=>'RTT Police',               'couleur_bg'=>'#93c47d','couleur_txt'=>'#222222'],
  'CM'  =>['code'=>'CM',  'libelle'=>'Congé maladie',            'couleur_bg'=>'#ff9999','couleur_txt'=>'#222222'],
  'DA'  =>['code'=>'DA',  'libelle'=>'Départ avancé',          'couleur_bg'=>'#fce5cd','couleur_txt'=>'#222222'],
  'PR'  =>['code'=>'PR',  'libelle'=>'Prise retardée',   'couleur_bg'=>'#fce5cd','couleur_txt'=>'#222222'],
  'HP'  =>['code'=>'HP',  'libelle'=>'Congé Annuel Hors Période',     'couleur_bg'=>'#d9d2e9','couleur_txt'=>'#222222'],
  'HPA' =>['code'=>'HPA', 'libelle'=>'Congé Annuel Hors Période antérieur',     'couleur_bg'=>'#d9d2e9','couleur_txt'=>'#222222'],
  'HS'  =>['code'=>'HS',  'libelle'=>'Heure supplémentaire',     'couleur_bg'=>'#f4cccc','couleur_txt'=>'#222222'],
  'CET' =>['code'=>'CET', 'libelle'=>'Compte épargne-temps',     'couleur_bg'=>'#0d47a1','couleur_txt'=>'#ffffff'],
  'CF'  =>['code'=>'CF',  'libelle'=>'Congé Férié',       'couleur_bg'=>'#e6b8a2','couleur_txt'=>'#222222'],
  'RPS' =>['code'=>'RPS', 'libelle'=>'Repos Pénibilité Spécifique',   'couleur_bg'=>'#ead1dc','couleur_txt'=>'#222222'],
  'ASA' =>['code'=>'ASA', 'libelle'=>'Autorisation Spéciale d Absence','couleur_bg'=>'#a4c2f4','couleur_txt'=>'#1a237e'],
  'CONV'=>['code'=>'CONV','libelle'=>'Convocation',   'couleur_bg'=>'#ffe599','couleur_txt'=>'#222222'],
  'GEM' =>['code'=>'GEM', 'libelle'=>'Garde enfant malade',      'couleur_bg'=>'#d9ead3','couleur_txt'=>'#222222'],
  'VEM' =>['code'=>'VEM', 'libelle'=>'Visite Examen Médical',   'couleur_bg'=>'#d0e0e3','couleur_txt'=>'#222222'],
  'STG' =>['code'=>'STG', 'libelle'=>'Stage',                    'couleur_bg'=>'#cfe2f3','couleur_txt'=>'#222222'],
  'RCH' =>['code'=>'RCH', 'libelle'=>'Repos Compensé Badgé',        'couleur_bg'=>'#b6d7a8','couleur_txt'=>'#222222'],
  'RCR' =>['code'=>'RCR', 'libelle'=>'Repos Compensé Reporté',  'couleur_bg'=>'#b6d7a8','couleur_txt'=>'#222222'],
  'CMO' =>['code'=>'CMO', 'libelle'=>'Congé Maladie Ordinaire',  'couleur_bg'=>'#ff6666','couleur_txt'=>'#ffffff'],
  'CLM' =>['code'=>'CLM', 'libelle'=>'Congé Longue Maladie',     'couleur_bg'=>'#cc0000','couleur_txt'=>'#ffffff'],
  'CLD' =>['code'=>'CLD', 'libelle'=>'Congé Longue Durée',       'couleur_bg'=>'#990000','couleur_txt'=>'#ffffff'],
  'PREV'=>['code'=>'PREV','libelle'=>'Prévisionnel congé',        'couleur_bg'=>'#ffcc80','couleur_txt'=>'#7a3e00'],
  'AUT' =>['code'=>'AUT', 'libelle'=>'Autres absences',           'couleur_bg'=>'#b0bec5','couleur_txt'=>'#212121'],
];
$CODES_DOUANE_DEFAUT = [
  'CA'  =>['code'=>'CA',  'libelle'=>'Congé annuel',             'couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'],
  'CM'  =>['code'=>'CM',  'libelle'=>'Congé maladie',            'couleur_bg'=>'#ff9999','couleur_txt'=>'#222222'],
  'CE'  =>['code'=>'CE',  'libelle'=>'Congé exceptionnel',       'couleur_bg'=>'#ffe599','couleur_txt'=>'#222222'],
  'DA'  =>['code'=>'DA',  'libelle'=>'Départ avancé',          'couleur_bg'=>'#fce5cd','couleur_txt'=>'#222222'],
  'PR'  =>['code'=>'PR',  'libelle'=>'Prise retardée',   'couleur_bg'=>'#fce5cd','couleur_txt'=>'#222222'],
  'RTT' =>['code'=>'RTT', 'libelle'=>'RTT Douane',               'couleur_bg'=>'#93c47d','couleur_txt'=>'#222222'],
  'CONV'=>['code'=>'CONV','libelle'=>'Convocation',   'couleur_bg'=>'#ffe599','couleur_txt'=>'#222222'],
];

$typesConges=[];
$res=$mysqli->query("SELECT code,libelle,couleur_bg,couleur_txt FROM types_conges WHERE actif=1 ORDER BY code");
while ($r=$res->fetch_assoc()) $typesConges[$r['code']]=$r;
// Si la table est vide (DB non initialisée), on utilise les valeurs par défaut
if (empty($typesConges)) {
    $typesConges = $CODES_POLICE_DEFAUT;
}

$typesCongesDouane=[];
$res=$mysqli->query("SELECT code,libelle,couleur_bg,couleur_txt FROM types_conges_douane WHERE actif=1 ORDER BY code");
while ($r=$res->fetch_assoc()) $typesCongesDouane[$r['code']]=$r;
// Si la table est vide, on utilise les valeurs par défaut douane
if (empty($typesCongesDouane)) {
    $typesCongesDouane = $CODES_DOUANE_DEFAUT;
}

$typesCongesGie=['P'=>['code'=>'P','libelle'=>'Présent','couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
  'J'=>['code'=>'J', 'libelle'=>'Journée',    'couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
  'M'=>['code'=>'M', 'libelle'=>'Matin',      'couleur_bg'=>'#ffd200','couleur_txt'=>'#222'],
  'AM'=>['code'=>'AM','libelle'=>'Après-midi','couleur_bg'=>'#2f5597','couleur_txt'=>'#ffc000'],
  'R'=>['code'=>'R', 'libelle'=>'Repos',      'couleur_bg'=>'#bfbfbf','couleur_txt'=>'#333']];

// Codes Standard (sans RTT, CF, HS)
$codesStandard=array_filter($typesConges,fn($k)=>in_array($k,
  ['ASA','CA','CAA','CAM','CET','CM','CONV','DA','GEM','HP','HPA','PR','RPS','STG','VEM']),ARRAY_FILTER_USE_KEY);
// Codes Police (avec RTT + CAM + HS + CF) — tous sauf GIE et Douane
$codesPolice=array_filter($typesConges,fn($k)=>in_array($k,
  ['ASA','CA','CAA','CAM','CET','CF','CM','CONV','DA','GEM','HP','HPA','HS','PR','RPS','RTT','STG','VEM']),ARRAY_FILTER_USE_KEY);
// Codes Nuit = Police
$codesNuit=$codesPolice;
// Codes Cne MOKADEM (Police + RCR + RCH)
$codesMokadem=array_filter($typesConges,fn($k)=>in_array($k,
  ['ASA','CA','CAA','CAM','CET','CF','CM','CONV','DA','GEM','HP','HPA','HS','PR','RCH','RCR','RPS','RTT','STG','VEM']),ARRAY_FILTER_USE_KEY);
// Codes LCL PARENT (Officier, cycle J) : Permission uniquement (Matin/AM/Journée)
$codesLclParent=['P'=>['code'=>'P','libelle'=>'Permission','couleur_bg'=>'#2e7d32','couleur_txt'=>'#ffffff']];

// Codes ADC LAMBERT (Sous-officier GIE) : Permission + Repos (Matin/AM/Journée)
$codesAdcLambert=[
  'P'=>['code'=>'P','libelle'=>'Permission','couleur_bg'=>'#2e7d32','couleur_txt'=>'#ffffff'],
  'R'=>['code'=>'R','libelle'=>'Repos',     'couleur_bg'=>'#bfbfbf','couleur_txt'=>'#333333'],
];

// Codes gie_j (ADJ LEFEBVRE / ADJ CORRARD) — inchangés
$typesCongesGieJ=['P'=>['code'=>'P','libelle'=>'Présent','couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
  'J'=>['code'=>'J', 'libelle'=>'Journée',   'couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
  'M'=>['code'=>'M', 'libelle'=>'Matin',     'couleur_bg'=>'#ffd200','couleur_txt'=>'#222'],
  'AM'=>['code'=>'AM','libelle'=>'Après-midi','couleur_bg'=>'#2f5597','couleur_txt'=>'#ffc000'],
  'R'=>['code'=>'R', 'libelle'=>'Repos',     'couleur_bg'=>'#bfbfbf','couleur_txt'=>'#333']];

/* ======================
   AGENTS
====================== */
$direction=['CoGe ROUSSEL','LCL PARENT','IR MOREAU','Cne MOKADEM'];
$nuit=['BC MASSON','BC SIGAUD','BC DAINOTTI'];
$equipe1=['BC BOUXOM','BC ARNAULT','BC HOCHARD'];
$equipe2=['BC DUPUIS','BC BASTIEN','BC ANTHONY'];
$gieEquipe1=['ADJ LEFEBVRE']; $gieEquipe2=['ADJ CORRARD'];
$douane=['ACP1 LOIN']; $secretariat=['AA MAES']; $informatique=['BC DRUEZ'];

/* ======================
   CYCLES
====================== */
function cycleHebdo(string $d,array $f): string {
  if(isset($f[$d]))return 'FERIE'; $j=(int)(new DateTime($d))->format('N');
  return $j===6?'RC':($j===7?'RL':'J');
}
function cycleNuit(string $d,string $ag): string {
  $c=['NUIT','NUIT','NUIT','NUIT','RC','RL']; $o=['BC MASSON'=>0,'BC SIGAUD'=>4,'BC DAINOTTI'=>2];
  $diff=(int)(new DateTime('2027-01-01'))->diff(new DateTime($d))->format('%r%a');
  $i=(($diff+($o[$ag]??0))%6+6)%6; return $c[$i];
}
function cycle14E1(string $d,array $f): string {
  // Référence : lundi 29/12/2025, ancre absolue vérifiée sur données réelles 2026
  // Semaine paire = M pour E1 (BOUXOM/ARNAULT/HOCHARD) et LEFEBVRE
  // Pérenne pour 2026 et toutes les années suivantes
  if(isset($f[$d]))return 'FERIE'; $j=(int)(new DateTime($d))->format('N');
  if($j===6)return 'RC'; if($j===7)return 'RL';
  $jours=(int)(new DateTime('2025-12-29'))->diff(new DateTime($d))->format('%r%a');
  return (intdiv($jours,7)%2===0)?'M':'AM';
}
function cycle14E2(string $d,array $f): string {
  // Référence identique, inverse : E2 (DUPUIS/BASTIEN/ANTHONY) et CORRARD
  if(isset($f[$d]))return 'FERIE'; $j=(int)(new DateTime($d))->format('N');
  if($j===6)return 'RC'; if($j===7)return 'RL';
  $jours=(int)(new DateTime('2025-12-29'))->diff(new DateTime($d))->format('%r%a');
  return (intdiv($jours,7)%2===0)?'AM':'M';
}
function cycleDouane(string $d,array $f): string {
  if(isset($f[$d]))return 'FERIE';
  $c=['AM','M','M','AM','M','RC','RL'];
  $i=((int)(new DateTime('2027-01-04'))->diff(new DateTime($d))->format('%r%a')%7+7)%7;
  return $c[$i];
}
function getCycleAgent(string $ag,string $d,array $f,array $ga): string {
  return match($ga[$ag]??'standard_j'){
    'nuit'   => cycleNuit($d,$ag),
    'equipe' => in_array($ag,['BC BOUXOM','BC ARNAULT','BC HOCHARD'])?cycle14E1($d,$f):cycle14E2($d,$f),
    'gie'    => in_array($ag,['ADJ LEFEBVRE'])?cycle14E1($d,$f):cycle14E2($d,$f),
    'douane' => cycleDouane($d,$f),
    default  => cycleHebdo($d,$f),
  };
}

function cb(string $v): string {
  return match($v){'J'=>'j','M'=>'m','AM'=>'am','NUIT'=>'nuit','RC'=>'rc','RL'=>'rl','FERIE'=>'ferie',default=>''};
}


// Symboles visuels pour cellules planning
$symSoleil  = '☀';
$svgIndispo = '<span style="color:#e53935;font-weight:bold">✖</span>';
/* ======================
   FONCTION LIGNE
====================== */
function periodeSymbole(string $p): string {
  return match($p){'M'=>'🔆','AM'=>'🌙','J'=>'','default'=>''};
}
function ligne(string $agent,callable $cb,bool $masqueRC,bool $ignFe=false,string $grp='standard'): void {
  global $annee,$mois,$moisStr,$nbJours,$conges,$congesIds,$congesMeta,$perms,$permsIds,$feries,$agentsPermanence,$tirs,$tirsIds,$tirsAnnul,$agentsTir,$isAdmin,$globalLocked,$locks,$userAgents,$symSoleil,$svgIndispo,$vacOvr,$vacOvrId;
  $pP      = in_array($agent,$agentsPermanence);
  $pTir    = in_array($agent,(array)$agentsTir);
  $editable= canEdit($agent,$isAdmin,$globalLocked,$locks,$userAgents,$moisStr);
  $lockCls = $editable ? '' : ' locked-agent';
  $lockName= $editable ? '' : ' locked-name';
  $agLocked   = isset($locks['agents'][$agent]);         // verrou annuel global
  $moisLocked = isset($locks['mois'][$agent][$moisStr]); // verrou du mois affiché
  $lockBtn = '';
  if ($isAdmin) {
    if ($agLocked) {
      // Verrou annuel actif → afficher cadenas rouge fixe (non cliquable pour mois)
      $lockBtn = "<button class='inline-lock-btn' data-agent='".htmlspecialchars($agent,ENT_QUOTES)."' data-locked='1' data-mois='' title='Agent verrouillé toute l\\'année (via paramètres)' style='background:#c0392b'>&#9632;</button>";
    } else {
      // Verrou mensuel : cliquable, bascule le mois affiché
      $lockIcon  = $moisLocked ? '&#9632;' : '&#9633;';  // ■ verrouillé, □ libre
      $lockTitle = $moisLocked ? 'Déverrouiller '.$moisStr : 'Verrouiller '.$moisStr;
      $lockBg    = $moisLocked ? '#e67e22' : '#27ae60'; // orange = verrouillé, vert = libre
      $lockBtn   = "<button class='inline-lock-btn' data-agent='".htmlspecialchars($agent,ENT_QUOTES)."' data-locked='".($moisLocked?'1':'0')."' data-mois='$moisStr' title='$lockTitle' style='background:$lockBg'>$lockIcon</button>";
    }
  }
  echo "<tr><td class='agent-name$lockName'>".$lockBtn."<span class='agent-name-text'>".htmlspecialchars($agent)."</span></td>";
  for ($d=1;$d<=$nbJours;$d++) {
    $date    = sprintf('%04d-%02d-%02d',$annee,$mois,$d);
    $isFe    = isset($feries[$date])&&!$ignFe;
    $cgCode  = $conges[$agent][$date]   ?? null;
    $cgId    = $congesIds[$agent][$date]?? 0;
    $pmData  = $perms[$agent][$date]    ?? null;
    $pmId    = $permsIds[$agent][$date] ?? 0;
    $tirPer  = $tirs[$agent][$date]     ?? null;
    $tirId   = $tirsIds[$agent][$date]  ?? 0;
    $annulId = $tirsAnnul[$agent][$date]?? 0;
    $vacVal  = $vacOvr[$agent][$date]   ?? null;  // Override vacation
    $vacId   = $vacOvrId[$agent][$date] ?? 0;

    if ($annulId > 0) {
      // L'annulation prime toujours sur le TIR
      $cycleVal= $cb($date);
      $da=" data-agent='".htmlspecialchars($agent,ENT_QUOTES)."' data-date='$date' data-cycle='$cycleVal' data-groupe='$grp' data-vac-id='0'";
      echo "<td class='tir-annule$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-tir-id='$tirId' data-annul-id='$annulId' title='TIR annulé'>🎯❌</td>";
      continue;
    }
    $cycleVal= $cb($date);
    // Si override vacation, remplacer le cycle calculé (sauf RC/RL/FERIE)
    if($vacVal && !in_array($cycleVal,['RC','RL','FERIE'])) $cycleVal=$vacVal;
    $da=" data-agent='".htmlspecialchars($agent,ENT_QUOTES)."' data-date='$date' data-cycle='$cycleVal' data-groupe='$grp' data-vac-id='$vacId'";

    if ($tirPer !== null) {
      // TIR enregistré
      $symTir = match($tirPer){'M'=>$symSoleil,'AM'=>'🌙','NUIT'=>'🌙',default=>''};
      $tipTir = 'TIR '.match($tirPer){'M'=>'Matin','AM'=>'Après-midi','NUIT'=>'Nuit',default=>$tirPer};
      $agentsCombine = ['CoGe ROUSSEL','Cne MOKADEM','GP DHALLEWYN','BC DELCROIX','BC DRUEZ'];
      if ($cgCode && in_array($agent,$agentsCombine)) {
        // Agent combinable : TIR + congé affichés ensemble
        $meta  = $congesMeta[$agent][$date] ?? ['periode'=>'J','libelle'=>$cgCode,'heure'=>'','couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'];
        $per   = $meta['periode']??'J';
        $hre   = $meta['heure']??'';
        $symCg = match($per){'M'=>'🔆','AM'=>'🌙',default=>''};
        $heureDisplay = ($hre && in_array($cgCode,['DA','PR'])) ? ' '.substr($hre,0,5) : '';
        $tip   = $tipTir.' + '.$cgCode.$heureDisplay.' '.match($per){'M'=>'Matin','AM'=>'Après-midi',default=>''};
        $txt   = 'TIR'.$symTir.'+'.$cgCode.$symCg;
        if ($cgCode === 'PREV') {
            $clsTirCg2 = 'tir tir-ok prev-conge';
            $sTirCg2   = 'font-size:7px';
        } else {
            $clsTirCg2 = 'tir tir-ok conge';
            $bgTirCg   = $meta['couleur_bg'] ?? '#1565c0';
            $txtTirCg  = $meta['couleur_txt'] ?? '#ffffff';
            $sTirCg2   = "font-size:7px;background:$bgTirCg;color:$txtTirCg";
        }
        echo "<td class='$clsTirCg2$lockCls'$da data-ferie='0' data-conge-id='$cgId' data-conge-type='$cgCode' data-perm-id='0' data-tir-id='$tirId' data-tir-per='$tirPer' data-conge-per='$per' data-conge-heure='".htmlspecialchars($hre)."' data-annul-id='0' title='".htmlspecialchars($tip)."' style='$sTirCg2'>$txt</td>";
      } else {
        echo "<td class='tir tir-ok$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-tir-id='$tirId' data-tir-per='$tirPer' data-annul-id='0' title='".htmlspecialchars($tipTir)."'>TIR$symTir</td>";
      }

    } elseif ($pmData) {
      $sym = match($pmData['type']){'M'=>'☀','IM'=>'☀','AM'=>'🌙','IAM'=>'🌙',default=>''};
      $tip = 'Permanence '.match($pmData['type']){'M'=>'Matin','AM'=>'Après-midi','IM'=>'Indisp M','IAM'=>'Indisp AM','IJ'=>'Indisp Journée',default=>$pmData['type']}.' (cycle: '.$pmData['cycle_orig'].')';
      $pmType     = trim($pmData['type']);
      $pmCycOrig  = trim($pmData['cycle_orig']);
      $isIndispo  = in_array($pmType,['IM','IAM','IJ']);
      $clsPerm = match($pmType){'M'=>'m','AM'=>'am','IM'=>'perm-indispo-m','IAM'=>'perm-indispo-am','IJ'=>'perm-indispo-j',default=>'m'};
      $cls = $clsPerm;
      $dispPerm = $isIndispo ? '<b style=\'color:#e53935\'>✖</b>' : ($pmType==='M' ? 'M' : ($pmType==='AM' ? 'AM' : $pmType));
      $hexType = bin2hex($pmType); // garde pour diagnostic interne uniquement
      // echo "<!-- DEBUG agent=$agent date=$date type=[$pmType] hex=$hexType -->"; // désactivé
      echo "<td class='$cls perm-ok$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='$pmId' data-perm-type='$pmType' data-cycle-orig='$pmCycOrig' data-tir-id='0' title='".htmlspecialchars($tip)."'>$dispPerm$sym</td>";

    } elseif ($cgCode) {
      $meta  = $congesMeta[$agent][$date] ?? ['periode'=>'J','libelle'=>$cgCode,'heure'=>'','couleur_bg'=>'#1565c0','couleur_txt'=>'#ffffff'];
      $per   = $meta['periode']??'J';
      $lib   = $meta['libelle']?:$cgCode;
      $hre   = $meta['heure']??'';
      $sym   = match($per){'M'=>'☀','AM'=>'🌙',default=>''};
      // Affichage dans la cellule : code + heure si DA/PR + symbole période
      $heureDisplay = ($hre && in_array($cgCode,['DA','PR'])) ? ' '.substr($hre,0,5) : '';
      $cellTxt = $cgCode.$heureDisplay.$sym;
      // Tooltip
      $tip   = $lib;
      if($hre && in_array($cgCode,['DA','PR'])) $tip .= ' à '.substr($hre,0,5);
      if($per!=='J') $tip .= ' ('.match($per){'M'=>'Matin','AM'=>'Après-midi',default=>$per}.')';
      if ($cgCode === 'PREV') {
          $clsCg2   = 'prev-conge';
          $styleCg2 = '';
      } else {
          $clsCg2   = 'conge';
          $bgCg2    = $meta['couleur_bg'] ?? '#1565c0';
          $txtCg2   = $meta['couleur_txt'] ?? '#ffffff';
          $styleCg2 = " style='background:$bgCg2;color:$txtCg2'";
      }
      echo "<td class='$clsCg2$lockCls'$da$styleCg2 data-ferie='0' data-conge-id='$cgId' data-conge-type='$cgCode' data-perm-id='0' data-tir-id='0' data-conge-per='$per' data-conge-heure='".htmlspecialchars($hre)."' title='".htmlspecialchars($tip)."'>$cellTxt</td>";

    } elseif ($isFe && !$pmData) {
      echo "<td class='ferie$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='FERIE' data-tir-id='0' title='Jour férié'>FERIE</td>";

    } elseif ($isFe && $pmData) {
      // Jour férié avec permanence/indisponibilité : afficher le vrai perm-id
      $isIndispo = in_array($pmData['type'],['IM','IAM','IJ']);
      $clsFe = $isIndispo ? ($pmData['type']==='IM' ? 'perm-indispo-m' : ($pmData['type']==='IJ' ? 'perm-indispo-j' : 'perm-indispo-am')) : 'ferie';
      $pmTypeFe   = $pmData['type'];
      $pmCycOrigFe= $pmData['cycle_orig'];
      $dispFe = $isIndispo ? match($pmTypeFe){'IM'=>$svgIndispo,'IAM'=>$svgIndispo,'M'=>'M','AM'=>'AM',default=>$pmTypeFe} : 'FERIE';
      $symFe  = match($pmTypeFe){'M'=>'☀','IM'=>'☀','AM'=>'🌙','IAM'=>'🌙',default=>''};
      $tipFe  = 'Permanence '.match($pmTypeFe){'M'=>'Matin','AM'=>'Après-midi','IM'=>'Indisp M','IAM'=>'Indisp AM',default=>$pmTypeFe}.' — Jour férié';
      echo "<td class='$clsFe perm-ok$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='$pmId' data-perm-type='$pmTypeFe' data-cycle-orig='$pmCycOrigFe' data-tir-id='0' title='".htmlspecialchars($tipFe)."'>$dispFe$symFe</td>";

    } else {
      $val=$cycleVal;
      $isTirCase = $pTir && ($val==='J'||$val==='NUIT'||$val==='M'||$val==='AM');
      if ($masqueRC&&($val==='RC'||$val==='RL')) {
        $cls = $pP ? 'rc-masque perm-ok' : 'rc-masque';
        $tipMasq=$val==='RC'?'Repos compensatoire':'Repos légal (dimanche)';
        echo "<td class='$cls$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='$val' data-masque='1' data-tir-id='0' title='".htmlspecialchars($tipMasq)."'></td>";
      } else {
        $cls = cb($val);
        if ($pP && ($val==='RC'||$val==='RL')) $cls .= ' perm-ok';
        // Note : pas de perm-ok permanent sur J pour LCL PARENT/ADC LAMBERT
        // (le clic est géré via le listener global ; la bordure orange n'apparaît que si une indispo est posée)
        if ($isTirCase) $cls .= ' tir-ok';
        $tipVal=match($val){'J'=>'Journée','M'=>'Matin','AM'=>'Après-midi','NUIT'=>'Nuit','RC'=>'Repos compensatoire','RL'=>'Repos légal (dimanche)','FERIE'=>'Jour férié',default=>$val};
        // Si vacation overridée, ajouter indicateur visuel (*)
        $dispVal = $val.($vacVal&&!in_array($cycleVal,['RC','RL','FERIE'])?'*':'');
        $tipValFull = $tipVal.($vacVal&&!in_array($cycleVal,['RC','RL','FERIE'])?' ✏ (modifié)':'');
        echo "<td class='".trim($cls.$lockCls)."'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='$val' data-tir-id='0' title='".htmlspecialchars($tipValFull)."'>$dispVal</td>";
      }
    }
  }
  echo "</tr>";
}

$moisPrev=$mois-1?:12; $anneePrev=$mois===1?$annee-1:$annee;
$moisNext=$mois%12+1;  $anneeNext=$mois===12?$annee+1:$annee;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Planning <?=$moisFR[$mois].' '.$annee?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f5;color:#222;
     display:flex;flex-direction:column;height:100vh}

/* ── HEADER COMPACT ── */
.header{
  background:#1a2742;color:#fff;
  padding:5px 14px;
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
  box-shadow:0 2px 6px rgba(0,0,0,.4);
  flex-shrink:0;
}
.header h1{font-size:.92rem;font-weight:700;white-space:nowrap}
.mois-nav{display:flex;gap:3px;flex:1;justify-content:center;flex-wrap:wrap}
.mois-nav a{
  background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#c5cfe8;
  padding:3px 7px;border-radius:4px;cursor:pointer;font-size:.7rem;font-weight:600;
  text-decoration:none;white-space:nowrap;transition:background .15s;
}
.mois-nav a:hover{background:rgba(255,255,255,.25);color:#fff}
.mois-nav a.active{background:#ffd600;color:#1a2742;border-color:#ffd600}
.nav-btn{
  background:#e8edf7;border:1px solid #c5cfe8;color:#1a2742;
  padding:4px 11px;border-radius:5px;cursor:pointer;
  font-size:.75rem;font-weight:600;text-decoration:none;white-space:nowrap;
  transition:background .15s;
}
.nav-btn:hover{background:#fff}
.nav-btn.active{background:#fff;font-weight:700}

/* ── BARRE ACTIONS + LEGENDE FUSIONNÉES ── */
.toolbar{
  display:flex;align-items:center;gap:8px;
  padding:4px 14px;background:#fff;
  border-bottom:1px solid #dde;flex-shrink:0;flex-wrap:wrap;
}
.toolbar .spacer{flex:1}
.btn-action{
  padding:4px 12px;border:none;border-radius:6px;cursor:pointer;
  font-size:.75rem;font-weight:600;transition:all .15s;white-space:nowrap;
}
.btn-print{background:#1a2742;color:#fff}
.btn-print:hover{background:#253560}
.btn-perm{background:#fff3cd;color:#7a5800;border:1px solid #f0c040}
.btn-perm:hover{background:#ffe08a}

/* Légende inline dans la toolbar */
.leg{display:inline-flex;align-items:center;gap:2px;font-size:.7rem;font-weight:700;
  padding:2px 6px;border-radius:3px;white-space:nowrap}
.leg-j   {background:#92d050;color:#222}
.leg-m   {background:#ffd200;color:#222}
.leg-am  {background:#2f5597;color:#ffc000}
.leg-nuit{background:#c00000;color:#fff}
.leg-rc  {background:#bfbfbf;color:#333}
.leg-ferie{background:#ffeb3b;color:#c00000}
.leg-conge{background:#1565c0;color:#fff}
.leg-perm{border:2px dashed #e67e22;color:#7a5800;background:transparent;padding:1px 5px}
.leg-indispo-m {background:#90caf9;color:#1a237e}
.leg-indispo-am{background:#64b5f6;color:#1a237e}
.leg-indispo-j {background:#42a5f5;color:#1a237e}

/* ── ZONE TABLEAU (scrollable horizontalement, occupe tout l'espace restant) ── */
.wrap{
  flex:1;overflow:auto;padding:6px 10px;
  min-height:0; /* important pour flex */
  display:flex;gap:10px;align-items:flex-start;
}
.wrap-table{overflow:auto;flex:1;min-width:0;}

/* ── PANNEAU NOTES ── */
.notes-panel{
  width:280px;min-width:240px;flex-shrink:0;
  background:#fff;border-radius:10px;
  box-shadow:0 2px 10px rgba(0,0,0,.12);
  border:1px solid #dde3f0;
  font-size:.75rem;overflow:hidden;
  position:sticky;top:0;
  display:flex;flex-direction:column;
  max-height:calc(100vh - 110px);
}
.notes-head{
  background:#1a2742;color:#ffd600;
  padding:8px 12px;font-weight:700;font-size:.78rem;
  display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;
}
.notes-head span{font-size:.68rem;color:#c5cfe8;font-weight:400}
.notes-body{overflow-y:auto;flex:1;padding:10px}
.notes-section{margin-bottom:12px}
.notes-section-title{
  font-size:.68rem;font-weight:700;color:#1a2742;
  text-transform:uppercase;letter-spacing:.05em;
  margin-bottom:6px;padding-bottom:3px;
  border-bottom:1.5px solid #e3f0ff;
}
.notes-evt-item{
  display:flex;align-items:flex-start;gap:5px;
  padding:4px 0;border-bottom:1px solid #f0f2f5;
}
.notes-evt-date{
  background:#1565c0;color:#fff;border-radius:4px;
  padding:1px 5px;font-size:.66rem;font-weight:700;
  white-space:nowrap;flex-shrink:0;
}
.notes-evt-label{flex:1;color:#222;line-height:1.3}
.notes-evt-del{
  color:#e74c3c;cursor:pointer;font-size:.75rem;
  flex-shrink:0;opacity:.6;
}
.notes-evt-del:hover{opacity:1}
.notes-add-evt{
  display:flex;flex-direction:column;gap:4px;margin-top:8px;
}
.notes-add-evt input{
  padding:4px 7px;border:1.5px solid #dde;border-radius:5px;
  font-size:.73rem;width:100%;
}
.notes-add-evt button{
  padding:4px 8px;background:#1565c0;color:#fff;
  border:none;border-radius:5px;cursor:pointer;
  font-size:.72rem;font-weight:700;
}
.notes-add-evt button:hover{background:#1a3a6b}
.notes-texte textarea{
  width:100%;padding:5px 7px;border:1.5px solid #dde;
  border-radius:5px;font-size:.73rem;resize:vertical;
  min-height:70px;font-family:inherit;line-height:1.4;
}
.notes-texte-saved{
  color:#27ae60;font-size:.68rem;
  margin-top:3px;display:none;
}
.notes-texte button{
  margin-top:5px;padding:4px 10px;background:#27ae60;color:#fff;
  border:none;border-radius:5px;cursor:pointer;
  font-size:.72rem;font-weight:700;width:100%;
}
.notes-empty{color:#aaa;font-style:italic;font-size:.72rem;padding:4px 0}
@media print{.notes-panel{display:none}}

table{border-collapse:collapse;font-size:9.5px;background:#fff;
  box-shadow:0 1px 8px rgba(0,0,0,.1)}
td,th{border:1px solid #ccc;padding:2px 2px;text-align:center;white-space:nowrap}
th{background:#1a2742;color:#fff;font-weight:600;font-size:9px;position:sticky;top:0;z-index:2}
th.wk{background:#253560}
th.ferie-th{background:#9a7200;color:#fff}
/* Hauteur fixe pour toutes les lignes du tableau planning */
table tbody tr{height:22px;max-height:22px}

td.agent-name{
  text-align:left;padding:0 4px;font-weight:600;background:#f7f8fa;
  min-width:105px;position:sticky;left:0;z-index:1;
  border-right:2px solid #bbb;cursor:default;font-size:9.5px;
  white-space:nowrap;overflow:hidden;height:22px;line-height:22px;
  box-sizing:border-box;
}
/* Bouton verrou inline — float:right, taille fixe alignée */
.inline-lock-btn{
  float:right;margin-top:3px;margin-left:2px;
  border:none;border-radius:3px;cursor:pointer;
  font-size:9px;padding:0 3px;line-height:16px;height:16px;
  color:#fff;opacity:.7;transition:opacity .15s;
  display:inline-block;overflow:hidden;
}
.inline-lock-btn:hover{opacity:1}
td.agent-name.locked-name .inline-lock-btn{opacity:.9}
/* Texte du nom */
.agent-name-text{vertical-align:middle;line-height:22px;}
td.equipe{
  background:#1a2742;color:#fff;font-weight:700;
  text-align:left;padding:2px 7px;cursor:default;font-size:10px;
}

td[data-date][data-ferie="0"]{cursor:pointer;transition:filter .1s,transform .1s}
td[data-date][data-ferie="0"]:hover{filter:brightness(.78);transform:scale(1.1);
  z-index:5;position:relative;box-shadow:0 2px 8px rgba(0,0,0,.25)}

.j    {background:#92d050;color:#222;font-weight:bold}
.m    {background:#ffd200;color:#222;font-weight:bold}

td.perm-indispo-m {background:#90caf9;color:#1a237e;font-weight:bold}
td.perm-indispo-am{background:#64b5f6;color:#1a237e;font-weight:bold}
td.perm-indispo-j {background:#42a5f5;color:#1a237e;font-weight:bold}
.am   {background:#2f5597;color:#ffc000;font-weight:bold}
.nuit {background:#c00000;color:#fff;font-weight:bold}
.rc,.rl{background:#bfbfbf;color:#333;font-weight:bold}
.ferie{background:#ffeb3b;color:#c00000;font-weight:bold}
.rc-masque{background:#fff}
.conge{background:#7030a0;color:#fff;font-weight:bold}
.prev-conge{background:#ffcc80;color:#7a3e00;font-weight:bold}
.tir{background:#1565c0;color:#ffd600;font-weight:bold;font-size:9px}
td.tir-ok{cursor:pointer!important}
<?php if(!$isAdmin&&!($userRights['can_tir']??false)): ?>td.tir{cursor:default!important}<?php endif; ?>
td.tir-annule{background:#fdecea!important;color:#c0392b!important;text-align:center;font-weight:700;font-size:.85rem;cursor:pointer!important;opacity:.9}
.leg-tir{background:#1565c0;color:#ffd600}
td.perm-ok{outline:2px dashed #e67e22;outline-offset:-2px;cursor:pointer!important}
td.perm-ok:hover{filter:brightness(.82)!important;transform:scale(1.1)!important}
td.perm-indispo-m{background:#90caf9!important;color:#1a237e!important}
td.perm-indispo-am{background:#64b5f6!important;color:#1a237e!important}

td.locked-agent{opacity:.7;cursor:no-drop!important}
td.locked-agent:hover{filter:none!important;transform:none!important}
/* Admin : peut toujours éditer même si verrou mensuel affiché */
body.is-admin td.locked-agent{cursor:pointer!important;opacity:.85}
/* Panneau admin */
.overlay-admin{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center;padding:20px;overflow:hidden}
.overlay-admin.open{display:flex}
.modal-admin{background:#fff;border-radius:12px;width:100%;max-width:1200px;max-height:95vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.25);overflow:hidden}
.adm-head{background:#1a2742;color:#fff;padding:12px 18px;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.adm-head h3{font-size:.95rem;font-weight:700}
.adm-tabs{display:flex;border-bottom:2px solid #eee;padding:0 16px;flex-shrink:0;background:#fff}
.adm-tab{padding:10px 18px;cursor:pointer;font-size:.8rem;font-weight:600;color:#888;border-bottom:3px solid transparent;margin-bottom:-2px}
.adm-tab.active{color:#1a2742;border-bottom-color:#1a2742}
.adm-body{padding:16px;flex:1;overflow-y:auto;min-height:0}
.adm-section{display:none}.adm-section.active{display:block}
.user-row{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:7px;margin-bottom:4px;background:#f7f8fa;font-size:.8rem}
.user-row:hover{background:#edf0f7}
.user-badge{padding:2px 7px;border-radius:4px;font-size:.68rem;font-weight:700}
.badge-admin{background:#1a2742;color:#fff}.badge-user{background:#92d050;color:#222}
.lock-row{display:flex;align-items:center;gap:8px;padding:5px 10px;border-radius:6px;font-size:.8rem;margin-bottom:3px}
.lock-row.locked{background:#fdecea}.lock-row.unlocked{background:#f0fdf4}
/* Verrou mensuel : fond orange pâle au lieu de rouge */
.lock-row[data-type="mois"].locked{background:#fff3e0}
/* Cellules grille verrous */
.lock-cell-mois:hover,.lock-cell-annuel:hover{filter:brightness(.88)}
.lock-cell-mois.lc-locked{background:#ffe0b2!important}
.lock-cell-mois.lc-free{background:#f9f9f9!important}
.lock-cell-annuel.la-locked{background:#fdecea!important}
.lock-cell-annuel.la-free{background:#f9f9f9!important}
.lba-locked:hover{opacity:.8}.lba-free:hover{background:#c8e6c9!important}
.adm-form input,.adm-form select{padding:6px 10px;border:1.5px solid #dde;border-radius:6px;font-size:.8rem;width:100%;margin-bottom:8px}
.adm-form label{font-size:.75rem;font-weight:600;color:#555;display:block;margin-bottom:2px}
.agents-check{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px}
.agents-check label{display:flex;align-items:center;gap:3px;font-size:.72rem;background:#eee;padding:2px 7px;border-radius:4px;cursor:pointer}
.agents-check input[type=checkbox]{width:auto;margin:0}
.vue-annuelle{flex:1;overflow:auto;padding:10px;min-height:0}
.annual-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
.mini-mois{background:#fff;border-radius:8px;box-shadow:0 1px 8px rgba(0,0,0,.1);overflow:hidden}
.mini-mois-header{background:#1a2742;color:#fff;padding:5px 12px;font-weight:700;font-size:.8rem;
  display:flex;justify-content:space-between;align-items:center}
.mini-mois-header a{color:#aac4ff;font-size:.7rem;text-decoration:none}
.mini-mois-header a:hover{color:#fff}
.mini-cal{width:100%;border-collapse:collapse;font-size:8.5px}
.mini-cal th{background:#253560;color:#fff;padding:1px;text-align:center;font-size:7.5px}
.mini-cal td{padding:1px;text-align:center;border:1px solid #eee}
.mini-cal .j{background:#92d050;color:#222}.mini-cal .m{background:#ffd200;color:#222}
.mini-cal .am{background:#2f5597;color:#ffc000}.mini-cal .nuit{background:#c00000;color:#fff}
.mini-cal .rc,.mini-cal .rl{background:#bfbfbf;color:#333}
.mini-cal .ferie{background:#ffeb3b;color:#c00000}.mini-cal .conge{background:#7030a0;color:#fff}.mini-cal .prev-conge{background:#ffcc80;color:#7a3e00}
.mini-cal .perm{background:#ffd200;color:#222}
.mini-agent-label{font-weight:700;text-align:left!important;padding-left:4px!important;
  background:#f7f8fa!important;font-size:7.5px;max-width:75px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}

/* ── MODAL COMPTEUR PERMANENCES ── */
.overlay-perm{display:none;position:fixed;inset:0;background:rgba(10,20,40,.6);
  z-index:2000;align-items:flex-start;justify-content:center;padding-top:40px;backdrop-filter:blur(3px)}
.overlay-perm.open{display:flex}
.modal-perm{background:#fff;border-radius:12px;width:min(98vw,1050px);max-height:92vh;
  overflow-y:auto;box-shadow:0 12px 50px rgba(0,0,0,.35);animation:pop .18s ease}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.mp-head{background:#7a5800;color:#fff;padding:11px 16px;display:flex;
  justify-content:space-between;align-items:center;position:sticky;top:0;z-index:1}
.mp-head h3{font-size:.88rem;font-weight:700}
.mp-body{padding:14px}
.mp-table{width:100%;border-collapse:collapse;font-size:11.5px}
.mp-table th{background:#253560;color:#fff;padding:5px 7px;text-align:center}
.mp-table td{padding:4px 7px;border-bottom:1px solid #eee;text-align:center}
.mp-table tr:hover td{background:#fffbf0}
.mp-table td:first-child{text-align:left;font-weight:600}
.mp-section td{background:#1a2742!important;color:#fff!important;font-weight:700}
.mp-total{font-weight:700;background:#fff3cd!important}
.mp-legende{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;font-size:.74rem}
.mp-legende span{padding:2px 9px;border-radius:4px;font-weight:600}

/* ── MODAL CONGÉ / PERMANENCE ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(10,20,40,.6);
  z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:12px;width:500px;max-width:96vw;
  max-height:92vh;overflow-y:auto;box-shadow:0 12px 50px rgba(0,0,0,.35);animation:pop .18s ease}
.modal-head{background:#1a2742;color:#fff;padding:11px 16px;
  display:flex;justify-content:space-between;align-items:flex-start}
.modal-head.perm-head{background:#7a5800}
.modal-head h3{font-size:.88rem;font-weight:700;margin-bottom:2px}
.modal-head small{font-size:.73rem;opacity:.75}
.btn-x{background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;line-height:1;padding:0;opacity:.6}
.btn-x:hover{opacity:1}
.modal-body{padding:13px 16px 16px}
.modal-cycle{background:#f0f4ff;border-radius:7px;padding:7px 11px;
  font-size:.78rem;margin-bottom:12px;color:#333;border-left:4px solid #1a2742}
.modal-cycle.perm-cycle{background:#fff8e1;border-left-color:#e67e22}

.date-range-row{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:9px}
.date-field label{display:block;font-size:.72rem;color:#666;margin-bottom:3px;font-weight:600}
.date-field input{width:100%;padding:6px 9px;border:2px solid #ddd;border-radius:6px;
  font-size:.78rem;transition:border-color .15s}
.date-field input:focus{outline:none;border-color:#1a2742}
.vac-override-dot{font-size:.6rem;vertical-align:super;color:#f9a825;font-weight:900}
td[data-vac-id]:not([data-vac-id="0"]){outline:2px solid #f9a825;outline-offset:-2px}
.range-info{font-size:.72rem;color:#1a6632;background:#e8f5e9;border-radius:5px;
  padding:5px 9px;margin-bottom:10px;display:none}

.code-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px}
.code-btn{border:2px solid transparent;border-radius:6px;padding:7px 4px;cursor:pointer;
  font-weight:700;font-size:.76rem;line-height:1.3;transition:transform .1s,box-shadow .12s;text-align:center}
.code-btn:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.2)}
.code-btn.sel{border-color:#111!important;box-shadow:0 0 0 3px rgba(0,0,0,.18)!important;transform:translateY(-2px)}
.code-btn small{display:block;font-size:.62rem;font-weight:400;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.perm-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-bottom:12px}
.perm-btn{border:2px solid transparent;border-radius:7px;padding:11px 5px;cursor:pointer;
  font-weight:700;font-size:.86rem;text-align:center;transition:transform .1s,box-shadow .12s}
.perm-btn:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.2)}
.perm-btn.sel{border-color:#111!important;box-shadow:0 0 0 3px rgba(0,0,0,.18)!important}
.perm-btn small{display:block;font-size:.63rem;font-weight:500;margin-top:3px}
.perm-btn.restore{background:#f5f5f5;color:#555;border:2px solid #ccc}

.options-row{display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap}
.opt-btn{flex:1;min-width:70px;padding:6px 5px;border:2px solid #ddd;border-radius:6px;
  background:#f8f8f8;cursor:pointer;font-size:.74rem;font-weight:600;text-align:center;
  transition:all .12s;color:#444}
.opt-btn.sel{border-color:#1a2742;background:#1a2742;color:#fff}

.modal-foot{display:flex;gap:7px;justify-content:flex-end;padding-top:10px;border-top:1px solid #eee}
.btn{padding:7px 16px;border:none;border-radius:7px;cursor:pointer;
  font-size:.8rem;font-weight:600;transition:opacity .15s,transform .1s}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.btn:disabled{opacity:.35;cursor:not-allowed;transform:none}
.btn-cancel{background:#ebebeb;color:#555}
.btn-del{background:#e74c3c;color:#fff}
.btn-save{background:#1a6632;color:#fff}

.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(14px);
  color:#fff;padding:9px 22px;border-radius:7px;font-size:.8rem;font-weight:500;
  opacity:0;pointer-events:none;z-index:3000;
  transition:opacity .25s,transform .25s;box-shadow:0 3px 14px rgba(0,0,0,.25)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}


/* ── Surbrillance cellule du jour ── */
@keyframes today-pulse {
  0%,100%{box-shadow:inset 0 0 0 3px #ff6f00,0 0 8px 2px rgba(255,111,0,.5)}
  50%    {box-shadow:inset 0 0 0 3px #ffca28,0 0 14px 5px rgba(255,202,40,.7)}
}
td.today-cell{
  animation:today-pulse 1.8s ease-in-out infinite;
  z-index:4;position:relative;
}

/* ── Tooltip custom cellules ── */
.cell-tip{
  position:fixed;z-index:9999;
  background:#1a2742;color:#fff;
  font-size:.75rem;font-weight:500;
  padding:5px 10px;border-radius:6px;
  box-shadow:0 3px 12px rgba(0,0,0,.35);
  pointer-events:none;white-space:nowrap;
  opacity:0;transition:opacity .15s;
  max-width:260px;white-space:normal;line-height:1.4;
}
.cell-tip.show{opacity:1}
/* ── IMPRESSION ── */
@media print {
  @page{size:A3 landscape;margin:7mm}
  html,body{height:auto;overflow:visible}
  body{display:block}
  .header{background:#1a2742!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;padding:6px 12px}
  .header .nav-btn{display:none}
  .toolbar,.overlay,.overlay-perm,.toast{display:none!important}
  .inline-lock-btn{display:none!important}
  .wrap{overflow:visible;padding:0;flex:none}
  table{box-shadow:none;font-size:7.5px;width:100%}
  td,th{padding:1px 2px}
  td.agent-name{min-width:80px;position:static}
  th{position:static}
  .j{background:#92d050!important;color:#222!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .m{background:#ffd200!important;color:#222!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .am{background:#2f5597!important;color:#ffc000!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .nuit{background:#c00000!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rc,.rl{background:#bfbfbf!important;color:#333!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .ferie{background:#ffeb3b!important;color:#c00000!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .conge{-webkit-print-color-adjust:exact;print-color-adjust:exact;color:#fff!important}
  .prev-conge{background:#ffcc80!important;color:#7a3e00!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .equipe{background:#1a2742!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  th{background:#1a2742!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  th.wk{background:#253560!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  th.ferie-th{background:#9a7200!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body<?php if($isAdmin) echo ' class="is-admin"'; ?>>

<!-- HEADER COMPACT -->
<div class="header">
  <h1>Planning <?=$annee?> <?=$globalLocked?'&#128274;':''?></h1>
  <?php if($vue==='mois'): ?>
  <nav class="mois-nav">
    <?php for($m2=1;$m2<=12;$m2++): ?>
    <a href="?annee=<?=$annee?>&mois=<?=$m2?>&vue=mois" class="<?=$m2===$mois?'active':''?>"><?=$moisFR[$m2]?></a>
    <?php endfor; ?>
  </nav>
  <?php else: ?>
  <div style="flex:1"></div>
  <?php endif; ?>
  <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
    <span style="font-size:.7rem;color:#c5cfe8;white-space:nowrap">&#128100; <?=htmlspecialchars($_SESSION['user_nom']?:$_SESSION['user_login'])?><?=$isAdmin?' (Admin)':''?></span>
    <?php if($isAdmin):?>
  <?php
  // Charger tous les users et leurs droits pour l'interface admin
  $allUsersRights = [];
  $resUR = $mysqli->query("SELECT u.id,u.login,u.nom,COALESCE(r.can_conge,1) as can_conge,COALESCE(r.can_perm,0) as can_perm FROM users u LEFT JOIN user_rights r ON r.user_id=u.id WHERE u.role!='admin' ORDER BY u.nom,u.login");
  while ($rUR=$resUR->fetch_assoc()) $allUsersRights[]=$rUR;
  ?>
    <button class="nav-btn" id="btn-admin-panel" style="background:#f0c040;color:#1a2742;border-color:#e0aa00">&#9881; Admin</button>
    <?php endif;?>
    <form method="POST" style="display:inline;margin:0">
      <input type="hidden" name="action_auth" value="logout">
      <button type="submit" class="nav-btn" style="background:#c0392b;color:#fff;border-color:#a93226">&#9210; D&eacute;co.</button>
    </form>
  </div>
</div>

<?php if(!empty($_SESSION['must_change'])):?>
<div style="background:#e65100;color:#fff;text-align:center;padding:8px 16px;font-size:.8rem;font-weight:600">
  ⚠️ Votre mot de passe est temporaire. <a href="#" id="lnk-change-now" style="color:#ffd600;text-decoration:underline">Changez-le maintenant</a>
</div>
<?php endif;?>

<!-- TOOLBAR : navigation + légende + boutons -->
<div class="toolbar">
  <a class="nav-btn <?=$vue==='mois'?'active':''?>" href="?annee=<?=$annee?>&mois=<?=$mois?>&vue=mois">Mensuel</a>
  <a class="nav-btn <?=$vue==='annee'?'active':''?>" href="?annee=<?=$annee?>&mois=<?=$mois?>&vue=annee">Annuel</a>
  <a class="nav-btn" href="?annee=<?=$annee-1?>&mois=<?=$mois?>&vue=<?=$vue?>">&laquo; <?=$annee-1?></a>
  <strong style="font-size:.78rem"><?=$annee?></strong>
  <a class="nav-btn" href="?annee=<?=$annee+1?>&mois=<?=$mois?>&vue=<?=$vue?>"><?=$annee+1?> &raquo;</a>
  <span style="color:#bbb">|</span>
  <!-- LÉGENDE INTÉGRÉE -->
  <span class="leg leg-j">J</span>
  <span class="leg leg-m">M</span>
  <span class="leg leg-am">AM</span>
  <span class="leg leg-nuit">NUIT</span>
  <span class="leg leg-rc">RC/RL</span>
  <span class="leg leg-ferie">Férié</span>
  <span class="leg leg-tir">TIR</span>
  <span class="leg leg-perm">Perm.</span>
  <span class="leg leg-indispo-m"><b style='color:#e53935'>✖</b>☀ Indisp M</span>
  <span class="leg leg-indispo-am"><b style='color:#e53935'>✖</b>🌙 Indisp AM</span>
  <span class="leg leg-indispo-j"><b style='color:#e53935'>✖</b> Indisp J</span>
  <div class="spacer"></div>
  <?php if(!$isDouane && !$isNuit): ?>
  <button class="btn-action btn-perm" id="btn-open-perm">Permanences</button>
  <button class="btn-action" id="btn-open-recap-fetes" style="background:#7b1fa2;color:#fff;border-color:#7b1fa2">🎄 Récap Fêtes</button>
  <?php endif; ?>
  <?php if(!$isGie && !$isDouane): ?>
  <button class="btn-action" id="btn-open-tir" style="background:#1565c0;color:#ffd600;border-color:#1565c0">&#127919; TIR</button>
  <?php endif; ?>
  <?php if($isAdmin): ?>
  <button class="btn-action" id="btn-lock-mois-global" style="background:#e67e22;color:#fff;border-color:#e67e22" title="Verrouiller/déverrouiller tous les agents pour <?=$moisStr?>">
    <?php echo (count(array_filter($locks['mois'] ?? [], fn($m)=>isset($m[$moisStr]), ARRAY_FILTER_USE_BOTH)) >= 1) ? '&#9632;' : '&#9633;'; ?>
    <?=$moisStr?>
  </button>
  <?php endif; ?>
  <button class="btn-action btn-print" onclick="window.print()">Imprimer</button>
</div>

<?php if($vue==='mois'): ?>
<div class="wrap">
<div class="wrap-table">
<table>
<thead>
<tr>
  <th>Agent</th>
  <?php for($d=1;$d<=$nbJours;$d++):
    $date=sprintf('%04d-%02d-%02d',$annee,$mois,$d);
    $jn=(int)(new DateTime($date))->format('N');
    $cls=isset($feries[$date])?'ferie-th':($jn>=6?'wk':'');
  ?><th class="<?=$cls?>"><?=$joursFR[$jn]?><br><?=$d?></th>
  <?php endfor; ?>
</tr>
</thead>
<tbody>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">DIRECTION</td></tr>
<?php
ligne('CoGe ROUSSEL',fn($d)=>cycleHebdo($d,$feries),false,false,'direction_police');
ligne('LCL PARENT',  fn($d)=>cycleHebdo($d,$feries),false,false,'lcl_parent');
ligne('IR MOREAU',   fn($d)=>cycleHebdo($d,$feries),false,false,'douane_j');
ligne('Cne MOKADEM', fn($d)=>cycleHebdo($d,$feries),false,false,'direction_police');
?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">NUIT</td></tr>
<?php foreach($nuit as $a) ligne($a,fn($d)=>cycleNuit($d,$a),false,true,'nuit'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">EQUIPE 1</td></tr>
<?php foreach($equipe1 as $a) ligne($a,fn($d)=>cycle14E1($d,$feries),true,false,'equipe'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">EQUIPE 2</td></tr>
<?php foreach($equipe2 as $a) ligne($a,fn($d)=>cycle14E2($d,$feries),true,false,'equipe'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">GIE</td></tr>
<?php foreach($gieEquipe2 as $a) ligne($a,fn($d)=>cycle14E2($d,$feries),true,false,'gie'); ?>
<?php foreach($gieEquipe1 as $a) ligne($a,fn($d)=>cycle14E1($d,$feries),true,false,'gie'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">ANALYSE</td></tr>
<?php
ligne('GP DHALLEWYN', fn($d)=>cycleHebdo($d,$feries),true,false,'standard_police');
ligne('BC DELCROIX',  fn($d)=>cycleHebdo($d,$feries),true,false,'standard_police');
ligne('ADC LAMBERT',  fn($d)=>cycleHebdo($d,$feries),false,false,'adc_lambert');
ligne('ACP1 DEMERVAL',fn($d)=>cycleHebdo($d,$feries),false,false,'douane_j');
?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">DOUANE</td></tr>
<?php foreach($douane as $a) ligne($a,fn($d)=>cycleDouane($d,$feries),false,false,'douane'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">SECRETARIAT</td></tr>
<?php ligne('AA MAES',fn($d)=>cycleHebdo($d,$feries),false,false,'standard_j'); ?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">INFORMATIQUE</td></tr>
<?php ligne('BC DRUEZ',fn($d)=>cycleHebdo($d,$feries),true,false,'standard_police'); ?>
</tbody>
</table>
</div><!-- /.wrap-table -->

<!-- PANNEAU NOTES -->
<?php if(canEditNotes($isAdmin,$userAgents)): ?>
<div class="notes-panel" id="notes-panel">
  <div class="notes-head">
    <div>📋 Notes <span><?=$moisFR[$mois]?> <?=$annee?></span></div>
    <span id="notes-sync" style="font-size:.65rem;color:#7dffb5;display:none">✓ Sauvé</span>
  </div>
  <div class="notes-body" id="notes-body">
    <div class="notes-section">
      <div class="notes-section-title">📅 Événements</div>
      <div id="notes-evts-list"></div>
      <div class="notes-add-evt" id="notes-add-form">
        <input type="date" id="notes-evt-date"
               style="padding:4px 7px;border:1.5px solid #dde;border-radius:5px;font-size:.73rem;width:100%;background:#fff;color:#222;color-scheme:light">
        <input type="text" id="notes-evt-label" placeholder="Ex : Opération, visite...">
        <button id="notes-evt-add">+ Ajouter</button>
      </div>
    </div>
    <div class="notes-section">
      <div class="notes-section-title">💬 Messages</div>
      <div id="notes-msgs-list"></div>
      <!-- Formulaire ajout message -->
      <div class="notes-add-evt" id="notes-add-msg" style="margin-top:8px">
        <textarea id="notes-msg-texte" placeholder="Saisir un message..." style="width:100%;padding:5px 7px;border:1.5px solid #dde;border-radius:5px;font-size:.73rem;resize:vertical;min-height:55px;font-family:inherit"></textarea>
        <div style="display:flex;gap:4px;align-items:center;margin-top:3px">
          <label style="font-size:.68rem;color:#666;white-space:nowrap">Jusqu'au :</label>
          <input type="date" id="notes-msg-datefin" style="flex:1;padding:4px 6px;border:1.5px solid #dde;border-radius:5px;font-size:.72rem;background:#fff;color:#222;color-scheme:light">
        </div>
        <button id="notes-msg-add" style="margin-top:4px">+ Ajouter le message</button>
      </div>
      <!-- Archives -->
      <div style="margin-top:10px">
        <button id="btn-notes-arch" onclick="(function(btn){const d=document.getElementById('notes-arch-list');const open=d.style.display!=='none';d.style.display=open?'none':'block';btn.textContent=open?'🗂 Voir les archives':'🗂 Masquer les archives';})(this)"
          style="width:100%;padding:4px 8px;background:#f0f0f0;color:#666;border:1px solid #ddd;border-radius:5px;font-size:.7rem;cursor:pointer;text-align:left">
          🗂 Voir les archives
        </button>
        <div id="notes-arch-list" style="display:none;margin-top:6px"></div>
      </div>
    </div>
    <!-- Widget quota restant — visible si l'agent est un policier -->
    <?php
    $agentsPoliceNotes=['CoGe ROUSSEL','Cne MOKADEM',
      'BC MASSON','BC SIGAUD','BC DAINOTTI',
      'BC BOUXOM','BC ARNAULT','BC HOCHARD',
      'BC DUPUIS','BC BASTIEN','BC ANTHONY',
      'GP DHALLEWYN','BC DELCROIX','AA MAES','BC DRUEZ'];
    $monAgentNotes = $isAdmin ? null : ($userAgents[0] ?? null);
    $afficherWidget = $monAgentNotes && in_array($monAgentNotes, $agentsPoliceNotes);
    ?>
    <?php if($afficherWidget): ?>
    <div class="notes-section" id="quota-widget-section" style="border-top:1px solid #e8edf5;margin-top:6px;padding-top:8px">
      <div class="notes-section-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>📊 Mes quotas congés <?=$annee?></span>
        <button onclick="loadQuotaWidget()" style="background:none;border:none;cursor:pointer;font-size:.75rem;color:#1565c0;padding:0" title="Actualiser">↻</button>
      </div>
      <div id="quota-widget-body" style="font-size:.72rem;margin-top:6px">
        <p style="color:#aaa;font-style:italic">Chargement...</p>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div><!-- /.wrap -->

<?php else: ?>
<div class="vue-annuelle">
<div class="annual-grid">
<?php for($m=1;$m<=12;$m++):
  $nb=cal_days_in_month(CAL_GREGORIAN,$m,$annee);
  $gMini=['DIR'=>$direction,'NUIT'=>$nuit,'EQ1'=>$equipe1,'EQ2'=>$equipe2,
    'GIE'=>array_merge($gieEquipe1,$gieEquipe2),
    'ANALYSE'=>['GP DHALLEWYN','BC DELCROIX','ADC LAMBERT','ACP1 DEMERVAL'],
    'DOUANE'=>$douane,'SECR.'=>$secretariat];
?>
<div class="mini-mois">
  <div class="mini-mois-header">
    <span><?=$moisFR[$m]?></span>
    <a href="?annee=<?=$annee?>&mois=<?=$m?>&vue=mois">D&eacute;tail &rarr;</a>
  </div>
  <div style="overflow-x:auto"><table class="mini-cal"><thead><tr>
    <th style="text-align:left;min-width:65px">Agent</th>
    <?php for($d=1;$d<=$nb;$d++):
      $dt=sprintf('%04d-%02d-%02d',$annee,$m,$d);
      $jn=(int)(new DateTime($dt))->format('N');
      $cls=isset($feriesAll[$dt])?'ferie-th':($jn>=6?'wk':'');
    ?><th class="<?=$cls?>"><?=$d?></th><?php endfor; ?>
  </tr></thead><tbody>
  <?php foreach($gMini as $lbl=>$agents): ?>
  <tr><td colspan="<?=$nb+1?>" style="background:#1a2742;color:#fff;font-weight:700;font-size:7px;padding:1px 3px"><?=$lbl?></td></tr>
  <?php foreach($agents as $agent): ?>
  <tr>
    <td class="mini-agent-label" title="<?=htmlspecialchars($agent)?>"><?=htmlspecialchars($agent)?></td>
    <?php for($d=1;$d<=$nb;$d++):
      $dt=sprintf('%04d-%02d-%02d',$annee,$m,$d);
      $fe=isset($feriesAll[$dt]);
      $cg=$congesAnnee[$agent][$dt]??null;
      $pm=$permsAnnee[$agent][$dt]??null;
      if($pm) echo "<td class='".cb($pm['type'])."'>".substr($pm['type'],0,1)."</td>";
      elseif($cg) { $lc=canEdit($agent,$isAdmin,$globalLocked,$locks,$userAgents,$moisStr)?'':' locked-agent'; $clsMini=($cg['type']==='PREV')?'prev-conge':'conge'; echo "<td class='$clsMini$lc'>•</td>"; }
      elseif($fe) echo "<td class='ferie'>F</td>";
      else{$cv=getCycleAgent($agent,$dt,$feriesAll,$groupeAgent);$c=match($cv){'J'=>'j','M'=>'m','AM'=>'am','NUIT'=>'nuit','RC'=>'rc','RL'=>'rl','FERIE'=>'ferie',default=>''};echo "<td class='$c'>".substr($cv,0,1)."</td>";}
    endfor; ?>
  </tr>
  <?php endforeach;endforeach; ?>
  </tbody></table></div>
</div>
<?php endfor; ?>
</div></div>
<?php endif; ?>

<!-- MODAL CONGÉ / PERMANENCE -->
<div class="overlay" id="overlay">
  <div class="modal">
    <div class="modal-head" id="modal-head">
      <div>
        <h3 id="m-agent"></h3>
        <small id="m-date"></small>
        <span id="m-groupe" style="font-size:.63rem;padding:1px 7px;border-radius:8px;background:rgba(255,255,255,.2);display:inline-block;margin-top:2px"></span>
      </div>
      <button class="btn-x" id="btn-x">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-cycle" id="m-cycle"></div>
      <!-- Bouton TIR rapide si case éligible -->
      <div style="margin-bottom:10px">
        <button type="button" id="btn-open-tir-from-conge" style="display:none;width:100%;padding:8px;background:#1565c0;color:#ffd600;border:none;border-radius:7px;font-weight:700;font-size:.8rem;cursor:pointer">
          &#127919; Enregistrer une s&eacute;ance de TIR
        </button>
      </div>

      <!-- Congé classique -->
      <div id="sec-conge">
        <div class="date-range-row">
          <div class="date-field"><label>D&eacute;but</label><input type="date" id="inp-debut"></div>
          <div class="date-field"><label>Fin</label><input type="date" id="inp-fin"></div>
        </div>
        <div class="range-info" id="range-info"></div>
        <div class="code-grid" id="code-grid"></div>
        <!-- Heure pour DA/PR uniquement -->
        <div id="sec-heure" style="display:none;margin-top:8px">
          <div style="font-size:.72rem;color:#666;margin-bottom:4px">&#128336; Heure (DA / PR) :</div>
          <input type="time" id="inp-heure" style="padding:6px 10px;border:1.5px solid #dde;border-radius:6px;font-size:.88rem;width:120px">
        </div>
        <div id="sec-periode">
          <div style="font-size:.72rem;color:#666;margin-bottom:5px">P&eacute;riode :</div>
          <div class="options-row" id="options-periode">
            <button type="button" class="opt-btn sel" data-val="J">&#128336; Journ&eacute;e</button>
            <button type="button" class="opt-btn" data-val="M">&#9728; Matin</button>
            <button type="button" class="opt-btn" data-val="AM">&#9790; Apr&egrave;s-midi</button>
          </div>
        </div>
      </div>

      <!-- Permanence -->
      <div id="sec-perm" style="display:none">

        <div class="perm-grid" id="perm-grid" style="grid-template-columns:1fr 1fr">
          <button type="button" class="perm-btn" id="perm-m" style="background:#ffd200;color:#222">
            M<small>Perm. matin</small></button>
          <button type="button" class="perm-btn" id="perm-am" style="background:#2f5597;color:#ffc000">
            AM<small>Perm. apr&egrave;s-midi</small></button>
          <button type="button" class="perm-btn" id="perm-indispo-m" style="background:#90caf9;color:#1a237e;font-size:.72rem">
            Indisp M<small>Matin</small></button>
          <button type="button" class="perm-btn" id="perm-indispo-am" style="background:#64b5f6;color:#1a237e;font-size:.72rem">
            Indisp AM<small>Apr&egrave;s-midi</small></button>
          <button type="button" class="perm-btn" id="perm-indispo-j" style="background:#42a5f5;color:#1a237e;font-size:.72rem">
            Indisp J<small>Journ&eacute;e</small></button>
        </div>
      </div>

      <!-- TIR -->
      <div id="sec-tir" style="display:none">
        <p style="font-size:.78rem;color:#555;margin-bottom:10px">S&eacute;ance de tir &mdash; choisir la p&eacute;riode :</p>
        <div class="perm-grid" id="tir-grid">
          <button type="button" class="perm-btn" id="tir-m"    style="background:#1565c0;color:#ffd600">&#9728; M<small>Matin</small></button>
          <button type="button" class="perm-btn" id="tir-am"   style="background:#1565c0;color:#ffd600">&#9790; AM<small>Apr&egrave;s-midi</small></button>
          <button type="button" class="perm-btn" id="tir-j"    style="display:none;background:#1565c0;color:#ffd600">&#128336; J<small>Journ&eacute;e</small></button>
          <button type="button" class="perm-btn" id="tir-nuit" style="background:#1565c0;color:#ffd600;display:none">&#9790; NUIT<small>Nuit</small></button>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button type="button" class="perm-btn restore" id="tir-del" style="display:none;flex:1">&#8635; Supprimer le TIR</button>
          <button type="button" id="btn-tir-to-conge" style="display:none;flex:1;padding:7px 10px;border:1px solid #1a2742;border-radius:6px;background:#f0f4ff;color:#1a2742;cursor:pointer;font-size:.75rem;font-weight:600">&#128196; Poser un cong&eacute;</button>
        </div>
        <!-- Annulation TIR -->
        <div id="sec-tir-annul" style="display:none;margin-top:12px;border-top:1px dashed #ddd;padding-top:10px">
          <p style="font-size:.75rem;font-weight:700;color:#c0392b;margin-bottom:8px">&#10060; Annulation de s&eacute;ance (N+1)</p>
          <div style="font-size:.72rem;color:#666;margin-bottom:5px">Motif :</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
            <button type="button" class="opt-btn" id="annul-motif-stand" data-motif="Indisponibilité stand" style="font-size:.72rem">Indisponibilit&eacute; stand</button>
            <button type="button" class="opt-btn" id="annul-motif-autre" data-motif="" style="font-size:.72rem">Autre</button>
          </div>
          <input type="text" id="annul-motif-libre" placeholder="Préciser le motif..." style="display:none;width:100%;padding:5px 8px;border:1.5px solid #dde;border-radius:6px;font-size:.78rem;box-sizing:border-box;margin-bottom:8px">
          <div style="display:flex;gap:6px">
            <button id="btn-save-annul" style="flex:1;padding:7px;background:#c0392b;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:.75rem;cursor:pointer">Enregistrer l'annulation</button>
            <button id="btn-del-annul"  style="display:none;flex:1;padding:7px;background:#888;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:.75rem;cursor:pointer">Supprimer l'annulation</button>
          </div>
        </div>
      </div>

      <!-- Section modification vacation (équipes, nuit, standard_police) -->
      <div id="sec-vac-override" style="display:none;margin:8px 16px;padding:9px 12px;background:#fff8e1;border:1.5px solid #f9a825;border-radius:8px">
        <div style="font-size:.72rem;font-weight:700;color:#7a5800;margin-bottom:7px">&#9998; Modifier la vacation affich&#233;e</div>
        <div style="display:flex;gap:6px;flex-wrap:nowrap" id="vac-btns"></div>
        <button type="button" id="btn-del-vac" style="display:none;margin-top:7px;padding:3px 10px;font-size:.72rem;background:#c0392b;color:#fff;border:none;border-radius:5px;cursor:pointer">
          &#128465; R&#233;tablir le cycle d'origine
        </button>
      </div>

      <div class="modal-foot">
        <button class="btn btn-cancel" id="btn-cancel">Annuler</button>
        <button class="btn btn-del"    id="btn-del"   disabled>Supprimer</button>
        <button class="btn btn-save"   id="btn-save"  disabled>Sauvegarder</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL COMPTEUR TIR -->
<!-- ══════════ OVERLAY RÉCAP QUOTAS INDIVIDUELS ══════════ -->
<div class="overlay-perm" id="overlay-quota-recap">
  <div style="background:#fff;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.35);
              width:min(98vw,700px);max-height:92vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="background:#27ae60;color:#fff;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <span style="font-size:.9rem;font-weight:800;letter-spacing:.04em">📊 Récapitulatif Quotas — <span id="quota-recap-titre"></span></span>
      <button id="btn-x-quota-recap" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1">✕</button>
    </div>
    <div id="quota-recap-body" style="overflow-y:auto;padding:16px;flex:1"></div>
  </div>
</div>

<!-- ══════════ OVERLAY RÉCAP FÊTES ══════════ -->
<div class="overlay-perm" id="overlay-recap-fetes">
  <div style="background:#fff;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.35);
              width:min(98vw,1100px);max-height:92vh;display:flex;flex-direction:column;overflow:hidden">
    <div style="background:#7b1fa2;color:#fff;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <span style="font-size:.9rem;font-weight:800;letter-spacing:.04em">🎄 Récapitulatif permanences fêtes — 01/01 · 25/12</span>
      <button id="btn-x-recap-fetes" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1">✕</button>
    </div>
    <div id="recap-fetes-body" style="overflow-y:auto;padding:14px;flex:1"></div>
  </div>
</div>

<div class="overlay-perm" id="overlay-tir">
  <div class="modal-perm" id="modal-tir">
    <div class="mp-head" style="background:#1565c0">
      <h3>&#127919; S&eacute;ances TIR &mdash; <?=$annee?></h3>
      <button class="btn-x" id="btn-x-tir">&times;</button>
    </div>
    <div class="mp-body" id="tir-body">
      <p style="text-align:center;color:#888;padding:20px">Chargement...</p>
    </div>
  </div>
</div>

<!-- MODAL COMPTEUR PERMANENCES -->
<div class="overlay-perm" id="overlay-perm">
  <div class="modal-perm">
    <div class="mp-head">
      <h3>Permanences effectu&eacute;es &mdash; <?=$annee?></h3>
      <button class="btn-x" id="btn-x-perm">&times;</button>
    </div>
    <div style="display:flex;gap:6px;align-items:center;padding:8px 16px;background:#e3f0ff;border-bottom:1px solid #c5d8f0;flex-wrap:wrap">
      <span style="font-size:.75rem;font-weight:600;color:#1565c0">Détail mensuel :</span>
      <?php for($m2=1;$m2<=12;$m2++):?>
      <button class="nav-btn perm-mois-btn" data-mois="<?=$m2?>" style="padding:2px 8px;font-size:.72rem"><?=mb_substr($moisFR[$m2],0,3,'UTF-8')?></button>
      <?php endfor;?>
      <button class="nav-btn perm-mois-btn" data-mois="0" id="perm-btn-annuel" style="padding:2px 8px;font-size:.72rem;background:#1a2742;color:#ffd600">Annuel</button>
    </div>
    <div class="mp-body" id="mp-body">
      <p style="text-align:center;color:#888;padding:20px">Chargement...</p>
    </div>
  </div>
</div>

<!-- MODAL CHANGEMENT MOT DE PASSE (users non-admin) -->
<?php if(!$isAdmin):?>
<div class="overlay-perm" id="overlay-change-pass">
  <div class="modal-perm" style="max-width:360px">
    <div class="mp-head" style="background:#e65100">
      <h3>&#128273; Changer mon mot de passe</h3>
      <button class="btn-x" id="btn-x-change-pass">&times;</button>
    </div>
    <div style="padding:16px">
      <?php if(!empty($_SESSION['must_change'])):?>
      <p style="font-size:.78rem;color:#e65100;margin-bottom:12px;font-weight:600">⚠️ Votre mot de passe temporaire doit être changé.</p>
      <?php endif;?>
      <div class="adm-form">
        <label>Ancien mot de passe</label><input type="password" id="user-old-pass">
        <label>Nouveau mot de passe (min. 8 car.)</label><input type="password" id="user-new-pass" minlength="8">
        <button class="btn-action btn-print" id="btn-user-change-pass" style="width:100%">Enregistrer</button>
        <p id="user-pass-msg" style="font-size:.78rem;margin-top:8px"></p>
      </div>
    </div>
  </div>
</div>
<?php endif;?>

<!-- PANNEAU ADMIN -->
<?php if($isAdmin):?>
<div class="overlay-admin" id="overlay-admin">
  <div class="modal-admin">
    <div class="adm-head">
      <h3>&#9881; Administration du planning</h3>
      <button class="btn-x" id="btn-x-admin">&times;</button>
    </div>
    <div class="adm-tabs">
      <div class="adm-tab active" data-tab="verrous">&#128274; Verrous</div>
      <div class="adm-tab" data-tab="users">&#128100; Utilisateurs</div>
      <div class="adm-tab" data-tab="moncompte">&#128273; Mon compte</div>
      <div class="adm-tab" data-tab="reset" style="color:#c0392b">&#128683; Reset</div>
      <div class="adm-tab" data-tab="quotas">&#127775; Quotas congés</div>
      <div class="adm-tab" data-tab="conges-recap">&#128197; Congés</div>
    </div>
    <div class="adm-body">

      <!-- ONGLET VERROUS -->
      <div class="adm-section active" id="tab-verrous">
        <!-- Verrou global planning -->
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
          <?php if($globalLocked):?>
          <button class="btn-action btn-print" id="btn-unlock-global" style="background:#27ae60">&#128275; D&eacute;verrouiller tout le planning</button>
          <?php else:?>
          <button class="btn-action" id="btn-lock-global" style="background:#c0392b;color:#fff">&#128274; Verrouiller tout le planning</button>
          <?php endif;?>
        </div>

        <?php
        $tousAgents=['CoGe ROUSSEL','LCL PARENT','IR MOREAU','Cne MOKADEM',
          'BC MASSON','BC SIGAUD','BC DAINOTTI',
          'BC BOUXOM','BC ARNAULT','BC HOCHARD',
          'BC DUPUIS','BC BASTIEN','BC ANTHONY',
          'ADJ LEFEBVRE','ADJ CORRARD',
          'GP DHALLEWYN','BC DELCROIX','ADC LAMBERT','ACP1 DEMERVAL',
          'ACP1 LOIN','AA MAES','BC DRUEZ'];
        $moisLabels=[1=>'Jan',2=>'Fév',3=>'Mar',4=>'Avr',5=>'Mai',6=>'Jun',
                     7=>'Jul',8=>'Aoû',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Déc'];
        ?>

        <!-- SECTION 1 : Grille verrous mensuels (12 mois × agents) -->
        <div style="margin-bottom:18px;padding:12px;background:#fff8f0;border:1.5px solid #e67e22;border-radius:8px;overflow-x:auto">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap">
            <span style="font-size:.8rem;font-weight:700;color:#e67e22">&#128197; Verrous mensuels — <?=$annee?></span>
            <button id="btn-lock-all-mois" class="btn-action" style="font-size:.7rem;padding:2px 10px;background:#e67e22;color:#fff">&#128274; Tout verrouiller <?=$annee?></button>
            <button id="btn-unlock-all-mois" class="btn-action" style="font-size:.7rem;padding:2px 10px;background:#27ae60;color:#fff">&#128275; Tout d&eacute;verrouiller <?=$annee?></button>
          </div>
          <table style="font-size:.7rem;border-collapse:collapse;width:auto;box-shadow:none;table-layout:fixed">
            <thead>
              <tr>
                <th style="background:#e67e22;color:#fff;padding:3px 8px;text-align:left;position:sticky;left:0;z-index:2;width:130px">Agent</th>
                <?php for($m=1;$m<=12;$m++): $mv=sprintf('%04d-%02d',$annee,$m); ?>
                <th style="background:<?=$m==$mois?'#c0392b':'#e67e22'?>;color:#fff;padding:3px 0;width:38px;text-align:center;cursor:pointer" data-mois-col="<?=$mv?>" title="<?=$mv?>"><?=$moisLabels[$m]?></th>
                <?php endfor;?>
                <th style="background:#7a3e00;color:#fff;padding:3px 0;width:42px;text-align:center">Année</th>
              </tr>
              <!-- Ligne boutons verrou par mois -->
              <tr>
                <td style="background:#fff3e0;padding:2px 8px;position:sticky;left:0;z-index:2;font-size:.62rem;color:#e67e22;font-weight:700">Verr. mois ▼</td>
                <?php for($m=1;$m<=12;$m++): $mv=sprintf('%04d-%02d',$annee,$m);
                  // Compter combien d'agents sont verrouillés pour ce mois
                  $nbVerr=0;
                  foreach($tousAgents as $a) if(isset($locks['mois'][$a][$mv])) $nbVerr++;
                  $allVerr=($nbVerr===count($tousAgents));
                  $bg=$allVerr?'#e67e22':($nbVerr>0?'#ffa726':'#f9f9f9');
                  $col=$nbVerr>0?'#fff':'#ccc';
                ?>
                <td class="btn-lock-col-mois" data-mois="<?=$mv?>"
                    style="text-align:center;padding:2px 0;cursor:pointer;background:<?=$bg?>;color:<?=$col?>;font-size:12px;border:1px solid #eee"
                    title="<?=$allVerr?'Tout déverrouiller':'Tout verrouiller'?> — <?=$mv?> (<?=$nbVerr?>/<?=count($tousAgents)?> verrouillés)">
                  <?=$nbVerr>0?'&#9632;':'&middot;'?>
                </td>
                <?php endfor;?>
                <td style="background:#f9f9f9;border:1px solid #eee"></td>
              </tr>
            </thead>
            <tbody>
            <?php foreach($tousAgents as $ag):
              $isAnnualLocked=isset($locks['agents'][$ag]);
            ?>
            <tr data-agent="<?=htmlspecialchars($ag)?>" style="height:28px">
              <td style="text-align:left;padding:0 8px;font-weight:600;background:#f7f8fa;position:sticky;left:0;z-index:1;white-space:nowrap;height:28px;line-height:28px"><?=htmlspecialchars($ag)?></td>
              <?php for($m=1;$m<=12;$m++):
                $mv=sprintf('%04d-%02d',$annee,$m);
                $isML=isset($locks['mois'][$ag][$mv]);
              ?>
              <td class="lock-cell-mois <?=$isML?'lc-locked':'lc-free'?>" data-agent="<?=htmlspecialchars($ag)?>" data-mois="<?=$mv?>"
                style="cursor:pointer;text-align:center;width:38px;height:28px;line-height:28px;padding:0;background:<?=$isML?'#ffe0b2':'#f9f9f9'?>;border:1px solid #eee;font-size:14px"
                title="<?=$isML?'&#128274; Verrouill&eacute;':'&#128275; Libre' ?> — <?=$mv?>">
                <?=$isML?'&#128274;':'&middot;'?>
              </td>
              <?php endfor;?>
              <!-- Verrou annuel -->
              <td class="lock-cell-annuel <?=$isAnnualLocked?'la-locked':'la-free'?>" data-agent="<?=htmlspecialchars($ag)?>"
                style="cursor:pointer;text-align:center;width:42px;height:28px;line-height:28px;padding:0;background:<?=$isAnnualLocked?'#fdecea':'#f9f9f9'?>;border:1px solid #eee;font-weight:700;font-size:14px"
                title="<?=$isAnnualLocked?'&#128274; Verrouill&eacute; toute l\'ann&eacute;e':'&#128275; Libre (ann&eacute;e)' ?>">
                <?=$isAnnualLocked?'&#128274;':'&middot;'?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
          <p style="font-size:.68rem;color:#aaa;margin-top:6px">Cliquer sur une cellule pour basculer le verrou. La colonne en rouge = mois actuellement affiché.</p>
        </div>

        <!-- SECTION 2 : Légende et verrou global agent (ligne séparée) -->
        <div style="padding:10px;background:#fdecea;border:1.5px solid #c0392b;border-radius:8px">
          <div style="font-size:.78rem;font-weight:700;color:#c0392b;margin-bottom:6px">&#128274; Verrou annuel agent — bloque toute l'ann&eacute;e</div>
          <p style="font-size:.72rem;color:#888;margin-bottom:8px">D&eacute;part, suspension... La colonne "Ann&eacute;e" dans la grille ci-dessus suffit. Liste synth&eacute;tique :</p>
          <div id="lock-agents-list" style="display:flex;flex-wrap:wrap;gap:4px">
          <?php foreach($tousAgents as $ag):
            $isLocked=isset($locks['agents'][$ag]);
          ?>
          <span class="lock-badge-annuel <?=$isLocked?'lba-locked':'lba-free'?>" data-agent="<?=htmlspecialchars($ag)?>"
            style="padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600;cursor:pointer;
                   background:<?=$isLocked?'#c0392b':'#e8f5e9'?>;color:<?=$isLocked?'#fff':'#27ae60'?>;
                   border:1px solid <?=$isLocked?'#c0392b':'#c8e6c9'?>">
            <?=$isLocked?'&#9632;':'&#9633;'?> <?=htmlspecialchars($ag)?>
          </span>
          <?php endforeach;?>
          </div>
        </div>
      </div>

      <!-- ONGLET UTILISATEURS -->
      <div class="adm-section" id="tab-users">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
          <button class="btn-action btn-print" id="btn-new-user">+ Nouvel utilisateur</button>
          <button class="btn-action" id="btn-create-agents" style="background:#27ae60;color:#fff" title="Crée un compte par agent avec login=NOM et un mot de passe temporaire aléatoire">&#9889; Créer tous les comptes agents</button>
          <button class="btn-action" id="btn-fix-gie" style="background:#e67e22;color:#fff" title="Donne à LEFEBVRE et CORRARD un accès mutuel à la ligne de l'autre">&#128257; Accès mutuel GIE</button>
        </div>
        <div id="users-list"><p style="color:#888;font-size:.8rem">Chargement...</p></div>

      </div>

      <!-- ONGLET MON COMPTE -->
      <div class="adm-section" id="tab-moncompte">
        <p style="font-size:.8rem;margin-bottom:12px">Connecté en tant que : <strong><?=htmlspecialchars($_SESSION['user_login'])?></strong></p>
        <div class="adm-form">
          <label>Ancien mot de passe</label><input type="password" id="old-pass">
          <label>Nouveau mot de passe (min. 8 car.)</label><input type="password" id="new-pass" minlength="8">
          <button class="btn-action btn-print" id="btn-change-pass">Modifier le mot de passe</button>
          <p id="pass-msg" style="font-size:.78rem;margin-top:8px"></p>
        </div><!-- /adm-form -->
      </div><!-- /tab-moncompte -->

      <!-- ONGLET RESET -->
      <div class="adm-section" id="tab-reset">
        <div style="background:#fdecea;border:2px solid #c0392b;border-radius:8px;padding:16px;margin-bottom:16px">
          <p style="font-weight:700;color:#c0392b;font-size:.9rem;margin-bottom:8px">⚠️ ZONE DANGEREUSE — Remise à zéro des données</p>
          <p style="font-size:.78rem;color:#555;margin-bottom:12px">Supprime définitivement les données sélectionnées. Action irréversible.</p>
          <div style="margin-bottom:10px">
            <label style="font-size:.8rem;font-weight:600">Périmètre :</label><br>
            <label style="margin-right:16px"><input type="radio" name="reset-scope" value="mois" checked> Mois en cours (<?=sprintf('%02d',$mois).'/'.$annee?>)</label>
            <label><input type="radio" name="reset-scope" value="annee"> Année entière (<?=$annee?>)</label>
          </div>
          <div style="margin-bottom:10px">
            <label style="font-size:.8rem;font-weight:600">Données à effacer :</label><br>
            <label style="margin-right:12px"><input type="checkbox" class="reset-table" value="conges" checked> Congés</label>
            <label style="margin-right:12px"><input type="checkbox" class="reset-table" value="permanences" checked> Permanences</label>
            <label style="margin-right:12px"><input type="checkbox" class="reset-table" value="tir" checked> TIR</label>
            <label><input type="checkbox" class="reset-table" value="tir_annulations" checked> Annulations TIR</label>
          </div>
          <div style="margin-bottom:12px">
            <label style="font-size:.8rem;font-weight:600">Mot de passe de confirmation :</label><br>
            <input type="password" id="reset-pwd" placeholder="Mot de passe reset" style="padding:5px 10px;border:1px solid #c0392b;border-radius:4px;font-size:.85rem;margin-top:4px">
          </div>
          <button id="btn-reset-data" style="background:#c0392b;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.85rem">
            &#128683; Lancer la remise à zéro
          </button>
          <div id="reset-result" style="margin-top:10px;font-size:.8rem"></div>
        </div>
      </div><!-- /tab-reset -->

      <!-- ONGLET QUOTAS CONGÉS -->
      <div class="adm-section" id="tab-quotas">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
          <span style="font-size:.82rem;font-weight:700;color:#1a2742">Année :</span>
          <select id="quota-annee" style="padding:4px 10px;border:1.5px solid #1a2742;border-radius:6px;font-size:.8rem">
            <?php for($y=$annee-1;$y<=$annee+1;$y++):?>
            <option value="<?=$y?>"<?=$y===$annee?' selected':''?>><?=$y?></option>
            <?php endfor;?>
          </select>
          <button id="btn-quota-load" class="btn-action btn-print" style="padding:4px 12px;font-size:.78rem">&#8635; Charger</button>
          <button id="btn-quota-save-all" class="btn-action" style="padding:4px 12px;font-size:.78rem;background:#27ae60;color:#fff">&#10003; Sauvegarder tout</button>
          <button id="btn-open-quota-recap" class="btn-action" style="padding:4px 12px;font-size:.78rem;background:#1565c0;color:#fff">📊 Récap individuel</button>
        </div>
        <p style="font-size:.75rem;color:#888;margin-bottom:10px">
          Jours ouvrés de congé autorisés par agent (WE et fériés exclus).
          Un <strong style="color:#c0392b">⚠</strong> s'affiche lors de la saisie si le quota est dépassé.
        </p>
        <div id="quota-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px">
          <p style="color:#888;font-size:.8rem;grid-column:1/-1">Cliquez sur Charger...</p>
        </div>
      </div><!-- /tab-quotas -->

      <!-- ONGLET RÉCAP CONGÉS PAR GROUPE -->
      <div class="adm-section" id="tab-conges-recap">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
          <span style="font-size:.82rem;font-weight:700;color:#1a2742">Année :</span>
          <select id="cr-annee" style="padding:4px 10px;border:1.5px solid #1a2742;border-radius:6px;font-size:.8rem">
            <?php for($y=$annee-1;$y<=$annee+1;$y++):?>
            <option value="<?=$y?>"<?=$y===$annee?' selected':''?>><?=$y?></option>
            <?php endfor;?>
          </select>
          <button id="btn-cr-load" class="btn-action btn-print" style="padding:4px 12px;font-size:.78rem">&#8635; Actualiser</button>
        </div>
        <div id="cr-body"><p style="color:#888;font-size:.8rem">Cliquez sur Actualiser...</p></div>
      </div><!-- /tab-conges-recap -->

    </div>
  </div>
</div>

<!-- Modale flottante formulaire utilisateur -->
<div id="user-form-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;align-items:center;justify-content:center">
  <div id="user-form-wrap" style="background:#fff;border-radius:12px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 12px 50px rgba(0,0,0,.35);animation:pop .18s ease;margin:20px">
    <div style="background:#1a2742;color:#fff;padding:12px 18px;border-radius:12px 12px 0 0;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1">
      <h4 style="font-size:.9rem;font-weight:700;margin:0" id="user-form-title">Nouvel utilisateur</h4>
      <button id="btn-cancel-user" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1;opacity:.7">&times;</button>
    </div>
    <div style="padding:16px">
      <div class="adm-form">
        <input type="hidden" id="f-uid" value="0">
        <label>Login</label><input type="text" id="f-login">
        <label>Nom affiché</label><input type="text" id="f-nom">
        <label>Rôle</label>
        <select id="f-role">
          <option value="user">Utilisateur</option>
          <option value="admin">Administrateur</option>
        </select>
        <label>Mot de passe <span id="pass-hint" style="color:#888;font-weight:400">(laisser vide pour ne pas changer)</span></label>
        <input type="password" id="f-pass" placeholder="••••••••">
        <div id="f-agents-wrap">
          <label>Agents autorisés (laisser vide = aucun accès)</label>
          <div class="agents-check" id="f-agents">
            <?php foreach($tousAgents as $ag):?>
            <label><input type="checkbox" value="<?=htmlspecialchars($ag)?>"> <?=htmlspecialchars($ag)?></label>
            <?php endforeach;?>
          </div>
        </div>
        <label style="margin-top:8px;font-weight:700;color:#1a2742">Droits de modification</label>
        <div style="display:flex;gap:12px;flex-wrap:wrap;padding:8px;background:#f0f4ff;border-radius:6px">
          <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;font-weight:600">
            <input type="checkbox" id="f-can-conge" style="width:16px;height:16px"> 📅 Congés
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;font-weight:600">
            <input type="checkbox" id="f-can-perm" style="width:16px;height:16px"> 🔆 Permanences
          </label>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px">
          <button class="btn-action btn-print" id="btn-save-user" style="flex:1">Enregistrer</button>
          <button class="btn-action" id="btn-cancel-user-2" style="background:#eee;color:#333">Annuler</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif;?>

<div class="toast" id="toast"></div>

<script>
const TYPES_GROUPE={
  direction         :<?=json_encode(array_values($codesStandard),JSON_UNESCAPED_UNICODE)?>,
  direction_police  :<?=json_encode(array_values($codesPolice),JSON_UNESCAPED_UNICODE)?>,
  direction_mokadem :<?=json_encode(array_values($codesMokadem),JSON_UNESCAPED_UNICODE)?>,
  nuit              :<?=json_encode(array_values($codesPolice),JSON_UNESCAPED_UNICODE)?>,
  standard          :<?=json_encode(array_values($codesStandard),JSON_UNESCAPED_UNICODE)?>,
  standard_j        :<?=json_encode(array_values($codesStandard),JSON_UNESCAPED_UNICODE)?>,
  standard_police   :<?=json_encode(array_values($codesPolice),JSON_UNESCAPED_UNICODE)?>,
  equipe            :<?=json_encode(array_values($codesPolice),JSON_UNESCAPED_UNICODE)?>,
  gie               :<?=json_encode(array_values($typesCongesGieJ),JSON_UNESCAPED_UNICODE)?>,
  gie_j             :<?=json_encode(array_values($typesCongesGieJ),JSON_UNESCAPED_UNICODE)?>,
  lcl_parent        :<?=json_encode(array_values($codesLclParent),JSON_UNESCAPED_UNICODE)?>,
  adc_lambert       :<?=json_encode(array_values($codesAdcLambert),JSON_UNESCAPED_UNICODE)?>,
  douane            :<?=json_encode(array_values($typesCongesDouane),JSON_UNESCAPED_UNICODE)?>,
  douane_j          :<?=json_encode(array_values($typesCongesDouane),JSON_UNESCAPED_UNICODE)?>,
};
// Codes autorisés aux utilisateurs non-admin (les autres sont réservés à l'administrateur)
// Ligne 1 : CAA, CA, HPA, HP, RTT, CET  — Ligne 2 : PR, DA, HS, CF, RPS
const USER_ALLOWED_CODES=['PREV']; // Utilisateurs : uniquement le prévisionnel de congé
// Ordre des codes pour l'admin (3 lignes de 6)
// Ligne 1 : CAA, CA, HPA, HP, RTT, CET — Ligne 2 : PR, DA, HS, CF, RPS, (vide) — Ligne 3 : ASA, CAM, CONV, GEM, VEM, STG
const ADMIN_CONGE_CODES=['CAA','CA','HPA','HP','RTT','CET','PR','DA','HS','CF','RPS','__EMPTY__','ASA','CAM','CONV','GEM','VEM','STG','CMO','CLM','CLD','AUT','__EMPTY__','PREV'];
// Tous les codes congés police (pour la grille admin — inclut CMO, CLM, CLD)
const ALL_TYPES_CONGES=<?=json_encode(array_values($typesConges),JSON_UNESCAPED_UNICODE)?>;
const AGENTS_PERM=<?=json_encode($agentsPermanence,JSON_UNESCAPED_UNICODE)?>;
const AGENTS_TIR=<?=json_encode(array_values($agentsTir),JSON_UNESCAPED_UNICODE)?>;
// LCL PARENT et ADC LAMBERT sont gérés via les groupes 'lcl_parent' et 'adc_lambert'
// Agents pour qui TIR + congé peuvent coexister le même jour
const AGENTS_TIR_COMBINE=['CoGe ROUSSEL','Cne MOKADEM','GP DHALLEWYN','BC DELCROIX','BC DRUEZ'];
const GROUPE_AGENT_JS=<?=json_encode($groupeAgent,JSON_UNESCAPED_UNICODE)?>;
const ANNEE=<?=$annee?>;
const MOIS=<?=$mois?>;
const VUE='<?=$vue?>';

// ── Polling : rechargement auto si données modifiées par un autre user ──────
// ── Polling inter-utilisateurs ──────────────────────────────────────────────
let _pollToken = null;

async function fetchToken(){
  try {
    const fd = new FormData(); fd.append('action','get_last_change');
    const r = await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const d = await r.json();
    return d.token;
  } catch(e){ return null; }
}

// Rafraîchit les cellules concernées via AJAX (sans reload de page)
// agents : string|string[], dates : string|string[]

// Applique les cellules reçues dans une réponse AJAX
// r = réponse complète (avec r.cells et r.token) OU tableau de cellules directement
function applyCells(r){
  const cells=Array.isArray(r)?r:(r?.cells||[]);
  const token=Array.isArray(r)?null:r?.token;
  if(token) _pollToken=token;
  _modalOpen=false; // toujours libérer le polling après une action
  if(!cells.length) return;
  cells.forEach(c=>{
    const old=document.querySelector('td[data-agent="'+CSS.escape(c.agent)+'"][data-date="'+c.date+'"]');
    if(old){
      const tmp=document.createElement('tbody');
      tmp.innerHTML='<tr>'+c.html+'</tr>';
      const newTd=tmp.querySelector('td');
      if(newTd) old.replaceWith(newTd);
    }
  });
  applyTodayHighlight();
}

// refreshCells : utilisé uniquement par le polling GIE (LEFEBVRE↔CORRARD)
async function refreshCells(agents, dates){
  if(!Array.isArray(agents)) agents=[agents];
  if(!Array.isArray(dates))  dates=[dates];
  try {
    const fd=new FormData();
    fd.append('action','get_cell');
    fd.append('agents',JSON.stringify(agents));
    fd.append('dates', JSON.stringify(dates));
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const d=await r.json();
    if(d.ok) applyCells(d.cells);
    if(d.token) _pollToken=d.token;
  } catch(e){ console.error('refreshCells error',e); }
}

// syncToken = alias pour compatibilité polling (agents non-admin)
async function syncToken(msg=''){
  const t = await fetchToken();
  if(t !== null) _pollToken = t;
}

let _modalOpen = false; // true = modal ouvert, polling suspendu

// Collecte tous les agents et dates visibles dans le tableau courant
function getVisibleAgentsDates(){
  const agents=new Set(), dates=new Set();
  document.querySelectorAll('td[data-agent][data-date]').forEach(td=>{
    agents.add(td.dataset.agent);
    dates.add(td.dataset.date);
  });
  return {agents:[...agents], dates:[...dates]};
}

// Rafraîchit silencieusement toutes les cellules visibles sans recharger la page
async function silentRefreshAll(){
  if(_modalOpen) return;
  const {agents,dates}=getVisibleAgentsDates();
  if(!agents.length||!dates.length) return;
  try {
    const fd=new FormData();
    fd.append('action','get_cell');
    fd.append('agents',JSON.stringify(agents));
    fd.append('dates', JSON.stringify(dates));
    const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const d=await r.json();
    if(d.ok){ applyCells(d); if(d.token) _pollToken=d.token; }
  } catch(e){ /* silence */ }
}

(async function startPolling(){
  _pollToken = await fetchToken();
  setInterval(async()=>{
    if(_modalOpen) return;
    const t = await fetchToken();
    if(t !== null && _pollToken !== null && t !== _pollToken){
      // Rafraîchissement silencieux au lieu de location.replace()
      await silentRefreshAll();
    }
    if(t !== null) _pollToken = t;
  }, 5000);
})();
const IS_ADMIN=<?=$isAdmin?'true':'false'?>;
const IS_DOUANE=<?=$isDouane?'true':'false'?>;
const CAN_TIR=<?=$isAdmin?'true':'false'?>; // TIR réservé admin
if(!CAN_TIR){const s=document.createElement('style');s.textContent='td.tir-ok,td.tir-annule{cursor:default!important}';document.head.appendChild(s);}
const CAN_CONGE=<?=($isAdmin||($userRights['can_conge']??false))?'true':'false'?>;
const CAN_PERM=<?=($isAdmin||($userRights['can_perm']??false))?'true':'false'?>;
const GLOBAL_LOCKED=<?=$globalLocked?'true':'false'?>;
const LOCKED_AGENTS=<?=json_encode(array_keys($locks['agents']),JSON_UNESCAPED_UNICODE)?>;
const LOCKED_AGENTS_MOIS=<?=json_encode($locks['mois'] ?? [],JSON_UNESCAPED_UNICODE)?>;
const CUR_MOIS='<?=$moisStr?>';
const USER_AGENTS=<?=json_encode($userAgents,JSON_UNESCAPED_UNICODE)?>;
// Surbrillance cellule du jour
const TODAY='<?=date('Y-m-d')?>';
const GIE_AGENTS=['ADJ LEFEBVRE','ADJ CORRARD'];
const MY_AGENT='<?=htmlspecialchars($userAgents[0] ?? '', ENT_QUOTES)?>';

function applyTodayHighlight(){
  document.querySelectorAll('td.today-cell').forEach(td=>td.classList.remove('today-cell'));

  if(IS_ADMIN){
    // Admin : surligner toute la colonne du jour
    document.querySelectorAll('td[data-date="'+TODAY+'"]').forEach(td=>td.classList.add('today-cell'));
    return;
  }

  // GIE : uniquement sa propre ligne
  if(GIE_AGENTS.includes(MY_AGENT)){
    const td=document.querySelector('td[data-agent="'+CSS.escape(MY_AGENT)+'"][data-date="'+TODAY+'"]');
    if(td) td.classList.add('today-cell');
    return;
  }

  // Autres agents : toutes leurs lignes
  USER_AGENTS.forEach(agent=>{
    const td=document.querySelector('td[data-agent="'+CSS.escape(agent)+'"][data-date="'+TODAY+'"]');
    if(td) td.classList.add('today-cell');
  });
}
document.addEventListener('DOMContentLoaded', ()=>{
  applyTodayHighlight();
});


function canEditCell(agent){
  if(GLOBAL_LOCKED) return false;
  if(LOCKED_AGENTS.includes(agent)) return false;
  // Verrou mensuel : bloquer si le mois affiché est verrouillé pour cet agent
  if(LOCKED_AGENTS_MOIS[agent] && LOCKED_AGENTS_MOIS[agent][CUR_MOIS]) return false;
  if(IS_ADMIN) return true;
  return USER_AGENTS.length===0||USER_AGENTS.includes(agent);
}

const overlay=document.getElementById('overlay');
const modalHead=document.getElementById('modal-head');
const mAgent=document.getElementById('m-agent');
const mDate=document.getElementById('m-date');
const mCycle=document.getElementById('m-cycle');
const codeGrid=document.getElementById('code-grid');
const btnSave=document.getElementById('btn-save');
const btnDel=document.getElementById('btn-del');
const inpDebut=document.getElementById('inp-debut');
const inpFin=document.getElementById('inp-fin');
const inpHeure=document.getElementById('inp-heure');
const rangeInfo=document.getElementById('range-info');
const secConge=document.getElementById('sec-conge');
const secPerm=document.getElementById('sec-perm');
const secTir=document.getElementById('sec-tir');

// modes : 'conge' | 'perm' | 'tir'
let curTd=null,selType='',selPer='J',curCongeId=0,curPermId=0,curTirId=0,curAnnulId=0,
    modePerm=false,modeTir=false,curCycleOrig='';

const JL=['','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
const ML=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

function joursOuvres(d1,d2){
  let n=0;const s=new Date(d1+'T00:00:00'),e=new Date(d2+'T00:00:00'),c=new Date(s);
  while(c<=e){const w=c.getDay();if(w&&w!==6)n++;c.setDate(c.getDate()+1);}return n;
}
// Cache quotas chargés pour alerte dans la modal
let _quotaModalCache={quotas:{},counts:{}};
async function ensureQuotaCache(){
  if(Object.keys(_quotaModalCache.quotas).length>0) return;
  try{
    const [rQ,rC]=await Promise.all([
      ajax({action:'get_quotas',annee:ANNEE}),
      ajax({action:'get_conges_count',annee:ANNEE})
    ]);
    if(rQ.ok) _quotaModalCache.quotas=rQ.quotas||{};
    if(rC.ok) _quotaModalCache.counts=rC.counts||{};
  }catch(e){}
}
function invalidateQuotaCache(){_quotaModalCache={quotas:{},counts:{}};}

function updateRangeInfo(){
  const d1=inpDebut.value,d2=inpFin.value;
  if(!d1||!d2){rangeInfo.style.display='none';return;}
  if(d2<d1){inpFin.value=d1;return;}
  const cal=Math.round((new Date(d2)-new Date(d1))/86400000)+1;
  const jOuvres=joursOuvres(d1,d2);
  let txt=`${cal} jour(s) cal. — ${jOuvres} ouvré(s)`;
  rangeInfo.style.background='#e8f5e9';rangeInfo.style.color='#1a6632';
  // Alerte quota par type si l'agent a un quota et qu'un type est sélectionné
  if(curTd && selType){
    const ag=curTd.dataset.agent;
    const agQuotas=_quotaModalCache.quotas[ag];
    if(agQuotas && agQuotas[selType]){
      const q=agQuotas[selType];
      if(q.unite==='jours'){
        const quota=q.quota;
        const used=(_quotaModalCache.counts[ag]||{})[selType]||0;
        const restant=quota-used-jOuvres;
        if(restant<0){
          txt+=` — ⚠️ ${selType} dépassé de ${Math.abs(restant)} j (quota: ${quota}j, posés: ${used}j)`;
          rangeInfo.style.background='#fdecea';rangeInfo.style.color='#c0392b';
        } else {
          txt+=` — ${selType}: reste ${restant} j / ${quota}j`;
        }
      } else {
        // Unité heures : pas de décompte en jours, on affiche juste le quota
        txt+=` — ${selType}: quota ${q.quota}h (en heures)`;
        rangeInfo.style.background='#fff8e1';rangeInfo.style.color='#7a5800';
      }
    }
  }
  rangeInfo.textContent=txt;
  rangeInfo.style.display='block';
  if(selType)btnSave.disabled=false;
}
inpDebut.addEventListener('change',()=>{if(inpFin.value<inpDebut.value)inpFin.value=inpDebut.value;updateRangeInfo();});
inpFin.addEventListener('change',updateRangeInfo);

function hideSections(){
  secConge.style.display='none';
  secPerm.style.display='none';
  secTir.style.display='none';
}

// Libellés de groupes
const GRP_LABEL={
  direction:'Direction',direction_police:'Direction',direction_mokadem:'Direction',
  nuit:'Nuit',equipe:'Équipe',
  standard_j:'Standard',standard_police:'Standard',
  gie:'GIE',gie_j:'GIE',
  lcl_parent:'Gendarmerie',adc_lambert:'Gendarmerie',
  douane:'Douane',douane_j:'Douane'
};
// Groupes où la demi-journée est disponible pour les congés
const GRP_DEMI=['direction','direction_police','direction_mokadem','standard_j','standard_police','equipe','nuit','lcl_parent','adc_lambert'];
// lcl_parent et adc_lambert : la ligne Période (M/AM/Journée) est utile pour Permission/Repos

function openModal(td){
  if(!canEditCell(td.dataset.agent)){
    showToast('🔒 Ligne verrouillée ou accès non autorisé.',false);return;
  }
  // Cellule TIR : accès admin uniquement
  if(!CAN_TIR && td.classList.contains('tir')){return;}
  curTd=td;
  curCongeId=parseInt(td.dataset.congeId)||0;
  curPermId =parseInt(td.dataset.permId) ||0;
  curTirId  =parseInt(td.dataset.tirId)  ||0;
  curAnnulId=parseInt(td.dataset.annulId)||0;
  selType   =td.dataset.permType||td.dataset.congeType||'';
  // Non-admin : bloquer si congé ou permanence déjà posé (sauf PREV qu'il peut modifier)
  // Exception : GIE, LCL PARENT et ADC LAMBERT gèrent leurs propres saisies
  const groupeLibre=['gie','gie_j','lcl_parent','adc_lambert','douane','douane_j'];
  // CoGe ROUSSEL (et tout user sur sa propre ligne) : libre de modifier ses propres congés
  const isOwnAgent=USER_AGENTS.length>0&&USER_AGENTS.includes(td.dataset.agent);
  if(!IS_ADMIN && !groupeLibre.includes(td.dataset.groupe) && !isOwnAgent && (curCongeId>0 || curPermId>0)){
    const typeExistant=td.dataset.congeType||td.dataset.permType||'';
    if(typeExistant !== 'PREV'){
      showToast('🔒 Modification réservée à l\'administrateur ou aux droits requis.',false);return;
    }
  }
  selPer    ='J';
  curCycleOrig=td.dataset.cycleOrig||td.dataset.cycle||'';
  const groupe=td.dataset.groupe||'standard_j';
  const cycle =td.dataset.cycle||'';
  const date  =td.dataset.date;
  // perm-ok = permanence/indispo existante ; ou agent habilité sur cellule FERIE/WE sans perm
  const hasPermOk=td.classList.contains('perm-ok')&&!td.classList.contains('tir');
  const cycleVal=td.dataset.cycle||td.dataset.cycleOrig||'';
  const isFerie=(cycleVal==='FERIE');
  const isWE=(cycleVal==='RC'||cycleVal==='RL');
  const isFerieOrWE=isFerie||isWE;
  // Mode perm si : perm existante, OU cellule WE/FERIE ET agent habilité
  // Exception non-admin sur FERIE : ouvre la modal congé (PREV) au lieu de perm
  const isPerm=hasPermOk
    ||(isFerieOrWE&&(IS_ADMIN||AGENTS_PERM.includes(td.dataset.agent))
       &&!((!IS_ADMIN)&&isFerie));
  const hasTir=curTirId>0;
  const hasAnnul=curAnnulId>0;
  // Cellule annulée : ouvrir directement le modal TIR en mode annulation
  if(hasAnnul){ if(!CAN_TIR){showToast('🔒 Séance de TIR — accès admin uniquement.',false);return;} modeTir=true;modePerm=false;openTirModal(td,date,groupe,cycle,false);return; }
  if(hasTir){ if(!CAN_TIR){return;} modeTir=true;modePerm=false;openTirModal(td,date,groupe,cycle,true);return; }
  // Cas mixte géré dans openTirModal via data-conge-id
  if(isPerm){ modeTir=false;modePerm=true;openCongeModal(td,date,groupe,cycle,true);return; }
  modeTir=false;modePerm=false;
  openCongeModal(td,date,groupe,cycle,false);
}

/* ── MODAL CONGÉ (+ bouton TIR intégré si éligible) ── */
function openCongeModal(td,date,groupe,cycle,isPerm){
  const[y,mo,d]=date.split('-');
  const dt=new Date(date+'T00:00:00');
  mAgent.textContent=td.dataset.agent;
  document.getElementById('m-groupe').textContent=GRP_LABEL[groupe]||groupe;
  mDate.textContent=JL[dt.getDay()||7]+' '+parseInt(d)+' '+ML[parseInt(mo)]+' '+y;
  const agentTirOk=AGENTS_TIR.includes(td.dataset.agent);
  const cycleOkTir=(cycle==='J'||cycle==='NUIT'||cycle==='M'||cycle==='AM');
  const hasConge=curCongeId>0;
  const isCombineConge=AGENTS_TIR_COMBINE.includes(td.dataset.agent);
  // Pour agents combinables : bouton TIR visible même sans congé
  // Pour autres agents : bouton TIR visible seulement si pas de congé
  const showTirBtn=CAN_TIR&&agentTirOk&&cycleOkTir&&!isPerm&&(isCombineConge?true:!hasConge);
  const tirBtnEl=document.getElementById('btn-open-tir-from-conge');
  if(tirBtnEl){
    tirBtnEl.style.display=showTirBtn?'block':'none';
    tirBtnEl.onclick=showTirBtn
      ? ()=>{ closeModal();modeTir=true;modePerm=false;openTirModal(td,date,groupe,cycle,false); }
      : null;
  }
  if(isPerm){
    modalHead.className='modal-head perm-head';modalHead.style.background='';
    mCycle.className='modal-cycle perm-cycle';mCycle.style.background='';mCycle.style.borderLeftColor='';
    mCycle.innerHTML='Cycle d\'origine : <strong>'+curCycleOrig+'</strong> \u2192 choisir une permanence';
    hideSections();secPerm.style.display='block';
    // Admin ou GIE : tous les boutons. Non-admin (hors GIE) : indisponibilités seulement (M/AM masqués)
    const isGieGroupe=(groupe==='gie');
    if(IS_ADMIN || isGieGroupe){
      ['perm-m','perm-am','perm-indispo-m','perm-indispo-am','perm-indispo-j'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.style.display='inline-block';
      });
    } else {
      // Non-admin (hors GIE) : uniquement les indisponibilités
      document.getElementById('perm-m').style.display='none';
      document.getElementById('perm-am').style.display='none';
      ['perm-indispo-m','perm-indispo-am','perm-indispo-j'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.style.display='inline-block';
      });
    }
    // Mise en page : 2 lignes (M+AM / Indisp M+AM+J), modale élargie, boutons ajustés
    const permGrid=document.getElementById('perm-grid');
    const modal=document.querySelector('.modal');
    if(permGrid){
      if(IS_ADMIN || isGieGroupe){
        permGrid.style.gridTemplateColumns='repeat(6,1fr)';
        modal.style.width='780px';
        document.getElementById('perm-m').style.gridColumn='span 3';
        document.getElementById('perm-am').style.gridColumn='span 3';
        document.getElementById('perm-indispo-m').style.gridColumn='span 2';
        document.getElementById('perm-indispo-am').style.gridColumn='span 2';
        document.getElementById('perm-indispo-j').style.gridColumn='span 2';
      } else {
        // Non-admin (hors GIE) : 3 boutons indispo sur une ligne
        permGrid.style.gridTemplateColumns='repeat(3,1fr)';
        modal.style.width='480px';
        document.getElementById('perm-indispo-m').style.gridColumn='span 1';
        document.getElementById('perm-indispo-am').style.gridColumn='span 1';
        document.getElementById('perm-indispo-j').style.gridColumn='span 1';
      }
      ['perm-m','perm-am','perm-indispo-m','perm-indispo-am','perm-indispo-j'].forEach(id=>{
        const el=document.getElementById(id);
        if(el){
          el.style.padding='10px 4px';
          el.style.fontSize='.82rem';
          const sm=el.querySelector('small');
          if(sm){sm.style.fontSize='.68rem';sm.style.whiteSpace='normal';sm.style.overflow='visible';sm.style.textOverflow='clip';}
        }
      });
    }
    document.querySelectorAll('.perm-btn').forEach(b=>b.classList.remove('sel'));
    if(selType==='M') document.getElementById('perm-m').classList.add('sel');
    if(selType==='AM')document.getElementById('perm-am').classList.add('sel');
    if(selType==='IM') document.getElementById('perm-indispo-m').classList.add('sel');
    if(selType==='IAM')document.getElementById('perm-indispo-am').classList.add('sel');
    if(selType==='IJ') document.getElementById('perm-indispo-j').classList.add('sel');
    btnDel.style.display='';btnDel.disabled=curPermId===0;btnSave.disabled=!selType;

  } else {
    modalHead.className='modal-head';modalHead.style.background='';
    mCycle.className='modal-cycle';mCycle.style.background='';mCycle.style.borderLeftColor='';
    mCycle.innerHTML=cycle?'Cycle : <strong>'+cycle+'</strong>':'';
    hideSections();secConge.style.display='block';
    inpDebut.value=date;inpFin.value=date;inpFin.disabled=false;rangeInfo.style.display='none';
  ensureQuotaCache(); // Précharger quota en arrière-plan
    // Récupérer la période existante si congé déjà posé, sinon J par défaut
    const defaultPer=(groupe==='nuit')?'NUIT':'J';
    selPer = td.dataset.congePer||defaultPer;
    if(inpHeure) inpHeure.value=td.dataset.congeHeure||'';
    document.getElementById('sec-periode').style.display=GRP_DEMI.includes(groupe)?'block':'none';
    buildGrid(groupe);buildPeriode();
    // Non-admin : bandeau informatif prévisionnel
    const prevInfoId='prev-info-banner';
    let prevBanner=document.getElementById(prevInfoId);
    const GRP_NO_PREV_BANNER=['gie','gie_j','lcl_parent','adc_lambert'];
    const _isCoGeRousselBanner=(typeof MY_AGENT!=='undefined'&&MY_AGENT==='CoGe ROUSSEL');
    if(!IS_ADMIN && !IS_DOUANE && !_isCoGeRousselBanner && !GRP_NO_PREV_BANNER.includes(groupe)){
      if(!prevBanner){
        prevBanner=document.createElement('div');
        prevBanner.id=prevInfoId;
        prevBanner.style.cssText='background:#fff3e0;border:1.5px solid #fb8c00;border-radius:7px;padding:7px 10px;margin-bottom:10px;font-size:.75rem;color:#7a3e00;font-weight:600';
        prevBanner.innerHTML='📋 Vous pouvez poser un <strong>prévisionnel de congé</strong>. L\u2019administrateur devra valider la demande.';
        secConge.insertBefore(prevBanner,secConge.firstChild);
      }
      prevBanner.style.display='block';
    } else {
      if(prevBanner) prevBanner.style.display='none';
    }
    // Section override vacation
    const vacId=parseInt(curTd.dataset.vacId)||0;
    const curVac=curTd.dataset.cycle||'';
    buildVacOverrideSection(groupe, curVac, vacId);
    btnDel.style.display='';btnDel.disabled=curCongeId===0;btnSave.disabled=!selType;
  }
  overlay.classList.add('open'); _modalOpen=true;
}

/* ── MODAL TIR ── */
function openTirModal(td,date,groupe,cycle,hasTir){
  const[y,mo,d]=date.split('-');
  const dt=new Date(date+'T00:00:00');
  mAgent.textContent=td.dataset.agent;
  document.getElementById('m-groupe').textContent='TIR';
  mDate.textContent=JL[dt.getDay()||7]+' '+parseInt(d)+' '+ML[parseInt(mo)]+' '+y;
  modalHead.className='modal-head';modalHead.style.background='#1565c0';
  mCycle.className='modal-cycle';mCycle.style.background='#e3f0ff';mCycle.style.borderLeftColor='#1565c0';
  const isNuit=(cycle==='NUIT');
  const isMatin=(cycle==='M');
  const isAM=(cycle==='AM');
  // Message contextuel
  mCycle.innerHTML=isNuit
    ?'S\u00e9ance de TIR \u2014 cycle : <strong>NUIT</strong><br><small style="color:#555">Indiquer NUIT si le fonctionnaire participe au TIR</small>'
    :'S\u00e9ance de TIR \u2014 cycle : <strong>'+(cycle||'J')+'</strong>';
  hideSections();secTir.style.display='block';
  // Largeur modale et style boutons TIR uniformisés
  document.querySelector('.modal').style.width='780px';
  const tirGrid=document.getElementById('tir-grid');
  if(tirGrid){
    tirGrid.style.gridTemplateColumns='1fr 1fr';
    ['tir-m','tir-am','tir-j','tir-nuit'].forEach(id=>{
      const el=document.getElementById(id);
      if(el){
        el.style.padding='10px 4px';el.style.fontSize='.82rem';
        const sm=el.querySelector('small');
        if(sm){sm.style.fontSize='.68rem';sm.style.whiteSpace='normal';sm.style.overflow='visible';sm.style.textOverflow='clip';}
      }
    });
  }
  // Masquer le bouton bleu "Enregistrer une séance de TIR" — inutile dans le modal TIR
  const tirFromCongeBtnEl=document.getElementById('btn-open-tir-from-conge');
  if(tirFromCongeBtnEl) tirFromCongeBtnEl.style.display='none';
  // Boutons : afficher uniquement ceux pertinents selon le cycle
  document.getElementById('tir-m').style.display   =isNuit||isAM?'none':'block';
  document.getElementById('tir-am').style.display  =isNuit||isMatin?'none':'block';
  document.getElementById('tir-j').style.display   ='none';
  document.getElementById('tir-nuit').style.display=isNuit?'block':'none';
  // Pour les équipes : pré-sélectionner automatiquement leur période de cycle
  if((isMatin||isAM)&&!hasTir){
    selPer=isMatin?'M':'AM';
    document.querySelectorAll('#sec-tir .perm-btn').forEach(b=>b.classList.remove('sel'));
    const autoBtn=document.getElementById(isMatin?'tir-m':'tir-am');
    if(autoBtn) autoBtn.classList.add('sel');
    btnSave.disabled=false;
  }
  const tirBtnConge=document.getElementById('btn-tir-to-conge');
  const hasCongeAlso=curCongeId>0;
  const isCombine=AGENTS_TIR_COMBINE.includes(td.dataset.agent);
  if(tirBtnConge){
    // Bouton congé visible uniquement pour les agents "combinaison possible"
    tirBtnConge.style.display=(CAN_TIR&&hasTir&&isCombine)?'inline-block':'none';
    tirBtnConge.textContent=hasCongeAlso?'📝 Modifier le congé associé':'📝 Poser un congé';
    tirBtnConge.onclick=()=>{closeModal();modeTir=false;modePerm=false;openCongeModal(td,date,groupe,cycle,false);};
  }
  const curPer=td.dataset.tirPer||'';
  document.querySelectorAll('#sec-tir .perm-btn').forEach(b=>b.classList.remove('sel'));
  if(curPer==='M')    document.getElementById('tir-m').classList.add('sel');
  if(curPer==='AM')   document.getElementById('tir-am').classList.add('sel');
  if(curPer==='J')    document.getElementById('tir-j').classList.add('sel');
  if(curPer==='NUIT') document.getElementById('tir-nuit').classList.add('sel');
  selPer=curPer||'';
  document.getElementById('tir-del').style.display=hasTir?'block':'none';
  btnDel.style.display='none';btnSave.disabled=!selPer;

  // ── Formulaire annulation ──
  const secAnnul=document.getElementById('sec-tir-annul');
  const hasAnnul=curAnnulId>0;
  // Afficher si agent TIR autorisé (qu'il y ait un TIR ou non)
  const agentTirOk=<?=json_encode(array_values($agentsTir),JSON_UNESCAPED_UNICODE)?>.includes(td.dataset.agent);
  secAnnul.style.display=agentTirOk?'block':'none';
  document.getElementById('btn-del-annul').style.display=hasAnnul?'block':'none';
  // Pré-sélectionner motif standard par défaut
  document.querySelectorAll('[data-motif]').forEach(b=>b.classList.remove('sel'));
  document.getElementById('annul-motif-stand').classList.add('sel');
  document.getElementById('annul-motif-libre').style.display='none';
  document.getElementById('annul-motif-libre').value='';

  overlay.classList.add('open'); _modalOpen=true;
  if(tirBtnConge){
    tirBtnConge.onclick=(e)=>{ e.preventDefault();modeTir=false;modePerm=false;openCongeModal(td,date,groupe,cycle,false); };
  }
}


function buildGrid(g){
  codeGrid.innerHTML='';
  let types=TYPES_GROUPE[g]||TYPES_GROUPE['standard_j'];
  // Groupes spéciaux avec leurs propres types (GIE, Douane) — ne jamais filtrer leurs codes
  const GROUPES_SPECIAUX=['gie','gie_j','douane','douane_j','lcl_parent','adc_lambert'];
  // CoGe ROUSSEL : accès à tous ses types comme les groupes spéciaux (pas limité à PREV)
  const IS_COGE_ROUSSEL = (typeof MY_AGENT !== 'undefined' && MY_AGENT === 'CoGe ROUSSEL');
  if(IS_COGE_ROUSSEL) GROUPES_SPECIAUX.push('direction_police');
  if(GROUPES_SPECIAUX.includes(g)){
    // Groupes spéciaux : afficher TOUS leurs codes tels quels, admin ou non
    // Layout adapté selon le nombre de codes
    if(g==='lcl_parent'||g==='adc_lambert'){
      // 1 ou 2 codes seulement (P, ou P+R) → 2 colonnes compactes
      codeGrid.style.gridTemplateColumns='repeat(2,1fr)';
      document.querySelector('.modal').style.width='400px';
    } else if(g==='direction_police'){
      // CoGe ROUSSEL : même layout que l'admin (6 colonnes, codes réordonnés)
      const ordered=ADMIN_CONGE_CODES
        .map(code=>{
          if(code==='__EMPTY__') return null;
          return types.find(t=>t.code===code) || ALL_TYPES_CONGES.find(t=>t.code===code) || undefined;
        })
        .filter(t=>t!==undefined);
      types=ordered;
      codeGrid.style.gridTemplateColumns='repeat(6,1fr)';
      document.querySelector('.modal').style.width='780px';
    } else {
      codeGrid.style.gridTemplateColumns='repeat(3,1fr)';
      document.querySelector('.modal').style.width='500px';
    }
  } else if(!IS_ADMIN){
    // Non-admin, groupe standard/police : uniquement PREV (prévisionnel)
    // Cherche PREV dans les types du groupe, sinon dans ALL_TYPES_CONGES
    const ordered=USER_ALLOWED_CODES
      .map(code=>types.find(t=>t.code===code) || ALL_TYPES_CONGES.find(t=>t.code===code))
      .filter(Boolean);
    types=ordered;
    codeGrid.style.gridTemplateColumns='repeat(1,1fr)';
    document.querySelector('.modal').style.width='420px';
  } else {
    // Admin, groupe standard/police : réordonner sur 3 lignes avec ADMIN_CONGE_CODES
    // Cherche d'abord dans le groupe, sinon dans ALL_TYPES_CONGES (ex: CMO/CLM/CLD)
    const ordered=ADMIN_CONGE_CODES
      .map(code=>{
        if(code==='__EMPTY__') return null;
        return types.find(t=>t.code===code) || ALL_TYPES_CONGES.find(t=>t.code===code) || undefined;
      })
      .filter(t=>t!==undefined); // garde les null (cellule vide) et les trouvés
    types=ordered;
    codeGrid.style.gridTemplateColumns='repeat(6,1fr)';
    document.querySelector('.modal').style.width='780px';
  }
  types.forEach(t=>{
    if(t===null){
      // Cellule vide pour compléter la grille
      const empty=document.createElement('div');
      codeGrid.appendChild(empty);
      return;
    }
    const btn=document.createElement('button');
    btn.type='button'; // Empêche le comportement "submit" par défaut
    btn.className='code-btn'+(selType===t.code?' sel':'');
    btn.style.cssText='background:'+t.couleur_bg+';color:'+t.couleur_txt
      +';padding:10px 4px;font-size:.82rem';
    btn.innerHTML=t.code+'<small style="font-size:.68rem;white-space:normal;overflow:visible;text-overflow:clip">'
      +t.libelle+'</small>';
    btn.addEventListener('click',()=>{
      selType=t.code;
      document.querySelectorAll('.code-btn').forEach(b=>b.classList.remove('sel'));
      btn.classList.add('sel');btnSave.disabled=false;
      // Afficher champ heure si DA ou PR
      const secH=document.getElementById('sec-heure');
      if(secH) secH.style.display=['DA','PR'].includes(t.code)?'block':'none';
      // Types forcément mono-jour : HP, HPA, DA, PR → forcer inpFin = inpDebut
      const monoJour=['HP','HPA','DA','PR'];
      if(monoJour.includes(t.code)){
        inpFin.value=inpDebut.value;
        inpFin.disabled=true;
      } else {
        inpFin.disabled=false;
      }
      // Rafraîchir info quota selon le type sélectionné
      updateRangeInfo();
    });
    codeGrid.appendChild(btn);
  });
  // Vérifier si le type déjà sélectionné nécessite l'heure
  const secH=document.getElementById('sec-heure');
  if(secH) secH.style.display=['DA','PR'].includes(selType)?'block':'none';
  // Si un type est déjà sélectionné (congé existant), activer Save immédiatement
  if(selType) btnSave.disabled=false;
}
function buildPeriode(){
  const container=document.getElementById('options-periode');
  const groupe=curTd?curTd.dataset.groupe||'standard_j':'standard_j';
  const isNuit=(groupe==='nuit');
  // Nuiteux : Nuit uniquement (M/AM/J n'ont pas de sens pour un congé de nuit)
  // La modification ponctuelle de vacation est gérée par la section dédiée
  // Autres : J/M/AM
  let btns;
  if(isNuit){
    btns=[
      {val:'NUIT', label:'🌙 Nuit', style:'background:#1a2742;color:#ffd600'},
    ];
  } else {
    btns=[
      {val:'J',  label:'🕐 Journée',    style:''},
      {val:'M',  label:'☀ Matin',      style:''},
      {val:'AM', label:'☽ Après-midi', style:''},
    ];
  }
  container.innerHTML='';
  btns.forEach(b=>{
    const btn=document.createElement('button');
    btn.type='button';btn.className='opt-btn';btn.dataset.val=b.val;
    btn.innerHTML=b.label;
    if(b.style) btn.style.cssText=b.style;
    if(b.val===selPer) btn.classList.add('sel');
    btn.addEventListener('click',()=>{
      selPer=b.val;
      container.querySelectorAll('.opt-btn').forEach(x=>x.classList.remove('sel'));
      btn.classList.add('sel');
    });
    container.appendChild(btn);
  });
}

function closeModal(){
  overlay.classList.remove('open');
  modalHead.style.background='';
  mCycle.style.background='';mCycle.style.borderLeftColor='';
  btnDel.style.display='';
  _modalOpen=false;
}

// ── Vacation Override ──
const secVacOverride = document.getElementById('sec-vac-override');
const vacBtnsWrap    = document.getElementById('vac-btns');
const btnDelVac      = document.getElementById('btn-del-vac');

// Groupes pour lesquels on affiche la section override
const GROUPES_VAC_OVERRIDE = ['equipe','nuit','standard_police','direction_police','direction_mokadem'];

// Vacations disponibles par groupe
function getVacOptions(groupe){
  // Nuiteux : Nuit en premier (valeur par défaut), puis les autres
  if(groupe==='nuit') return [
    {val:'NUIT',label:'🌙 Nuit',    bg:'#1a2742',col:'#ffd600'},
    {val:'M',   label:'☀ Matin',   bg:'#e3f0ff',col:'#1565c0'},
    {val:'AM',  label:'☽ AM',      bg:'#1565c0',col:'#fff'},
    {val:'J',   label:'🕐 Journée',bg:'#e8f5e9',col:'#1a6632'},
  ];
  // Tous les autres policiers (équipes, standard, direction) : J/M/AM/Nuit
  return [
    {val:'J',   label:'🕐 Journée',bg:'#e8f5e9',col:'#1a6632'},
    {val:'M',   label:'☀ Matin',   bg:'#e3f0ff',col:'#1565c0'},
    {val:'AM',  label:'☽ AM',      bg:'#1565c0',col:'#fff'},
    {val:'NUIT',label:'🌙 Nuit',    bg:'#1a2742',col:'#ffd600'},
  ];
}

function buildVacOverrideSection(groupe, currentVac, vacId){
  // Section vacation réservée à l'admin
  if(!IS_ADMIN){ secVacOverride.style.display='none'; return; }
  if(!GROUPES_VAC_OVERRIDE.includes(groupe)){
    secVacOverride.style.display='none';
    // Rétablir la largeur par défaut si pas de vacation
    const modal=document.querySelector('.modal');
    if(modal && !IS_ADMIN) modal.style.width='420px';
    return;
  }
  secVacOverride.style.display='block';
  // Élargir la modal pour que les boutons vacation tiennent sur une ligne
  const modal=document.querySelector('.modal');
  if(modal){
    const opts=getVacOptions(groupe);
    // 4 boutons → ~660px minimum pour tenir sur une ligne
    modal.style.width=Math.max(parseInt(modal.style.width)||500, opts.length<=3?520:660)+'px';
  }
  vacBtnsWrap.innerHTML='';
  const opts=getVacOptions(groupe);
  opts.forEach(o=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.style.cssText=`padding:5px 10px;border:2px solid ${currentVac===o.val?'#f9a825':'#ddd'};border-radius:6px;background:${o.bg};color:${o.col};font-size:.75rem;font-weight:700;cursor:pointer`;
    btn.innerHTML=o.label;
    btn.dataset.val=o.val;
    if(currentVac===o.val) btn.style.boxShadow='0 0 0 2px #f9a825';
    btn.addEventListener('click',async()=>{
      // Sauvegarder l'override
      const r=await ajax({action:'save_vacation_override',
        agent:curTd.dataset.agent,
        date:curTd.dataset.date,
        vacation:o.val});
      if(r.ok){
        // Recharger la cellule via get_cell
        const rc=await ajax({action:'get_cell',agents:JSON.stringify([r.agent]),dates:JSON.stringify([r.date])});
        if(rc.ok) applyCells(rc);
        showToast('Vacation modifiée → '+o.val);
        closeModal();
      } else showToast('Erreur : '+(r.msg||'?'),false);
    });
    vacBtnsWrap.appendChild(btn);
  });
  // Bouton rétablir si override active
  btnDelVac.style.display=vacId>0?'block':'none';
  btnDelVac.dataset.vacId=vacId;
}

btnDelVac.addEventListener('click',async()=>{
  const vid=parseInt(btnDelVac.dataset.vacId)||0;
  if(!vid) return;
  const r=await ajax({action:'delete_vacation_override',vac_id:vid});
  if(r.ok){
    const rc=await ajax({action:'get_cell',agents:JSON.stringify([r.agent]),dates:JSON.stringify([r.date])});
    if(rc.ok) applyCells(rc);
    showToast('Cycle d\u2019origine r\u00e9tabli');
    closeModal();
  } else showToast('Erreur',false);
});
document.getElementById('btn-x').addEventListener('click',closeModal);
document.getElementById('btn-cancel').addEventListener('click',closeModal);
overlay.addEventListener('click',e=>{if(e.target===overlay)closeModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closePerm();}});

async function ajax(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data))fd.append(k,v);
  const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
  return r.json();
}

const CC={J:'j',M:'m',AM:'am',NUIT:'nuit',RC:'rc',RL:'rl',FERIE:'ferie'};

// Restaure une cellule à son cycle d'origine après suppression d'une permanence
function restoreCell(){
  if(!curTd)return;
  const co=curTd.dataset.cycleOrig||curTd.dataset.cycle||'';
  const masque=(curTd.dataset.masque==='1');
  curTd.removeAttribute('style');
  if(co==='FERIE'){
    curTd.className='ferie perm-ok';
    curTd.textContent='FERIE';
  } else if(masque){
    curTd.className='rc-masque perm-ok';
    curTd.textContent='';
  } else if(co==='RC'||co==='RL'){
    // Week-end : fond blanc (rc-masque) après suppression permanence
    curTd.className='rc-masque perm-ok';
    curTd.textContent='';
  } else {
    curTd.className=(CC[co]||'j')+' perm-ok';
    curTd.textContent=co;
  }
  curTd.dataset.permId='0';
  curTd.dataset.permType='';
  curTd.dataset.congeType='';
}

// Restaure une cellule à son cycle brut (après suppression TIR ou congé)
function restoreCellCycle(td,cyc){
  const CC2={J:'j',M:'m',AM:'am',NUIT:'nuit',RC:'rc',RL:'rl',FERIE:'ferie'};
  const tirElig=['J','M','AM','NUIT'].includes(cyc);
  const permElig=(td.dataset.masque==='1')||cyc==='RC'||cyc==='RL'||cyc==='FERIE';
  td.removeAttribute('style');
  // Si un congé existe toujours sur cette cellule, le restaurer
  const congeType=td.dataset.congeType||'';
  const congeId=parseInt(td.dataset.congeId)||0;
  if(congeType&&congeId>0){
    const per=td.dataset.congePer||'J';
    const heure=td.dataset.congeHeure||'';
    const sym=per==='M'?'☀':per==='AM'?'🌙':'';
    const hDisp=heure?' '+heure.replace(':','h'):'';
    td.className='conge';
    td.innerHTML=congeType+hDisp+sym;
    return;
  }
  let cls=CC2[cyc]||'j';
  if(tirElig) cls+=' tir-ok';
  if(permElig) cls+=' perm-ok';
  td.className=cls;
  if(cyc==='FERIE') td.textContent='FERIE';
  else if(td.dataset.masque==='1') td.textContent='';
  else td.textContent=cyc;
  td.title='';
}

function showToast(msg,ok=true){
  const t=document.getElementById('toast');
  t.textContent=msg;t.style.background=ok?'#1a6632':'#c0392b';
  t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2800);
}

// Boutons TIR période
['tir-m','tir-am','tir-j','tir-nuit'].forEach(id=>{
  document.getElementById(id).addEventListener('click',()=>{
    selPer=id==='tir-m'?'M':id==='tir-am'?'AM':'NUIT';
    document.querySelectorAll('#sec-tir .perm-btn').forEach(b=>b.classList.remove('sel'));
    document.getElementById(id).classList.add('sel');
    btnSave.disabled=false;
  });
});
document.getElementById('tir-del').addEventListener('click',async()=>{
  if(!curTd||curTirId===0){closeModal();return;}
  if(!confirm('Supprimer le TIR de '+curTd.dataset.agent+' ?'))return;
  try{
    const r=await ajax({action:'delete_tir',tir_id:curTirId});
    if(r.ok){
      applyCells(r);showToast('TIR supprimé');closeModal();
    } else showToast('Erreur : '+r.msg,false);
  }catch(e){showToast('Erreur réseau',false);}
});

// ── Handlers annulation TIR ──
document.querySelectorAll('[data-motif]').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('[data-motif]').forEach(b=>b.classList.remove('sel'));
    btn.classList.add('sel');
    const libre=document.getElementById('annul-motif-libre');
    libre.style.display=(btn.id==='annul-motif-autre')?'block':'none';
  });
});

document.getElementById('btn-save-annul').addEventListener('click',async()=>{
  if(!curTd) return;
  const selMotifBtn=document.querySelector('[data-motif].sel');
  let motif=selMotifBtn?selMotifBtn.dataset.motif:'Indisponibilité stand';
  if(selMotifBtn&&selMotifBtn.id==='annul-motif-autre'){
    motif=document.getElementById('annul-motif-libre').value.trim()||'Autre';
  }
  try{
    const r=await ajax({action:'save_tir_annul',agent:curTd.dataset.agent,
                        date_debut:curTd.dataset.date,motif});
    if(r.ok){
      document.getElementById('btn-del-annul').style.display='block';
      applyCells(r);showToast('Annulation enregistrée');closeModal();
    } else showToast('Erreur : '+r.msg,false);
  }catch(e){showToast('Erreur réseau',false);}
});

document.getElementById('btn-del-annul').addEventListener('click',async()=>{
  if(!curAnnulId) return;
  if(!confirm('Supprimer cette annulation ?')) return;
  try{
    const r=await ajax({action:'delete_tir_annul',annul_id:curAnnulId});
    if(r.ok){
      document.getElementById('btn-del-annul').style.display='none';
      applyCells(r);showToast('Annulation supprimée');closeModal();
    } else showToast('Erreur : '+r.msg,false);
  }catch(e){showToast('Erreur réseau',false);}
});

// Boutons permanence
['perm-m','perm-am','perm-indispo-m','perm-indispo-am','perm-indispo-j'].forEach(id=>{
  document.getElementById(id).addEventListener('click',()=>{
    const map={'perm-m':'M','perm-am':'AM','perm-indispo-m':'IM','perm-indispo-am':'IAM','perm-indispo-j':'IJ'};
    selType=map[id];
    document.querySelectorAll('.perm-btn').forEach(b=>b.classList.remove('sel'));
    document.getElementById(id).classList.add('sel');
    btnSave.disabled=false;
  });
});

// Sauvegarder
btnSave.addEventListener('click',async()=>{
  if(!curTd)return;
  if(modeTir&&!selPer){showToast('Choisir une période',false);return;}
  if(!modeTir&&!selType){return;}
  btnSave.disabled=true;
  try{
    let r;
    if(modeTir){
      r=await ajax({action:'save_tir',agent:curTd.dataset.agent,
        date_debut:curTd.dataset.date,periode:selPer,tir_id:curTirId});
      if(r.ok){
        applyCells(r);showToast('TIR '+selPer+' enregistré');closeModal();
      } else {showToast('Erreur : '+r.msg,false);btnSave.disabled=false;}
    } else if(modePerm){
      r=await ajax({action:'save_perm',agent:curTd.dataset.agent,
        date_debut:curTd.dataset.date,type:selType,
        cycle_orig:curCycleOrig,perm_id:curPermId});
      if(r.ok){
        applyCells(r);showToast('Permanence enregistrée');closeModal();
      } else {showToast('Erreur : '+r.msg,false);btnSave.disabled=false;}
    } else {
      const d1=inpDebut.value;
      // Si la cellule a un TIR, le congé ne peut couvrir qu'un seul jour
      const d2=curTd.dataset.tirId&&curTd.dataset.tirId!=='0' ? d1 : (inpFin.value||d1);
      if(!d1){showToast('Date manquante',false);btnSave.disabled=false;return;}
      // Vérification quota par type avant sauvegarde
      {
        const ag=curTd.dataset.agent;
        await ensureQuotaCache();
        const agQuotas=_quotaModalCache.quotas[ag];
        if(agQuotas && agQuotas[selType] && agQuotas[selType].unite==='jours'){
          const quota=agQuotas[selType].quota;
          const used=(_quotaModalCache.counts[ag]||{})[selType]||0;
          const debut=new Date(d1+'T00:00:00');
          const fin2=new Date(d2+'T00:00:00');
          let newDays=0;
          for(let dd=new Date(debut);dd<=fin2;dd.setDate(dd.getDate()+1)){
            const dow=dd.getDay();if(dow!==0&&dow!==6)newDays++;
          }
          const total=used+newDays;
          if(total>quota){
            const ok=confirm(`⚠️ ALERTE QUOTA — ${ag} — ${selType}

Quota autorisé : ${quota} j
Déjà posés : ${used} j
Ce congé : ~${newDays} j
Total après ajout : ${total} j

Le quota ${selType} sera DÉPASSÉ de ${total-quota} jour(s).
Voulez-vous quand même enregistrer ?`);
            if(!ok){btnSave.disabled=false;return;}
          }
        }
      }
      r=await ajax({action:'save_conge',agent:curTd.dataset.agent,
        date_debut:d1,date_fin:d2,type:selType,periode:selPer,
        heure:(['DA','PR'].includes(selType)&&inpHeure)?inpHeure.value:'',
        conge_id:curCongeId});
      if(r.ok){
        // Rafraîchir toutes les cellules de la plage
        const dts=[];
        let cur=new Date(d1+'T00:00:00');
        const end=new Date(d2+'T00:00:00');
        while(cur<=end){
          dts.push(cur.toISOString().split('T')[0]);
          cur.setDate(cur.getDate()+1);
        }
        invalidateQuotaCache();
        applyCells(r);showToast('Congé '+selType+' sauvegardé');closeModal();
      } else {showToast('Erreur : '+r.msg,false);btnSave.disabled=false;}
    }
  }catch(e){showToast('Erreur réseau',false);btnSave.disabled=false;}
});

// Supprimer
btnDel.addEventListener('click',async()=>{
  if(!curTd)return;
  const lbl=modePerm?'la permanence':'le congé';
  if(!confirm('Supprimer '+lbl+' de '+curTd.dataset.agent+' ?'))return;
  btnDel.disabled=true;
  try{
    const r=modePerm
      ? await ajax({action:'delete_perm',perm_id:curPermId,perm_type:curTd.dataset.permType||'',cycle_orig:curTd.dataset.cycleOrig||curTd.dataset.cycle||'',agent:curTd.dataset.agent,date_debut:curTd.dataset.date})
      : await ajax({action:'delete_conge',conge_id:curCongeId,agent:curTd.dataset.agent,date_debut:curTd.dataset.date});
    if(r.ok){
      if(!modePerm) invalidateQuotaCache();
      applyCells(r);showToast(modePerm?'Permanence supprimée':'Congé supprimé');closeModal();
    } else {showToast('Erreur : '+r.msg,false);btnDel.disabled=false;}
  }catch(e){showToast('Erreur réseau',false);btnDel.disabled=false;}
});

// Clic cellule
document.addEventListener('click',e=>{
  const td=e.target.closest('td[data-date]');
  if(!td)return;
  openModal(td);
});

/* ══ COMPTEUR PERMANENCES ══ */
const overlayPerm=document.getElementById('overlay-perm');
const mpBody=document.getElementById('mp-body');
let permMoisActif=0; // 0 = vue annuelle

const sections={
  'EQUIPE 1':['BC BOUXOM','BC ARNAULT','BC HOCHARD'],
  'EQUIPE 2':['BC DUPUIS','BC BASTIEN','BC ANTHONY'],
  'GIE'     :['ADJ LEFEBVRE','ADJ CORRARD'],
  'ANALYSE' :['GP DHALLEWYN','BC DELCROIX'],
  'INFORMATIQUE'   :['BC DRUEZ'],
};

// Colonnes de base : Matin / AM / Férié / Nuit (RC+RL confondus)
// Les clés correspondent aux keys 'RC_M','RC_AM','RL_M','RL_AM','FERIE_M','FERIE_AM'
const ML3=['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
const ML4=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// Calcul des totaux par agent à partir des données brutes
function getPermTotaux(d){
  const matin   = (d['RC_M']   ||0)+(d['RL_M']  ||0);
  const am      = (d['RC_AM']  ||0)+(d['RL_AM'] ||0);
  const ferie   = (d['FERIE_M']||0)+(d['FERIE_AM']||0);
  const nuit    = (d['RC_N']   ||0)+(d['RL_N']  ||0); // future-proof
  const hferie  = matin + am + nuit;
  const total   = hferie + ferie;
  return {matin, am, ferie, nuit, hferie, total};
}

function buildPermTableau(data, mo){
  const getD=(ag)=> mo>0 ? (data[ag]?.mois?.[mo]||{}) : (data[ag]||{});

  // ─── Titre ───
  const titre = mo>0 ? `${ML4[mo]} ${ANNEE}` : `Année ${ANNEE} — toutes permanences`;

  let html=`<p style="font-size:.78rem;font-weight:700;color:#1565c0;margin-bottom:10px">${titre}</p>`;

  // ─── Tableau principal style capture ───
  html+=`<div style="overflow-x:auto">
  <table style="border-collapse:collapse;font-size:12px;width:100%">
  <thead>
    <tr style="background:#253560;color:#ffd600;text-align:center">
      <th style="text-align:left;padding:6px 10px;min-width:130px">Agent</th>
      <th style="padding:6px 10px;background:#1a6b3c;color:#fff">Matin</th>
      <th style="padding:6px 10px;background:#2f5597;color:#fff">AM</th>
      <th style="padding:6px 10px;background:#7a5800;color:#fff">Férié</th>
      <th style="padding:6px 10px;background:#6a1b9a;color:#fff">Nuit</th>
      <th style="padding:6px 4px;background:#1a5276;color:#fff;font-size:10px">Hors<br>Férié</th>
      <th style="padding:6px 4px;background:#6e3b00;color:#fff;font-size:10px">Total<br>Férié</th>
      <th style="padding:6px 10px;background:#c0392b;color:#fff;font-size:14px;font-weight:800">TOTAL</th>
    </tr>
  </thead>
  <tbody>`;

  const totG={matin:0,am:0,ferie:0,nuit:0,hferie:0,total:0};

  // Déterminer la section de MY_AGENT pour le highlight
  const myPermSection=Object.entries(sections).find(([,ags])=>ags.includes(MY_AGENT))?.[0]||null;

  Object.entries(sections).forEach(([lbl,agents])=>{
    const isMyPermSection=MY_AGENT && lbl===myPermSection;
    const secBg=isMyPermSection?'#1a4f8a':'#1a2742';
    html+=`<tr style="background:${secBg}">
      <td colspan="8" style="padding:4px 10px;font-size:10.5px;font-weight:700;color:#ffd600;letter-spacing:.05em">${lbl}${isMyPermSection?'<span style="font-size:9px;margin-left:6px;background:#ffd600;color:#1a2742;padding:1px 5px;border-radius:3px">MON ÉQUIPE</span>':''}</td>
    </tr>`;

    agents.forEach((ag,i)=>{
      const d=getD(ag);
      const t=getPermTotaux(d);
      totG.matin+=t.matin; totG.am+=t.am; totG.ferie+=t.ferie;
      totG.nuit+=t.nuit; totG.hferie+=t.hferie; totG.total+=t.total;

      const isMe=MY_AGENT && ag===MY_AGENT;
      const allZero=t.total===0;
      const rowBg=isMe?'#fffde7':allZero?'#f0f0f0':i%2===0?'#f5f9ff':'#fff';
      // Couleur total
      const totCol=t.total===0?'#bbb':t.total>=20?'#c0392b':t.total>=15?'#e67e22':'#27ae60';
      const hfCol=t.hferie===0?'#bbb':'#1a5276';
      const feCol=t.ferie===0?'#bbb':'#7a5800';

      html+=`<tr style="background:${rowBg};${isMe?'outline:2px solid #f9a825;outline-offset:-2px;':''}">
        <td style="padding:5px 10px;font-weight:700;color:${isMe?'#e65100':allZero?'#999':'#1a2742'}${isMe?';background:#fff8e1':''}">${isMe?'👤 ':''}${ag}</td>
        <td style="text-align:center;padding:5px 8px;background:${t.matin>0?'#d5f0e0':'transparent'};font-weight:${t.matin>0?'700':'400'};color:${t.matin>0?'#1a6b3c':'#bbb'}">${t.matin||'0'}</td>
        <td style="text-align:center;padding:5px 8px;background:${t.am>0?'#d9e8ff':'transparent'};font-weight:${t.am>0?'700':'400'};color:${t.am>0?'#2f5597':'#bbb'}">${t.am||'0'}</td>
        <td style="text-align:center;padding:5px 8px;background:${t.ferie>0?'#fff3cc':'transparent'};font-weight:${t.ferie>0?'700':'400'};color:${t.ferie>0?'#7a5800':'#bbb'}">${t.ferie||'0'}</td>
        <td style="text-align:center;padding:5px 8px;background:${t.nuit>0?'#ede8ff':'transparent'};font-weight:${t.nuit>0?'700':'400'};color:${t.nuit>0?'#6a1b9a':'#bbb'}">${t.nuit||'0'}</td>
        <td style="text-align:center;padding:5px 8px;font-weight:700;color:${hfCol};background:#eef5fb">${t.hferie||'—'}</td>
        <td style="text-align:center;padding:5px 8px;font-weight:700;color:${feCol};background:#fdf8ec">${t.ferie>0?t.ferie:'—'}</td>
        <td style="text-align:center;padding:5px 10px;font-weight:800;font-size:15px;color:${totCol};background:#fff0f0">${t.total||'—'}</td>
      </tr>`;
    });
  });

  // Ligne TOTAL général
  const totTotCol=totG.total===0?'#bbb':'#c0392b';
  html+=`<tr style="background:#1a2742;color:#fff;font-weight:800;font-size:14px">
    <td style="padding:6px 10px;color:#ffd600;font-size:15px">TOTAL</td>
    <td style="text-align:center;padding:6px 8px;color:#7dffb5">${totG.matin||'—'}</td>
    <td style="text-align:center;padding:6px 8px;color:#aac8ff">${totG.am||'—'}</td>
    <td style="text-align:center;padding:6px 8px;color:#ffe066">${totG.ferie||'—'}</td>
    <td style="text-align:center;padding:6px 8px;color:#d4aaff">${totG.nuit||'—'}</td>
    <td style="text-align:center;padding:6px 8px;color:#7fc8ff">${totG.hferie||'—'}</td>
    <td style="text-align:center;padding:6px 8px;color:#ffcc80">${totG.ferie||'—'}</td>
    <td style="text-align:center;padding:6px 10px;color:#ff6b6b;font-size:16px">${totG.total||'—'}</td>
  </tr>`;

  html+='</tbody></table></div>';

  // ─── Permanences passées (vue annuelle) ───
  if(mo===0){
    const today=new Date();
    today.setHours(0,0,0,0);
    const todayStr=today.toISOString().slice(0,10);
    // Calculer les perms passées et futures pour chaque agent
    const tousAgents2=Object.values(sections).flat();
    let passeeTotal=0,futureTotal=0;
    const passeeByAg={},futureByAg={};
    tousAgents2.forEach(ag=>{
      const d=data[ag]||{};
      let p=0,f=0;
      for(let m=1;m<=12;m++){
        const md=d.mois?.[m]||{};
        const t=getPermTotaux(md).total;
        // Construire date de fin du mois
        const finMois=new Date(today.getFullYear(),m,0); // dernier jour du mois
        const debutMois=new Date(today.getFullYear(),m-1,1);
        if(finMois<today){p+=t;}        // mois entier passé
        else if(debutMois>today){f+=t;} // mois entier futur
        else { p+=t; } // mois courant : on compte tout comme passé (approximation)
      }
      passeeByAg[ag]=p; futureByAg[ag]=f;
      passeeTotal+=p; futureTotal+=f;
    });

    // ── Bouton bascule helper ──
    const toggleBtn=(id,label,color)=>`<button onclick="(function(btn){const d=document.getElementById('${id}');const open=d.style.display!=='none';d.style.display=open?'none':'block';btn.querySelector('.tog-ico').textContent=open?'▶':'▼';})(this)"
      style="width:100%;display:flex;align-items:center;gap:8px;padding:7px 12px;margin:14px 0 0;background:${color}22;color:${color};border:1.5px solid ${color};border-radius:7px;font-weight:700;font-size:.78rem;cursor:pointer;text-align:left">
      <span class="tog-ico">▶</span>${label}</button>
    <div id="${id}" style="display:none;margin-top:6px">`;

    html+=toggleBtn('perm-passees-body','&#128337; Permanences passées ('+today.getFullYear()+')','#1a5276');
    html+='<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:11px;width:100%">';
    html+='<thead><tr style="background:#1a5276;color:#fff"><th style="padding:5px 10px;text-align:left">Agent</th>';
    html+='<th style="padding:5px 8px;text-align:center">Passées</th><th style="padding:5px 8px;text-align:center">À venir</th><th style="padding:5px 8px;text-align:center;background:#253560">Total</th></tr></thead><tbody>';
    tousAgents2.forEach((ag,i)=>{
      const p=passeeByAg[ag]||0;
      const f=futureByAg[ag]||0;
      const tot=p+f;
      const isMe2=MY_AGENT && ag===MY_AGENT;
      const rowBg=isMe2?'#fffde7':tot===0?'#f5f5f5':i%2?'#f0f4ff':'#fff';
      html+=`<tr style="background:${rowBg};${isMe2?'outline:2px solid #f9a825;outline-offset:-2px;':''}">
        <td style="padding:4px 10px;font-weight:${isMe2?'800':'600'};color:${isMe2?'#e65100':tot===0?'#aaa':'#1a2742'}${isMe2?';background:#fff8e1':''}">${isMe2?'👤 ':''}${ag}</td>
        <td style="text-align:center;padding:4px 8px;font-weight:${p>0?'700':'400'};color:${p>0?'#1a5276':'#bbb'}">${p>0?p:'—'}</td>
        <td style="text-align:center;padding:4px 8px;font-weight:${f>0?'700':'400'};color:${f>0?'#27ae60':'#bbb'}">${f>0?f:'—'}</td>
        <td style="text-align:center;padding:4px 10px;font-weight:700;background:#eef5fb;color:${tot>0?'#1a2742':'#bbb'}">${tot>0?tot:'—'}</td>
      </tr>`;
    });
    html+=`<tr style="background:#1a5276;color:#fff;font-weight:800">
      <td style="padding:5px 10px;color:#ffd600">TOTAL</td>
      <td style="text-align:center;padding:5px 8px">${passeeTotal||'—'}</td>
      <td style="text-align:center;padding:5px 8px;color:#7dffb5">${futureTotal||'—'}</td>
      <td style="text-align:center;padding:5px 10px;color:#ff6b6b">${(passeeTotal+futureTotal)||'—'}</td>
    </tr>`;
    html+='</tbody></table></div>';
    html+='</div>'; // fin perm-passees-body
  }

  // ─── Récap mensuel (vue annuelle seulement) ───
  if(mo===0){
    const toggleBtn2=(id,label,color)=>`<button onclick="(function(btn){const d=document.getElementById('${id}');const open=d.style.display!=='none';d.style.display=open?'none':'block';btn.querySelector('.tog-ico').textContent=open?'▶':'▼';})(this)"
      style="width:100%;display:flex;align-items:center;gap:8px;padding:7px 12px;margin:14px 0 0;background:${color}22;color:${color};border:1.5px solid ${color};border-radius:7px;font-weight:700;font-size:.78rem;cursor:pointer;text-align:left">
      <span class="tog-ico">▶</span>${label}</button>
    <div id="${id}" style="display:none;margin-top:6px">`;

    html+=toggleBtn2('perm-recap-body','Récapitulatif mensuel','#1565c0');
    html+='<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:11px;width:100%">';
    html+='<thead><tr style="background:#253560;color:#ffd600"><th style="padding:5px 10px;text-align:left">Mois</th>';
    const tousAgents=Object.values(sections).flat();
    tousAgents.forEach(ag=>html+=`<th style="padding:5px 8px;text-align:center;white-space:nowrap;${MY_AGENT&&ag===MY_AGENT?'background:#f9a825;color:#1a2742;':''}">${MY_AGENT&&ag===MY_AGENT?'👤 ':''}${ag.split(' ').pop()}</th>`);
    html+='<th style="padding:5px 8px;text-align:center;background:#c0392b">Total</th></tr></thead><tbody>';

    for(let m=1;m<=12;m++){
      let moisTot=0;
      const rowBg=m%2?'#f0f4ff':'#fff';
      html+=`<tr style="background:${rowBg}"><td style="padding:4px 10px;font-weight:700;color:#253560">${ML3[m]}</td>`;
      tousAgents.forEach(ag=>{
        const d=data[ag]?.mois?.[m]||{};
        const v=getPermTotaux(d).total;
        moisTot+=v;
        const isMeCol=MY_AGENT && ag===MY_AGENT;
        html+=`<td style="text-align:center;padding:4px 8px;${isMeCol?'background:#fff8e1;font-weight:700;color:#e65100;':v>0?'font-weight:700;color:#1565c0;':''}">${v>0?v:''}</td>`;
      });
      html+=`<td style="text-align:center;padding:4px 10px;font-weight:700;background:#e3f0ff;color:${moisTot>0?'#c0392b':'#aaa'}">${moisTot>0?moisTot:'—'}</td></tr>`;
    }
    html+='</tbody></table></div>';
    html+='</div>'; // fin perm-recap-body

  } else {
    // Détail des dates pour le mois sélectionné
    if(permMoisDetail && permMoisDetail.length>0){
      // Trier par date puis agent
      const sorted=[...permMoisDetail].sort((a,b)=>a.date.localeCompare(b.date)||a.agent.localeCompare(b.agent));
      const detailId='perm-detail-'+mo;
      html+=`<div style="margin-top:14px">
        <button onclick="(function(btn,id){const d=document.getElementById(id);const open=d.style.display!=='none';d.style.display=open?'none':'block';btn.innerHTML=open?'▶ Détail ${ML4[mo]} (${sorted.length} permanences)':'▼ Masquer le détail';})(this,'${detailId}')"
          style="width:100%;padding:7px 12px;background:#e3f0ff;color:#1565c0;border:1.5px solid #1565c0;border-radius:7px;font-weight:700;font-size:.78rem;cursor:pointer;text-align:left">
          ▶ Détail ${ML4[mo]} (${sorted.length} permanences)
        </button>
        <div id="${detailId}" style="display:none;margin-top:8px;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.75rem">
        <thead><tr style="background:#e3f0ff;color:#1565c0">
          <th style="padding:5px 8px;text-align:left">Date</th>
          <th style="padding:5px 8px;text-align:left">Agent</th>
          <th style="padding:5px 8px;text-align:center">Type</th>
          <th style="padding:5px 8px;text-align:center">Période</th>
        </tr></thead><tbody>`;
      const JL3=['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
      sorted.forEach((p,i)=>{
        const dt=new Date(p.date+'T00:00:00');
        const jl=JL3[dt.getDay()||7];
        const d=parseInt(p.date.split('-')[2]);
        const typLib={RC:'RC travaillé',RL:'RL travaillé',FERIE:'Férié travaillé'}[p.cycle_orig]||p.cycle_orig;
        const perLib2={M:'🔆 Matin',AM:'🌙 AM'}[p.type]||p.type;
        html+=`<tr style="background:${i%2?'#f7f8fa':'#fff'}">
          <td style="padding:4px 8px">${jl} ${d}</td>
          <td style="padding:4px 8px;font-weight:600">${p.agent}</td>
          <td style="padding:4px 8px;text-align:center">${typLib}</td>
          <td style="padding:4px 8px;text-align:center">${perLib2}</td></tr>`;
      });
      html+='</tbody></table></div></div>';
    } else {
      html+=`<p style="color:#888;font-size:.78rem;padding:6px 4px;margin-top:10px;font-style:italic">Aucune permanence en ${ML4[mo]} ${ANNEE}.</p>`;
    }
  }
  return html;
}

let permData=null, permMoisDetail=[];

async function loadPerm(mo){
  mpBody.innerHTML='<p style="text-align:center;color:#888;padding:20px">Chargement...</p>';
  try{
    const r=await ajax({action:'compteur_perm',annee:ANNEE,mois:mo});
    if(!r.ok){mpBody.innerHTML='<p style="color:red;padding:14px">Erreur</p>';return;}
    permData=r.data;
    permMoisDetail=r.mensuel||[];
    mpBody.innerHTML=buildPermTableau(permData,mo);
  }catch(e){mpBody.innerHTML='<p style="color:red;padding:14px">Erreur réseau</p>';}
}

const btnOpenPerm=document.getElementById('btn-open-perm');
if(btnOpenPerm) btnOpenPerm.addEventListener('click',()=>{
  overlayPerm.classList.add('open');
  // Activer le mois courant par défaut
  document.querySelectorAll('.perm-mois-btn').forEach(b=>{b.style.background='';b.style.color='';});
  document.getElementById('perm-btn-annuel').style.background='';
  document.getElementById('perm-btn-annuel').style.color='';
  const btnMoisCourant=document.querySelector(`.perm-mois-btn[data-mois="${MOIS}"]`);
  if(btnMoisCourant){btnMoisCourant.style.background='#1565c0';btnMoisCourant.style.color='#ffd600';}
  permMoisActif=MOIS;
  loadPerm(MOIS);
});

document.querySelectorAll('.perm-mois-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    permMoisActif=parseInt(btn.dataset.mois);
    document.querySelectorAll('.perm-mois-btn').forEach(b=>{b.style.background='';b.style.color='';});
    btn.style.background='#1565c0';btn.style.color='#ffd600';
    if(permMoisActif===0){
      document.getElementById('perm-btn-annuel').style.background='#1a2742';
      document.getElementById('perm-btn-annuel').style.color='#ffd600';
    }
    loadPerm(permMoisActif);
  });
});

function closePerm(){overlayPerm.classList.remove('open');}
document.getElementById('btn-x-perm').addEventListener('click',closePerm);
overlayPerm.addEventListener('click',e=>{if(e.target===overlayPerm)closePerm();});

/* ══ COMPTEUR TIR ══ */
const overlayTir=document.getElementById('overlay-tir');
const tirBody=document.getElementById('tir-body');

const tirAgents=<?=json_encode(array_values($agentsTir),JSON_UNESCAPED_UNICODE)?>;
const ML_TIR=['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const ML_TIR3=['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
const perLibTir={M:'🔆 Matin',AM:'🌙 AM',NUIT:'🌙 Nuit'};
const Q_DEF=[
  {num:1,label:'Q1 (Jan – Avr)',mois:[1,2,3,4]},
  {num:2,label:'Q2 (Mai – Août)',mois:[5,6,7,8]},
  {num:3,label:'Q3 (Sep – Déc)',mois:[9,10,11,12]},
];
const moisNowTir=(new Date()).getMonth()+1;
const qNow=moisNowTir<=4?1:moisNowTir<=8?2:3;

let tirDataCache=null;

const btnOpenTir=document.getElementById('btn-open-tir');
if(btnOpenTir) btnOpenTir.addEventListener('click',()=>{
  overlayTir.classList.add('open');
  loadTir();
});
document.getElementById('btn-x-tir').addEventListener('click',()=>overlayTir.classList.remove('open'));
overlayTir.addEventListener('click',e=>{if(e.target===overlayTir)overlayTir.classList.remove('open');});

async function loadTir(){
  tirBody.innerHTML='<p style="text-align:center;color:#888;padding:20px">Chargement...</p>';
  const r=await ajax({action:'compteur_tir',annee:ANNEE,mois:0,quad:0});
  if(!r.ok){tirBody.innerHTML='<p style="color:red;padding:14px">Erreur</p>';return;}
  tirBody.innerHTML=buildTirAnnuel(r);
}

/* ── Note TIR (admin) ── */
function openTirNote(agent, noteId){
  const existing=document.getElementById(noteId+'-txt');
  const currentNote=existing?existing.title:'';
  const val=prompt('Note pour '+agent+' :', currentNote);
  if(val===null) return; // annulé
  ajax({action:'save_tir_note',agent:agent,annee:ANNEE,note:val}).then(res=>{
    if(res&&res.ok){
      if(existing){
        existing.textContent=val||'—';
        existing.title=val;
        existing.style.fontStyle=val?'normal':'italic';
      }
    } else {
      alert('Erreur lors de la sauvegarde de la note.');
    }
  });
}

/* ── Vue annuelle : tableau mois × agents style capture ── */
function buildTirAnnuel(r){
  const quadri=r.quadri||{};
  const parMois=r.parMois||{};
  let html='';

  // Couleurs par quadrimestre (en-têtes)
  const qStyle=[
    null,
    {bg:'#1a2742',col:'#ffd600',label:'Q1 (Jan – Avr)'},
    {bg:'#1a2742',col:'#ffd600',label:'Q2 (Mai – Août)'},
    {bg:'#1a2742',col:'#ffd600',label:'Q3 (Sep – Déc)'},
  ];
  // Fond uniforme et sobre par quadrimestre — pas de dégradé perturbant
  const moisBg=[
    null,
    '#e8eef6','#e8eef6','#e8eef6','#e8eef6',   // Q1 : gris-bleu pâle
    '#e8f4ee','#e8f4ee','#e8f4ee','#e8f4ee',   // Q2 : gris-vert pâle
    '#f5ede8','#f5ede8','#f5ede8','#f5ede8',   // Q3 : gris-orange pâle
  ];
  // Couleur des textes de mois par quadrimestre
  const moisCol=[null,'#1565c0','#1565c0','#1565c0','#1565c0','#27ae60','#27ae60','#27ae60','#27ae60','#e65100','#e65100','#e65100','#e65100'];
  // Couleur de fond des cellules TIR effectué (compacte, lisible)
  const tirDoneBg=['','#1565c0','#1565c0','#1565c0','#1565c0','#2e7d32','#2e7d32','#2e7d32','#2e7d32','#bf360c','#bf360c','#bf360c','#bf360c'];

  html+=`<div style="overflow-x:auto">
  <table style="border-collapse:collapse;font-size:11.5px;width:100%">
  <thead>`;

  // Ligne 1 : en-têtes quadrimestres + TOTAL + Annulation
  html+=`<tr style="text-align:center;font-weight:800">
    <th style="padding:5px 10px;background:#1a2742;color:#ffd600;text-align:center" rowspan="2">Agents</th>
    ${IS_ADMIN?'<th style="padding:5px 8px;background:#37474f;color:#ffcc80;font-size:10px;text-align:center" rowspan="2">Notes</th>':''}
    <th colspan="4" style="padding:5px 8px;background:#1565c0;color:#ffd600;border-right:4px solid #1a2742;border-left:3px solid #1a2742">${qStyle[1].label}</th>
    <th colspan="4" style="padding:5px 8px;background:#2e7d32;color:#fff;border-right:4px solid #1a2742">${qStyle[2].label}</th>
    <th colspan="4" style="padding:5px 8px;background:#bf360c;color:#fff;border-right:4px solid #1a2742">${qStyle[3].label}</th>
    <th style="padding:5px 8px;background:#c0392b;color:#fff;font-size:14px;font-weight:800" rowspan="2">TOTAL</th>
    <th style="padding:5px 8px;background:#7b1fa2;color:#fff;font-size:10px" rowspan="2">Annulation<br>TIR</th>
  </tr>`;

  // Ligne 2 : initiales des mois J F M A M J J A S O N D
  const moisInit=['J','F','M','A','M','J','J','A','S','O','N','D'];
  html+='<tr style="text-align:center">';
  for(let m=1;m<=12;m++){
    const isFirst=m===1;
    const isLast=m===4||m===8||m===12;
    const sepR=isLast?'border-right:4px solid #1a2742;':'';
    const sepL=isFirst?'border-left:3px solid #1a2742;':'';
    html+=`<th style="padding:4px 6px;background:${moisBg[m]};color:${moisCol[m]};font-weight:700;${sepL}${sepR}min-width:22px">${moisInit[m-1]}</th>`;
  }
  html+='</tr></thead><tbody>';

  // Sections agents
  const sectionsT={
    'DIRECTION' :['CoGe ROUSSEL','Cne MOKADEM'],
    'ÉQUIPE 1'  :['BC BOUXOM','BC ARNAULT','BC HOCHARD'],
    'ÉQUIPE 2'  :['BC DUPUIS','BC BASTIEN','BC ANTHONY'],
    'NUIT'      :['BC MASSON','BC SIGAUD','BC DAINOTTI'],
    'ANALYSE'   :['GP DHALLEWYN','BC DELCROIX','ACP1 DEMERVAL'],
    'INFORMATIQUE'     :['BC DRUEZ'],
  };

  // Ne garder que les agents autorisés TIR
  const tirSet=new Set(tirAgents);
  let grandTot=0;

  // Déterminer la section de l'agent connecté pour le highlight et le filtre d'affichage
  const mySection=Object.entries(sectionsT).find(([,ags])=>ags.includes(MY_AGENT))?.[0]||null;
  // Non-admin : afficher toute l'équipe de l'agent connecté (pas seulement lui)
  const mySectionAgents=mySection ? new Set(sectionsT[mySection]) : null;

  Object.entries(sectionsT).forEach(([lbl,agents])=>{
    const agsFilt=agents.filter(a=>tirSet.has(a) && (IS_ADMIN || !mySectionAgents || mySectionAgents.has(a)));
    if(agsFilt.length===0) return;

    const isMySection=MY_AGENT && lbl===mySection;
    const secBg=isMySection?'#1a4f8a':'#253560';
    const secCol=isMySection?'#ffe066':'#ffd600';
    const secExtra=isMySection?' ★ ':' ';
    html+=`<tr style="background:${secBg}">
      <td colspan="${IS_ADMIN?16:15}" style="padding:3px 10px;font-size:10px;font-weight:700;color:${secCol};letter-spacing:.05em">${lbl}${isMySection?'<span style="font-size:9px;margin-left:6px;background:#ffd600;color:#1a2742;padding:1px 5px;border-radius:3px">MON ÉQUIPE</span>':''}</td>
    </tr>`;

    agsFilt.forEach((ag,i)=>{
      const isMe=MY_AGENT && ag===MY_AGENT;
      const rowBg=isMe?'#fffde7':i%2===0?'#f5f9ff':'#fff';
      let tot=0;
      html+=`<tr style="background:${rowBg};${isMe?'outline:2px solid #f9a825;outline-offset:-2px;':''}">`;
      html+=`<td style="padding:4px 10px;font-weight:700;white-space:nowrap;color:${isMe?'#e65100':'#1a2742'}${isMe?';background:#fff8e1':''}">${isMe?'👤 ':''}${ag}</td>`;
      if(IS_ADMIN){
        const noteVal=(r.tirNotes&&r.tirNotes[ag])||'';
        const noteId='tir-note-'+ag.replace(/[^a-z0-9]/gi,'_');
        html+=`<td style="padding:3px 6px;min-width:90px;max-width:150px;background:#f5f0e8;vertical-align:middle">
          <div style="display:flex;align-items:center;gap:4px">
            <span id="${noteId}-txt" style="font-size:.7rem;color:#5d4037;flex:1;font-style:${noteVal?'normal':'italic'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px" title="${noteVal}">${noteVal||'—'}</span>
            <button onclick="openTirNote('${ag.replace(/'/g,"\'")}','${noteId}')" title="Modifier la note" style="background:none;border:none;cursor:pointer;padding:1px 3px;font-size:.75rem;color:#8d6e63;flex-shrink:0">✏️</button>
          </div>
        </td>`;
      }

      for(let m=1;m<=12;m++){
        const tirs=(parMois[ag]&&parMois[ag][m])||[];
        const v=tirs.length;
        tot+=v;
        const isFirst=m===1;
        const isLast=m===4||m===8||m===12;
        const sepR=isLast?'border-right:4px solid #1a2742;':'';
        const sepL=isFirst?'border-left:3px solid #1a2742;':'';
        const tip=v>0?tirs.map(t=>t.date.split('-')[2]+'/'+t.date.split('-')[1]+' '+perLibTir[t.periode]).join(', '):'';
        // Cellule : fond pâle quadrimestre, chiffre en couleur foncée ; si TIR fait → fond coloré + blanc
        const cellBg=v>0?tirDoneBg[m]:moisBg[m];
        const cellCol=v>0?'#fff':moisCol[m]+'88';
        const disp=v>0?v:'·';
        html+=`<td style="text-align:center;padding:4px 5px;background:${cellBg};color:${cellCol};font-weight:${v>0?'700':'400'};${sepL}${sepR}" title="${tip}">${disp}</td>`;
      }

      // Calcul des TIR par quadrimestre pour détecter les reports
      const q1tot=(parMois[ag]?([1,2,3,4].reduce((s,m)=>s+((parMois[ag][m]||[]).length),0)):0);
      const q2tot=(parMois[ag]?([5,6,7,8].reduce((s,m)=>s+((parMois[ag][m]||[]).length),0)):0);
      const q3tot=(parMois[ag]?([9,10,11,12].reduce((s,m)=>s+((parMois[ag][m]||[]).length),0)):0);

      grandTot+=tot;
      const totCol=tot===0?'#bbb':tot>=3?'#27ae60':tot===2?'#e67e22':'#c0392b';
      html+=`<td style="text-align:center;padding:4px 8px;font-weight:800;font-size:15px;background:#fff0f0;color:${totCol}">${tot}</td>`;
      const annulAg=(r.annulations&&r.annulations[ag])||[];
      const nbAnnul=annulAg.length;
      const JL7=['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
      const ML7=['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
      const tipAnnul=nbAnnul>0?'Annulé le : '+annulAg.map(a=>{
        const dt=new Date(a.date+'T00:00:00');
        return JL7[dt.getDay()]+' '+parseInt(a.date.split('-')[2])+' '+ML7[parseInt(a.date.split('-')[1])];
      }).join(', '):'';
      // Colonne annulation
      html+=`<td style="text-align:center;padding:4px 8px;font-weight:${nbAnnul>0?'700':'400'};background:${nbAnnul>0?'#fdecea':'transparent'};color:${nbAnnul>0?'#c0392b':'#bbb'}" title="${tipAnnul}">${nbAnnul>0?nbAnnul:''}</td>`;
      html+='</tr>';

      // ── Ligne de report si TIR(s) supplémentaires dans un quadrimestre ──
      const reports=[];
      if(q1tot>1) reports.push({q:'Q1',extra:q1tot-1,bg:qStyle[1].bg,col:qStyle[1].col});
      if(q2tot>1) reports.push({q:'Q2',extra:q2tot-1,bg:qStyle[2].bg,col:qStyle[2].col});
      if(q3tot>1) reports.push({q:'Q3',extra:q3tot-1,bg:qStyle[3].bg,col:qStyle[3].col});
      if(reports.length>0){
        const msgParts=reports.map(rp=>`<span style="display:inline-flex;align-items:center;gap:3px;background:${rp.bg};color:${rp.col};padding:1px 7px;border-radius:10px;font-size:.68rem;font-weight:700">`+
          `${rp.q} : +${rp.extra} TIR${rp.extra>1?'s':''} supplémentaire${rp.extra>1?'s':''} reporté${rp.extra>1?'s':''}</span>`);
        html+=`<tr style="background:${rowBg}">
          <td colspan="${IS_ADMIN?16:15}" style="padding:2px 10px 5px 10px;border-top:none">
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
              <span style="font-size:.68rem;color:#888;font-style:italic">↳ Report :</span>
              ${msgParts.join('')}
            </div>
          </td>
        </tr>`;
      }
    });
  });

  // Ligne total général (admin uniquement — évite de révéler les données des autres)
  if(IS_ADMIN){
  const totParMois=[];
  for(let m=1;m<=12;m++){
    totParMois.push(tirAgents.reduce((s,ag)=>{
      return s+((parMois[ag]&&parMois[ag][m])?parMois[ag][m].length:0);
    },0));
  }
  html+=`<tr style="background:#1a2742;color:#fff;font-weight:800;font-size:14px">
    <td style="padding:5px 10px;color:#ffd600;font-size:15px">TOTAL</td>
    ${IS_ADMIN?'<td style="background:#37474f"></td>':''}`;
  totParMois.forEach((v,idx)=>{
    const m=idx+1;
    const isFirst=m===1;
    const isLast=m===4||m===8||m===12;
    const sepR=isLast?'border-right:4px solid #555;':'';
    const sepL=isFirst?'border-left:3px solid #555;':'';
    const tcol=v>0?'#ffd600':'#555';
    html+=`<td style="text-align:center;padding:5px 5px;${sepL}${sepR};color:${tcol}">${v||'—'}</td>`;
  });
  const gtot=totParMois.reduce((s,v)=>s+v,0);
  html+=`<td style="text-align:center;padding:5px 8px;color:#ff6b6b;font-size:16px;font-weight:800">${gtot}</td>`;
  html+=`<td style="background:#4a0072"></td>`;
  html+='</tr>';

  // ── Ligne TOTAL global : agents ayant tiré sur l'ensemble du quadrimestre (X/N) ──
  const nbTotalAg=tirAgents.length;
  const qDef=[[1,2,3,4],[5,6,7,8],[9,10,11,12]];
  const qStyles=[
    {bg:'#1565c0',sepL:'border-left:3px solid #555;',sepR:'border-right:4px solid #555;'},
    {bg:'#2e7d32',sepL:'',sepR:'border-right:4px solid #555;'},
    {bg:'#bf360c',sepL:'',sepR:'border-right:4px solid #555;'},
  ];
  // Pré-calculer nb agents ayant tiré par quadrimestre
  const nbOkParQ=qDef.map(mois=>tirAgents.filter(ag=>mois.some(m=>parMois[ag]&&parMois[ag][m]&&parMois[ag][m].length>0)).length);

  html+=`<tr style="background:#253560;color:#ffd600;font-weight:800;font-size:12px">`;
  html+=`<td style="padding:5px 10px;color:#aac8ff;font-size:.72rem;white-space:nowrap">TOTAL global</td>
  ${IS_ADMIN?'<td style="background:#37474f"></td>':''}`;
  for(let m=1;m<=12;m++){
    const qIdx=m<=4?0:m<=8?1:2;
    const isFirstOfQ=m===1||m===5||m===9;
    const isLastOfQ=m===4||m===8||m===12;
    const sepL=m===1?'border-left:3px solid #555;':'';
    const sepR=isLastOfQ?'border-right:4px solid #555;':'';
    const nbOkQ=nbOkParQ[qIdx];
    const col=nbOkQ===nbTotalAg?'#69f0ae':nbOkQ===0?'#555':'#ffd600';
    if(isFirstOfQ){
      // Première colonne du quadrimestre : colspan=4 avec le chiffre
      html+=`<td colspan="4" style="text-align:center;padding:5px 5px;${sepL}${sepR};background:${qStyles[qIdx].bg};color:${col};font-size:.85rem;font-weight:800">${nbOkQ}/${nbTotalAg}</td>`;
      // Sauter les 3 mois suivants dans la boucle en avançant m
      m+=3;
    }
  }
  // Colonne TOTAL : agents ayant tiré au moins une fois dans l'année
  const nbOkAnnee=tirAgents.filter(ag=>Object.values(parMois[ag]||{}).some(arr=>arr.length>0)).length;
  const totAnneeCol=nbOkAnnee===nbTotalAg?'#69f0ae':nbOkAnnee===0?'#ff6b6b':'#ffd600';
  html+=`<td style="text-align:center;padding:5px 8px;font-size:.75rem;font-weight:800;color:${totAnneeCol}">${nbOkAnnee}/${nbTotalAg}</td>`;
  html+=`<td style="background:#4a0072"></td>`;
  html+='</tr>';

  } // fin IS_ADMIN total

  html+='</tbody></table></div>';

  // ── Récap quadrimestriel statut ✅/❌ ──
  // ID unique pour le toggle (évite conflit si buildTirAnnuel appelé plusieurs fois)
  const quadWrapId='tir-quadri-wrap-'+Date.now();
  html+=`<h4 id="tir-quadri-toggle" style="font-size:.82rem;font-weight:700;color:#1565c0;margin:14px 0 6px;padding:4px 6px;cursor:pointer;user-select:none;display:flex;align-items:center;gap:6px;border-radius:5px;transition:background .15s" onclick="(function(el){const w=el.nextElementSibling;const open=w.style.display!=='none';w.style.display=open?'none':'';el.querySelector('.tir-q-arrow').textContent=open?'▶':'▼';})(this)" onmouseenter="this.style.background='#e8f0fb'" onmouseleave="this.style.background=''">
    <span class="tir-q-arrow" style="font-size:.65rem;color:#1565c0">▶</span>
    Suivi quadrimestriel &nbsp;<span style="font-size:.7rem;font-weight:400;color:#888">(1 TIR obligatoire / quadrimestre)</span>
  </h4>`;
  html+=`<div id="${quadWrapId}" style="display:none">`;
  html+='<div style="font-size:.7rem;color:#555;margin-bottom:8px">'
    +'✅ Effectué &nbsp;|&nbsp; ❌ Manquant &nbsp;|&nbsp; '
    +'<span style="background:#fff8e1;padding:1px 5px;border-radius:3px;color:#e65100">⏳ En cours</span></div>';
  html+='<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:11.5px;width:100%">';
  html+=`<thead><tr style="background:#1a2742;color:#ffd600">
    <th style="text-align:left;padding:5px 10px">Agent</th>`;
  Q_DEF.forEach(q=>{
    const cur=q.num===qNow;
    html+=`<th style="padding:5px 10px;text-align:center;background:${qStyle[q.num].bg};color:${qStyle[q.num].col}">${q.label}</th>`;
  });
  html+=`<th style="padding:5px 10px;text-align:center;background:#c0392b;color:#fff">Total / 3</th>`;
  html+='</tr></thead><tbody>';

  tirAgents.filter(ag=>IS_ADMIN || (mySectionAgents && mySectionAgents.has(ag)) || (!mySectionAgents && USER_AGENTS.includes(ag))).forEach((ag,i)=>{
    const aq=quadri[ag]||{};
    const tot=Q_DEF.reduce((s,q)=>s+(aq[q.num]?aq[q.num].nb:0),0);
    const isMe=MY_AGENT && ag===MY_AGENT;
    const rowBg=isMe?'#fffde7':i%2?'#f0f4ff':'#fff';
    html+=`<tr style="background:${rowBg};${isMe?'outline:2px solid #f9a825;outline-offset:-2px;':''}">`;
    html+=`<td style="padding:4px 10px;font-weight:${isMe?'800':'600'};color:${isMe?'#e65100':'inherit'}${isMe?';background:#fff8e1':''}">${isMe?'👤 ':''}${ag}</td>`;
    Q_DEF.forEach(q=>{
      const qd=aq[q.num]; const nb=qd?qd.nb:0;
      const cur=q.num===qNow;
      const tip=qd&&qd.dates&&qd.dates.length?'TIR : '+qd.dates.map(d=>{
        const dt=new Date(d+'T00:00:00');
        return ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'][dt.getDay()]+' '+parseInt(d.split('-')[2])+'/'+d.split('-')[1];
      }).join(', '):'Aucun TIR';
      let bg,col,icon;
      if(nb>=1){bg='#e8f5e9';col='#1b5e20';icon='✅';}
      else if(cur){bg='#fff8e1';col='#e65100';icon='⏳';}
      else{bg='#fdecea';col='#c0392b';icon='❌';}
      const plus=nb>1?` <span style="font-size:.65rem;color:#555">(×${nb})</span>`:'';
      html+=`<td style="text-align:center;padding:4px 8px;background:${bg};color:${col};font-weight:700" title="${tip}">${icon}${plus}</td>`;
    });
    const tc=tot===0?'#999':tot<3?'#e65100':'#27ae60';
    html+=`<td style="text-align:center;padding:4px 10px;font-weight:800;background:#e3f0ff;color:${tc}">${tot}/3</td>`;
    html+='</tr>';
  });

  html+='</tbody></table></div>';
  html+='</div>'; // fin tir-quadri-wrap (toggle)
  return html;
}

/* ── Vue quadrimestre : tableau agents × périodes + détail dates ── */
function buildTirQuad(r,qr){
  const Q=Q_DEF[qr-1];
  const quadri=r.quadri||{};
  const detail=r.detailQuad||[];
  let html='';
  // Déterminer la section de l'agent connecté (même logique que buildTirAnnuel)
  const sectionsRef={'DIRECTION':['CoGe ROUSSEL','Cne MOKADEM'],'ÉQUIPE 1':['BC BOUXOM','BC ARNAULT','BC HOCHARD'],'ÉQUIPE 2':['BC DUPUIS','BC BASTIEN','BC ANTHONY'],'NUIT':['BC MASSON','BC SIGAUD','BC DAINOTTI'],'ANALYSE':['GP DHALLEWYN','BC DELCROIX','ACP1 DEMERVAL'],'INFORMATIQUE':['BC DRUEZ']};
  const mySectionQ=Object.entries(sectionsRef).find(([,ags])=>ags.includes(MY_AGENT))?.[0]||null;
  const mySectionAgents=mySectionQ ? new Set(sectionsRef[mySectionQ]) : null;

  html+=`<h4 style="font-size:.84rem;font-weight:700;color:#1565c0;margin:0 0 8px;padding:0 4px">
    🎯 ${Q.label} — ${ANNEE}
    ${qr===qNow?'<span style="font-size:.7rem;background:#fff8e1;color:#e65100;padding:1px 7px;border-radius:4px;margin-left:6px">En cours</span>':''}
  </h4>`;

  // Tableau récap agents
  html+='<div style="overflow-x:auto;margin-bottom:16px"><table style="width:100%;border-collapse:collapse;font-size:.76rem">';
  html+='<thead><tr style="background:#1565c0;color:#ffd600"><th style="text-align:left;padding:6px 10px">Agent</th>';
  ['M','AM','NUIT'].forEach(p=>html+=`<th style="padding:6px 10px;text-align:center">${perLibTir[p]}</th>`);
  html+='<th style="padding:6px 10px;text-align:center;background:#0d47a1">Total</th><th style="padding:6px 10px;text-align:center">Statut</th></tr></thead><tbody>';
  tirAgents.filter(ag=>IS_ADMIN || (mySectionAgents && mySectionAgents.has(ag)) || (!mySectionAgents && USER_AGENTS.includes(ag))).forEach((ag,i)=>{
    const aq=quadri[ag]&&quadri[ag][qr]?quadri[ag][qr]:null;
    const nb=aq?aq.nb:0;
    // Compter par période depuis le détail
    const perCount={M:0,AM:0,NUIT:0};
    detail.filter(d=>d.agent===ag).forEach(d=>perCount[d.periode]=(perCount[d.periode]||0)+1);
    const isMe=MY_AGENT && ag===MY_AGENT;
    const rowBg=isMe?'#fffde7':i%2?'#f0f4ff':'#fff';
    let statut=nb>=1?'✅ OK':(qr===qNow?'⏳ En cours':'❌ Manquant');
    let scol=nb>=1?'#1b5e20':(qr===qNow?'#e65100':'#c0392b');
    html+=`<tr style="background:${rowBg};${isMe?'outline:2px solid #f9a825;outline-offset:-2px;':''}">`;
    html+=`<td style="padding:5px 10px;font-weight:${isMe?'800':'600'};color:${isMe?'#e65100':'inherit'}${isMe?';background:#fff8e1':''}">${isMe?'👤 ':''}${ag}</td>`;
    ['M','AM','NUIT'].forEach(p=>html+=`<td style="text-align:center;padding:5px 8px">${perCount[p]||'—'}</td>`);
    html+=`<td style="text-align:center;padding:5px 8px;font-weight:700;background:#e3f0ff">${nb}</td>`;
    html+=`<td style="text-align:center;padding:5px 8px;font-weight:700;color:${scol}">${statut}</td>`;
    html+='</tr>';
  });
  html+='</tbody></table></div>';

  // Détail des séances du quadrimestre
  if(detail.length>0){
    html+=`<h4 style="font-size:.82rem;font-weight:700;color:#1565c0;margin-bottom:8px;padding:0 4px">Séances du ${Q.label} — ${detail.length} au total</h4>`;
    html+='<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.75rem">';
    html+='<thead><tr style="background:#e3f0ff;color:#1565c0"><th style="padding:5px 8px;text-align:left">Date</th><th style="padding:5px 8px;text-align:left">Agent</th><th style="padding:5px 8px;text-align:center">Période</th></tr></thead><tbody>';
    const JL=['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
    detail.forEach((t,i)=>{
      const dt=new Date(t.date+'T00:00:00');
      html+=`<tr style="background:${i%2?'#f7f8fa':'#fff'}">
        <td style="padding:4px 8px">${JL[dt.getDay()||7]} ${parseInt(t.date.split('-')[2])} ${ML_TIR3[parseInt(t.date.split('-')[1])]}</td>
        <td style="padding:4px 8px;font-weight:600">${t.agent}</td>
        <td style="padding:4px 8px;text-align:center">${perLibTir[t.periode]||t.periode}</td></tr>`;
    });
    html+='</tbody></table></div>';
  } else {
    html+=`<p style="color:#888;font-size:.78rem;padding:6px 4px;font-style:italic">Aucun TIR enregistré pour ${Q.label}.</p>`;
  }
  return html;
}

/* ── Vue mensuelle ── */
function buildTirMensuel(r,mo){
  const parMois=r.parMois||{};
  const mensuel=r.mensuel||[];
  let html='';

  // Récap agents pour ce mois
  html+=`<h4 style="font-size:.84rem;font-weight:700;color:#1565c0;margin:0 0 8px;padding:0 4px">🎯 ${ML_TIR[mo]} ${ANNEE} — ${mensuel.length} séance(s)</h4>`;
  const agentsAvec=tirAgents.filter(ag=>mensuel.some(t=>t.agent===ag));

  if(agentsAvec.length>0){
    html+='<div style="overflow-x:auto;margin-bottom:14px"><table style="width:100%;border-collapse:collapse;font-size:.76rem">';
    html+='<thead><tr style="background:#1565c0;color:#ffd600"><th style="text-align:left;padding:6px 10px">Agent</th>';
    ['M','AM','NUIT'].forEach(p=>html+=`<th style="padding:6px 10px;text-align:center">${perLibTir[p]}</th>`);
    html+='<th style="padding:6px 10px;text-align:center;background:#0d47a1">Total</th></tr></thead><tbody>';
    agentsAvec.forEach((ag,i)=>{
      const perC={M:0,AM:0,NUIT:0};
      mensuel.filter(t=>t.agent===ag).forEach(t=>perC[t.periode]=(perC[t.periode]||0)+1);
      const tot=Object.values(perC).reduce((s,v)=>s+v,0);
      html+=`<tr style="background:${i%2?'#f0f4ff':'#fff'}">`;
      html+=`<td style="padding:5px 10px;font-weight:600">${ag}</td>`;
      ['M','AM','NUIT'].forEach(p=>html+=`<td style="text-align:center;padding:5px 8px">${perC[p]||'—'}</td>`);
      html+=`<td style="text-align:center;padding:5px 8px;font-weight:700;background:#e3f0ff">${tot}</td></tr>`;
    });
    html+='</tbody></table></div>';
  }

  // Détail jour par jour
  if(mensuel.length>0){
    html+=`<h4 style="font-size:.82rem;font-weight:700;color:#1565c0;margin-bottom:8px;padding:0 4px">Détail — ${ML_TIR[mo]}</h4>`;
    html+='<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.75rem">';
    html+='<thead><tr style="background:#e3f0ff;color:#1565c0"><th style="padding:5px 8px;text-align:left">Date</th><th style="padding:5px 8px;text-align:left">Agent</th><th style="padding:5px 8px;text-align:center">Période</th></tr></thead><tbody>';
    const JL=['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
    mensuel.forEach((t,i)=>{
      const dt=new Date(t.date+'T00:00:00');
      html+=`<tr style="background:${i%2?'#f7f8fa':'#fff'}">
        <td style="padding:4px 8px">${JL[dt.getDay()||7]} ${parseInt(t.date.split('-')[2])}</td>
        <td style="padding:4px 8px;font-weight:600">${t.agent}</td>
        <td style="padding:4px 8px;text-align:center">${perLibTir[t.periode]||t.periode}</td></tr>`;
    });
    html+='</tbody></table></div>';
  } else {
    html+=`<p style="color:#888;font-size:.78rem;padding:6px 4px;font-style:italic">Aucun TIR en ${ML_TIR[mo]} ${ANNEE}.</p>`;
  }
  return html;
}


<?php if(!$isAdmin):?>
// Changement mot de passe (users non-admin)
const overlayChangePass=document.getElementById('overlay-change-pass');
document.getElementById('btn-x-change-pass').addEventListener('click',()=>overlayChangePass.classList.remove('open'));
document.getElementById('btn-user-change-pass').addEventListener('click',async()=>{
  const newP=document.getElementById('user-new-pass').value;
  if(newP.length<8){const msg=document.getElementById('user-pass-msg');msg.textContent='Mot de passe trop court (min 8 car.)';msg.style.color='#c0392b';return;}
  const r=await ajax({
    action:'change_password',
    old_pass:document.getElementById('user-old-pass').value,
    new_pass:newP
  });
  const msg=document.getElementById('user-pass-msg');
  msg.textContent=r.msg;msg.style.color=r.ok?'#27ae60':'#c0392b';
  if(r.ok){
    document.getElementById('user-old-pass').value='';
    document.getElementById('user-new-pass').value='';
    setTimeout(()=>overlayChangePass.classList.remove('open'),1200);
    const lnk=document.getElementById('lnk-change-now');
    if(lnk) lnk.closest('div').style.display='none';
  }
});
<?php endif;?>

<?php if(!empty($_SESSION['must_change'])):?>
const lnkChangeNow=document.getElementById('lnk-change-now');
if(lnkChangeNow) lnkChangeNow.addEventListener('click',e=>{
  e.preventDefault();
  <?php if($isAdmin):?>
  overlayAdmin.classList.add('open');
  document.querySelectorAll('.adm-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.adm-section').forEach(s=>s.classList.remove('active'));
  document.querySelector('.adm-tab[data-tab="moncompte"]').classList.add('active');
  document.getElementById('tab-moncompte').classList.add('active');
  <?php else:?>
  document.getElementById('overlay-change-pass').classList.add('open');
  <?php endif;?>
});
<?php endif;?>


// Ouverture / fermeture overlay récap fêtes
const overlayRecapFetes = document.getElementById('overlay-recap-fetes');
const recapFetesBody = document.getElementById('recap-fetes-body');

const btnOpenRecapFetes=document.getElementById('btn-open-recap-fetes');
if(btnOpenRecapFetes) btnOpenRecapFetes.addEventListener('click',()=>{
  recapFetesBody.innerHTML = buildRecapFetes();
  overlayRecapFetes.classList.add('open');
});
document.getElementById('btn-x-recap-fetes').addEventListener('click',()=>overlayRecapFetes.classList.remove('open'));
overlayRecapFetes.addEventListener('click',e=>{if(e.target===overlayRecapFetes)overlayRecapFetes.classList.remove('open');});

// ── Récap Fêtes : données historiques multi-années ──
// Format : { annee: { '01/01': [agents...], '25/12': [agents...] } }
// À compléter ultérieurement avec les vraies données
const RECAP_FETES_DATA = {
  // Données réelles — format : { agent: periode } où periode = 'M' ou 'AM'
  2020: {
    '01/01': {'ADJ LEFEBVRE':'M','BC DELEAU':'AM'},
    '25/12': {'ADJ CARLIER':'AM','BC BOUXOM':'M'},
  },
  2021: {
    '01/01': {'BC DELCROIX':'M','BC CATOIRE':'M','BC DRUEZ':'AM'},
    '25/12': {'MAJ LELEU':'AM','BC ANTHONY':'M'},
  },
  2022: {
    '01/01': {'ADJ LEFEBVRE':'M','BC DEPREY':'AM'},
    '25/12': {'BC BOUXOM':'M','BC ARNAULT':'AM'},
  },
  2023: {
    '01/01': {'BC DELEAU':'M','ADJ CORRARD':'AM'},
    '25/12': {'BC HOCHARD':'M','MAJ LELEU':'AM'},
  },
  2024: {
    '01/01': {'BC BASTIEN':'M','BC DRUEZ':'AM'},
    '25/12': {'BC ARNAULT':'AM','BC BOUXOM':'M'},
  },
  2025: {
    '01/01': {'ADJ LEFEBVRE':'M','BC DELCROIX':'AM'},
    '25/12': {'BC HOCHARD':'AM','ADJ CORRARD':'M'},
  },
};

const FETES_JOURS = ['01/01','25/12'];
const FETES_LABELS = {'01/01':'🎆 1er Jan','25/12':'🎄 25 Déc'};
const FETES_COLORS_M  = {'25/12':'#1565c0','01/01':'#c0392b'};
const FETES_COLORS_AM = {'25/12':'#42a5f5','01/01':'#ef5350'};
const FETES_BG        = {'25/12':'#e3f0ff','01/01':'#fdecea'};

// Agents concernés par les permanences fêtes (Équipes 1&2, GIE, GP DHALLEWYN, BC DELCROIX, BC DRUEZ)
const RECAP_AGENTS = [
  // Équipe 1
  'BC BOUXOM','BC ARNAULT','BC HOCHARD',
  // Équipe 2
  'BC DUPUIS','BC BASTIEN','BC ANTHONY',
  // GIE
  'ADJ CORRARD','ADJ LEFEBVRE',
  // Analyse / Secrétariat concernés
  'GP DHALLEWYN','BC DELCROIX','BC DRUEZ',
];

function buildRecapFetes(){
  const annees = Object.keys(RECAP_FETES_DATA).map(Number).sort();
  if(annees.length===0){
    return '<p style="color:#888;padding:20px;text-align:center">Aucune donnée historique disponible.</p>';
  }

  // Liste des agents : union de RECAP_AGENTS (actifs) + tous les agents présents dans les données (anciens)
  const agentsSet = new Set(RECAP_AGENTS);
  annees.forEach(an=>{
    FETES_JOURS.forEach(j=>{
      if(RECAP_FETES_DATA[an]&&RECAP_FETES_DATA[an][j]){
        Object.keys(RECAP_FETES_DATA[an][j]).forEach(ag=>agentsSet.add(ag));
      }
    });
  });
  // Actifs en premier (ordre RECAP_AGENTS), anciens ensuite (triés)
  const agentsActifs = RECAP_AGENTS.filter(ag=>agentsSet.has(ag));
  const agentsAnciens = [...agentsSet].filter(ag=>!RECAP_AGENTS.includes(ag)).sort();
  const agents = [...agentsActifs, ...agentsAnciens];

  let html='<div style="overflow-x:auto">';
  html+='<table style="border-collapse:collapse;font-size:11px;min-width:100%">';

  // En-tête ligne 1 : années
  html+='<thead><tr>';
  html+=`<th rowspan="2" style="padding:5px 10px;background:#7b1fa2;color:#fff;text-align:left;position:sticky;left:0;z-index:3;min-width:120px">Agent</th>`;
  annees.forEach(an=>{
    html+=`<th colspan="2" style="padding:5px 8px;background:#4a0072;color:#fff;text-align:center;border-left:3px solid #7b1fa2;min-width:76px">${an}</th>`;
  });
  html+='</tr>';

  // En-tête ligne 2 : jours fêtes
  html+='<tr>';
  annees.forEach(an=>{
    FETES_JOURS.forEach((j,ji)=>{
      const bl=ji===0?'border-left:3px solid #7b1fa2;':'';
      html+=`<th style="padding:3px 4px;background:${FETES_BG[j]};color:${FETES_COLORS_M[j]};font-weight:700;text-align:center;min-width:38px;${bl}font-size:.65rem">${FETES_LABELS[j].replace(/🎄 |🥂 |🎆 /,'')}</th>`;
    });
  });
  html+='</tr></thead><tbody>';

  // Corps : une ligne par agent
  agents.forEach((ag,ai)=>{
    const isAncien = !RECAP_AGENTS.includes(ag);
    const rowBg=ai%2===0?'#f9f9fb':'#fff';
    const ancienStyle=isAncien?'color:#999;font-style:italic;':'';
    html+=`<tr style="background:${rowBg}">`;
    html+=`<td style="padding:3px 10px;font-weight:600;white-space:nowrap;background:${rowBg};position:sticky;left:0;border-right:2px solid #dde;${ancienStyle}" title="${isAncien?'Ancien fonctionnaire':''}">${ag}${isAncien?' <span style="font-size:.6rem;font-weight:400;color:#bbb">(anc.)</span>':''}</td>`;

    annees.forEach(an=>{
      FETES_JOURS.forEach((j,ji)=>{
        const periode=(RECAP_FETES_DATA[an]&&RECAP_FETES_DATA[an][j]&&RECAP_FETES_DATA[an][j][ag])||null;
        const bl=ji===0?'border-left:3px solid #7b1fa2;':'';
        if(periode==='M'){
          html+=`<td style="text-align:center;padding:2px 3px;background:${FETES_COLORS_M[j]};color:#fff;font-weight:800;${bl};font-size:11px" title="${ag} — ${j}/${an} (Matin)">M</td>`;
        } else if(periode==='AM'){
          html+=`<td style="text-align:center;padding:2px 3px;background:${FETES_COLORS_AM[j]};color:#fff;font-weight:800;${bl};font-size:11px" title="${ag} — ${j}/${an} (Après-midi)">AM</td>`;
        } else {
          html+=`<td style="text-align:center;padding:2px 3px;color:#ddd;background:${FETES_BG[j]}22;${bl}">·</td>`;
        }
      });
    });

    html+='</tr>';
  });

  html+='</tbody></table></div>';

  // Légende
  html+=`<div style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:.72rem;color:#555">
    <strong>Légende :</strong>
    ${FETES_JOURS.map(j=>`<span style="display:inline-flex;align-items:center;gap:4px">
      <span style="background:${FETES_COLORS_M[j]};color:#fff;padding:1px 5px;border-radius:3px;font-weight:700;font-size:.68rem">M</span>Matin
      <span style="background:${FETES_COLORS_AM[j]};color:#fff;padding:1px 5px;border-radius:3px;font-weight:700;font-size:.68rem;margin-left:2px">AM</span>Après-midi — ${FETES_LABELS[j]}
    </span>`).join('')}
  </div>`;

  html+=`<p style="margin-top:8px;font-size:.68rem;color:#aaa;font-style:italic;padding:5px 8px;background:#f9f9f9;border-radius:6px;border-left:3px solid #ddd">
    💡 Données à mettre à jour dans la variable <code>RECAP_FETES_DATA</code> du code source JS.
  </p>`;

  return html;
}


<?php if($isAdmin):?>
const overlayAdmin=document.getElementById('overlay-admin');
const btnAdminPanel=document.getElementById('btn-admin-panel');
if(btnAdminPanel) btnAdminPanel.addEventListener('click',()=>{
  overlayAdmin.classList.add('open');
  loadUsers();
});
document.getElementById('btn-x-admin').addEventListener('click',()=>overlayAdmin.classList.remove('open'));
overlayAdmin.addEventListener('click',e=>{if(e.target===overlayAdmin)overlayAdmin.classList.remove('open');});

// ── Quotas congés (par type) ──
let quotaCache={};

// Types en jours avec leur libellé
const QUOTA_TYPES_J={CA:'Congé annuel',HP:'Congé annuel Hors Période',HPA:'Congé annuel antérieur Hors Période',CAM:'Congé Arret Maladie',RTT:'RTT',CET:'CET',CF:'Crédit Férié'};
// Types en heures
const QUOTA_TYPES_H={RTC:'RTC',CF_H:'CF'};

async function loadQuotas(){
  const yr=parseInt(document.getElementById('quota-annee').value);
  const wrap=document.getElementById('quota-list');
  wrap.innerHTML='<p style="color:#888;font-size:.8rem;grid-column:1/-1">Chargement...</p>';
  const [rQ,rC]=await Promise.all([
    ajax({action:'get_quotas',annee:yr}),
    ajax({action:'get_conges_count',annee:yr})
  ]);
  if(!rQ.ok){wrap.innerHTML='<p style="color:red">Erreur</p>';return;}
  quotaCache=rQ.quotas||{};
  const counts=rC.counts||{};
  wrap.innerHTML='';
  // Une carte par agent
  Object.entries(rQ.quotas).forEach(([ag,types])=>{
    if(!types||Object.keys(types).length===0) return;
    const agCounts=counts[ag]||{};
    // Y a-t-il un dépassement sur au moins un type en jours ?
    let anyOver=false;
    Object.entries(types).forEach(([tc,q])=>{
      if(q.unite==='jours'){
        const used=agCounts[tc]||0;
        if(used>q.quota) anyOver=true;
      }
    });
    const card=document.createElement('div');
    card.style.cssText='background:#f7f9ff;border:1.5px solid '+(anyOver?'#c0392b':'#dde5f0')+';border-radius:8px;padding:9px 11px';
    let html=`<div style="font-size:.75rem;font-weight:700;color:#1a2742;margin-bottom:7px">${ag}</div>`;
    // Lignes par type
    Object.entries(types).forEach(([tc,q])=>{
      const isH=(q.unite==='heures');
      const isCForRTC=(tc==='CF'||tc==='RTC');
      const used=isH?'—':(agCounts[tc]||0);
      const over=!isH&&used>q.quota;
      const pct=!isH&&q.quota>0?Math.round(used/q.quota*100):null;
      const col=over?'#c0392b':isH?'#7a5800':'#27ae60';
      const fieldW=isCForRTC?'72px':'52px';
      html+=`<div style="display:flex;align-items:center;gap:5px;margin-bottom:4px">
        <span style="font-size:.68rem;font-weight:700;color:#1a2742;width:32px;flex-shrink:0">${tc}</span>
        <input type="number" min="0" max="9999" step="${isH?'0.01':'1'}" value="${q.quota}"
          data-agent="${ag}" data-type="${tc}" data-unite="${q.unite}" class="quota-input"
          style="width:${fieldW};padding:2px 5px;border:1.5px solid ${over?'#c0392b':'#ccc'};border-radius:4px;font-size:.78rem;font-weight:700">
        <span style="font-size:.68rem;color:#888">${isH?'h':'j'}</span>
        <span style="font-size:.68rem;color:${col};font-weight:600;flex:1">
          ${isH?'(heures)':over?'⚠ '+used+'/'+q.quota:'✓ '+used+'/'+q.quota+(pct!==null?' ('+pct+'%)':'')}
        </span>
      </div>`;
    });
    card.innerHTML=html;
    wrap.appendChild(card);
  });
  if(wrap.childElementCount===0){
    wrap.innerHTML='<p style="color:#888;font-size:.8rem;grid-column:1/-1">Aucun quota défini pour cette année.</p>';
  }
}

document.getElementById('btn-quota-load').addEventListener('click',loadQuotas);

document.getElementById('btn-quota-save-all').addEventListener('click',async()=>{
  const yr=parseInt(document.getElementById('quota-annee').value);
  const inputs=document.querySelectorAll('.quota-input');
  let ok=true;
  for(const inp of inputs){
    const ag=inp.dataset.agent;
    const tc=inp.dataset.type;
    const unite=inp.dataset.unite||'jours';
    const q=parseFloat(inp.value)||0;
    const r=await ajax({action:'set_quota',agent:ag,annee:yr,type_conge:tc,quota:q,unite});
    if(!r.ok) ok=false;
  }
  showToast(ok?'Quotas sauvegardés ✓':'Erreur lors de la sauvegarde',ok);
  if(ok){invalidateQuotaCache();loadQuotas();}
});

// Onglets admin
document.querySelectorAll('.adm-tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    document.querySelectorAll('.adm-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.adm-section').forEach(s=>s.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-'+tab.dataset.tab).classList.add('active');
    if(tab.dataset.tab==='users') loadUsers();
    if(tab.dataset.tab==='quotas') loadQuotas();
  });
});

// Verrou global
const btnLock=document.getElementById('btn-lock-global');
const btnUnlock=document.getElementById('btn-unlock-global');
if(btnLock) btnLock.addEventListener('click',async()=>{
  if(!confirm('Verrouiller tout le planning ? Plus aucune modification ne sera possible.')) return;
  const r=await ajax({action:'lock_global'});
  if(r.ok){showToast('Planning verrouillé 🔒');setTimeout(()=>location.reload(),800);}
  else showToast('Erreur : '+r.msg,false);
});
if(btnUnlock) btnUnlock.addEventListener('click',async()=>{
  const r=await ajax({action:'unlock_global'});
  if(r.ok){showToast('Planning déverrouillé 🔓');setTimeout(()=>location.reload(),800);}
  else showToast('Erreur : '+r.msg,false);
});

// Verrous par agent
document.querySelectorAll('.btn-toggle-lock').forEach(btn=>{
  btn.addEventListener('click',async()=>{
    const ag=btn.dataset.agent;
    const locked=btn.dataset.locked==='1';
    const action=locked?'unlock_agent':'lock_agent';
    const r=await ajax({action,agent:ag});
    if(r.ok){
      const row=btn.closest('.lock-row');
      if(locked){
        row.className='lock-row unlocked';
        row.querySelector('span:last-of-type').innerHTML='&#9633; Libre';
        row.querySelector('span:last-of-type').style.color='#27ae60';
        btn.textContent='Verrouiller';btn.style.background='#c0392b';btn.dataset.locked='0';
      } else {
        row.className='lock-row locked';
        row.querySelector('span:last-of-type').innerHTML='&#9632; Verrouillé';
        row.querySelector('span:last-of-type').style.color='#c0392b';
        btn.textContent='Déverrouiller';btn.style.background='#27ae60';btn.dataset.locked='1';
      }
      // Mettre à jour le curseur des cellules de cet agent dans le tableau
      document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(td=>{
        if(locked) td.classList.remove('locked-agent');
        else td.classList.add('locked-agent');
      });
      document.querySelectorAll('td.agent-name').forEach(td=>{
        if(td.textContent.trim()===ag){
          if(locked) td.classList.remove('locked-name');
          else td.classList.add('locked-name');
        }
      });
      showToast(locked?ag+' déverrouillé':ag+' verrouillé');
    } else showToast('Erreur',false);
  });
});

// ── Verrous mensuels dans le panneau admin ──
function refreshLockRowMois(ag, mois, nowLocked) {
  const row = document.querySelector('#lock-agents-mois-list .lock-row[data-agent="'+ag+'"]');
  if (!row) return;
  const sp  = row.querySelector('.lock-status-mois');
  const btn = row.querySelector('.btn-toggle-lock-mois');
  if (nowLocked) {
    row.className = 'lock-row locked';
    if (sp)  { sp.innerHTML = '&#9632; '+mois+' verrouillé'; sp.style.color = '#e67e22'; }
    if (btn) { btn.textContent = 'Déverrouiller'; btn.style.background = '#27ae60'; btn.dataset.locked = '1'; }
  } else {
    row.className = 'lock-row unlocked';
    if (sp)  { sp.innerHTML = '&#9633; Libre'; sp.style.color = '#27ae60'; }
    if (btn) { btn.textContent = 'Verrouiller '+mois; btn.style.background = '#e67e22'; btn.dataset.locked = '0'; }
  }
  // Mettre à jour le bouton inline sur la ligne du tableau planning
  document.querySelectorAll('.inline-lock-btn[data-agent="'+ag+'"][data-mois="'+mois+'"]').forEach(b => {
    if (nowLocked) {
      b.innerHTML = '&#9632;'; b.title = 'Déverrouiller '+mois;
      b.style.background = '#e67e22'; b.dataset.locked = '1';
    } else {
      b.innerHTML = '&#9633;'; b.title = 'Verrouiller '+mois;
      b.style.background = '#27ae60'; b.dataset.locked = '0';
    }
  });
}

document.querySelectorAll('.btn-toggle-lock-mois').forEach(btn => {
  btn.addEventListener('click', async () => {
    const ag     = btn.dataset.agent;
    const mois   = btn.dataset.mois;
    const locked = btn.dataset.locked === '1';
    const action = locked ? 'unlock_agent_mois' : 'lock_agent_mois';
    const r = await ajax({action, agent: ag, mois});
    if (r.ok) {
      const nowLocked = !locked;
      // Mettre à jour LOCKED_AGENTS_MOIS en mémoire
      if (nowLocked) {
        if (!LOCKED_AGENTS_MOIS[ag]) LOCKED_AGENTS_MOIS[ag] = {};
        LOCKED_AGENTS_MOIS[ag][mois] = true;
      } else {
        if (LOCKED_AGENTS_MOIS[ag]) delete LOCKED_AGENTS_MOIS[ag][mois];
      }
      refreshLockRowMois(ag, mois, nowLocked);
      // Mettre à jour les cellules du tableau planning (uniquement le mois verrouillé)
      document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(cell => {
        if (!cell.dataset.date || cell.dataset.date.slice(0,7) !== mois) return;
        if (nowLocked) cell.classList.add('locked-agent');
        else           cell.classList.remove('locked-agent');
      });
      document.querySelectorAll('td.agent-name').forEach(td => {
        const name = td.childNodes[0]?.textContent?.trim() || '';
        if (name === ag) {
          if (nowLocked) td.classList.add('locked-name');
          else           td.classList.remove('locked-name');
        }
      });
      showToast(nowLocked ? ag+' — '+mois+' verrouillé 🔒' : ag+' — '+mois+' déverrouillé 🔓');
    } else {
      showToast('Erreur lors du verrouillage', false);
    }
  });
});

// ── Verrous inline dans le tableau planning (admin uniquement — verrou mensuel) ──
document.addEventListener('click', async e => {
  const btn = e.target.closest('.inline-lock-btn');
  if (!btn) return;
  e.stopPropagation(); // Ne pas ouvrir la modal
  const ag     = btn.dataset.agent;
  const mois   = btn.dataset.mois;
  const locked = btn.dataset.locked === '1';
  // Verrou annuel global (mois vide) : non modifiable ici, rediriger vers paramètres
  if (!mois) {
    showToast('🔒 Verrou annuel — à gérer dans les paramètres', false);
    return;
  }
  const action = locked ? 'unlock_agent_mois' : 'lock_agent_mois';
  const r = await ajax({action, agent: ag, mois});
  if (r.ok) {
    if (locked) {
      // Déverrouillé
      btn.innerHTML = '&#9633;';
      btn.title = 'Verrouiller ' + mois;
      btn.style.background = '#27ae60';
      btn.dataset.locked = '0';
      // Mettre à jour LOCKED_AGENTS_MOIS en mémoire
      if (LOCKED_AGENTS_MOIS[ag]) delete LOCKED_AGENTS_MOIS[ag][mois];
      // Retirer locked-agent sur les cellules du mois uniquement
      document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(cell => {
        if (!cell.dataset.date || cell.dataset.date.slice(0,7) !== mois) return;
        cell.classList.remove('locked-agent');
      });
      const td = btn.closest('td.agent-name');
      if (td) td.classList.remove('locked-name');
    } else {
      // Verrouillé
      btn.innerHTML = '&#9632;';
      btn.title = 'Déverrouiller ' + mois;
      btn.style.background = '#e67e22'; // orange = verrou mensuel
      btn.dataset.locked = '1';
      // Mettre à jour LOCKED_AGENTS_MOIS en mémoire
      if (!LOCKED_AGENTS_MOIS[ag]) LOCKED_AGENTS_MOIS[ag] = {};
      LOCKED_AGENTS_MOIS[ag][mois] = true;
      // Ajouter locked-agent sur les cellules de l'agent pour ce mois uniquement
      document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(cell => {
        if (!cell.dataset.date || cell.dataset.date.slice(0,7) !== mois) return;
        cell.classList.add('locked-agent');
      });
      const td = btn.closest('td.agent-name');
      if (td) td.classList.add('locked-name');
    }
    // Mettre à jour la ligne dans le panneau admin verrous (section mois) si ouverte
    refreshLockRowMois(ag, mois, !locked);
    showToast(locked ? ag + ' — ' + mois + ' déverrouillé' : ag + ' — ' + mois + ' verrouillé 🔒');
  } else {
    showToast('Erreur lors du verrouillage', false);
  }
});

// ── Grille verrous : clic cellule mensuelle ──
document.addEventListener('click', async e => {
  const cell = e.target.closest('.lock-cell-mois');
  if (!cell) return;
  const ag     = cell.dataset.agent;
  const mois   = cell.dataset.mois;
  const locked = cell.classList.contains('lc-locked');
  const action = locked ? 'unlock_agent_mois' : 'lock_agent_mois';
  const r = await ajax({action, agent: ag, mois});
  if (r.ok) {
    const nowLocked = !locked;
    cell.classList.toggle('lc-locked', nowLocked);
    cell.classList.toggle('lc-free',   !nowLocked);
    cell.style.background = nowLocked ? '#ffe0b2' : '#f9f9f9';
    cell.innerHTML = nowLocked ? '&#9632;' : '&middot;';
    cell.title = (nowLocked ? '🔒 Verrouillé' : '🔓 Libre') + ' — ' + mois;
    // Sync mémoire JS
    if (!LOCKED_AGENTS_MOIS[ag]) LOCKED_AGENTS_MOIS[ag] = {};
    if (nowLocked) LOCKED_AGENTS_MOIS[ag][mois] = true;
    else delete LOCKED_AGENTS_MOIS[ag][mois];
    // Sync bouton inline sur le planning si le mois affiché est concerné
    if (mois === CUR_MOIS) {
      document.querySelectorAll('.inline-lock-btn[data-agent="'+ag+'"][data-mois="'+mois+'"]').forEach(b => {
        b.innerHTML = nowLocked ? '&#9632;' : '&#9633;';
        b.style.background = nowLocked ? '#e67e22' : '#27ae60';
        b.dataset.locked = nowLocked ? '1' : '0';
        b.title = (nowLocked ? 'Déverrouiller ' : 'Verrouiller ') + mois;
      });
      document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(td => {
        if (nowLocked) td.classList.add('locked-agent');
        else td.classList.remove('locked-agent');
      });
    }
    showToast(nowLocked ? ag+' — '+mois+' verrouillé 🔒' : ag+' — '+mois+' déverrouillé');
  } else showToast('Erreur: '+(r.msg||'?'), false);
});

// ── Grille verrous : clic cellule annuelle ──
document.addEventListener('click', async e => {
  const cell = e.target.closest('.lock-cell-annuel');
  if (!cell) return;
  const ag     = cell.dataset.agent;
  const locked = cell.classList.contains('la-locked');
  const action = locked ? 'unlock_agent' : 'lock_agent';
  const r = await ajax({action, agent: ag});
  if (r.ok) {
    const nowLocked = !locked;
    cell.classList.toggle('la-locked', nowLocked);
    cell.classList.toggle('la-free',   !nowLocked);
    cell.style.background = nowLocked ? '#fdecea' : '#f9f9f9';
    cell.innerHTML = nowLocked ? '&#9632;' : '&middot;';
    // Sync badge annuel
    const badge = document.querySelector('.lock-badge-annuel[data-agent="'+ag+'"]');
    if (badge) {
      badge.classList.toggle('lba-locked', nowLocked);
      badge.classList.toggle('lba-free',   !nowLocked);
      badge.style.background = nowLocked ? '#c0392b' : '#e8f5e9';
      badge.style.color      = nowLocked ? '#fff'    : '#27ae60';
      badge.style.borderColor= nowLocked ? '#c0392b' : '#c8e6c9';
      badge.innerHTML = (nowLocked ? '&#9632; ' : '&#9633; ') + ag;
    }
    // Sync cellules planning
    document.querySelectorAll('td[data-agent="'+ag+'"]').forEach(td => {
      if (nowLocked) td.classList.add('locked-agent');
      else td.classList.remove('locked-agent');
    });
    showToast(nowLocked ? ag+' verrouillé toute l\'année 🔒' : ag+' déverrouillé');
  } else showToast('Erreur', false);
});

// ── Grille verrous : badges annuels (section liste) ──
document.addEventListener('click', async e => {
  const badge = e.target.closest('.lock-badge-annuel');
  if (!badge) return;
  const ag     = badge.dataset.agent;
  const locked = badge.classList.contains('lba-locked');
  const action = locked ? 'unlock_agent' : 'lock_agent';
  const r = await ajax({action, agent: ag});
  if (r.ok) {
    const nowLocked = !locked;
    badge.classList.toggle('lba-locked', nowLocked);
    badge.classList.toggle('lba-free',   !nowLocked);
    badge.style.background  = nowLocked ? '#c0392b' : '#e8f5e9';
    badge.style.color       = nowLocked ? '#fff'    : '#27ae60';
    badge.style.borderColor = nowLocked ? '#c0392b' : '#c8e6c9';
    badge.innerHTML = (nowLocked ? '&#9632; ' : '&#9633; ') + ag;
    // Sync cellule annuelle dans la grille
    const cellA = document.querySelector('.lock-cell-annuel[data-agent="'+ag+'"]');
    if (cellA) {
      cellA.classList.toggle('la-locked', nowLocked);
      cellA.classList.toggle('la-free',   !nowLocked);
      cellA.style.background = nowLocked ? '#fdecea' : '#f9f9f9';
      cellA.innerHTML = nowLocked ? '&#9632;' : '&middot;';
    }
    showToast(nowLocked ? ag+' verrouillé toute l\'année 🔒' : ag+' déverrouillé');
  } else showToast('Erreur', false);
});

// ── Tout verrouiller / déverrouiller l'année ──
document.getElementById('btn-lock-all-mois')?.addEventListener('click', async () => {
  const agents=[...document.querySelectorAll('.lock-cell-mois')].map(c=>c.dataset.agent).filter((v,i,a)=>a.indexOf(v)===i);
  const moisList=[...document.querySelectorAll('th[data-mois-col]')].map(th=>th.dataset.moisCol);
  if (!confirm('Verrouiller TOUS les agents pour TOUS les mois de l\'année ?')) return;
  for (const ag of agents) {
    for (const mo of moisList) {
      await ajax({action:'lock_agent_mois', agent:ag, mois:mo});
    }
  }
  location.reload();
});
document.getElementById('btn-unlock-all-mois')?.addEventListener('click', async () => {
  const agents=[...document.querySelectorAll('.lock-cell-mois')].map(c=>c.dataset.agent).filter((v,i,a)=>a.indexOf(v)===i);
  const moisList=[...document.querySelectorAll('th[data-mois-col]')].map(th=>th.dataset.moisCol);
  if (!confirm('Déverrouiller TOUS les agents pour TOUS les mois de l\'année ?')) return;
  for (const ag of agents) {
    for (const mo of moisList) {
      await ajax({action:'unlock_agent_mois', agent:ag, mois:mo});
    }
  }
  location.reload();
});

// ── Verrou par colonne de mois (ligne boutons dans grille admin) ──
document.addEventListener('click', async e => {
  const cell = e.target.closest('.btn-lock-col-mois');
  if (!cell) return;
  const moisCol = cell.dataset.mois;
  // Collecter tous les agents de la grille pour ce mois
  const agentCells = [...document.querySelectorAll(`.lock-cell-mois[data-mois="${moisCol}"]`)];
  const agents = agentCells.map(c => c.dataset.agent).filter(Boolean);
  if (!agents.length) return;
  // État actuel : si au moins 1 verrouillé → déverrouiller tout, sinon verrouiller tout
  const anyLocked = agentCells.some(c => c.classList.contains('lc-locked'));
  const action = anyLocked ? 'unlock_agent_mois' : 'lock_agent_mois';
  const label  = anyLocked ? 'Déverrouiller' : 'Verrouiller';
  if (!confirm(`${label} TOUS les agents pour ${moisCol} ?`)) return;

  for (const ag of agents) {
    await ajax({action, agent: ag, mois: moisCol});
    if (!LOCKED_AGENTS_MOIS[ag]) LOCKED_AGENTS_MOIS[ag] = {};
    if (action === 'lock_agent_mois') LOCKED_AGENTS_MOIS[ag][moisCol] = true;
    else delete LOCKED_AGENTS_MOIS[ag][moisCol];
  }
  const nowLocked = !anyLocked;
  // Mettre à jour les cellules de la grille
  agentCells.forEach(c => {
    c.classList.toggle('lc-locked', nowLocked);
    c.classList.toggle('lc-free',   !nowLocked);
    c.style.background = nowLocked ? '#ffe0b2' : '#f9f9f9';
    c.innerHTML = nowLocked ? '&#9632;' : '&middot;';
  });
  // Mettre à jour le bouton de colonne lui-même
  const nbVerr = nowLocked ? agents.length : 0;
  cell.innerHTML = nowLocked ? '&#9632;' : '&middot;';
  cell.style.background = nowLocked ? '#e67e22' : '#f9f9f9';
  cell.style.color = nowLocked ? '#fff' : '#ccc';
  cell.title = (nowLocked ? 'Tout déverrouiller' : 'Tout verrouiller') + ` — ${moisCol} (${nbVerr}/${agents.length} verrouillés)`;
  // Sync boutons inline planning si c'est le mois affiché
  if (moisCol === CUR_MOIS) {
    document.querySelectorAll(`.inline-lock-btn[data-mois="${moisCol}"]`).forEach(b => {
      b.innerHTML = nowLocked ? '&#9632;' : '&#9633;';
      b.style.background = nowLocked ? '#e67e22' : '#27ae60';
      b.dataset.locked = nowLocked ? '1' : '0';
    });
    // Mettre à jour le bouton global toolbar
    const btnG = document.getElementById('btn-lock-mois-global');
    if (btnG) {
      btnG.innerHTML = (nowLocked ? '&#9632; ' : '&#9633; ') + CUR_MOIS;
      btnG.style.background = nowLocked ? '#c0392b' : '#27ae60';
    }
  }
  showToast(nowLocked ? `${moisCol} — tout verrouillé &#9632;` : `${moisCol} — tout déverrouillé &#9633;`);
});


// ── Verrou mensuel global (tous agents du mois affiché) ──
const btnLockMoisGlobal = document.getElementById('btn-lock-mois-global');
if(btnLockMoisGlobal){
  btnLockMoisGlobal.addEventListener('click', async ()=>{
    // Collecter les agents depuis les boutons inline qui ont data-mois = CUR_MOIS
    // (le data-agent est sur les boutons inline-lock-btn, pas sur les TR)
    const btns = [...document.querySelectorAll('.inline-lock-btn[data-mois="'+CUR_MOIS+'"]')];
    const agentList = btns.map(b=>b.dataset.agent).filter(Boolean);
    if(agentList.length===0){
      showToast('Aucun agent trouvé pour ce mois',false); return;
    }
    // Vérifier via LOCKED_AGENTS_MOIS
    const anyLocked = agentList.some(ag=>LOCKED_AGENTS_MOIS[ag]&&LOCKED_AGENTS_MOIS[ag][CUR_MOIS]);
    const action = anyLocked ? 'unlock_agent_mois' : 'lock_agent_mois';
    const label  = anyLocked ? 'Déverrouiller' : 'Verrouiller';
    if(!confirm(`${label} TOUS les agents pour le mois ${CUR_MOIS} ?`)) return;

    for(const ag of agentList){
      await ajax({action, agent:ag, mois:CUR_MOIS});
      if(action==='lock_agent_mois'){
        if(!LOCKED_AGENTS_MOIS[ag]) LOCKED_AGENTS_MOIS[ag]={};
        LOCKED_AGENTS_MOIS[ag][CUR_MOIS]=true;
      } else {
        if(LOCKED_AGENTS_MOIS[ag]) delete LOCKED_AGENTS_MOIS[ag][CUR_MOIS];
      }
    }
    const nowLocked = !anyLocked;
    // Mettre à jour l'icône du bouton global
    btnLockMoisGlobal.innerHTML = nowLocked ? `&#9632; ${CUR_MOIS}` : `&#9633; ${CUR_MOIS}`;
    btnLockMoisGlobal.style.background = nowLocked ? '#c0392b' : '#27ae60';
    // Mettre à jour tous les boutons inline du planning
    document.querySelectorAll('.inline-lock-btn[data-mois="'+CUR_MOIS+'"]').forEach(b=>{
      b.innerHTML = nowLocked ? '&#9632;' : '&#9633;';
      b.style.background = nowLocked ? '#e67e22' : '#27ae60';
      b.dataset.locked = nowLocked ? '1' : '0';
    });
    // Mettre à jour la grille verrous dans le panneau admin
    document.querySelectorAll('.lock-cell-mois[data-mois="'+CUR_MOIS+'"]').forEach(cell=>{
      cell.classList.toggle('lc-locked', nowLocked);
      cell.classList.toggle('lc-free',  !nowLocked);
      cell.style.background = nowLocked ? '#ffe0b2' : '#f9f9f9';
      cell.innerHTML = nowLocked ? '&#9632;' : '&middot;';
    });
    showToast(nowLocked ? `${CUR_MOIS} — tout verrouillé 🔒` : `${CUR_MOIS} — tout déverrouillé 🔓`);
  });
}

// ── Récap quotas individuels ──
const overlayQuotaRecap = document.getElementById('overlay-quota-recap');
const quotaRecapBody    = document.getElementById('quota-recap-body');

document.getElementById('btn-open-quota-recap')?.addEventListener('click', async ()=>{
  quotaRecapBody.innerHTML='<p style="color:#888;text-align:center;padding:20px">Chargement...</p>';
  overlayQuotaRecap.classList.add('open');
  // Déterminer l'agent affiché (non-admin = mon agent ; admin = récap de tous les policiers)
  const yr = ANNEE;
  const [rQ, rC] = await Promise.all([
    ajax({action:'get_quotas', annee:yr}),
    ajax({action:'get_conges_count', annee:yr})
  ]);
  if(!rQ.ok){ quotaRecapBody.innerHTML='<p style="color:red">Erreur chargement quotas</p>'; return; }

  document.getElementById('quota-recap-titre').textContent = `${yr} — Policiers`;
  const quotas = rQ.quotas||{};
  const counts = (rC.counts)||{};
  const caHorsPeriode = (rC.ca_hors_periode)||{};

  // Calcule le HP acquis selon la règle Police (périodes basses : jan-avr et oct-déc)
  function hpAcquis(ag) {
    const caHP = caHorsPeriode[ag] || 0;
    if (caHP >= 8) return 2;
    if (caHP >= 5) return 1;
    return 0;
  }

  const TYPE_LABELS = {CA:'Congé annuel',HP:'Congés Annuels Hors Période',HPA:'Congés Annuels Antérieurs Hors Période', CAM: 'Congés Annuels Maladie',RTT:'RTT',CET:'CET',CF:'Crédit Férié (j)',RTC:'RTC (h)',CF_H:'CF (h)'};
  let html='';

  const agents = IS_ADMIN ? Object.keys(quotas) : (MY_AGENT ? [MY_AGENT] : Object.keys(quotas));

  agents.forEach(ag=>{
    const types = quotas[ag]||{};
    if(!Object.keys(types).length) return;
    const agCounts = counts[ag]||{};
    html+=`<div style="background:#f7f9ff;border:1.5px solid #dde5f0;border-radius:10px;padding:12px 14px;margin-bottom:12px">
      <div style="font-size:.82rem;font-weight:800;color:#1a2742;margin-bottom:10px;border-bottom:1px solid #dde5f0;padding-bottom:6px">${ag}</div>`;
    Object.entries(types).forEach(([tc,q])=>{
      const isH=(q.unite==='heures');
      // Pour HP : le quota affiché est le HP acquis selon la règle (CA en périodes basses)
      const effectiveQuota = (tc==='HP' && !isH) ? hpAcquis(ag) : q.quota;
      const used=isH?'—':(agCounts[tc]||0);
      const over=!isH&&used>effectiveQuota;
      const pct=!isH&&effectiveQuota>0?Math.round(used/effectiveQuota*100):null;
      const barW=!isH&&effectiveQuota>0?Math.min(100,Math.round(used/effectiveQuota*100)):0;
      const barCol=over?'#c0392b':barW>80?'#e67e22':'#27ae60';
      const lbl=TYPE_LABELS[tc]||tc;
      // Pour HP : afficher un sous-titre explicatif
      const caHP = tc==='HP' ? (caHorsPeriode[ag]||0) : null;
      const hpSubtitle = caHP!==null ? ` <span style="font-size:.62rem;color:#888;font-weight:400">(${caHP} CA hors pér. → ${effectiveQuota}j acquis)</span>` : '';
      html+=`<div style="margin-bottom:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px">
          <span style="font-size:.72rem;font-weight:700;color:#1a2742">${lbl}${hpSubtitle}</span>
          <span style="font-size:.72rem;font-weight:700;color:${over?'#c0392b':'#555'}">
            ${isH?q.quota+'h (stock)':over?'⚠ '+used+'/'+effectiveQuota+' j':''+used+'/'+effectiveQuota+' j'+(pct!==null?' — '+pct+'%':'')}
          </span>
        </div>`;
      if(!isH&&q.quota>0){
        html+=`<div style="background:#e8eef6;border-radius:4px;height:8px;overflow:hidden">
          <div style="background:${barCol};height:100%;width:${barW}%;transition:width .3s"></div>
        </div>`;
      }
      html+='</div>';
    });
    html+='</div>';
  });

  if(!html) html='<p style="color:#888;padding:20px;text-align:center">Aucun quota trouvé pour cette année.</p>';
  quotaRecapBody.innerHTML=html;
});
document.getElementById('btn-x-quota-recap').addEventListener('click',()=>overlayQuotaRecap.classList.remove('open'));
overlayQuotaRecap.addEventListener('click',e=>{if(e.target===overlayQuotaRecap)overlayQuotaRecap.classList.remove('open');});

// ── Onglet Récap Congés par groupe ──────────────────────────────────────────
// Agents douane exclus de l'onglet congés admin (MOREAU, LOIN, DEMERVAL)
const CR_DOUANE_EXCLUS = ['IR MOREAU','ACP1 DEMERVAL','ACP1 LOIN'];
const CR_GROUPES = [
  { lbl:'Direction',     agents:['CoGe ROUSSEL','Cne MOKADEM'] },
  { lbl:'Équipe 1',      agents:['BC BOUXOM','BC ARNAULT','BC HOCHARD'] },
  { lbl:'Équipe 2',      agents:['BC DUPUIS','BC BASTIEN','BC ANTHONY'] },
  { lbl:'Nuit',          agents:['BC MASSON','BC SIGAUD','BC DAINOTTI'] },
  { lbl:'Analyse',       agents:['GP DHALLEWYN','BC DELCROIX'] },
  { lbl:'GIE',           agents:['ADJ LEFEBVRE','ADJ CORRARD'] },
  { lbl:'Secrétariat',   agents:['AA MAES'] },
  { lbl:'Informatique',  agents:['BC DRUEZ'] },
];

const CR_TYPE_LBL = {
  CA:'CA','HP':'HP','HPA':'HPA','CAM':'CAM','RTT':'RTT',
  CET:'CET','CF':'CF','DA':'DA','PR':'PR','HS':'HS',
  CMO:'CMO','CLM':'CLM','CLD':'CLD','PREV':'Prév.','AUT':'Autres'
};

function hpAcquisCR(ag, caHors) {
  const n = caHors[ag]||0;
  return n>=8?2:n>=5?1:0;
}

async function loadCongesRecap(){
  const body = document.getElementById('cr-body');
  const yr   = parseInt(document.getElementById('cr-annee').value);
  body.innerHTML = '<p style="color:#888;text-align:center;padding:16px">Chargement…</p>';

  const [rQ, rC] = await Promise.all([
    ajax({action:'get_quotas',       annee:yr}),
    ajax({action:'get_conges_count', annee:yr})
  ]);
  if(!rQ.ok||!rC.ok){ body.innerHTML='<p style="color:red;padding:12px">Erreur chargement</p>'; return; }

  const quotas   = rQ.quotas   || {};
  const counts   = rC.counts   || {};
  const caHors   = rC.ca_hors_periode || {};

  // Codes autorisés dans l'onglet congés admin (exclus : effectifs douane)
  const ADMIN_CR_ALLOWED = ['CA','CAA','HP','HPA','RTT','CET','CF','RPS','HS'];

  // Collecter tous les types de congés présents (pour les en-têtes de colonnes)
  // On exclut les agents douane du calcul des types présents
  const countsHorsDuane = Object.fromEntries(
    Object.entries(counts).filter(([ag]) => !CR_DOUANE_EXCLUS.includes(ag))
  );
  const quotasHorsDuane = Object.fromEntries(
    Object.entries(quotas).filter(([ag]) => !CR_DOUANE_EXCLUS.includes(ag))
  );
  const typesSet = new Set();
  Object.values(quotasHorsDuane).forEach(q=>Object.keys(q).forEach(t=>{ if((quotasHorsDuane[Object.keys(quotasHorsDuane)[0]]||{})[t]?.unite!=='heures') typesSet.add(t); }));
  Object.values(countsHorsDuane).forEach(c=>Object.keys(c).forEach(t=>typesSet.add(t)));
  Object.values(quotasHorsDuane).forEach(q=>Object.entries(q).forEach(([t,v])=>{ if(v.unite==='heures') typesSet.delete(t); }));
  // Filtrer pour ne garder que les compteurs autorisés (CA, CAA, HP, HPA, RTT, CET si présent, CF, RPS, HS)
  const TYPES = [...typesSet].filter(t=>ADMIN_CR_ALLOWED.includes(t));

  // Bordure épaisse entre les groupes de compteurs (à droite de la col "Reste")
  const SEP_STYLE = 'border-right:3px solid #1a2742;';

  let html = `<div style="overflow-x:auto">
  <table style="border-collapse:collapse;font-size:11.5px;width:100%">
  <thead>
    <tr style="background:#1a2742;color:#ffd600;text-align:center">
      <th style="text-align:left;padding:6px 10px;min-width:130px;position:sticky;left:0;background:#1a2742;border-right:3px solid #253560">Agent</th>`;
  TYPES.forEach((t,idx)=>{
    const isLast = idx===TYPES.length-1;
    html+=`<th style="padding:5px 7px;white-space:nowrap;${isLast?'':SEP_STYLE}" colspan="2">${CR_TYPE_LBL[t]||t}</th>`;
  });
  html+=`</tr>
    <tr style="background:#253560;color:#aac8ff;text-align:center;font-size:10px">
      <th style="position:sticky;left:0;background:#253560;border-right:3px solid #1a2742"></th>`;
  TYPES.forEach((t,idx)=>{
    const isLast = idx===TYPES.length-1;
    html+=`<th style="padding:3px 5px">Posé</th><th style="padding:3px 5px;${isLast?'':SEP_STYLE}">Reste</th>`;
  });
  html+=`</tr></thead><tbody>`;

  CR_GROUPES.forEach(({lbl, agents})=>{
    html+=`<tr style="background:#e8eef6">
      <td colspan="${1+TYPES.length*2}" style="padding:4px 10px;font-size:10.5px;font-weight:800;color:#1a2742;letter-spacing:.04em;text-transform:uppercase">${lbl}</td>
    </tr>`;
    agents.forEach((ag,i)=>{
      const agCounts  = counts[ag]  || {};
      const agQuotas  = quotas[ag]  || {};
      const rowBg = i%2===0?'#f7f9ff':'#fff';
      html+=`<tr style="background:${rowBg}">
        <td style="padding:5px 10px;font-weight:700;color:#1a2742;position:sticky;left:0;background:${rowBg};white-space:nowrap;border-right:3px solid #d0d8f0">${ag}</td>`;
      TYPES.forEach((t,idx)=>{
        const isLast = idx===TYPES.length-1;
        const used  = agCounts[t] || 0;
        const qObj  = agQuotas[t];
        const quota = qObj ? (t==='HP' ? hpAcquisCR(ag,caHors) : qObj.quota) : null;
        const reste = quota !== null ? quota - used : null;
        const over  = reste !== null && reste < 0;
        const hasQ  = quota !== null && quota > 0;
        // Couleur reste
        const restCol = over ? '#c0392b' : reste===0 ? '#e67e22' : '#27ae60';
        const posCol  = used>0 ? '#1565c0' : '#bbb';
        // Affichage : pas de décimales inutiles (.0)
        const usedFmt = used % 1 === 0 ? used : used.toFixed(1);
        const resteFmt = reste === null ? null : (reste % 1 === 0 ? reste : reste.toFixed(1));
        html+=`<td style="text-align:center;padding:4px 6px;color:${posCol};font-weight:${used>0?'700':'400'}">${used>0?usedFmt:'—'}</td>`;
        html+=`<td style="text-align:center;padding:4px 6px;font-weight:${hasQ?'700':'400'};color:${hasQ?restCol:'#ccc'};${isLast?'':SEP_STYLE}">${hasQ?(over?'⚠'+resteFmt:resteFmt):'—'}</td>`;
      });
      html+='</tr>';
    });
  });

  html+=`</tbody></table></div>`;

  body.innerHTML = html;
}

document.getElementById('btn-cr-load')?.addEventListener('click', loadCongesRecap);
// Chargement auto à l'activation de l'onglet
document.addEventListener('click', e=>{
  if(e.target.closest('.adm-tab[data-tab="conges-recap"]')) loadCongesRecap();
});

// Gestion utilisateurs
const _userDataMap = new WeakMap();
async function loadUsers(){
  const wrap=document.getElementById('users-list');
  if(!wrap) return;
  wrap.innerHTML='<p style="color:#888;font-size:.8rem">Chargement...</p>';
  const r=await ajax({action:'list_users'});
  if(!r.ok){wrap.innerHTML='<p style="color:red">Erreur</p>';return;}
  wrap.innerHTML='';
  r.users.forEach(u=>{
    const div=document.createElement('div');
    div.className='user-row';
    div.innerHTML=`<span class="user-badge badge-${u.role}">${u.role.toUpperCase()}</span>
      <span style="flex:1;font-weight:600">${u.login}</span>
      <span style="color:#888;font-size:.75rem">${u.nom||''}</span>
      ${u.must_change_password==1?'<span style="font-size:.68rem;background:#fff3e0;color:#e65100;padding:1px 5px;border-radius:4px">⚠️ mdp temp.</span>':''}
      <span style="color:#888;font-size:.72rem">${u.role==='user'?(u.agents.length?u.agents.join(', '):'Aucun agent'):'Tous'}</span>
      ${u.role==='user'?`<span style="font-size:.68rem;padding:1px 6px;border-radius:4px;background:#e8f5e9;color:#2e7d32">${u.can_conge?'📅':''}</span><span style="font-size:.68rem;padding:1px 6px;border-radius:4px;background:#e3f0ff;color:#1a2742">${u.can_perm?'🔆':''}</span>`:''}
      <button class="btn-action btn-reset-pass" data-uid="${u.id}" data-login="${u.login}" title="Réinitialiser à 'password'" style="padding:2px 8px;font-size:.72rem;background:#fff3e0;color:#e65100">🔄</button>
      <button class="btn-action btn-perm btn-edit-user" style="padding:2px 8px;font-size:.72rem">✏️</button>
      <button class="btn-action btn-del-user" data-uid="${u.id}" style="padding:2px 8px;font-size:.72rem;background:#fdecea;color:#c0392b">🗑</button>`;
    // Stocker l'objet user directement sur le bouton (évite les problèmes d'échappement HTML)
    const editBtn = div.querySelector('.btn-edit-user');
    _userDataMap.set(editBtn, u);
    wrap.appendChild(div);
  });
  // Reset mot de passe
  wrap.querySelectorAll('.btn-reset-pass').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      if(!confirm('Réinitialiser le mot de passe de '+btn.dataset.login+' ?\nUn mot de passe temporaire aléatoire sera généré.')) return;
      const r=await ajax({action:'reset_password',uid:btn.dataset.uid});
      if(r.ok && r.msg){
        alert('✅ Nouveau mot de passe temporaire pour '+btn.dataset.login+' :\n\n'+r.msg+'\n\nCommuniquez-le à l\'agent. Il devra le changer à la prochaine connexion.');
      } else { showToast(r.msg||'Erreur',r.ok); }
      if(r.ok) loadUsers();
    });
  });
  // Handlers edit/delete
  wrap.querySelectorAll('.btn-edit-user').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const u=_userDataMap.get(btn);
      if(u) openUserForm(u);
    });
  });
  wrap.querySelectorAll('.btn-del-user').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      if(!confirm('Supprimer cet utilisateur ?')) return;
      const r=await ajax({action:'delete_user',uid:btn.dataset.uid});
      if(r.ok){showToast('Utilisateur supprimé');loadUsers();}
      else showToast(r.msg||'Erreur',false);
    });
  });
}

function openUserForm(u=null){
  const overlay=document.getElementById('user-form-overlay');
  overlay.style.display='flex';
  document.getElementById('user-form-title').textContent=u?'Modifier : '+u.login:'Nouvel utilisateur';
  document.getElementById('f-uid').value=u?u.id:0;
  document.getElementById('f-login').value=u?u.login:'';
  document.getElementById('f-nom').value=u?u.nom:'';
  document.getElementById('f-role').value=u?u.role:'user';
  document.getElementById('f-pass').value='';
  document.getElementById('pass-hint').style.display=u?'inline':'none';
  // Agents
  const agWrap=document.getElementById('f-agents-wrap');
  agWrap.style.display=(document.getElementById('f-role').value==='admin')?'none':'block';
  document.querySelectorAll('#f-agents input[type=checkbox]').forEach(cb=>{
    cb.checked=u&&u.agents?u.agents.includes(cb.value):false;
  });
  document.getElementById('f-role').onchange=()=>{
    agWrap.style.display=(document.getElementById('f-role').value==='admin')?'none':'block';
  };
  // Droits
  document.getElementById('f-can-conge').checked=u?!!u.can_conge:true;
  document.getElementById('f-can-perm').checked=u?!!u.can_perm:false;
}

document.getElementById('btn-new-user').addEventListener('click',()=>openUserForm(null));
function closeUserForm(){document.getElementById('user-form-overlay').style.display='none';}
document.getElementById('btn-cancel-user').addEventListener('click',closeUserForm);
document.getElementById('btn-cancel-user-2').addEventListener('click',closeUserForm);
document.getElementById('user-form-overlay').addEventListener('click',e=>{if(e.target===document.getElementById('user-form-overlay'))closeUserForm();});
document.getElementById('btn-create-agents').addEventListener('click',async()=>{
  if(!confirm('Créer un compte pour chaque agent du planning ?\nLogin = NOM DE FAMILLE, mot de passe TEMPORAIRE ALÉATOIRE.\nLes comptes existants ne seront pas modifiés.')) return;
  const r=await ajax({action:'create_agent_users'});
  if(r.ok && r.comptes && r.comptes.length>0){
    let txt='✅ '+r.msg+'\n\n📋 MOTS DE PASSE TEMPORAIRES (à communiquer aux agents) :\n\n';
    r.comptes.forEach(c=>{ txt+=c.login+' → '+c.mdp+'\n'; });
    txt+='\nChaque agent devra changer son mot de passe à la première connexion.';
    alert(txt);
  } else { showToast(r.msg,r.ok); }
  if(r.ok) loadUsers();
});
document.getElementById('btn-fix-gie').addEventListener('click',async()=>{
  if(!confirm('Donner à ADJ LEFEBVRE et ADJ CORRARD un accès mutuel à la ligne de l\'autre ?\n(Les comptes LEFEBVRE et CORRARD doivent déjà exister.)')) return;
  const r=await ajax({action:'fix_gie_agents'});
  showToast(r.msg,r.ok);if(r.ok) loadUsers();
});

document.getElementById('btn-save-user').addEventListener('click',async()=>{
  const agents=[];
  document.querySelectorAll('#f-agents input[type=checkbox]:checked').forEach(cb=>agents.push(cb.value));
  const r=await ajax({
    action:'save_user',
    uid:document.getElementById('f-uid').value,
    ulogin:document.getElementById('f-login').value,
    unom:document.getElementById('f-nom').value,
    urole:document.getElementById('f-role').value,
    upass:document.getElementById('f-pass').value,
    uagents:JSON.stringify(agents),
    can_conge:document.getElementById('f-can-conge').checked?'1':'0',
    can_perm:document.getElementById('f-can-perm').checked?'1':'0'
  });
  if(r.ok){showToast('Utilisateur enregistré');closeUserForm();loadUsers();}
  else showToast(r.msg||'Erreur',false);
});

// Changement mot de passe
document.getElementById('btn-change-pass').addEventListener('click',async()=>{
  const newP2=document.getElementById('new-pass').value;
  if(newP2.length<8){const msg2=document.getElementById('pass-msg');msg2.textContent='Mot de passe trop court (min 8 car.)';msg2.style.color='#c0392b';return;}
  const r=await ajax({
    action:'change_password',
    old_pass:document.getElementById('old-pass').value,
    new_pass:newP2
  });
  const msg=document.getElementById('pass-msg');
  msg.textContent=r.msg;msg.style.color=r.ok?'#27ae60':'#c0392b';
  if(r.ok){document.getElementById('old-pass').value='';document.getElementById('new-pass').value='';}
});
<?php endif;?>

<?php if($vue==='mois' && canEditNotes($isAdmin,$userAgents)): ?>
/* ── NOTES ── */
(function(){
  const ANNEE=<?=$annee?>, MOIS=<?=$mois?>;
  const MLC=['','Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
  const MOIS_STR=String(MOIS).padStart(2,'0');
  const NBJOURSM=new Date(ANNEE,MOIS,0).getDate();

  // Init champ date événement
  const inpDate=document.getElementById('notes-evt-date');
  inpDate.value=`${ANNEE}-${MOIS_STR}-01`;

  // Init champ date_fin message = fin du mois courant
  const inpFin=document.getElementById('notes-msg-datefin');
  inpFin.value=`${ANNEE}-${MOIS_STR}-${String(NBJOURSM).padStart(2,'0')}`;
  inpFin.min=new Date().toISOString().split('T')[0];

  async function loadNotes(){
    const r=await ajax({action:'get_notes',annee:ANNEE,mois:MOIS});
    if(!r.ok) return;
    renderEvts(r.evenements||[]);
    renderMsgs(r.messages||[]);
    renderArch(r.archives||[]);
  }

  /* ── Événements ── */
  function renderEvts(evts){
    const ul=document.getElementById('notes-evts-list');
    const sorted=[...evts].sort((a,b)=>a.date.localeCompare(b.date));
    if(!sorted.length){ul.innerHTML='<div class="notes-empty">Aucun événement</div>';return;}
    ul.innerHTML=sorted.map(e=>{
      const d=new Date(e.date+'T00:00:00');
      const label=d.getDate()+' '+MLC[d.getMonth()+1];
      return `<div class="notes-evt-item" data-id="${e.id}">
        <span class="notes-evt-date">${label}</span>
        <span class="notes-evt-label">${e.libelle}</span>
        <span class="notes-evt-del" title="Supprimer" data-id="${e.id}">✕</span>
      </div>`;
    }).join('');
    ul.querySelectorAll('.notes-evt-del').forEach(btn=>{
      btn.addEventListener('click',async()=>{
        if(!confirm('Supprimer cet événement ?')) return;
        const r=await ajax({action:'delete_note_evt',evt_id:parseInt(btn.dataset.id)});
        if(r.ok) loadNotes(); else showToast('Erreur',false);
      });
    });
  }

  /* ── Messages actifs ── */
  function renderMsgs(msgs){
    const ul=document.getElementById('notes-msgs-list');
    if(!msgs.length){ul.innerHTML='<div class="notes-empty">Aucun message actif</div>';return;}
    ul.innerHTML=msgs.map(m=>{
      const fin=new Date(m.date_fin+'T00:00:00');
      const finLabel=fin.getDate()+' '+MLC[fin.getMonth()+1];
      const today=new Date(); today.setHours(0,0,0,0);
      const diff=Math.round((fin-today)/(1000*60*60*24));
      const urgCol=diff<=2?'#c0392b':diff<=5?'#e67e22':'#27ae60';
      return `<div style="background:#f0f7ff;border:1px solid #c5daf5;border-radius:7px;padding:7px 9px;margin-bottom:7px">
        <div style="font-size:.73rem;color:#222;line-height:1.4;margin-bottom:5px">${m.texte.replace(/\n/g,'<br>')}</div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:.65rem;color:${urgCol};font-weight:700">⏱ jusqu'au ${finLabel}</span>
          <span class="notes-msg-del" data-id="${m.id}" title="Supprimer" style="color:#e74c3c;cursor:pointer;font-size:.75rem">✕</span>
        </div>
      </div>`;
    }).join('');
    ul.querySelectorAll('.notes-msg-del').forEach(btn=>{
      btn.addEventListener('click',async()=>{
        if(!confirm('Supprimer ce message ?')) return;
        const r=await ajax({action:'delete_note_msg',msg_id:parseInt(btn.dataset.id)});
        if(r.ok) loadNotes(); else showToast('Erreur',false);
      });
    });
  }

  /* ── Archives ── */
  function renderArch(arch){
    const ul=document.getElementById('notes-arch-list');
    if(!arch.length){ul.innerHTML='<div class="notes-empty">Aucune archive</div>';return;}
    ul.innerHTML=arch.map(m=>{
      const fin=new Date(m.date_fin+'T00:00:00');
      const finLabel=fin.getDate()+' '+MLC[fin.getMonth()+1];
      return `<div style="background:#f8f8f8;border:1px solid #ddd;border-radius:7px;padding:6px 9px;margin-bottom:5px;opacity:.75">
        <div style="font-size:.71rem;color:#555;line-height:1.4;margin-bottom:4px">${m.texte.replace(/\n/g,'<br>')}</div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:.63rem;color:#999">expiré le ${finLabel}</span>
          <span class="notes-arch-del" data-id="${m.id}" title="Supprimer définitivement" style="color:#bbb;cursor:pointer;font-size:.72rem">✕</span>
        </div>
      </div>`;
    }).join('');
    ul.querySelectorAll('.notes-arch-del').forEach(btn=>{
      btn.addEventListener('click',async()=>{
        if(!confirm('Supprimer définitivement cette archive ?')) return;
        const r=await ajax({action:'delete_note_msg',msg_id:parseInt(btn.dataset.id)});
        if(r.ok) loadNotes(); else showToast('Erreur',false);
      });
    });
  }

  /* ── Ajout événement ── */
  document.getElementById('notes-evt-add').addEventListener('click',async()=>{
    const date=document.getElementById('notes-evt-date').value;
    const label=document.getElementById('notes-evt-label').value.trim();
    if(!label){showToast('Saisir un libellé',false);return;}
    if(!date){showToast('Saisir une date',false);return;}
    const r=await ajax({action:'save_note_evt',evt_date:date,evt_libelle:label});
    if(r.ok){
      document.getElementById('notes-evt-label').value='';
      if(r.mois===MOIS && r.annee===ANNEE) loadNotes();
      showToast((r.mois!==MOIS||r.annee!==ANNEE)?`Ajouté sur ${MLC[r.mois]} ${r.annee}`:'Événement ajouté');
    } else showToast('Erreur : '+r.msg,false);
  });
  document.getElementById('notes-evt-label').addEventListener('keydown',e=>{
    if(e.key==='Enter') document.getElementById('notes-evt-add').click();
  });

  /* ── Ajout message ── */
  document.getElementById('notes-msg-add').addEventListener('click',async()=>{
    const texte=document.getElementById('notes-msg-texte').value.trim();
    const datefin=document.getElementById('notes-msg-datefin').value;
    if(!texte){showToast('Saisir un message',false);return;}
    if(!datefin){showToast('Choisir une date de fin',false);return;}
    const r=await ajax({action:'save_note_msg',annee:ANNEE,mois:MOIS,texte,date_fin:datefin});
    if(r.ok){
      document.getElementById('notes-msg-texte').value='';
      loadNotes();
      showToast('Message ajouté');
    } else showToast('Erreur : '+r.msg,false);
  });

  loadNotes();

  // ── Widget quota restant dans le panneau notes ──
  window.loadQuotaWidget = async function() {
    const wrap = document.getElementById('quota-widget-body');
    if (!wrap) return;
    wrap.innerHTML = '<p style="color:#aaa;font-style:italic;font-size:.7rem">Chargement...</p>';
    const [rQ, rC] = await Promise.all([
      ajax({action:'get_quotas', annee:ANNEE}),
      ajax({action:'get_conges_count', annee:ANNEE})
    ]);
    if (!rQ.ok || !Object.keys(rQ.quotas||{}).length) {
      wrap.innerHTML = '<p style="color:#aaa;font-style:italic;font-size:.7rem">Aucun quota défini.</p>';
      return;
    }
    const counts = rC.counts || {};
    const caHorsPeriodeW = rC.ca_hors_periode || {};
    // Calcule le HP acquis selon la règle Police (périodes basses : jan-avr et oct-déc)
    function hpAcquisW(ag) {
      const caHP = caHorsPeriodeW[ag] || 0;
      if (caHP >= 8) return 2;
      if (caHP >= 5) return 1;
      return 0;
    }
    const TYPE_LBL = {CA:'Congé annuel',HP:'Congés Annuels Hors Période',HPA:'Congés Annuels Antérieur Hors Période', CAM: 'Congés Annuels Maladie',RTT:'RTT',CET:'CET',CF:'Crédit Férié'};
    let html = '';
    // Pour chaque agent (non-admin = 1 seul, admin = tous)
    Object.entries(rQ.quotas).forEach(([ag, types]) => {
      if (!types || !Object.keys(types).length) return;
      const agCounts = counts[ag] || {};
      if (IS_ADMIN) html += `<div style="font-size:.68rem;font-weight:700;color:#1a2742;margin:6px 0 2px">${ag}</div>`;
      Object.entries(types).forEach(([tc, q]) => {
        if (q.unite === 'heures') return; // On n'affiche que les jours
        // Pour HP : quota effectif = HP acquis selon la règle
        const quota  = (tc === 'HP') ? hpAcquisW(ag) : q.quota;
        const used   = agCounts[tc] || 0;
        const reste  = quota - used;
        const pct    = quota > 0 ? Math.round(used / quota * 100) : 0;
        const barW   = Math.min(100, pct);
        const over   = used > quota;
        const barCol = over ? '#c0392b' : pct > 80 ? '#e67e22' : '#27ae60';
        const lbl    = TYPE_LBL[tc] || tc;
        // Pour HP : sous-titre avec nombre de CA hors période
        const caHPn  = tc === 'HP' ? (caHorsPeriodeW[ag]||0) : null;
        const hpNote = caHPn !== null ? `<span style="font-size:.6rem;color:#888;font-weight:400"> (${caHPn} CA hors pér.)</span>` : '';
        html += `<div style="margin-bottom:6px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:1px">
            <span style="font-weight:600;color:#1a2742">${lbl}${hpNote}</span>
            <span style="color:${over?'#c0392b':barCol};font-weight:700">${over?'⚠ ':''}<b>${reste}</b> j restant${reste>1||reste<-1?'s':''} / ${quota}j</span>
          </div>
          <div style="background:#e8eef6;border-radius:4px;height:6px;overflow:hidden">
            <div style="background:${barCol};height:100%;width:${barW}%"></div>
          </div>
          <div style="text-align:right;font-size:.65rem;color:#888;margin-top:1px">${used} posé${used>1?'s':''} — ${pct}% consommé</div>
        </div>`;
      });
    });
    wrap.innerHTML = html || '<p style="color:#aaa;font-style:italic;font-size:.7rem">Aucun quota jour défini.</p>';
  };
  // Chargement automatique si le widget est présent
  if (document.getElementById('quota-widget-body')) loadQuotaWidget();

})();
<?php endif;?>


// ── Tooltip custom sur toutes les cellules ──────────────────────────────────
(function(){
  const tip=document.createElement('div');
  tip.className='cell-tip';
  document.body.appendChild(tip);

  const CYCLE_LABELS={
    'J':'Journée','M':'Matin','AM':'Après-midi','NUIT':'Nuit',
    'RC':'Repos compensatoire','RL':'Repos légal (dimanche)','FERIE':'Jour férié'
  };
  const PERM_LABELS={
    'M':'Permanence Matin','AM':'Permanence Après-midi',
    'IM':'Indisponibilité Matin','IAM':'Indisponibilité Après-midi','IJ':'Indisponibilité Journée'
  };
  const CONGE_SYM={'M':'☀ Matin','AM':'🌙 Après-midi','J':''};

  function getLabel(td){
    const permType=td.dataset.permType;
    const congeType=td.dataset.congeType;
    const cycle=td.dataset.cycleOrig||td.dataset.cycle||'';
    const tirId=td.dataset.tirId;
    const tirPer=td.dataset.tirPer;
    const per=td.dataset.congePer||'J';
    const heure=td.dataset.congeHeure||'';

    let lines=[];

    // TIR
    if(tirId&&tirId!=='0'){
      const perLbl={M:'Matin',AM:'Après-midi',NUIT:'Nuit',J:'Journée'}[tirPer]||tirPer;
      lines.push('🎯 TIR — '+perLbl);
    }
    // Permanence / Indisponibilité
    if(permType&&permType!==''){
      lines.push('📋 '+(PERM_LABELS[permType]||permType));
    }
    // Congé
    if(congeType&&congeType!==''){
      const sym=CONGE_SYM[per]||'';
      const hLbl=heure?' à '+heure.replace(':','h'):'';
      lines.push('📅 '+congeType+hLbl+(sym?' — '+sym:''));
    }
    // Cycle de base
    if(lines.length===0&&cycle){
      lines.push(CYCLE_LABELS[cycle]||cycle);
    }
    // Jour du mois
    const date=td.dataset.date;
    if(date){
      const d=new Date(date+'T00:00:00');
      const jours=['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
      const mois=['jan','fév','mar','avr','mai','juin','juil','aoû','sep','oct','nov','déc'];
      lines.unshift(jours[d.getDay()]+' '+d.getDate()+' '+mois[d.getMonth()]+' '+d.getFullYear());
    }
    return lines.join('<br>');
  }

  let hideTimer;
  document.addEventListener('mouseover',e=>{
    const td=e.target.closest('td[data-date]');
    if(!td){
      clearTimeout(hideTimer);
      hideTimer=setTimeout(()=>tip.classList.remove('show'),80);
      return;
    }
    clearTimeout(hideTimer);
    const label=getLabel(td);
    if(!label){tip.classList.remove('show');return;}
    tip.innerHTML=label;
    tip.classList.add('show');
  });
  document.addEventListener('mousemove',e=>{
    if(!tip.classList.contains('show'))return;
    let x=e.clientX+14, y=e.clientY+14;
    if(x+tip.offsetWidth>window.innerWidth-10) x=e.clientX-tip.offsetWidth-10;
    if(y+tip.offsetHeight>window.innerHeight-10) y=e.clientY-tip.offsetHeight-10;
    tip.style.left=x+'px'; tip.style.top=y+'px';
  });
  document.addEventListener('mouseout',e=>{
    const td=e.target.closest('td[data-date]');
    if(td){hideTimer=setTimeout(()=>tip.classList.remove('show'),80);}
  });
  // Masquer si modal ouvre
  document.addEventListener('click',()=>tip.classList.remove('show'));
})();

// ── Reset données ────────────────────────────────────────────────────────────
const btnResetData = document.getElementById('btn-reset-data');
if(btnResetData){
  btnResetData.addEventListener('click', async()=>{
    const pwd = document.getElementById('reset-pwd').value.trim();
    if(!pwd){ alert('Saisissez le mot de passe de confirmation.'); return; }
    const scope = document.querySelector('input[name="reset-scope"]:checked')?.value || 'mois';
    const tables = [...document.querySelectorAll('.reset-table:checked')].map(c=>c.value);
    if(!tables.length){ alert('Sélectionnez au moins une table à effacer.'); return; }
    const scopeLbl = scope==='mois' ? 'le mois en cours' : "l'année entière";
    if(!confirm('⚠️ Effacer ' + tables.join(', ') + ' pour ' + scopeLbl + ' ?\nCette action est IRRÉVERSIBLE.')) return;
    btnResetData.disabled=true;
    const result = document.getElementById('reset-result');
    result.textContent = 'Suppression en cours...';
    try{
      const r = await ajax({action:'reset_data', reset_pwd:pwd, scope, tables:JSON.stringify(tables)});
      if(r.ok){
        let msg = '✅ Supprimé : ';
        msg += Object.entries(r.deleted).map(([t,n])=>t+' ('+n+' lignes)').join(', ');
        result.style.color='#27ae60'; result.textContent=msg;
        setTimeout(()=>location.replace(location.pathname+'?annee='+ANNEE+'&mois='+MOIS+'&vue='+VUE), 1500);
      } else {
        result.style.color='#c0392b'; result.textContent='❌ '+r.msg;
        btnResetData.disabled=false;
      }
    }catch(e){
      result.style.color='#c0392b'; result.textContent='❌ Erreur réseau';
      btnResetData.disabled=false;
    }
  });
}
</script>
</body>
</html>
