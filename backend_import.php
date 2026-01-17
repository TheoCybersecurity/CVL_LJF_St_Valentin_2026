<?php
// backend_import.php

// --- DEBUG & CONFIG ---
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    echo json_encode(['status' => 'error', 'message' => "PHP Error: $errstr (Ligne $errline)"]);
    exit;
}
set_error_handler("jsonErrorHandler");

try {
    require_once 'db.php'; 
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Erreur DB: " . $e->getMessage()]);
    exit;
}

$uploadDir = __DIR__ . '/uploads_temp/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

$action = $_POST['action'] ?? '';

// --- HELPER : UTF8 ---
function forceUtf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'auto');
}

// --- HELPER : GESTION DES CLASSES ---
function getClassId($pdo, $className) {
    if (empty($className)) return null;
    $stmt = $pdo->prepare("SELECT id FROM classes WHERE name = ?");
    $stmt->execute([$className]);
    $res = $stmt->fetch();
    if ($res) return $res['id'];
    
    $stmtIns = $pdo->prepare("INSERT INTO classes (name) VALUES (?)");
    $stmtIns->execute([$className]);
    return $pdo->lastInsertId();
}

// --- HELPER : GESTION DES SALLES ---
function getRoomId($pdo, $roomName) {
    if (empty($roomName) || $roomName === '?') return null;

    $roomName = trim($roomName);
    $cleanName = $roomName;

    // 1. EXCEPTIONS NOMS LONGS
    if (stripos($roomName, 'NSI-SNT-GT') !== false) {
        $cleanName = 'Salle Info NSI-SNT';
    } 
    elseif (stripos($roomName, 'Salle Info') !== false) {
        $cleanName = 'Salle Info NSI-SNT';
    }
    else {
        // 2. NETTOYAGE STANDARD
        if (preg_match('/^([A-Z0-9]+)/', $roomName, $matches)) {
            $cleanName = $matches[1];
        }

        // 3. EXCEPTIONS CODES COURTS
        $shortCodeAliases = [
            'G8' => 'G08',
            'G9' => 'G09',
        ];

        if (isset($shortCodeAliases[$cleanName])) {
            $cleanName = $shortCodeAliases[$cleanName];
        }
    }

    // 4. RECHERCHE BDD
    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE name = ?");
    $stmt->execute([$cleanName]);
    $res = $stmt->fetch();
    
    if ($res) {
        return $res['id'];
    } else {
        try {
            // Création par défaut (Bat 1, Etage 1)
            $stmtIns = $pdo->prepare("INSERT INTO rooms (name, building_id, floor_id) VALUES (?, 1, 1)"); 
            $stmtIns->execute([$cleanName]);
            return $pdo->lastInsertId();
        } catch (Exception $e) {
            return null;
        }
    }
}

// --- HELPER : PARSING NOM FICHIER ---
function parseFilenameInfo($filename) {
    $temp = str_replace('.ics', '', $filename);
    if (strpos($temp, 'Calendrier_') === 0) $temp = substr($temp, 11);
    $temp = preg_replace('/_\d{8}$/', '', $temp); 
    
    $parts = explode('_', $temp);
    $prenom = array_pop($parts);
    $nom = implode(' ', $parts);
    
    return ['nom' => trim($nom), 'prenom' => trim($prenom)];
}

// =========================================================
// 1. IMPORT CSV
// =========================================================
if ($action === 'import_csv') {
    try {
        if (empty($_FILES['csv_file']['tmp_name'])) throw new Exception('Aucun fichier.');
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
        if ($handle === false) throw new Exception('Erreur lecture CSV.');

        $added = 0; $updated = 0;
        $pdo->beginTransaction();

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            // On s'attend à 3 colonnes minimum
            if (count($data) < 3) continue;
            
            // LECTURE STRICTE : Colonne 0 = Nom, Colonne 1 = Prénom, Colonne 2 = Classe
            // On applique juste trim() et UTF8, aucune conversion de majuscule/minuscule.
            $nom = trim(forceUtf8($data[0]));
            $prenom = trim(forceUtf8($data[1]));
            $className = trim(forceUtf8($data[2]));

            // Sauter la ligne d'entête (insensible à la casse)
            if (strtolower($nom) == 'nom' && strtolower($prenom) == 'prenom') continue;
            
            if (empty($nom) || empty($prenom)) continue;

            $classId = getClassId($pdo, $className);

            // Recherche exacte (sensible ou non selon la config SQL, généralement insensible)
            $stmt = $pdo->prepare("SELECT id FROM recipients WHERE nom = ? AND prenom = ?");
            $stmt->execute([$nom, $prenom]);
            $row = $stmt->fetch();

            if ($row) {
                $upd = $pdo->prepare("UPDATE recipients SET class_id = ? WHERE id = ?");
                $upd->execute([$classId, $row['id']]);
                $updated++;
            } else {
                $ins = $pdo->prepare("INSERT INTO recipients (nom, prenom, class_id) VALUES (?, ?, ?)");
                $ins->execute([$nom, $prenom, $classId]);
                $added++;
            }
        }
        $pdo->commit();
        fclose($handle);
        echo json_encode(['status' => 'success', 'message' => "CSV : $added ajouts, $updated MAJ."]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================
// 2. UPLOAD ICS
// =========================================================
if ($action === 'upload') {
    $count = 0;
    if (!empty($_FILES['files'])) {
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            $name = $_FILES['files']['name'][$key];
            $cleanName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
            if (move_uploaded_file($tmpName, $uploadDir . $cleanName)) $count++;
        }
    }
    echo json_encode(['status' => 'success', 'count' => $count]);
    exit;
}

// =========================================================
// 3. LISTER ICS
// =========================================================
if ($action === 'list') {
    $files = glob($uploadDir . '*.ics');
    $list = [];
    if ($files) {
        foreach ($files as $filePath) {
            $info = parseFilenameInfo(basename($filePath));
            $list[] = ['filename' => basename($filePath), 'student' => $info['nom'] . ' ' . $info['prenom']];
        }
    }
    echo json_encode(['status' => 'success', 'files' => $list]);
    exit;
}

// =========================================================
// 4. TRAITEMENT ICS
// =========================================================
if ($action === 'process') {
    try {
        $fileName = $_POST['filename'];
        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) throw new Exception('Fichier introuvable');

        // 1. Infos Nom/Prénom
        $info = parseFilenameInfo($fileName);
        // MODIFICATION ICI : On ne force plus la majuscule, on garde le nom tel quel
        $nom = $info['nom']; 
        $prenom = $info['prenom'];

        // 2. Chercher l'élève
        $stmtCheck = $pdo->prepare("SELECT id FROM recipients WHERE nom = ? AND prenom = ?");
        $stmtCheck->execute([$nom, $prenom]);
        $recipient = $stmtCheck->fetch();
        
        $recipientId = null;
        if ($recipient) {
            $recipientId = $recipient['id'];
        } else {
            // Création élève si absent (ex: pas dans le CSV)
            // On insère le nom tel qu'il vient du fichier ICS
            $stmtIns = $pdo->prepare("INSERT INTO recipients (nom, prenom, class_id) VALUES (?, ?, NULL)");
            $stmtIns->execute([$nom, $prenom]);
            $recipientId = $pdo->lastInsertId();
        }

        // 3. Parsing ICS
        $content = file_get_contents($filePath);
        $heures = [];
        $events = explode('BEGIN:VEVENT', $content);
        array_shift($events);

        foreach ($events as $event) {
            $block = explode('END:VEVENT', $event)[0];
            preg_match('/DTSTART:(.*?)[\r\n]/', $block, $s);
            preg_match('/DTEND:(.*?)[\r\n]/', $block, $e);
            preg_match('/LOCATION;LANGUAGE=fr:(.*?)[\r\n]/', $block, $l);

            $startStr = trim($s[1]??''); 
            $endStr = trim($e[1]??''); 
            $loc = trim($l[1]??'');

            if ($startStr && $endStr) {
                try {
                    $tz = new DateTimeZone('Europe/Paris');
                    $start = new DateTime($startStr); $end = new DateTime($endStr);
                    if (substr($startStr, -1) === 'Z') $start->setTimezone($tz);
                    if (substr($endStr, -1) === 'Z') $end->setTimezone($tz);

                    if ($start->format('Y-m-d') === '2026-02-13') {
                        $roomId = null;
                        if (!empty($loc)) {
                            $roomId = getRoomId($pdo, $loc);
                        }
                        
                        $hStart = (int)$start->format('G');
                        $hEnd = (int)$end->format('G');
                        for ($h=$hStart; $h<$hEnd; $h++) {
                            if ($h>=8 && $h<=17) $heures[$h] = $roomId;
                        }
                    }
                } catch (Exception $ex) {}
            }
        }

        // 4. SQL
        $stmtSched = $pdo->prepare("SELECT id FROM schedules WHERE recipient_id = ?");
        $stmtSched->execute([$recipientId]);
        $scheduleRow = $stmtSched->fetch();

        $p = [
            ':rid' => $recipientId,
            ':h8'=>$heures[8]??null, ':h9'=>$heures[9]??null, ':h10'=>$heures[10]??null,
            ':h11'=>$heures[11]??null, ':h12'=>$heures[12]??null, ':h13'=>$heures[13]??null,
            ':h14'=>$heures[14]??null, ':h15'=>$heures[15]??null, ':h16'=>$heures[16]??null,
            ':h17'=>$heures[17]??null
        ];

        if ($scheduleRow) {
            $sql = "UPDATE schedules SET 
                    h08=:h8, h09=:h9, h10=:h10, h11=:h11, h12=:h12, 
                    h13=:h13, h14=:h14, h15=:h15, h16=:h16, h17=:h17 
                    WHERE id = :id";
            $p[':id'] = $scheduleRow['id'];
            unset($p[':rid']);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
        } else {
            $sql = "INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) 
                    VALUES (:rid, :h8, :h9, :h10, :h11, :h12, :h13, :h14, :h15, :h16, :h17)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
        }

        if (file_exists($filePath)) unlink($filePath);
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================
// 5. CLEANUP
// =========================================================
if ($action === 'cleanup') {
    $files = glob($uploadDir . '*.ics');
    if ($files) foreach ($files as $f) if (is_file($f)) unlink($f);
    echo json_encode(['status' => 'cleaned']);
    exit;
}
?>