<?php
// apis.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    echo json_encode(['status' => 'error', 'message' => "Erreur PHP: $errstr"]);
    exit;
}
set_error_handler("jsonErrorHandler");

// --- CONFIG BDD ---
$host = 'mariadb-server';
$db   = 'CVL_LJF_St_Valentin_2026';
$user = 'web';
$pass = '*RaspberryPiThéo_ServeurWeb_Compteweb@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Erreur Connexion BDD']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$action = $_POST['action'] ?? '';

// 1. UPLOAD
if ($action === 'upload') {
    $count = 0;
    if (!empty($_FILES['files'])) {
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            $name = $_FILES['files']['name'][$key];
            // Nettoyage nom fichier pour éviter soucis accents
            $cleanName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name); 
            if (move_uploaded_file($tmpName, $uploadDir . $cleanName)) {
                $count++;
            }
        }
    }
    echo json_encode(['status' => 'success', 'count' => $count]);
    exit;
}

// 2. LISTER LES FICHIERS EN ATTENTE (Nouveau)
if ($action === 'list') {
    $files = glob($uploadDir . '*.ics');
    $list = [];
    foreach ($files as $filePath) {
        $content = file_get_contents($filePath);
        $nom = 'Inconnu'; $prenom = '';
        
        // On essaie de lire le nom tout de suite pour l'affichage
        if (preg_match('/X-WR-CALNAME.*:Calendrier - ([^\s]+) ([^\s]+)/u', $content, $matches)) {
            $nom = $matches[1];
            $prenom = $matches[2];
        } else {
            // Fallback si format différent
             $nom = basename($filePath);
        }

        $list[] = [
            'filename' => basename($filePath),
            'student'  => "$prenom $nom"
        ];
    }
    echo json_encode(['status' => 'success', 'files' => $list]);
    exit;
}

// 3. TRAITER UN FICHIER
if ($action === 'process') {
    $fileName = $_POST['filename'];
    $forceOverwrite = filter_var($_POST['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $filePath = $uploadDir . $fileName;

    if (!file_exists($filePath)) {
        echo json_encode(['status' => 'error', 'message' => 'Fichier introuvable']);
        exit;
    }

    $content = file_get_contents($filePath);
    
    // Extraction Nom
    $nom = 'Inconnu'; $prenom = 'Inconnu';
    if (preg_match('/X-WR-CALNAME.*:Calendrier - ([^\s]+) ([^\s]+)/u', $content, $matches)) {
        $nom = $matches[1];
        $prenom = $matches[2];
    }

    // Vérif Doublon
    if (!$forceOverwrite) {
        $stmtCheck = $pdo->prepare("SELECT id FROM planning_13_fevrier WHERE nom = ? AND prenom = ?");
        $stmtCheck->execute([$nom, $prenom]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['status' => 'conflict', 'student' => "$prenom $nom"]);
            exit;
        }
    }

    // Traitement 13 Fév
    $classe = "Non défini";
    $parts = explode('_', $fileName);
    if (count($parts) > 1 && strlen($parts[0]) <= 6) $classe = $parts[0];

    $heures = [];
    $events = explode('BEGIN:VEVENT', $content);
    array_shift($events);

    foreach ($events as $event) {
        $block = explode('END:VEVENT', $event)[0];
        preg_match('/DTSTART:(.*?)[\r\n]/', $block, $s);
        preg_match('/DTEND:(.*?)[\r\n]/', $block, $e);
        preg_match('/LOCATION;LANGUAGE=fr:(.*?)[\r\n]/', $block, $l);

        $startStr = trim($s[1]??''); $endStr = trim($e[1]??''); $loc = trim($l[1]??'');

        if ($startStr && $endStr) {
            try {
                $tz = new DateTimeZone('Europe/Paris');
                $start = new DateTime($startStr); $start->setTimezone($tz);
                $end = new DateTime($endStr); $end->setTimezone($tz);

                if ($start->format('Y-m-d') === '2026-02-13') {
                    $salle = '?';
                    if (!empty($loc)) {
                        preg_match('/^([A-Z0-9]+)/', $loc, $m);
                        $salle = $m[1] ?? $loc;
                    }
                    $hStart = (int)$start->format('G');
                    $hEnd = (int)$end->format('G');
                    for ($h=$hStart; $h<$hEnd; $h++) {
                        if ($h>=8 && $h<=17) $heures[$h] = $salle;
                    }
                }
            } catch (Exception $ex) {}
        }
    }

    // SQL Upsert
    $sql = "INSERT INTO planning_13_fevrier 
            (nom, prenom, classe, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) 
            VALUES (:n, :p, :c, :h8, :h9, :h10, :h11, :h12, :h13, :h14, :h15, :h16, :h17)
            ON DUPLICATE KEY UPDATE 
            h08=VALUES(h08), h09=VALUES(h09), h10=VALUES(h10), h11=VALUES(h11), h12=VALUES(h12), 
            h13=VALUES(h13), h14=VALUES(h14), h15=VALUES(h15), h16=VALUES(h16), h17=VALUES(h17)";

    $stmt = $pdo->prepare($sql);
    $params = [
        ':n'=>$nom, ':p'=>$prenom, ':c'=>$classe,
        ':h8'=>$heures[8]??null, ':h9'=>$heures[9]??null, ':h10'=>$heures[10]??null,
        ':h11'=>$heures[11]??null, ':h12'=>$heures[12]??null, ':h13'=>$heures[13]??null,
        ':h14'=>$heures[14]??null, ':h15'=>$heures[15]??null, ':h16'=>$heures[16]??null,
        ':h17'=>$heures[17]??null
    ];
    $stmt->execute($params);

    echo json_encode(['status' => 'success']);
    exit;
}

// 4. NETTOYAGE
if ($action === 'cleanup') {
    $files = glob($uploadDir . '*');
    foreach ($files as $file) if (is_file($file)) unlink($file);
    echo json_encode(['status' => 'cleaned']);
    exit;
}
?>