<?php
/**
 * API Backend - Importation & Traitement des Données (CSV / ICS)
 * backend_import.php
 * * Ce script agit comme un contrôleur AJAX pour gérer l'importation massive des élèves
 * et le parsing des emplois du temps (fichiers ICS).
 *
 * Il gère :
 * 1. L'import CSV des élèves (Création/Mise à jour).
 * 2. L'upload des fichiers ICS temporaires.
 * 3. Le parsing des fichiers ICS pour extraire les salles de cours à une date précise.
 * 4. L'algorithme de comparaison (Diff) pour ne mettre à jour la BDD que si nécessaire.
 */

// --- CONFIGURATION DE L'ENVIRONNEMENT & GESTION D'ERREURS ---
// On force le retour en JSON même en cas d'erreur PHP fatale
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
    $pdo->exec("SET time_zone = '+01:00'"); // Synchronisation fuseau horaire
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => "Erreur Connexion DB: " . $e->getMessage()]);
    exit;
}

// Configuration du dossier temporaire pour les fichiers ICS
$uploadDir = __DIR__ . '/uploads_temp/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

$action = $_POST['action'] ?? '';

// =========================================================
// FONCTIONS UTILITAIRES (HELPERS)
// =========================================================

/**
 * Force l'encodage en UTF-8 pour éviter les caractères corrompus.
 */
function forceUtf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'auto');
}

/**
 * Récupère l'ID d'une classe via son nom, ou la crée si elle n'existe pas.
 * Gère l'exclusion des classes spécifiques (ex: PAEN).
 */
function getClassId($pdo, $className) {
    if (empty($className)) return null;
    
    // Exclusion des classes sans emploi du temps standard
    if (in_array(strtoupper($className), ['PAEN3', 'PAEN4'])) return 'EXCLUDED';

    $stmt = $pdo->prepare("SELECT id FROM classes WHERE name = ?");
    $stmt->execute([$className]);
    $res = $stmt->fetch();
    if ($res) return $res['id'];
    
    // Création à la volée
    $stmtIns = $pdo->prepare("INSERT INTO classes (name) VALUES (?)");
    $stmtIns->execute([$className]);
    return $pdo->lastInsertId();
}

/**
 * Normalise le nom d'une salle et récupère son ID.
 * Gère les alias (G8 -> G08) et le nettoyage des noms complexes.
 */
function getRoomId($pdo, $roomName) {
    if (empty($roomName) || $roomName === '?') return null;
    $roomName = trim($roomName);
    $cleanName = $roomName;

    // Règles de normalisation spécifiques au lycée
    if (stripos($roomName, 'NSI-SNT-GT') !== false || stripos($roomName, 'Salle Info') !== false) {
        $cleanName = 'Salle Info NSI-SNT';
    } elseif (stripos($roomName, 'Absent(e)') !== false) {
        $cleanName = 'Absent(e)';
    } else {
        // Extraction du code salle principal (ex: "E102 (Cours)" -> "E102")
        if (preg_match('/^([A-Z0-9]+)/', $roomName, $matches)) $cleanName = $matches[1];
        
        // Mapping des alias courants
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
            // Création automatique si la salle est inconnue (Bâtiment/Étage par défaut : 1)
            $stmtIns = $pdo->prepare("INSERT INTO rooms (name, building_id, floor_id) VALUES (?, 1, 1)"); 
            $stmtIns->execute([$cleanName]);
            return $pdo->lastInsertId();
        } catch (Exception $e) { return null; }
    }
}

/**
 * Nettoie le nom du fichier ICS pour l'affichage utilisateur.
 */
function parseFilenameDisplay($filename) {
    $temp = str_replace('.ics', '', $filename);
    if (strpos($temp, 'Calendrier_') === 0) $temp = substr($temp, 11);
    $temp = preg_replace('/_\d{8}$/', '', $temp); // Retire le timestamp final
    return str_replace('_', ' ', $temp);
}

/**
 * Algorithme de correspondance Fichier <-> Élève.
 * Tente de trouver l'ID de l'élève en comparant le nom du fichier avec les données en base.
 * Utilise une comparaison "aplatie" (sans espaces/accents/tirets) pour la robustesse.
 */
function findRecipientIdFromFilename($pdo, $filename) {
    $temp = str_replace('.ics', '', $filename);
    if (strpos($temp, 'Calendrier_') === 0) $temp = substr($temp, 11);
    $temp = preg_replace('/_\d{8}$/', '', $temp); 
    
    // Correctifs pour les cas particuliers connus (erreurs de saisie administration)
    if (stripos($temp, 'PENEVERE') !== false && stripos($temp, 'Elior') !== false) {
        $temp = str_replace('Elior', 'Eleanor', $temp);
    }
    if (stripos($temp, 'RISEROLE') !== false && stripos($temp, 'PONS') === false) {
        $temp = str_replace('RISEROLE', 'PONS-RISEROLE', $temp);
    }
    
    // Création de la clé de recherche "plate"
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
// MODULE 1 : IMPORTATION MASSE (CSV)
// =========================================================
if ($action === 'import_csv') {
    try {
        if (empty($_FILES['csv_file']['tmp_name'])) throw new Exception('Aucun fichier reçu.');
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
        if ($handle === false) throw new Exception('Erreur lecture du fichier CSV.');

        $added = 0; $updated = 0; $excluded = 0;
        
        // Utilisation d'une transaction pour garantir l'intégrité des données
        $pdo->beginTransaction();

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            // Structure attendue : NOM ; PRENOM ; CLASSE
            if (count($data) < 3) continue;
            
            $nom = trim(forceUtf8($data[0]));
            $prenom = trim(forceUtf8($data[1]));
            $className = trim(forceUtf8($data[2]));

            // Ignore la ligne d'en-tête
            if (strtolower($nom) == 'nom' && strtolower($prenom) == 'prenom') continue;
            if (empty($nom) || empty($prenom)) continue;

            $classId = getClassId($pdo, $className);

            // Gestion des élèves exclus (ex: classes non concernées)
            if ($classId === 'EXCLUDED') {
                $excluded++;
                // Nettoyage préventif si l'élève existait déjà
                $del = $pdo->prepare("DELETE FROM recipients WHERE nom = ? AND prenom = ?");
                $del->execute([$nom, $prenom]);
                continue;
            }

            // Vérification existence pour Insert ou Update
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
// MODULE 2 : TÉLÉCHARGEMENT (Upload Fichiers ICS)
// =========================================================
if ($action === 'upload') {
    $count = 0;
    if (!empty($_FILES['files'])) {
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            $name = $_FILES['files']['name'][$key];
            // Nettoyage strict du nom de fichier pour la sécurité
            $cleanName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
            if (move_uploaded_file($tmpName, $uploadDir . $cleanName)) $count++;
        }
    }
    echo json_encode(['status' => 'success', 'count' => $count]);
    exit;
}

// =========================================================
// MODULE 3 : LISTING DES FICHIERS EN ATTENTE
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
// MODULE 4 : TRAITEMENT ASYNCHRONE (Parsing ICS)
// =========================================================
if ($action === 'process') {
    try {
        $fileName = $_POST['filename'];
        $filePath = $uploadDir . $fileName;

        if (!file_exists($filePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Fichier introuvable sur serveur']); exit;
        }

        // --- EXCLUSIONS SPÉCIFIQUES ---
        // Liste noire de fichiers à ignorer (Doublons ou cas particuliers)
        $ignoredFiles = [
            'Calendrier_AHMADOUN_Mohamed_04122007.ics', 'Calendrier_AHMED_Whabi_03032008.ics',
            'Calendrier_BENCHEIK_Sarah_10072005.ics', 'Calendrier_BORJAS_Connor_24102006.ics'
        ];
        if (in_array($fileName, $ignoredFiles)) {
             if (file_exists($filePath)) unlink($filePath);
             echo json_encode(['status' => 'skipped', 'message' => 'Fichier ignoré (Liste noire)']); exit;
        }

        // 1. IDENTIFICATION
        $recipientId = findRecipientIdFromFilename($pdo, $fileName);
        if (!$recipientId) {
            echo json_encode(['status' => 'error', 'message' => 'Élève introuvable (Base de données)']); exit;
        }
        
        // Vérification si la classe de l'élève est exclue (PAEN)
        $stmtClass = $pdo->prepare("SELECT c.name FROM recipients r LEFT JOIN classes c ON r.class_id = c.id WHERE r.id = ?");
        $stmtClass->execute([$recipientId]);
        $classInfo = $stmtClass->fetchColumn();
        if (in_array(strtoupper($classInfo), ['PAEN3', 'PAEN4'])) {
             if (file_exists($filePath)) unlink($filePath);
             echo json_encode(['status' => 'skipped', 'message' => 'Classe PAEN ignorée']); exit;
        }

        // 2. PARSING DU FICHIER ICS
        $content = file_get_contents($filePath);
        $heures = [];
        $events = explode('BEGIN:VEVENT', $content);
        array_shift($events); // Ignore le header

        foreach ($events as $event) {
            $block = explode('END:VEVENT', $event)[0];
            
            // Extraction des métadonnées de l'événement (Début, Fin, Lieu)
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

                    // --- FILTRE DATE CIBLE ---
                    // On ne retient que les cours du 13 Février 2026
                    if ($start->format('Y-m-d') === '2026-02-13') {
                        $roomId = null;
                        if (!empty($loc)) $roomId = getRoomId($pdo, $loc);
                        
                        // Mapping des heures (8h à 17h)
                        $hStart = (int)$start->format('G');
                        $hEnd = (int)$end->format('G');
                        for ($h=$hStart; $h<$hEnd; $h++) if ($h>=8 && $h<=17) $heures[$h] = $roomId;
                    }
                } catch (Exception $ex) {}
            }
        }

        // 3. GESTION DES ABSENCES
        // Si aucun cours n'est détecté ce jour-là, on marque l'élève comme "Absent(e)"
        if (empty($heures)) {
            $AbsentRoomId = getRoomId($pdo, 'Absent(e)');
            for ($h=8; $h<=17; $h++) $heures[$h] = $AbsentRoomId;
        }

        // 4. LOGIQUE DE MISE À JOUR (UPSERT INTELLIGENT)
        $stmtSched = $pdo->prepare("SELECT * FROM schedules WHERE recipient_id = ?");
        $stmtSched->execute([$recipientId]);
        $existing = $stmtSched->fetch(PDO::FETCH_ASSOC);

        // Construction du tableau de données pour l'insertion/update
        $newValues = [
            'h08' => $heures[8] ?? null, 'h09' => $heures[9] ?? null, 'h10' => $heures[10] ?? null,
            'h11' => $heures[11] ?? null, 'h12' => $heures[12] ?? null, 'h13' => $heures[13] ?? null,
            'h14' => $heures[14] ?? null, 'h15' => $heures[15] ?? null, 'h16' => $heures[16] ?? null,
            'h17' => $heures[17] ?? null
        ];

        $status = 'error';

        if (!$existing) {
            // Cas 1 : Aucune donnée -> INSERTION
            $sql = "INSERT INTO schedules (recipient_id, h08, h09, h10, h11, h12, h13, h14, h15, h16, h17) 
                    VALUES (:rid, :h08, :h09, :h10, :h11, :h12, :h13, :h14, :h15, :h16, :h17)";
            $params = array_merge([':rid' => $recipientId], $newValues);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $status = 'inserted';
        } else {
            // Cas 2 : Données existantes -> COMPARAISON
            $isDifferent = false;
            foreach ($newValues as $key => $val) {
                if ($existing[$key] != $val) {
                    $isDifferent = true;
                    break;
                }
            }

            if ($isDifferent) {
                // Si différence détectée -> MISE À JOUR
                $sql = "UPDATE schedules SET 
                        h08=:h08, h09=:h09, h10=:h10, h11=:h11, h12=:h12, 
                        h13=:h13, h14=:h14, h15=:h15, h16=:h16, h17=:h17 
                        WHERE id = :id";
                $params = array_merge([':id' => $existing['id']], $newValues);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $status = 'updated';
            } else {
                // Si identique -> IGNORE (Optimisation BDD)
                $status = 'unchanged';
            }
        }

        // Suppression du fichier après traitement réussi
        if (file_exists($filePath)) unlink($filePath);
        echo json_encode(['status' => $status]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => "Erreur : " . $e->getMessage()]);
    }
    exit;
}

// =========================================================
// MODULE 5 : NETTOYAGE
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