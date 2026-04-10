<?php
/* =====================================================================
   PLANNING v86 — C.C.P.D. Tournai
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

runMigrationOnce($mysqli, 'create_agents_history_v1', function(mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS agents_history (
      id         INT AUTO_INCREMENT PRIMARY KEY,
      agent      VARCHAR(100) NOT NULL,
      groupe     VARCHAR(40)  NOT NULL,
      date_debut DATE         NOT NULL,
      date_fin   DATE         DEFAULT NULL,
      created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_agent_debut (agent, date_debut)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Peupler avec les agents actuels (date_debut = date la plus ancienne trouvée en base)
    $groupeAgent = [
        'CoGe ROUSSEL'=>'direction_police','Cne MOKADEM'=>'direction_mokadem',
        'LCL PARENT'=>'lcl_parent','IR MOREAU'=>'douane_j',
        'BC MASSON'=>'nuit','BC SIGAUD'=>'nuit','BC DAINOTTI'=>'nuit',
        'BC BOUXOM'=>'equipe','BC ARNAULT'=>'equipe','BC HOCHARD'=>'equipe',
        'BC ANTHONY'=>'equipe','BC BASTIEN'=>'equipe','BC DUPUIS'=>'equipe',
        'ADJ LEFEBVRE'=>'gie','ADJ CORRARD'=>'gie',
        'ACP1 LOIN'=>'douane','GP DHALLEWYN'=>'standard_police',
        'BC DELCROIX'=>'standard_police','ADC LAMBERT'=>'adc_lambert',
        'ACP1 DEMERVAL'=>'douane_j','AA MAES'=>'standard_j','BC DRUEZ'=>'standard_police',
    ];
    foreach ($groupeAgent as $ag => $grp) {
        // Chercher la date la plus ancienne dans les données
        $minDate = null;
        foreach (['conges'=>'date_debut','permanences'=>'date','tir'=>'date','tir_annulations'=>'date'] as $tbl=>$col) {
            $res = $db->query("SELECT MIN($col) FROM $tbl WHERE agent='".addslashes($ag)."'");
            if ($res) {
                $row = $res->fetch_row();
                if ($row[0] && ($minDate===null || $row[0]<$minDate)) $minDate=$row[0];
            }
        }
        $minDate = $minDate ?: '2020-01-01';
        $db->query("INSERT IGNORE INTO agents_history (agent,groupe,date_debut) VALUES ('".addslashes($ag)."','".addslashes($grp)."','$minDate')");
    }

    // Détecter les anciens agents dans les données (absents de groupeAgent)
    $knownAgents = array_keys($groupeAgent);
    $placeholders = implode(',', array_map(fn($a)=>"'".addslashes($a)."'", $knownAgents));
    foreach (['conges'=>'date_debut','permanences'=>'date','tir'=>'date'] as $tbl=>$col) {
        $res = $db->query("SELECT DISTINCT agent, MIN($col) as min_date FROM $tbl WHERE agent NOT IN ($placeholders) GROUP BY agent");
        if ($res) while ($row = $res->fetch_assoc()) {
            $db->query("INSERT IGNORE INTO agents_history (agent,groupe,date_debut,date_fin) VALUES ('".addslashes($row['agent'])."','inconnu','".addslashes($row['min_date'])."','".addslashes($row['min_date'])."')");
        }
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
body {
  background-color: #0077ff; /* Bleu uni, tu peux changer la valeur hexadécimale pour un bleu différent */
}
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
  position:relative;
  display:flex;
  flex-direction:row;
  align-items:center;
  justify-content:center;
  width:100%;
}

.login-side-logo{
  position:absolute;
  width:360px;
  height:360px;
  border-radius:50%;
  object-fit:cover;
  opacity:0.13;
  filter:grayscale(30%);
  top:50%;
  transform:translateY(-50%);
}
.login-side-logo:first-child{ left:240px; }
.login-side-logo:last-child{  right:240px; }
@media(max-width:800px){
  .login-side-logo{display:none !important;}
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

  <!-- Titre institutionnel -->
  <div class="login-header">
    <div class="org-name">C.C.P.D. &mdash; C.P.D.S. Tournai / Doornik</div>
    <div class="org-sub">Planning des effectifs</div>
  </div>

  <!-- Ligne : logo gauche | carte | logo droit -->
  <div class="login-center-row">

    <!-- Logo gauche -->
    <img class="login-side-logo" src="ccpd_tournai.jpg" alt="Logo CCPD Tournai" onerror="this.style.display='none'">

    <!-- Carte connexion -->
    <div class="login-box">

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
      <form method="POST" autocomplete="off">
        <input type="hidden" name="action_auth" value="login">
        <!-- Champs leurres invisibles pour tromper le gestionnaire de mots de passe -->
        <input type="text" style="display:none" aria-hidden="true" tabindex="-1">
        <input type="password" style="display:none" aria-hidden="true" tabindex="-1">
        <label>Identifiant</label>
        <input type="text" id="inp-login" name="login" autocomplete="new-password" required placeholder="Votre identifiant">
        <label>Mot de passe</label>
        <input type="password" id="inp-pass" name="password" autocomplete="new-password" required placeholder="••••••••">
        <button type="submit" class="btn-login">🔓 Se connecter</button>
      </form>
      <div class="login-footer-link">
        <a href="#" id="lnk-forgot">Mot de passe oublié ?</a>
      </div>
    </div>

    <div id="form-forgot" style="display:none">
      <form method="POST" autocomplete="off">
        <input type="hidden" name="action_auth" value="forgot_password">
        <input type="text" style="display:none" aria-hidden="true" tabindex="-1">
        <p style="font-size:.74rem;color:#7a8aaa;margin-bottom:14px">
          Saisissez votre identifiant. Un mot de passe temporaire sera généré. Notez-le bien et changez-le immédiatement après connexion.
        </p>
        <label>Identifiant</label>
        <input type="text" name="login" autocomplete="new-password" required placeholder="Votre identifiant">
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
    <img class="login-side-logo" src="ccpd_tournai.jpg" alt="Logo CCPD Tournai" onerror="this.style.display='none'">

  </div><!-- fin login-center-row -->

  <div class="login-version">C.C.P.D. Tournai</div>
</div>

<script>
  // Vider les champs au chargement pour bloquer le pré-remplissage
  window.addEventListener("load",function(){
    setTimeout(function(){
      var l=document.getElementById("inp-login");
      var p=document.getElementById("inp-pass");
      if(l){l.value="";l.focus();}
      if(p) p.value="";
    },50);
  });
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

    // Corriger les couleurs des congés GIE (M, AM, J, P, R non présents dans types_conges)
    $gieAgentsList = ['ADJ LEFEBVRE', 'ADJ CORRARD'];
    $gieCouleurs = [
        'P'  => ['couleur_bg'=>'#92d050','couleur_txt'=>'#222','libelle'=>'Permission'],
        'J'  => ['couleur_bg'=>'#92d050','couleur_txt'=>'#222','libelle'=>'Journée'],
        'M'  => ['couleur_bg'=>'#ffd200','couleur_txt'=>'#222','libelle'=>'Matin'],
        'AM' => ['couleur_bg'=>'#2f5597','couleur_txt'=>'#ffc000','libelle'=>'Après-midi'],
        'R'  => ['couleur_bg'=>'#bfbfbf','couleur_txt'=>'#333','libelle'=>'Repos'],
    ];
    foreach ($gieAgentsList as $gieAg) {
        if (!isset($cgMeta[$gieAg])) continue;
        foreach ($cgMeta[$gieAg] as $dt => &$meta) {
            $code = $cg[$gieAg][$dt] ?? '';
            if (isset($gieCouleurs[$code])) {
                $meta['couleur_bg']  = $gieCouleurs[$code]['couleur_bg'];
                $meta['couleur_txt'] = $gieCouleurs[$code]['couleur_txt'];
                if ($meta['libelle'] === $code) $meta['libelle'] = $gieCouleurs[$code]['libelle'];
            }
        }
        unset($meta);
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
                $isGieGrp = in_array($grp, ['gie','gie_j']);
                $clsPm  = match($pmType){'M'=>'m','AM'=>'am','IM'=>'perm-indispo-m','IAM'=>'perm-indispo-am','IJ'=>'perm-indispo-j',default=>'m'};
                // Cellule verrouillée visuellement pour non-admin non-GIE si M ou AM
                $permLocked = (!$isAdmin && !$isGieGrp && in_array($pmType,['M','AM'])) ? ' perm-locked' : '';
                $disp   = $isInd ? '<b style=\'color:#e53935\'>✖</b>' : $pmType;
                $sym    = match($pmType){'M'=>'☀','IM'=>'☀','AM'=>'🌙','IAM'=>'🌙',default=>''};
                $tip    = 'Permanence '.match($pmType){'M'=>'Matin','AM'=>'Après-midi','IM'=>'Indisp M','IAM'=>'Indisp AM','IJ'=>'Indisp Journée',default=>$pmType};
                echo "<td class='$clsPm perm-ok$permLocked$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='$pmId2' data-perm-type='$pmType' data-cycle-orig='$pmCyc' data-tir-id='0' title='".htmlspecialchars($tip)."'>$disp$sym</td>";

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
                $feriePermOkAjax = $pP ? ' perm-ok' : '';
                echo "<td class='ferie$feriePermOkAjax$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='FERIE' data-tir-id='0' title='Jour férié'>FERIE</td>";

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
        $agent   = trim($_POST['agent']   ?? '');
        $date    = trim($_POST['date']    ?? '');
        $vacation= trim($_POST['vacation']?? '');
        $grpVac  = $groupeAgent[$agent] ?? '';
        $isDouaneVac = in_array($grpVac, ['douane','douane_j']);
        if (!$isAdmin && !$isDouaneVac) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants — réservé à l\'administrateur']); exit; }        if (!$agent||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!in_array($vacation,['J','M','AM','NUIT'])){
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
        $vacId=(int)($_POST['vac_id']??0);
        if(!$vacId){ echo json_encode(['ok'=>false,'msg'=>'ID manquant']); exit; }
        // Récupérer agent+date avant suppression
        $sInfo=$mysqli->prepare("SELECT agent,date FROM vacation_overrides WHERE id=?");
        $sInfo->bind_param('i',$vacId);$sInfo->execute();
        $rInfo=$sInfo->get_result()->fetch_assoc();
        $agentDel=$rInfo['agent']??'';$dateDel=$rInfo['date']??'';
        $grpDel = $groupeAgent[$agentDel] ?? '';
        $isDouaneDel = in_array($grpDel, ['douane','douane_j']);
        if (!$isAdmin && !$isDouaneDel) { echo json_encode(['ok'=>false,'msg'=>'Droits insuffisants']); exit; }
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
        // Blocage supplémentaire : un non-admin ne peut JAMAIS supprimer un congé non-PREV,
        // même s'il a can_conge=true (ex: GP DHALLEWYN, BC DELCROIX, AA MAES, BC DRUEZ...)
        if ($action === 'delete_conge' && !$isAdmin) {
            $congeIdPosted = (int)($_POST['conge_id'] ?? 0);
            if ($congeIdPosted > 0) {
                $stChkDel = $mysqli->prepare("SELECT type FROM conges WHERE id=?");
                $stChkDel->bind_param('i', $congeIdPosted); $stChkDel->execute();
                $rowChkDel = $stChkDel->get_result()->fetch_assoc();
                if (!$rowChkDel || $rowChkDel['type'] !== 'PREV') {
                    echo json_encode(['ok'=>false,'msg'=>'Suppression réservée à l\'administrateur.']);
                    exit;
                }
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
        // Vérifier le type de la permanence : M et AM ne peuvent pas être supprimées
        // sauf pour le GIE qui a un statut particulier
        $grpAgent = $groupeAgent[$agent] ?? 'standard_j';
        $isGieAgent = in_array($grpAgent, ['gie','gie_j']);
        $stChk = $mysqli->prepare("SELECT type FROM permanences WHERE id=?");
        $stChk->bind_param('i', $perm_id);
        $stChk->execute();
        $rowChk = $stChk->get_result()->fetch_assoc();
        if ($rowChk && in_array($rowChk['type'], ['M','AM']) && !$isGieAgent && !$isAdmin) {
            echo json_encode(['ok'=>false,'msg'=>'Une permanence M ou AM ne peut pas être supprimée.']);
            exit;
        }
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

    /* ── Aperçu remplacement agent ── */
    } elseif ($action==='replace_agent_preview') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $ancien  = trim($_POST['ancien']  ?? '');
        $nouveau = trim($_POST['nouveau'] ?? '');
        $datePrise = trim($_POST['date_prise'] ?? '');
        if(!$ancien||!$nouveau||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datePrise)){
            echo json_encode(['ok'=>false,'msg'=>'Paramètres invalides']); exit;
        }
        // Compter les enregistrements concernés
        $counts = [];
        $tables = [
            'conges'           => "SELECT COUNT(*) FROM conges WHERE agent=? AND date_debut>=?",
            'permanences'      => "SELECT COUNT(*) FROM permanences WHERE agent=? AND date>=?",
            'tir'              => "SELECT COUNT(*) FROM tir WHERE agent=? AND date>=?",
            'tir_annulations'  => "SELECT COUNT(*) FROM tir_annulations WHERE agent=? AND date>=?",
            'tir_notes'        => "SELECT COUNT(*) FROM tir_notes WHERE agent=?",
            'vacation_overrides'=> "SELECT COUNT(*) FROM vacation_overrides WHERE agent=? AND date>=?",
        ];
        foreach($tables as $tbl => $sql){
            $st = $mysqli->prepare($sql);
            if($tbl==='tir_notes') $st->bind_param('s',$ancien);
            else $st->bind_param('ss',$ancien,$datePrise);
            $st->execute();
            $counts[$tbl] = $st->get_result()->fetch_row()[0];
        }
        // Compte utilisateur
        $stU = $mysqli->prepare("SELECT u.id,u.login,u.nom FROM users u JOIN user_agents ua ON ua.user_id=u.id WHERE ua.agent=? LIMIT 1");
        $stU->bind_param('s',$ancien);
        $stU->execute();
        $userRow = $stU->get_result()->fetch_assoc();
        echo json_encode(['ok'=>true,'counts'=>$counts,'user'=>$userRow,'ancien'=>$ancien,'nouveau'=>$nouveau,'date_prise'=>$datePrise]);
        exit;

    /* ── Exécution remplacement agent ── */
    } elseif ($action==='replace_agent_exec') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $ancien       = trim($_POST['ancien']        ?? '');
        $nouveau      = trim($_POST['nouveau']       ?? '');
        $datePrise    = trim($_POST['date_prise']    ?? '');
        $transferUser = ($_POST['transfer_user']??'0')==='1';
        if(!$ancien||!$nouveau||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datePrise)){
            echo json_encode(['ok'=>false,'msg'=>'Paramètres invalides']); exit;
        }
        $mysqli->begin_transaction();
        try {
            // conges : date_debut >= datePrise
            $st=$mysqli->prepare("UPDATE conges SET agent=?,agent_nom=? WHERE agent=? AND date_debut>=?");
            $st->bind_param('ssss',$nouveau,$nouveau,$ancien,$datePrise);
            $st->execute(); $cnt['conges']=$st->affected_rows;
            // permanences
            $st=$mysqli->prepare("UPDATE permanences SET agent=? WHERE agent=? AND date>=?");
            $st->bind_param('sss',$nouveau,$ancien,$datePrise);
            $st->execute(); $cnt['permanences']=$st->affected_rows;
            // tir
            $st=$mysqli->prepare("UPDATE tir SET agent=? WHERE agent=? AND date>=?");
            $st->bind_param('sss',$nouveau,$ancien,$datePrise);
            $st->execute(); $cnt['tir']=$st->affected_rows;
            // tir_annulations
            $st=$mysqli->prepare("UPDATE tir_annulations SET agent=? WHERE agent=? AND date>=?");
            $st->bind_param('sss',$nouveau,$ancien,$datePrise);
            $st->execute(); $cnt['tir_annulations']=$st->affected_rows;
            // tir_notes (pas de date — transférer entièrement)
            $st=$mysqli->prepare("UPDATE tir_notes SET agent=? WHERE agent=?");
            $st->bind_param('ss',$nouveau,$ancien);
            $st->execute(); $cnt['tir_notes']=$st->affected_rows;
            // vacation_overrides
            $st=$mysqli->prepare("UPDATE vacation_overrides SET agent=? WHERE agent=? AND date>=?");
            $st->bind_param('sss',$nouveau,$ancien,$datePrise);
            $st->execute(); $cnt['vacation_overrides']=$st->affected_rows;
            // locks_mois (si agent y est référencé)
            $st=$mysqli->prepare("UPDATE locks_mois SET agent=? WHERE agent=? AND mois>=?");
            $st->bind_param('sss',$nouveau,$ancien,substr($datePrise,0,7));
            $st->execute(); $cnt['locks_mois']=$st->affected_rows;
            // Compte utilisateur
            if($transferUser){
                $stU=$mysqli->prepare("SELECT ua.user_id FROM user_agents ua WHERE ua.agent=? LIMIT 1");
                $stU->bind_param('s',$ancien);
                $stU->execute();
                $rowU=$stU->get_result()->fetch_assoc();
                if($rowU){
                    $uid=(int)$rowU['user_id'];
                    $st=$mysqli->prepare("UPDATE users SET nom=?,login=? WHERE id=?");
                    $st->bind_param('ssi',$nouveau,$nouveau,$uid);
                    $st->execute();
                    $st=$mysqli->prepare("UPDATE user_agents SET agent=? WHERE user_id=? AND agent=?");
                    $st->bind_param('sis',$nouveau,$uid,$ancien);
                    $st->execute();
                }
            }
            // Mettre à jour agents_history
            // Fermer l'ancien agent (date_fin = veille de la prise de fonction)
            $dateVeille = date('Y-m-d', strtotime($datePrise.' -1 day'));
            $st=$mysqli->prepare("UPDATE agents_history SET date_fin=? WHERE agent=? AND date_fin IS NULL");
            $st->bind_param('ss',$dateVeille,$ancien);
            $st->execute();
            // Ouvrir le nouvel agent avec son groupe
            $grpNouvel = $groupeAgent[$ancien] ?? 'inconnu';
            $st=$mysqli->prepare("INSERT INTO agents_history (agent,groupe,date_debut) VALUES (?,?,?) ON DUPLICATE KEY UPDATE date_fin=NULL");
            $st->bind_param('sss',$nouveau,$grpNouvel,$datePrise);
            $st->execute();

            $mysqli->commit();
            // Instructions PHP à modifier manuellement
            $phpInstructions = "Dans le fichier PHP, remplacer toutes les occurrences de '$ancien' par '$nouveau' dans \$groupeAgent et les listes d'agents (lignes ~1027-1038).";
            echo json_encode(['ok'=>true,'counts'=>$cnt,'php_instructions'=>$phpInstructions]);
        } catch(Exception $e){
            $mysqli->rollback();
            echo json_encode(['ok'=>false,'msg'=>'Erreur : '.$e->getMessage()]);
        }
        exit;

    /* ── Historique agents : liste ── */
    } elseif ($action==='list_agents_history') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $res=$mysqli->query("SELECT id,agent,groupe,date_debut,date_fin FROM agents_history ORDER BY agent,date_debut");
        $rows=[];
        while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode(['ok'=>true,'rows'=>$rows]);
        exit;

    /* ── Historique agents : sauvegarde d'une ligne ── */
    } elseif ($action==='save_agent_history') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $hid        = (int)($_POST['hid']        ?? 0);
        $agH        = trim($_POST['agent']       ?? '');
        $grpH       = trim($_POST['groupe']      ?? 'inconnu');
        $dateDebH   = trim($_POST['date_debut']  ?? '');
        $dateFinH   = trim($_POST['date_fin']    ?? '') ?: null;
        if(!$agH || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateDebH)){
            echo json_encode(['ok'=>false,'msg'=>'Données invalides']); exit;
        }
        if($dateFinH && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFinH)){
            echo json_encode(['ok'=>false,'msg'=>'Date de fin invalide']); exit;
        }
        if($hid>0){
            $st=$mysqli->prepare("UPDATE agents_history SET agent=?,groupe=?,date_debut=?,date_fin=? WHERE id=?");
            $st->bind_param('ssssi',$agH,$grpH,$dateDebH,$dateFinH,$hid);
        } else {
            $st=$mysqli->prepare("INSERT INTO agents_history (agent,groupe,date_debut,date_fin) VALUES (?,?,?,?)");
            $st->bind_param('ssss',$agH,$grpH,$dateDebH,$dateFinH);
        }
        $ok=$st->execute();
        $nid=$hid>0?$hid:(int)$mysqli->insert_id;
        echo json_encode(['ok'=>$ok,'id'=>$nid,'msg'=>$ok?'Sauvegardé':'Erreur : '.$mysqli->error]);
        exit;

    /* ── Historique agents : suppression ── */
    } elseif ($action==='delete_agent_history') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $hid=(int)($_POST['hid']??0);
        $st=$mysqli->prepare("DELETE FROM agents_history WHERE id=?");
        $st->bind_param('i',$hid);
        $ok=$st->execute();
        echo json_encode(['ok'=>$ok,'msg'=>$ok?'Supprimé':'Erreur']);
        exit;

    /* ── Historique agents : get (pour récap) ── */
    } elseif ($action==='get_agents_history') {
        if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Réservé admin']); exit; }
        $res = $mysqli->query("SELECT agent,groupe,date_debut,date_fin FROM agents_history ORDER BY agent,date_debut");
        $history = [];
        while ($r=$res->fetch_assoc()) $history[] = $r;
        echo json_encode(['ok'=>true,'history'=>$history]);
        exit;

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
        // Notes TIR — visibles par tous, modifiables uniquement par admin
        $tirNotes=[];
        $stNotes=$mysqli->prepare("SELECT agent,note FROM tir_notes WHERE annee=?");
        $stNotes->bind_param('i',$yr);
        $stNotes->execute();
        $resN=$stNotes->get_result();
        while($rn=$resN->fetch_assoc()) $tirNotes[$rn['agent']]=$rn['note'];
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
    // Corriger les couleurs des congés GIE (M, AM, J, P, R absents de types_conges)
    $gieCouleursFix = [
        'P'  => ['couleur_bg'=>'#92d050','couleur_txt'=>'#222','libelle'=>'Permission'],
        'J'  => ['couleur_bg'=>'#92d050','couleur_txt'=>'#222','libelle'=>'Journée'],
        'M'  => ['couleur_bg'=>'#ffd200','couleur_txt'=>'#222','libelle'=>'Matin'],
        'AM' => ['couleur_bg'=>'#2f5597','couleur_txt'=>'#ffc000','libelle'=>'Après-midi'],
        'R'  => ['couleur_bg'=>'#bfbfbf','couleur_txt'=>'#333','libelle'=>'Repos'],
    ];
    foreach (['ADJ LEFEBVRE','ADJ CORRARD'] as $gieAg) {
        if (!isset($congesMeta[$gieAg])) continue;
        foreach ($congesMeta[$gieAg] as $ds => &$meta) {
            $code = $conges[$gieAg][$ds] ?? '';
            if (isset($gieCouleursFix[$code])) {
                $meta['couleur_bg']  = $gieCouleursFix[$code]['couleur_bg'];
                $meta['couleur_txt'] = $gieCouleursFix[$code]['couleur_txt'];
                if ($meta['libelle'] === $code) $meta['libelle'] = $gieCouleursFix[$code]['libelle'];
            }
        }
        unset($meta);
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

$typesCongesGie=['P'=>['code'=>'P','libelle'=>'Permission','couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
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
$typesCongesGieJ=['P'=>['code'=>'P','libelle'=>'Permission','couleur_bg'=>'#92d050','couleur_txt'=>'#222'],
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
      // Curseur sens interdit pour non-admin non-GIE sur permanence M ou AM
      $permLocked2 = (!$isAdmin && $grp !== 'gie' && $grp !== 'gie_j' && in_array($pmType,['M','AM'])) ? ' perm-locked' : '';
      $dispPerm = $isIndispo ? '<b style=\'color:#e53935\'>✖</b>' : ($pmType==='M' ? 'M' : ($pmType==='AM' ? 'AM' : $pmType));
      $hexType = bin2hex($pmType); // garde pour diagnostic interne uniquement
      // echo "<!-- DEBUG agent=$agent date=$date type=[$pmType] hex=$hexType -->"; // désactivé
      echo "<td class='$cls perm-ok$permLocked2$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='$pmId' data-perm-type='$pmType' data-cycle-orig='$pmCycOrig' data-tir-id='0' title='".htmlspecialchars($tip)."'>$dispPerm$sym</td>";

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
      // Equipes et GIE : perm-ok sur FERIE (comme WE) pour accès au mode perm
      $feriePermOk = $pP ? ' perm-ok' : '';
      echo "<td class='ferie$feriePermOk$lockCls'$da data-ferie='0' data-conge-id='0' data-conge-type='' data-perm-id='0' data-cycle-orig='FERIE' data-tir-id='0' title='Jour férié'>FERIE</td>";

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
  width:420px;min-width:360px;flex-shrink:0;
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
/* Permanence M/AM posée par admin : curseur sens interdit pour non-admin non-GIE */
<?php if(!$isAdmin&&!$isGie): ?>
td.perm-locked{cursor:no-drop!important}
td.perm-locked:hover{filter:brightness(.9)!important;transform:none!important}
<?php endif; ?>
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
?>
<tr><td class="equipe" colspan="<?=$nbJours+1?>">DOUANE</td></tr>
<?php
ligne('ACP1 DEMERVAL',fn($d)=>cycleHebdo($d,$feries),false,false,'douane_j');
foreach($douane as $a) ligne($a,fn($d)=>cycleDouane($d,$feries),false,false,'douane');
?>
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
    'ANALYSE'=>['GP DHALLEWYN','BC DELCROIX','ADC LAMBERT'],
    'DOUANE'=>['ACP1 LOIN','ACP1 DEMERVAL'],'SECR.'=>$secretariat];
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
        <div id="sec-perm-separator" style="display:none;border-top:2px solid #e3f0ff;margin:10px 0 10px;padding-top:8px;font-size:.72rem;font-weight:700;color:#1565c0;letter-spacing:.04em">
          ☀ PERMANENCE (M / AM)
        </div>

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
      <div style="display:flex;align-items:center;gap:14px">
        <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer;color:#e1bee7">
          <input type="checkbox" id="fetes-show-anciens"> Afficher anciens agents
        </label>
        <button id="btn-x-recap-fetes" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1">✕</button>
      </div>
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
      <label style="margin-left:auto;display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer;color:#555">
        <input type="checkbox" id="perm-show-anciens"> Afficher anciens agents
      </label>
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
      <div class="adm-tab" data-tab="remplacement" style="color:#7b1fa2">&#128257; Remplacement</div>
      <div class="adm-tab" data-tab="historique-agents" style="color:#00695c">&#128100; Historique agents</div>
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
          'GP DHALLEWYN','BC DELCROIX','ADC LAMBERT',
          'ACP1 LOIN','ACP1 DEMERVAL','AA MAES','BC DRUEZ'];
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

      <!-- ══ ONGLET REMPLACEMENT AGENT ══ -->
      <div class="adm-section" id="tab-remplacement">
        <div style="background:#f3e5f5;border:1.5px solid #ce93d8;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.78rem;color:#4a148c">
          &#9888; Le remplacement transfère les données <strong>à partir de la date choisie</strong> vers le nouvel agent. L'historique antérieur est conservé sous l'ancien nom.<br>
          &#9888; Après la validation, vous devrez <strong>mettre à jour manuellement le fichier PHP</strong> (tableau <code>$groupeAgent</code> et listes d'agents) pour que le nouveau nom apparaisse correctement dans le planning.
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label style="font-size:.75rem;font-weight:700;color:#4a148c;display:block;margin-bottom:4px">Agent à remplacer (partant)</label>
            <select id="repl-ancien" style="width:100%;padding:6px 10px;border:1.5px solid #ce93d8;border-radius:6px;font-size:.8rem">
              <option value="">— Sélectionner —</option>
              <?php
              $allAgents = array_keys($groupeAgent);
              sort($allAgents);
              foreach($allAgents as $ag): ?>
              <option value="<?=htmlspecialchars($ag)?>"><?=htmlspecialchars($ag)?> (<?=$groupeAgent[$ag]?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:.75rem;font-weight:700;color:#4a148c;display:block;margin-bottom:4px">Nom du nouvel agent (arrivant)</label>
            <input type="text" id="repl-nouveau" placeholder="Ex : LCL DUPONT" style="width:100%;padding:6px 10px;border:1.5px solid #ce93d8;border-radius:6px;font-size:.8rem;box-sizing:border-box">
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:.75rem;font-weight:700;color:#4a148c;display:block;margin-bottom:4px">Date de prise de fonction du nouvel agent</label>
          <input type="date" id="repl-date" style="padding:6px 10px;border:1.5px solid #ce93d8;border-radius:6px;font-size:.8rem">
          <span style="font-size:.7rem;color:#888;margin-left:8px">Les données à partir de cette date seront transférées au nouveau nom.</span>
        </div>
        <div style="margin-bottom:14px">
          <label style="font-size:.75rem;font-weight:700;color:#4a148c;display:block;margin-bottom:4px">Transférer également le compte utilisateur ?</label>
          <label style="font-size:.78rem;display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" id="repl-transfer-user" checked> Oui — renommer le login et le nom d'affichage du compte
          </label>
        </div>
        <button id="btn-repl-preview" style="padding:7px 18px;background:#7b1fa2;color:#fff;border:none;border-radius:7px;font-size:.8rem;font-weight:700;cursor:pointer;margin-right:8px">&#128269; Aperçu des changements</button>
        <div id="repl-preview" style="display:none;margin-top:12px;background:#fff;border:1.5px solid #ce93d8;border-radius:8px;padding:12px;font-size:.75rem"></div>
        <div id="repl-confirm-wrap" style="display:none;margin-top:10px">
          <button id="btn-repl-exec" style="padding:8px 22px;background:#c0392b;color:#fff;border:none;border-radius:7px;font-size:.82rem;font-weight:700;cursor:pointer">&#9889; Confirmer le remplacement</button>
          <span style="font-size:.72rem;color:#c0392b;margin-left:10px">Cette action est irréversible (sauf restauration BDD).</span>
        </div>
        <div id="repl-result" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:.78rem"></div>
      </div><!-- /tab-remplacement -->

      <!-- ══ ONGLET HISTORIQUE AGENTS ══ -->
      <div class="adm-section" id="tab-historique-agents">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
          <span style="font-size:.82rem;font-weight:700;color:#00695c">&#128100; Historique des agents — dates de début et fin de fonction</span>
          <button id="btn-hist-reload" style="padding:4px 12px;background:#00695c;color:#fff;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;margin-left:auto">&#8635; Actualiser</button>
        </div>
        <div style="background:#e0f2f1;border:1.5px solid #80cbc4;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:.75rem;color:#004d40">
          &#9432; Cliquez sur une date pour la modifier. <strong>date_fin vide</strong> = agent encore actif. Les modifications sont sauvegardées immédiatement.
        </div>
        <div id="hist-agents-body">
          <p style="color:#888;font-size:.8rem;text-align:center;padding:20px">Cliquez sur Actualiser pour charger...</p>
        </div>
      </div><!-- /tab-historique-agents -->
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
  const sep=document.getElementById('sec-perm-separator');
  if(sep) sep.style.display='none';
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
  // Si un congé non-PREV posé par l'admin existe : cellule totalement inerte pour le non-admin
  if(!IS_ADMIN && curCongeId>0){
    const typeExistant=td.dataset.congeType||'';
    if(typeExistant!=='PREV') return;
  }
  // Non-admin : bloquer si permanence déjà posée (sauf groupes libres)
  // Exception : GIE, LCL PARENT et ADC LAMBERT gèrent leurs propres saisies
  const groupeLibre=['gie','gie_j','lcl_parent','adc_lambert','douane','douane_j'];
  // CoGe ROUSSEL (et tout user sur sa propre ligne) : libre de modifier ses propres congés PREV
  const isOwnAgent=USER_AGENTS.length>0&&USER_AGENTS.includes(td.dataset.agent);
  if(!IS_ADMIN && !groupeLibre.includes(td.dataset.groupe) && !isOwnAgent && curPermId>0){
    const typeExistant=td.dataset.permType||'';
    if(typeExistant !== 'PREV'){
      showToast('🔒 Modification réservée à l\'administrateur ou aux droits requis.',false);return;
    }
  }
  // Permanence M ou AM posée par l'admin : aucune boîte de dialogue pour les non-GIE non-admin
  const _permType=td.dataset.permType||'';
  const _isGie=(td.dataset.groupe==='gie'||td.dataset.groupe==='gie_j');
  if(!IS_ADMIN&&curPermId>0&&(_permType==='M'||_permType==='AM')&&!_isGie){return;}

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
  const isGieAgent=(td.dataset.groupe==='gie'||td.dataset.groupe==='gie_j');
  const isDouaneAgent=(td.dataset.groupe==='douane'||td.dataset.groupe==='douane_j');
  // Groupes qui n'ont rien à faire sur FERIE : on bloque l'ouverture de modal
  const GROUPES_NO_FERIE=['direction','direction_police','direction_mokadem','standard_j','lcl_parent','adc_lambert'];
  if(isFerie && GROUPES_NO_FERIE.includes(td.dataset.groupe)) return;
  // GIE en semaine (cycle M ou AM) : mode congé (M-AM-J/P-R), pas mode perm
  const isGieSemaine=(td.dataset.groupe==='gie'||td.dataset.groupe==='gie_j')&&!isFerieOrWE;
  const isDouaneJ=(td.dataset.groupe==='douane'||td.dataset.groupe==='douane_j');
  // Douane : jamais en mode perm — vacation override via la section dédiée (J/M/AM)
  // Equipe et GIE : mode perm sur FERIE (comme WE) — pas de vacation override sur FERIE
  const GROUPES_PERM_FERIE=['equipe','gie','standard_police'];
  const isPerm=!isGieSemaine&&!isDouaneAgent&&(hasPermOk
    ||(isFerieOrWE&&(IS_ADMIN||AGENTS_PERM.includes(td.dataset.agent))
       &&!(isFerie&&!GROUPES_PERM_FERIE.includes(td.dataset.groupe)&&!IS_ADMIN)));
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
    // FERIE traité comme WE : mêmes boutons que samedi/dimanche
    const isGieGroupe=(groupe==='gie'||groupe==='gie_j');
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
    // Mise en page
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
    // Permanence M ou AM posée sur WE/férié : suppression interdite sauf pour le GIE
    const isGieGroupe2=(groupe==='gie'||groupe==='gie_j');
    const permEstMouAM=(selType==='M'||selType==='AM')&&curPermId>0&&!isGieGroupe2&&!IS_ADMIN;
    btnDel.style.display=permEstMouAM?'none':'';
    btnDel.disabled=curPermId===0;
    btnSave.disabled=!selType;

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
    // Section override vacation — masquée sur FERIE pour équipes et GIE
    const vacId=parseInt(curTd.dataset.vacId)||0;
    const curVac=curTd.dataset.cycle||'';
    const isFerieForVac=(curTd.dataset.cycle==='FERIE'||curTd.dataset.cycleOrig==='FERIE');
    const GROUPES_NO_VAC_FERIE=['equipe','gie','gie_j'];
    if(isFerieForVac && GROUPES_NO_VAC_FERIE.includes(groupe)){
      secVacOverride.style.display='none';
    } else {
      buildVacOverrideSection(groupe, curVac, vacId);
    }
    // Non-admin : ne peut supprimer que ses propres congés PREV, pas ceux posés par l'admin
    const congeTypeExist=curTd.dataset.congeType||'';
    const canDelConge=IS_ADMIN||(curCongeId===0)||(congeTypeExist==='PREV');
    btnDel.style.display=(!IS_ADMIN&&curCongeId>0&&congeTypeExist!=='PREV')?'none':'';
    btnDel.disabled=curCongeId===0||!canDelConge;
    btnSave.disabled=!selType;
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
    } else if(g==='gie'||g==='gie_j'){
      // GIE jours de semaine : 2 lignes
      // Ligne 1 : M - AM - J (3 colonnes égales)
      // Ligne 2 : P - R (2 boutons agrandis, chacun moitié de la ligne)
      const gieOrder=['M','AM','J','P','R'];
      const orderedGie=gieOrder.map(code=>types.find(t=>t.code===code)).filter(Boolean);
      types=orderedGie;
      // Grille 6 colonnes : M/AM/J → span 2 chacun | P/R → span 3 chacun
      codeGrid.style.gridTemplateColumns='repeat(6,1fr)';
      document.querySelector('.modal').style.width='500px';
      // Appliquer les spans après rendu
      setTimeout(()=>{
        const spans={'M':2,'AM':2,'J':2,'P':3,'R':3};
        codeGrid.querySelectorAll('.code-btn').forEach(b=>{
          const s=spans[b.dataset.code];
          if(s) b.style.gridColumn='span '+s;
        });
      },0);
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
    btn.type='button';
    btn.dataset.code=t.code;
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
const GROUPES_VAC_OVERRIDE = ['equipe','nuit','standard_police','direction_police','direction_mokadem','douane','douane_j'];

function getVacOptions(groupe){
  if(groupe==='nuit') return [
    {val:'NUIT',label:'🌙 Nuit'   },
    {val:'M',   label:'✳ Matin'  },
    {val:'AM',  label:'☽ AM'     },
    {val:'J',   label:'⊙ Journée'},
  ];
  if(groupe==='douane'||groupe==='douane_j') return [
    {val:'J',   label:'⊙ Journée'},
    {val:'M',   label:'✳ Matin'  },
    {val:'AM',  label:'☽ AM'     },
  ];
  return [
    {val:'J',   label:'⊙ Journée'},
    {val:'M',   label:'✳ Matin'  },
    {val:'AM',  label:'☽ AM'     },
    {val:'NUIT',label:'🌙 Nuit'   },
  ];
}

function buildVacOverrideSection(groupe, currentVac, vacId){
  const isDouane=(groupe==='douane'||groupe==='douane_j');
  // Section vacation réservée à l'admin, sauf pour la douane
  if(!IS_ADMIN && !isDouane){ secVacOverride.style.display='none'; return; }
  if(!GROUPES_VAC_OVERRIDE.includes(groupe)){
    secVacOverride.style.display='none';
    const modal=document.querySelector('.modal');
    if(modal && !IS_ADMIN) modal.style.width='420px';
    return;
  }
  secVacOverride.style.display='block';
  const modal=document.querySelector('.modal');
  if(modal){
    const opts=getVacOptions(groupe);
    modal.style.width=Math.max(parseInt(modal.style.width)||500, opts.length<=3?520:660)+'px';
  }
  vacBtnsWrap.innerHTML='';
  const opts=getVacOptions(groupe);
  opts.forEach(o=>{
    const isSel=currentVac===o.val;
    const btn=document.createElement('button');
    btn.type='button';
    btn.style.cssText=`
      padding:5px 16px;
      border:2px solid ${isSel?'#1a2742':'#ccc'};
      border-radius:20px;
      background:${isSel?'#1a2742':'#fff'};
      color:${isSel?'#ffd600':'#444'};
      font-size:.78rem;font-weight:700;cursor:pointer;
      transition:all .15s;
      ${isSel?'box-shadow:0 0 0 2px #f9a825;':''}
    `.replace(/\n\s+/g,' ');
    btn.textContent=o.label;
    btn.addEventListener('click',async()=>{
      const r=await ajax({action:'save_vacation_override',
        agent:curTd.dataset.agent,
        date:curTd.dataset.date,
        vacation:o.val});
      if(r.ok){
        const rc=await ajax({action:'get_cell',agents:JSON.stringify([r.agent]),dates:JSON.stringify([r.date])});
        if(rc.ok) applyCells(rc);
        showToast('Vacation modifiée → '+o.val);
        closeModal();
      } else showToast('Erreur : '+(r.msg||'?'),false);
    });
    vacBtnsWrap.appendChild(btn);
  });
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
    const r=await ajax({action:'delete_tir',tir_id:curTirId,agent:curTd.dataset.agent,date_debut:curTd.dataset.date});
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
    const r=await ajax({action:'delete_tir_annul',annul_id:curAnnulId,agent:curTd.dataset.agent,date_debut:curTd.dataset.date});
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

// Historique des agents (chargé une fois à l'ouverture)
let _agentsHistory = null;
async function ensureAgentsHistory(){
  if(_agentsHistory!==null) return;
  try{
    const r=await ajax({action:'get_agents_history'});
    _agentsHistory=r.ok?(r.history||[]):[];
  }catch(e){_agentsHistory=[];}
}

// Retourne {actif:bool, date_debut, date_fin, groupe} pour un agent dans agents_history
function agentHistoryInfo(ag){
  if(!_agentsHistory) return null;
  // Prendre l'entrée la plus récente pour cet agent
  const entries=_agentsHistory.filter(h=>h.agent===ag).sort((a,b)=>b.date_debut.localeCompare(a.date_debut));
  if(!entries.length) return null;
  const e=entries[0];
  return {actif:!e.date_fin, date_debut:e.date_debut, date_fin:e.date_fin, groupe:e.groupe};
}

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
  const showAnciens=document.getElementById('perm-show-anciens')?.checked||false;
  const getD=(ag)=> mo>0 ? (data[ag]?.mois?.[mo]||{}) : (data[ag]||{});

  // Agents actifs définis dans sections + anciens détectés dans les données
  const actifsSet=new Set(Object.values(sections).flat());

  // Anciens agents : présents dans data mais absents de sections
  const anciensInData=Object.keys(data).filter(ag=>!actifsSet.has(ag)).sort();

  // ─── Titre ───
  const titre = mo>0 ? `${ML4[mo]} ${ANNEE}` : `Année ${ANNEE} — toutes permanences`;

  let html=`<p style="font-size:.78rem;font-weight:700;color:#1565c0;margin-bottom:10px">${titre}</p>`;

  // ─── Tableau principal style capture ───
  html+=`<div style="overflow-x:auto">
  <table style="border-collapse:collapse;font-size:12px;width:100%">
  <thead>
    <tr style="background:#253560;color:#ffd600;text-align:center">
      <th style="text-align:center;padding:6px 10px;min-width:130px;font-size:1rem">Agents</th>
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

  // Ligne TOTAL général (agents actifs)
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

  // ─── Anciens agents (si case cochée et données présentes) ───
  if(showAnciens && anciensInData.length>0){
    html+=`<tr style="background:#78909c">
      <td colspan="8" style="padding:4px 10px;font-size:10.5px;font-weight:700;color:#fff;letter-spacing:.05em">
        📦 ANCIENS AGENTS (historique)
      </td>
    </tr>`;
    anciensInData.forEach((ag,i)=>{
      const d=getD(ag);
      const t=getPermTotaux(d);
      if(t.total===0) return; // n'afficher que si données présentes
      const info=agentHistoryInfo(ag);
      const periode=info?(` — ${info.date_debut}${info.date_fin?' → '+info.date_fin:' → ?'}`):'';
      const totCol=t.total===0?'#999':'#546e7a';
      html+=`<tr style="background:${i%2?'#eceff1':'#f5f5f5'};opacity:.8">
        <td style="padding:5px 10px;font-weight:600;color:#546e7a;font-style:italic">
          🗂 ${ag}<span style="font-size:.68rem;color:#90a4ae">${periode}</span>
        </td>
        <td style="text-align:center;padding:5px 8px;color:${t.matin>0?'#546e7a':'#ccc'}">${t.matin||'0'}</td>
        <td style="text-align:center;padding:5px 8px;color:${t.am>0?'#546e7a':'#ccc'}">${t.am||'0'}</td>
        <td style="text-align:center;padding:5px 8px;color:${t.ferie>0?'#546e7a':'#ccc'}">${t.ferie||'0'}</td>
        <td style="text-align:center;padding:5px 8px;color:${t.nuit>0?'#546e7a':'#ccc'}">${t.nuit||'0'}</td>
        <td style="text-align:center;padding:5px 8px;font-weight:700;color:#546e7a">${t.hferie||'—'}</td>
        <td style="text-align:center;padding:5px 8px;font-weight:700;color:#546e7a">${t.ferie>0?t.ferie:'—'}</td>
        <td style="text-align:center;padding:5px 10px;font-weight:800;color:${totCol}">${t.total||'—'}</td>
      </tr>`;
    });
  } else if(!showAnciens && anciensInData.length>0){
    html+=`<tr style="background:#eceff1">
      <td colspan="8" style="padding:3px 10px;font-size:.68rem;color:#78909c;font-style:italic;text-align:center">
        📦 ${anciensInData.length} ancien(s) agent(s) masqué(s) — cocher "Afficher anciens agents" pour les voir
      </td>
    </tr>`;
  }

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
if(btnOpenPerm) btnOpenPerm.addEventListener('click',async()=>{
  overlayPerm.classList.add('open');
  await ensureAgentsHistory(); // charger l'historique agents
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

// Toggle "Afficher anciens agents" — rafraîchit sans recharger les données
document.getElementById('perm-show-anciens')?.addEventListener('change',()=>{
  if(permData) mpBody.innerHTML=buildPermTableau(permData,permMoisActif);
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
    <th style="padding:5px 10px;background:#1a2742;color:#ffd600;text-align:center;font-size:1rem" rowspan="2">Agents</th>
    <th style="padding:5px 8px;background:#37474f;color:#ffcc80;font-size:.85rem;text-align:center;font-weight:800" rowspan="2">Notes</th>
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
    'ANALYSE'   :['GP DHALLEWYN','BC DELCROIX'],
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
      } else {
        // Non-admin : affichage lecture seule de la note
        const noteVal=(r.tirNotes&&r.tirNotes[ag])||'';
        html+=`<td style="padding:3px 6px;min-width:90px;max-width:150px;background:#f5f0e8;vertical-align:middle">
          <span style="font-size:.7rem;color:#5d4037;font-style:${noteVal?'normal':'italic'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:140px" title="${noteVal}">${noteVal||'—'}</span>
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
  const sectionsRef={'DIRECTION':['CoGe ROUSSEL','Cne MOKADEM'],'ÉQUIPE 1':['BC BOUXOM','BC ARNAULT','BC HOCHARD'],'ÉQUIPE 2':['BC DUPUIS','BC BASTIEN','BC ANTHONY'],'NUIT':['BC MASSON','BC SIGAUD','BC DAINOTTI'],'ANALYSE':['GP DHALLEWYN','BC DELCROIX'],'INFORMATIQUE':['BC DRUEZ']};
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
if(btnOpenRecapFetes) btnOpenRecapFetes.addEventListener('click',async()=>{
  await ensureAgentsHistory();
  recapFetesBody.innerHTML = buildRecapFetes();
  overlayRecapFetes.classList.add('open');
});
document.getElementById('btn-x-recap-fetes').addEventListener('click',()=>overlayRecapFetes.classList.remove('open'));
overlayRecapFetes.addEventListener('click',e=>{if(e.target===overlayRecapFetes)overlayRecapFetes.classList.remove('open');});

// Toggle anciens agents récap fêtes
document.getElementById('fetes-show-anciens')?.addEventListener('change',()=>{
  recapFetesBody.innerHTML=buildRecapFetes();
});

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
  2026: {
    '01/01': {},
    '25/12': {},
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
  const showAnciens=document.getElementById('fetes-show-anciens')?.checked||false;
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
  // Appliquer le filtre
  const agents = showAnciens ? [...agentsActifs, ...agentsAnciens] : agentsActifs;

  let html='<div style="overflow-x:auto">';
  html+='<table style="border-collapse:collapse;font-size:11px;min-width:100%">';

  // En-tête ligne 1 : années
  html+='<thead><tr>';
  html+=`<th rowspan="2" style="padding:5px 10px;background:#7b1fa2;color:#fff;text-align:center;position:sticky;left:0;z-index:3;min-width:120px;font-size:1rem">Agents</th>`;
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
    const info = isAncien ? agentHistoryInfo(ag) : null;
    const periode = info ? ` (${info.date_debut}${info.date_fin?' → '+info.date_fin:''})` : '';
    const rowBg = isAncien ? (ai%2===0?'#f0f0f0':'#e8e8e8') : (ai%2===0?'#f9f9fb':'#fff');
    const ancienStyle = isAncien ? 'color:#888;font-style:italic;opacity:.85;' : '';
    html+=`<tr style="background:${rowBg}">`;
    html+=`<td style="padding:3px 10px;font-weight:600;white-space:nowrap;background:${rowBg};position:sticky;left:0;border-right:2px solid #dde;${ancienStyle}" title="${isAncien?'Ancien fonctionnaire'+periode:''}">
      ${isAncien?'🗂 ':''}${ag}${isAncien?`<span style="display:block;font-size:.6rem;font-weight:400;color:#aaa">${periode}</span>`:''}
    </td>`;

    annees.forEach(an=>{
      FETES_JOURS.forEach((j,ji)=>{
        const periode=(RECAP_FETES_DATA[an]&&RECAP_FETES_DATA[an][j]&&RECAP_FETES_DATA[an][j][ag])||null;
        const bl=ji===0?'border-left:3px solid #7b1fa2;':'';
        const opacity=isAncien?'opacity:.7;':'';
        if(periode==='M'){
          html+=`<td style="text-align:center;padding:2px 3px;background:${FETES_COLORS_M[j]};color:#fff;font-weight:800;${bl};font-size:11px;${opacity}" title="${ag} — ${j}/${an} (Matin)">M</td>`;
        } else if(periode==='AM'){
          html+=`<td style="text-align:center;padding:2px 3px;background:${FETES_COLORS_AM[j]};color:#fff;font-weight:800;${bl};font-size:11px;${opacity}" title="${ag} — ${j}/${an} (Après-midi)">AM</td>`;
        } else {
          html+=`<td style="text-align:center;padding:2px 3px;color:#ddd;background:${FETES_BG[j]}22;${bl}${opacity}">·</td>`;
        }
      });
    });

    html+='</tr>';
  });

  // Bandeau anciens masqués
  if(!showAnciens && agentsAnciens.length>0){
    html+=`<tr style="background:#f3e5f5">
      <td colspan="${1+annees.length*FETES_JOURS.length}" style="padding:3px 10px;font-size:.68rem;color:#7b1fa2;font-style:italic;text-align:center">
        📦 ${agentsAnciens.length} ancien(s) agent(s) masqué(s) — cocher "Afficher anciens agents" pour les voir
      </td>
    </tr>`;
  }

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
  'CAA':'CAA','CA':'CA','HPA':'HPA','HP':'HP','RTT':'RTT',
  'CET':'CET','CF':'CF','DA':'DA','PR':'PR','HS':'HS',
  'CMO':'CMO','CLM':'CLM','CLD':'CLD','PREV':'Prév.','AUT':'Autres'
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

  // Codes autorisés dans l'onglet congés admin — ordre imposé, sans RPS
  const ADMIN_CR_ALLOWED = ['CAA','CA','HPA','HP','RTT','CET'];

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
  // Forcer l'ordre imposé — exclure RPS et tout ce qui n'est pas dans ADMIN_CR_ALLOWED
  const TYPES = ADMIN_CR_ALLOWED.filter(t=>typesSet.has(t)||Object.values(counts).some(c=>c[t]>0));

  // Bordure épaisse entre chaque type de congé
  const SEP_STYLE = 'border-right:3px solid #1a2742;';

  let html = `<div style="overflow-x:auto">
  <table style="border-collapse:collapse;font-size:11.5px;width:100%">
  <thead>
    <tr style="background:#1a2742;color:#ffd600;text-align:center">
      <th style="text-align:left;padding:6px 10px;min-width:130px;position:sticky;left:0;background:#1a2742;border-right:3px solid #253560">Agent</th>`;
  TYPES.forEach((t,idx)=>{
    html+=`<th style="padding:5px 7px;white-space:nowrap;${SEP_STYLE}" colspan="2">${CR_TYPE_LBL[t]||t}</th>`;
  });
  html+=`</tr>
    <tr style="background:#253560;color:#aac8ff;text-align:center;font-size:10px">
      <th style="position:sticky;left:0;background:#253560;border-right:3px solid #1a2742"></th>`;
  TYPES.forEach((t,idx)=>{
    html+=`<th style="padding:3px 5px">Posé</th><th style="padding:3px 5px;${SEP_STYLE}">Reste</th>`;
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
        html+=`<td style="text-align:center;padding:4px 6px;font-weight:${hasQ?'700':'400'};color:${hasQ?restCol:'#ccc'};${SEP_STYLE}">${hasQ?(over?'⚠'+resteFmt:resteFmt):'—'}</td>`;
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

// ══ REMPLACEMENT AGENT ══
document.getElementById('btn-repl-preview')?.addEventListener('click', async()=>{
  const ancien  = document.getElementById('repl-ancien').value.trim();
  const nouveau = document.getElementById('repl-nouveau').value.trim().toUpperCase();
  const date    = document.getElementById('repl-date').value;
  const preview = document.getElementById('repl-preview');
  const confirmWrap = document.getElementById('repl-confirm-wrap');
  const result  = document.getElementById('repl-result');
  result.style.display='none';
  confirmWrap.style.display='none';
  if(!ancien||!nouveau||!date){
    preview.style.display='block';
    preview.innerHTML='<span style="color:#c0392b">⚠️ Veuillez remplir tous les champs.</span>';
    return;
  }
  if(ancien===nouveau){
    preview.style.display='block';
    preview.innerHTML='<span style="color:#c0392b">⚠️ L\'ancien et le nouveau nom sont identiques.</span>';
    return;
  }
  preview.style.display='block';
  preview.innerHTML='<span style="color:#888">Chargement de l\'aperçu...</span>';
  const r=await ajax({action:'replace_agent_preview',ancien,nouveau,date_prise:date});
  if(!r.ok){ preview.innerHTML='<span style="color:#c0392b">Erreur : '+r.msg+'</span>'; return; }
  const labels={'conges':'Congés','permanences':'Permanences','tir':'Séances TIR',
    'tir_annulations':'Annulations TIR','tir_notes':'Notes TIR',
    'vacation_overrides':'Overrides vacation','locks_mois':'Verrous mois'};
  let html=`<div style="font-weight:700;color:#4a148c;margin-bottom:8px">📋 Aperçu — <em>${ancien}</em> → <strong>${nouveau}</strong> à partir du ${date}</div>`;
  html+='<table style="border-collapse:collapse;width:100%;font-size:.75rem">';
  html+='<tr style="background:#f3e5f5"><th style="padding:4px 8px;text-align:left">Table</th><th style="padding:4px 8px;text-align:center">Enregistrements transférés</th></tr>';
  for(const[tbl,cnt] of Object.entries(r.counts)){
    const color=cnt>0?'#4a148c':'#aaa';
    html+=`<tr><td style="padding:3px 8px;color:${color}">${labels[tbl]||tbl}</td><td style="padding:3px 8px;text-align:center;font-weight:700;color:${color}">${cnt}</td></tr>`;
  }
  html+='</table>';
  if(r.user){
    const transferChecked=document.getElementById('repl-transfer-user').checked;
    html+=`<div style="margin-top:8px;padding:6px 10px;background:#e8f5e9;border-radius:6px;font-size:.73rem">
      👤 Compte utilisateur trouvé : <strong>${r.user.login}</strong> (${r.user.nom})
      ${transferChecked?'→ sera renommé en <strong>'+nouveau+'</strong>':'→ <em>non transféré</em> (case décochée)'}
    </div>`;
  } else {
    html+=`<div style="margin-top:8px;padding:6px 10px;background:#fff8e1;border-radius:6px;font-size:.73rem">⚠️ Aucun compte utilisateur associé à cet agent.</div>`;
  }
  html+=`<div style="margin-top:8px;padding:6px 10px;background:#fce4ec;border-radius:6px;font-size:.72rem;color:#880e4f">
    ⚠️ Après validation, mettez à jour manuellement le fichier PHP : remplacez <code>'${ancien}'</code> par <code>'${nouveau}'</code> dans <code>$groupeAgent</code> et les listes d'agents (lignes ~1027-1038).
  </div>`;
  preview.innerHTML=html;
  confirmWrap.style.display='block';
});

document.getElementById('btn-repl-exec')?.addEventListener('click', async()=>{
  const ancien  = document.getElementById('repl-ancien').value.trim();
  const nouveau = document.getElementById('repl-nouveau').value.trim().toUpperCase();
  const date    = document.getElementById('repl-date').value;
  const transferUser = document.getElementById('repl-transfer-user').checked?'1':'0';
  const result  = document.getElementById('repl-result');
  if(!confirm(`Confirmer le remplacement de "${ancien}" par "${nouveau}" à partir du ${date} ?\n\nCette action est irréversible.`)) return;
  const r=await ajax({action:'replace_agent_exec',ancien,nouveau,date_prise:date,transfer_user:transferUser});
  result.style.display='block';
  if(r.ok){
    document.getElementById('repl-confirm-wrap').style.display='none';
    let html=`<div style="background:#e8f5e9;border:1.5px solid #27ae60;border-radius:8px;padding:10px 14px;color:#1a6632">
      <div style="font-weight:700;font-size:.85rem;margin-bottom:6px">✅ Remplacement effectué avec succès !</div>`;
    const labels={'conges':'Congés','permanences':'Permanences','tir':'Séances TIR',
      'tir_annulations':'Annulations TIR','tir_notes':'Notes TIR',
      'vacation_overrides':'Overrides vacation','locks_mois':'Verrous mois'};
    for(const[tbl,cnt] of Object.entries(r.counts)){
      if(cnt>0) html+=`<div style="font-size:.75rem">• ${labels[tbl]||tbl} : ${cnt} ligne(s) transférée(s)</div>`;
    }
    html+=`<div style="margin-top:8px;padding:6px 10px;background:#fff8e1;border-radius:6px;font-size:.73rem;color:#7a5800">
      ⚠️ N'oubliez pas : ${r.php_instructions}
    </div></div>`;
    result.innerHTML=html;
  } else {
    result.innerHTML=`<div style="background:#fdecea;border:1.5px solid #c0392b;border-radius:8px;padding:10px 14px;color:#c0392b;font-size:.8rem">❌ Erreur : ${r.msg}</div>`;
  }
});

// ══ HISTORIQUE AGENTS ══
const groupesDisponibles=['direction_police','direction_mokadem','lcl_parent','douane_j','nuit','equipe','gie','douane','standard_police','adc_lambert','standard_j','inconnu'];

async function loadHistAgents(){
  const body=document.getElementById('hist-agents-body');
  if(!body) return;
  body.innerHTML='<p style="color:#888;font-size:.8rem;text-align:center;padding:20px">Chargement...</p>';
  const r=await ajax({action:'list_agents_history'});
  if(!r.ok){ body.innerHTML='<p style="color:red;padding:14px">Erreur</p>'; return; }
  if(!r.rows.length){ body.innerHTML='<p style="color:#888;font-size:.8rem;padding:14px;text-align:center">Aucune entrée.</p>'; return; }

  let html=`<div style="overflow-x:auto">
  <table style="border-collapse:collapse;width:100%;font-size:.78rem">
  <thead><tr style="background:#00695c;color:#fff">
    <th style="padding:6px 10px;text-align:left;min-width:140px">Agent</th>
    <th style="padding:6px 10px;text-align:left;min-width:120px">Groupe</th>
    <th style="padding:6px 10px;text-align:center;min-width:120px">Date début</th>
    <th style="padding:6px 10px;text-align:center;min-width:120px">Date fin</th>
    <th style="padding:6px 10px;text-align:center;min-width:60px">Statut</th>
    <th style="padding:6px 10px;text-align:center;min-width:80px">Actions</th>
  </tr></thead><tbody id="hist-tbody">`;

  r.rows.forEach((row,i)=>{
    const actif=!row.date_fin;
    const rowBg=actif?(i%2?'#f0faf8':'#e8f5e9'):(i%2?'#f5f5f5':'#efefef');
    const statut=actif
      ?'<span style="background:#27ae60;color:#fff;padding:2px 7px;border-radius:10px;font-size:.68rem;font-weight:700">Actif</span>'
      :'<span style="background:#90a4ae;color:#fff;padding:2px 7px;border-radius:10px;font-size:.68rem;font-weight:700">Inactif</span>';
    html+=`<tr style="background:${rowBg}" data-hid="${row.id}">
      <td style="padding:5px 10px;font-weight:600;color:${actif?'#004d40':'#607d8b'}">${row.agent}</td>
      <td style="padding:5px 10px;color:#555">${row.groupe}</td>
      <td style="padding:5px 10px;text-align:center">
        <input type="date" class="hist-date-debut" value="${row.date_debut||''}"
          style="padding:3px 6px;border:1.5px solid #b2dfdb;border-radius:5px;font-size:.75rem;width:130px">
      </td>
      <td style="padding:5px 10px;text-align:center">
        <input type="date" class="hist-date-fin" value="${row.date_fin||''}"
          placeholder="vide = actif"
          style="padding:3px 6px;border:1.5px solid #b2dfdb;border-radius:5px;font-size:.75rem;width:130px">
      </td>
      <td style="padding:5px 10px;text-align:center">${statut}</td>
      <td style="padding:5px 10px;text-align:center;white-space:nowrap">
        <button class="hist-btn-save" data-hid="${row.id}" data-agent="${row.agent.replace(/"/g,'&quot;')}" data-groupe="${row.groupe}"
          style="padding:3px 10px;background:#00695c;color:#fff;border:none;border-radius:5px;font-size:.72rem;font-weight:700;cursor:pointer;margin-right:4px">
          &#128190; Sauver
        </button>
        <button class="hist-btn-del" data-hid="${row.id}" data-agent="${row.agent.replace(/"/g,'&quot;')}"
          style="padding:3px 8px;background:#e53935;color:#fff;border:none;border-radius:5px;font-size:.72rem;font-weight:700;cursor:pointer">
          &#128465;
        </button>
      </td>
    </tr>`;
  });

  html+=`</tbody></table></div>`;

  // Formulaire ajout nouvelle entrée
  const grpOpts=groupesDisponibles.map(g=>`<option value="${g}">${g}</option>`).join('');
  html+=`<div style="margin-top:16px;padding:12px 14px;background:#e0f2f1;border:1.5px solid #80cbc4;border-radius:8px">
    <div style="font-size:.78rem;font-weight:700;color:#00695c;margin-bottom:8px">➕ Ajouter une entrée</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 130px 130px auto;gap:8px;align-items:end;flex-wrap:wrap">
      <div>
        <label style="font-size:.7rem;color:#555;display:block;margin-bottom:2px">Agent</label>
        <input type="text" id="hist-new-agent" placeholder="Ex : LCL DUPONT"
          style="width:100%;padding:5px 8px;border:1.5px solid #80cbc4;border-radius:5px;font-size:.78rem;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:.7rem;color:#555;display:block;margin-bottom:2px">Groupe</label>
        <select id="hist-new-groupe" style="width:100%;padding:5px 8px;border:1.5px solid #80cbc4;border-radius:5px;font-size:.78rem">${grpOpts}</select>
      </div>
      <div>
        <label style="font-size:.7rem;color:#555;display:block;margin-bottom:2px">Date début</label>
        <input type="date" id="hist-new-debut" style="width:100%;padding:5px 6px;border:1.5px solid #80cbc4;border-radius:5px;font-size:.75rem">
      </div>
      <div>
        <label style="font-size:.7rem;color:#555;display:block;margin-bottom:2px">Date fin (vide=actif)</label>
        <input type="date" id="hist-new-fin" style="width:100%;padding:5px 6px;border:1.5px solid #80cbc4;border-radius:5px;font-size:.75rem">
      </div>
      <button id="hist-btn-add"
        style="padding:6px 14px;background:#00695c;color:#fff;border:none;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap;height:32px">
        ➕ Ajouter
      </button>
    </div>
  </div>`;

  body.innerHTML=html;

  // ── Boutons Sauver ──
  body.querySelectorAll('.hist-btn-save').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      const hid=parseInt(btn.dataset.hid);
      const tr=btn.closest('tr');
      const debut=tr.querySelector('.hist-date-debut').value;
      const fin=tr.querySelector('.hist-date-fin').value;
      if(!debut){ showToast('Date de début requise',false); return; }
      const r=await ajax({action:'save_agent_history',hid,agent:btn.dataset.agent,groupe:btn.dataset.groupe,date_debut:debut,date_fin:fin});
      if(r.ok){
        showToast('✅ Sauvegardé');
        _agentsHistory=null; // invalider le cache
        loadHistAgents();
      } else showToast('Erreur : '+r.msg,false);
    });
  });

  // ── Boutons Supprimer ──
  body.querySelectorAll('.hist-btn-del').forEach(btn=>{
    btn.addEventListener('click',async()=>{
      if(!confirm(`Supprimer l'entrée de "${btn.dataset.agent}" ?`)) return;
      const r=await ajax({action:'delete_agent_history',hid:btn.dataset.hid});
      if(r.ok){
        showToast('Supprimé');
        _agentsHistory=null;
        loadHistAgents();
      } else showToast('Erreur',false);
    });
  });

  // ── Bouton Ajouter ──
  body.querySelector('#hist-btn-add')?.addEventListener('click',async()=>{
    const ag=document.getElementById('hist-new-agent').value.trim().toUpperCase();
    const grp=document.getElementById('hist-new-groupe').value;
    const debut=document.getElementById('hist-new-debut').value;
    const fin=document.getElementById('hist-new-fin').value;
    if(!ag||!debut){ showToast('Agent et date de début requis',false); return; }
    const r=await ajax({action:'save_agent_history',hid:0,agent:ag,groupe:grp,date_debut:debut,date_fin:fin});
    if(r.ok){
      showToast('✅ Entrée ajoutée');
      _agentsHistory=null;
      loadHistAgents();
    } else showToast('Erreur : '+r.msg,false);
  });
}

document.getElementById('btn-hist-reload')?.addEventListener('click', loadHistAgents);
// Chargement auto à l'activation de l'onglet
document.addEventListener('click', e=>{
  if(e.target.closest('.adm-tab[data-tab="historique-agents"]')) loadHistAgents();
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
