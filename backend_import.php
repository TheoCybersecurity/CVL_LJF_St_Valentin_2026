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
    $pdo->exec("SET time_zone = '+01:00'"); 
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Erreur DB: " . $e->getMessage()]);
    exit;
}

$uploadDir = __DIR__ . '/uploads_temp/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

$action = $_POST['action'] ?? '';

// --- HELPERS ---
function forceUtf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'auto');
}

function getClassId($pdo, $className) {
    if (empty($className)) return null;
    if (in_array(strtoupper($className), ['PAEN3', 'PAEN4'])) return 'EXCLUDED';

    $stmt = $pdo->prepare("SELECT id FROM classes WHERE name = ?");
    $stmt->execute([$className]);
    $res = $stmt->fetch();
    if ($res) return $res['id'];
    
    $stmtIns = $pdo->prepare("INSERT INTO classes (name) VALUES (?)");
    $stmtIns->execute([$className]);
    return $pdo->lastInsertId();
}

function getRoomId($pdo, $roomName) {
    if (empty($roomName) || $roomName === '?') return null;
    $roomName = trim($roomName);
    $cleanName = $roomName;

    if (stripos($roomName, 'NSI-SNT-GT') !== false || stripos($roomName, 'Salle Info') !== false) {
        $cleanName = 'Salle Info NSI-SNT';
    } elseif (stripos($roomName, 'Stage') !== false) {
        $cleanName = 'Stage';
    } else {
        if (preg_match('/^([A-Z0-9]+)/', $roomName, $matches)) $cleanName = $matches[1];
        $shortCodeAliases = ['G8' => 'G08', 'G9' => 'G09'];
        if (isset($shortCodeAliases[$cleanName])) $cleanName = $shortCodeAliases[$cleanName];
    }

    $stmt = $pdo->prepare("SELECT id FROM rooms WHERE name = ?");
    $stmt->execute([$cleanName]);
    $res = $stmt->fetch();
    
    if ($res) {
        return $res['id'];
    } else {
        try {
            $stmtIns = $pdo->prepare("INSERT INTO rooms (name, building_id, floor_id) VALUES (?, 1, 1)"); 
            $stmtIns->execute([$cleanName]);
            return $pdo->lastInsertId();
        } catch (Exception $e) { return null; }
    }
}

function parseFilenameDisplay($filename) {
    $temp = str_replace('.ics', '', $filename);
    if (strpos($temp, 'Calendrier_') === 0) $temp = substr($temp, 11);
    $temp = preg_replace('/_\d{8}$/', '', $temp); 
    return str_replace('_', ' ', $temp);
}

function findRecipientIdFromFilename($pdo, $filename) {
    $temp = str_replace('.ics', '', $filename);
    if (strpos($temp, 'Calendrier_') === 0) $temp = substr($temp, 11);
    $temp = preg_replace('/_\d{8}$/', '', $temp); 
    
    // Correctifs spécifiques
    if (stripos($temp, 'PENEVERE') !== false && stripos($temp, 'Elior') !== false) {
        $temp = str_replace('Elior', 'Eleanor', $temp);
    }
    if (stripos($temp, 'RISEROLE') !== false && stripos($temp, 'PONS') === false) {
        $temp = str_replace('RISEROLE', 'PONS-RISEROLE', $temp);
    }
    
    $flatFilename = strtoupper(str_replace(['_', ' ', '-'], '', $temp));

    $sql = "SELECT id FROM recipients 
            WHERE REPLACE(REPLACE(REPLACE(CONCAT(nom, prenom), ' ', ''), '-', ''), '_', '') = :flatName
            OR REPLACE(REPLACE(REPLACE(CONCAT(prenom, nom), ' ', ''), '-', ''), '_', '') = :flatName";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':flatName' => $flatFilename]);
    $res = $stmt->fetch();

    return $res ? $res['id'] : null;
}

// =========================================================
// 1. IMPORT CSV
// =========================================================
if ($action === 'import_csv') {
    try {
        if (empty($_FILES['csv_file']['tmp_name'])) throw new Exception('Aucun fichier.');
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
        if ($handle === false) throw new Exception('Erreur lecture CSV.');

        $added = 0; $updated = 0; $excluded = 0;
        $pdo->beginTransaction();

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            if (count($data) < 3) continue;
            
            $nom = trim(forceUtf8($data[0]));
            $prenom = trim(forceUtf8($data[1]));
            $className = trim(forceUtf8($data[2]));

            if (strtolower($nom) == 'nom' && strtolower($prenom) == 'prenom') continue;
            if (empty($nom) || empty($prenom)) continue;

            $classId = getClassId($pdo, $className);

            if ($classId === 'EXCLUDED') {
                $excluded++;
                $del = $pdo->prepare("DELETE FROM recipients WHERE nom = ? AND prenom = ?");
                $del->execute([$nom, $prenom]);
                continue;
            }

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
        echo json_encode(['status' => 'success', 'message' => "CSV : $added ajouts, $updated MAJ, $excluded exclus."]);

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
            $list[] = [
                'filename' => basename($filePath), 
                'student' => parseFilenameDisplay(basename($filePath))
            ];
        }
    }
    echo json_encode(['status' => 'success', 'files' => $list]);
    exit;
}

// =========================================================
// 4. TRAITEMENT ICS (AVEC COMPARAISON INTELLIGENTE)
// =========================================================
if ($action === 'process') {
    try {
        $fileName = $_POST['filename'];
        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Fichier introuvable sur serveur']); exit;
        }

        // --- PAEN EXCLUSION ---
        $ignoredFiles = [
            'Calendrier_AHMADOUN_Mohamed_04122007.ics', 'Calendrier_AHMED_Whabi_03032008.ics',
            'Calendrier_BENCHEIK_Sarah_10072005.ics', 'Calendrier_BORJAS_Connor_24102006.ics'
        ];
        if (in_array($fileName, $ignoredFiles)) {
             if (file_exists($filePath)) unlink($filePath);
             echo json_encode(['status' => 'skipped', 'message' => 'PAEN (Normal)']); exit;
        }

        // 1. RECHERCHE
        $recipientId = findRecipientIdFromFilename($pdo, $fileName);
        if (!$recipientId) {
            echo json_encode(['status' => 'error', 'message' => 'Élève introuvable (Base)']); exit;
        }
        
        $stmtClass = $pdo->prepare("SELECT c.name FROM recipients r LEFT JOIN classes c ON r.class_id = c.id WHERE r.id = ?");
        $stmtClass->execute([$recipientId]);
        $classInfo = $stmtClass->fetchColumn();
        if (in_array(strtoupper($classInfo), ['PAEN3', 'PAEN4'])) {
             if (file_exists($filePath)) unlink($filePath);
             echo json_encode(['status' => 'skipped', 'message' => 'Classe PAEN']); exit;
        }

        // 2. PARSING
        $content = file_get_contents($filePath);
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
                    $start = new DateTime($startStr); $end = new DateTime($endStr);
                    if (substr($startStr, -1) === 'Z') $start->setTimezone($tz);
                    if (substr($endStr, -1) === 'Z') $end->setTimezone($tz);

                    if ($start->format('Y-m-d') === '2026-02-13') {
                        $roomId = null;
                        if (!empty($loc)) $roomId = getRoomId($pdo, $loc);
                        $hStart = (int)$start->format('G');
                        $hEnd = (int)$end->format('G');
                        for ($h=$hStart; $h<$hEnd; $h++) if ($h>=8 && $h<=17) $heures[$h] = $roomId;
                    }
                } catch (Exception $ex) {}
            }
        }

        // 3. STAGE
        if (empty($heures)) {
            $stageRoomId = getRoomId($pdo, 'Stage');
            for ($h=8; $h<=17; $h++) $heures[$h] = $stageRoomId;
        }

        // 4. SQL COMPARE & UPSERT
        $stmtSched = $pdo->prepare("SELECT * FROM schedules WHERE recipient_id = ?");
        $stmtSched->execute([$recipientId]);
        $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);

        // Préparation des nouvelles valeurs pour comparaison
        $newValues = [
            'h08' => $heures[8] ?? null, 'h09' => $heures[9] ?? null, 'h10' => $heures[10] ?? null,
            'h11' => $heures[11] ?? null, 'h12' => $heures[12] ?? null, 'h13' => $heures[13] ?? null,
            'h14' => $heures[14] ?? null, 'h15' => $heures[15] ?? null, 'h16' => $heures[16] ?? null,
            'h17' => $heures[17] ?? null
        ];

        $status = 'error';

        if (!$existing) {
            // INSERT
            $sql = "INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) 
                    VALUES (:rid, :h08, :h09, :h10, :h11, :h12, :h13, :h14, :h15, :h16, :h17)";
            $params = array_merge([':rid' => $recipientId], $newValues);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $status = 'inserted';
        } else {
            // COMPARE
            $isDifferent = false;
            foreach ($newValues as $key => $val) {
                // On compare (attention aux types NULL vs string vide etc)
                if ($existing[$key] != $val) {
                    $isDifferent = true;
                    break;
                }
            }

            if ($isDifferent) {
                // UPDATE
                $sql = "UPDATE schedules SET 
                        h08=:h08, h09=:h09, h10=:h10, h11=:h11, h12=:h12, 
                        h13=:h13, h14=:h14, h15=:h15, h16=:h16, h17=:h17 
                        WHERE id = :id";
                $params = array_merge([':id' => $existing['id']], $newValues);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $status = 'updated';
            } else {
                // IGNORE
                $status = 'unchanged';
            }
        }

        // SUCCÈS : On supprime le fichier
        if (file_exists($filePath)) unlink($filePath);
        echo json_encode(['status' => $status]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => "Erreur : " . $e->getMessage()]);
    }
    exit;
}

// =========================================================
// 5. CLEANUP
// =========================================================
if ($action === 'cleanup') {
    $files = glob($uploadDir . '*.ics');
    $count = 0;
    if ($files) {
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
                $count++;
            }
        }
    }
    echo json_encode(['status' => 'cleaned', 'count' => $count]);
    exit;
}
?>