<?php
// manage_orders.php
require_once 'db.php';
require_once 'auth_check.php'; 
require_once 'mail_config.php';
require_once 'logger.php';

checkAccess('cvl');

// --- 0. DONNÉES DE RÉFÉRENCE ---
$allClasses = $pdo->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$allRooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Produits (Roses)
$stmtRoses = $pdo->query("SELECT id, name FROM rose_products");
$allRoseTypes = $stmtRoses->fetchAll(PDO::FETCH_KEY_PAIR);

// Grille tarifaire
$stmtPrices = $pdo->query("SELECT quantity, price FROM roses_prices");
$rosesPriceTable = $stmtPrices->fetchAll(PDO::FETCH_KEY_PAIR); 
$maxQtyDefined = !empty($rosesPriceTable) ? max(array_keys($rosesPriceTable)) : 0;

$allMessages = $pdo->query("SELECT id, content FROM predefined_messages ORDER BY position ASC, id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- 1. TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $msgSuccess = "";
    $msgError = "";
    $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    try {
        // --- A. SUPPRESSION TOTALE COMMANDE ---
        if ($_POST['action'] === 'delete_order' && $orderId > 0) {
            $pdo->beginTransaction();

            // [LOG] Capture de l'état avant suppression
            $oldState = getOrderSnapshot($pdo, $orderId);

            // 1. Supprimer les roses
            $sqlRoses = "DELETE rr FROM recipient_roses rr 
                         INNER JOIN order_recipients ort ON rr.recipient_id = ort.id 
                         WHERE ort.order_id = ?";
            $stmt = $pdo->prepare($sqlRoses);
            $stmt->execute([$orderId]);

            // 2. Supprimer les destinataires
            $stmt = $pdo->prepare("DELETE FROM order_recipients WHERE order_id = ?");
            $stmt->execute([$orderId]);

            // 3. Supprimer la commande
            $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);

            // [LOG] Enregistrement
            logAction($_SESSION['user_id'], 'order', $orderId, 'ORDER_DELETED', $oldState, null, "Suppression définitive");

            $pdo->commit();
            $msgSuccess = "La commande #$orderId a été supprimée définitivement.";
        }

        // --- B. SUPPRESSION UN SEUL DESTINATAIRE ---
        elseif ($_POST['action'] === 'delete_recipient' && $orderId > 0) {
            $recipientToDeleteId = intval($_POST['target_recipient_id']);

            if ($recipientToDeleteId > 0) {
                $pdo->beginTransaction();

                // [LOG] On récupère infos du destinataire avant suppression
                $stmtLog = $pdo->prepare("
                    SELECT r.id, r.nom, r.prenom, r.class_id 
                    FROM order_recipients ort
                    JOIN recipients r ON ort.recipient_id = r.id
                    WHERE ort.id = ?
                ");
                $stmtLog->execute([$recipientToDeleteId]);
                $oldRecipientData = $stmtLog->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM recipient_roses WHERE recipient_id = ?")->execute([$recipientToDeleteId]);
                $pdo->prepare("DELETE FROM order_recipients WHERE id = ?")->execute([$recipientToDeleteId]);

                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM order_recipients WHERE order_id = ?");
                $stmtCount->execute([$orderId]);
                $remainingCount = $stmtCount->fetchColumn();

                if ($remainingCount == 0) {
                    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
                    $msgSuccess = "Dernier destinataire supprimé. La commande #$orderId a été annulée.";
                } else {
                    // Recalcul du prix
                    $stmtRem = $pdo->prepare("SELECT id FROM order_recipients WHERE order_id = ?");
                    $stmtRem->execute([$orderId]);
                    $remainingIds = $stmtRem->fetchAll(PDO::FETCH_COLUMN);
                    $newTotalPrice = 0.0;
                    foreach($remainingIds as $rId) {
                        $qStmt = $pdo->prepare("SELECT SUM(quantity) FROM recipient_roses WHERE recipient_id = ?");
                        $qStmt->execute([$rId]);
                        $qty = intval($qStmt->fetchColumn());
                        if ($qty > 0) {
                            if (isset($rosesPriceTable[$qty])) {
                                $newTotalPrice += floatval($rosesPriceTable[$qty]);
                            } else {
                                $basePrice = $rosesPriceTable[$maxQtyDefined] ?? ($qty * 2); 
                                $newTotalPrice += ($qty > $maxQtyDefined) ? ($qty * 2.00) : $basePrice;
                            }
                        }
                    }
                    $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?")->execute([$newTotalPrice, $orderId]);
                    $msgSuccess = "Destinataire supprimé. Nouveau total : " . number_format($newTotalPrice, 2) . " €";
                }
                $targetStudentId = $oldRecipientData['id'] ?? $recipientToDeleteId;
                
                logAction(
                    $_SESSION['user_id'], 
                    'recipient', 
                    $recipientToDeleteId, 
                    'RECIPIENT_DELETED', 
                    $oldRecipientData, // Contient maintenant le bon Nom/Prénom
                    null, 
                    "Suppression destinataire depuis commande #$orderId"
                );
                $pdo->commit();
            }
        }

        // --- C. VALIDATION PAIEMENT (AVEC ENVOI DE MAIL) ---
        elseif ($_POST['action'] === 'validate_payment' && $orderId > 0) {
            $adminId = $_SESSION['user_id'] ?? null; 

            $now = date('Y-m-d H:i:s');
            
            // 1. Mise à jour de la base de données
            $stmt = $pdo->prepare("UPDATE orders SET is_paid = 1, paid_at = ?, paid_by_cvl_id = ? WHERE id = ?");
            $stmt->execute([$now, $adminId, $orderId]);

            // [LOG] Juste après l'execute SQL
            logAction($_SESSION['user_id'], 'order', $orderId, 'PAYMENT_VALIDATED', ['is_paid' => 0], ['is_paid' => 1], "Paiement de la commande #$orderId validé");
            
            // 2. ENVOI DU MAIL DE CONFIRMATION
            try {
                // On récupère les infos de l'acheteur
                $stmtInfo = $pdo->prepare("
                    SELECT u.email, u.nom, u.prenom, o.total_price 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.id = ?
                ");
                $stmtInfo->execute([$orderId]);
                $orderInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                if ($orderInfo && !empty($orderInfo['email'])) {
                    $mail = getMailer();
                    $mail->addAddress($orderInfo['email']);
                    
                    $montant = number_format($orderInfo['total_price'], 2);
                    $prenom = htmlspecialchars($orderInfo['prenom']);
                    
                    $body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px;'>
                        <div style='background-color: #d63384; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                            <h1 style='margin: 0;'>Paiement Reçu ! ✅</h1>
                        </div>
                        <div style='padding: 20px; color: #333;'>
                            <p>Bonjour <strong>$prenom</strong>,</p>
                            <p>Bonne nouvelle ! Le CVL confirme la réception de votre paiement de <strong>$montant €</strong> pour la commande <strong>#$orderId</strong>.</p>
                            
                            <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d63384;'>
                                <p style='margin: 0;'>Vos roses sont désormais validées. Elles seront distribuées le Vendredi 13 Février.</p>
                            </div>

                            <p style='font-size: 0.9em; color: #666;'>
                                Prochaine étape : La distribution 🚚<br>
                                Vous recevrez un mail lorsque les roses seront livrées.
                            </p>
                            
                            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                            <p style='font-size: 0.8em; color: #888; text-align: center;'>
                                Ceci est un mail automatique. Merci de ne pas répondre.<br>
                                L'équipe du CVL.
                            </p>
                        </div>
                    </div>";

                    $mail->isHTML(true);
                    $mail->Subject = "Paiement validé - Commande #$orderId";
                    $mail->Body    = $body;
                    $mail->AltBody = "Paiement de $montant EUR reçu pour la commande #$orderId. Merci !";
                    $mail->send();
                }
            } catch (Exception $e) {
                // On ne bloque pas l'admin si le mail échoue, mais on le note dans les logs
                error_log("Erreur mail paiement commande $orderId : " . $e->getMessage());
            }

            $msgSuccess = "Paiement validé pour la commande #$orderId !";

            // Si c'est une requête AJAX
            if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
                // 1. On calcule le nouveau nombre de commandes impayées
                $stmtCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0");
                $countUnpaid = $stmtCount->fetchColumn();

                // 2. On l'ajoute dans la réponse JSON ('new_count')
                echo json_encode([
                    'success' => true, 
                    'is_paid' => true, 
                    'date' => date('d/m H:i'),
                    'new_count' => $countUnpaid
                ]);
                exit;
            }
        } 
        
        // --- D. ANNULATION PAIEMENT (AVEC MAIL DE CORRECTION) ---
        elseif ($_POST['action'] === 'cancel_payment' && $orderId > 0) {
            // 1. Mise à jour en base (Retour à impayé)
            $stmt = $pdo->prepare("UPDATE orders SET is_paid = 0, paid_at = NULL, paid_by_cvl_id = NULL WHERE id = ?");
            $stmt->execute([$orderId]);

            // [LOG] Juste après l'execute SQL
            logAction($_SESSION['user_id'], 'order', $orderId, 'PAYMENT_CANCELLED', ['is_paid' => 1], ['is_paid' => 0], "Annulation du paiement de la commande #$orderId");

            // 2. ENVOI DU MAIL D'ANNULATION
            try {
                // On récupère les infos pour le mail
                $stmtInfo = $pdo->prepare("
                    SELECT u.email, u.prenom, o.total_price 
                    FROM orders o 
                    JOIN users u ON o.user_id = u.user_id 
                    WHERE o.id = ?
                ");
                $stmtInfo->execute([$orderId]);
                $orderInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

                if ($orderInfo && !empty($orderInfo['email'])) {
                    $mail = getMailer();
                    $mail->addAddress($orderInfo['email']);
                    
                    $prenom = htmlspecialchars($orderInfo['prenom']);
                    $montant = number_format($orderInfo['total_price'], 2);

                    $body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; border: 1px solid #eee; border-radius: 8px;'>
                        <div style='background-color: #dc3545; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0;'>
                            <h1 style='margin: 0;'>Paiement Annulé ❌</h1>
                        </div>
                        <div style='padding: 20px; color: #333;'>
                            <p>Bonjour <strong>$prenom</strong>,</p>
                            <p>Suite à une erreur de manipulation administrative, nous avons annulé la validation du paiement de votre commande <strong>#$orderId</strong>.</p>
                            
                            <div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ffc107; color: #856404;'>
                                <p style='margin: 0;'><strong>Statut actuel : À payer ($montant €)</strong><br>
                                Si vous n'avez pas encore réglé, merci de venir au foyer/CVL pour finaliser la commande.</p>
                            </div>

                            <p style='font-size: 0.9em; color: #666;'>
                                Si vous pensez qu'il s'agit d'une erreur de notre part et que vous avez bien payé, n'hésitez pas à venir nous voir.
                            </p>
                            
                            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                            <p style='font-size: 0.8em; color: #888; text-align: center;'>
                                Ceci est un mail automatique. Merci de ne pas répondre.<br>
                                L'équipe du CVL.
                            </p>
                        </div>
                    </div>";

                    $mail->isHTML(true);
                    $mail->Subject = "Correction : Paiement annulé - Commande #$orderId";
                    $mail->Body    = $body;
                    $mail->AltBody = "Le statut 'Payé' de votre commande #$orderId a été annulé suite à une erreur. Merci de venir régler.";
                    $mail->send();
                }
            } catch (Exception $e) {
                error_log("Erreur mail annulation commande $orderId : " . $e->getMessage());
            }

            $msgSuccess = "Paiement annulé pour la commande #$orderId (Mail de correction envoyé).";

            // Si c'est une requête AJAX
            if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
                // 1. On calcule le nouveau nombre
                $stmtCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0");
                $countUnpaid = $stmtCount->fetchColumn();

                // 2. On l'ajoute dans la réponse JSON
                echo json_encode([
                    'success' => true, 
                    'is_paid' => false,
                    'new_count' => $countUnpaid
                ]);
                exit;
            }
        }

        // --- E. UPDATE COMMANDE ---
        elseif ($_POST['action'] === 'update_order' && $orderId > 0) {
            // [LOG] 1. Capture de l'état AVANT modif
            $stateBefore = getOrderSnapshot($pdo, $orderId);

            $pdo->beginTransaction();
            $calculatedTotalPrice = 0.0;

            // 1. Update Acheteur
            $stmtGetUserId = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
            $stmtGetUserId->execute([$orderId]);
            $userId = $stmtGetUserId->fetchColumn();

            if ($userId) {
                $buyerClass = !empty($_POST['buyer_class_id']) ? $_POST['buyer_class_id'] : NULL;
                $stmtUserUpdate = $pdo->prepare("UPDATE users SET nom = ?, prenom = ?, class_id = ? WHERE user_id = ?");
                $stmtUserUpdate->execute([$_POST['buyer_nom'], $_POST['buyer_prenom'], $buyerClass, $userId]);
            }

            // 2. Update Destinataires
            if (isset($_POST['recipients']) && is_array($_POST['recipients'])) {
                foreach ($_POST['recipients'] as $orderRecipientId => $rData) {
                    $orderRecipientId = intval($orderRecipientId);
                    $studentId = intval($rData['student_id']);
                    
                    // Info Destinataire
                    $destClass = !empty($rData['class_id']) ? $rData['class_id'] : NULL;
                    $stmtStudent = $pdo->prepare("UPDATE recipients SET nom = ?, prenom = ?, class_id = ? WHERE id = ?");
                    $stmtStudent->execute([$rData['nom'], $rData['prenom'], $destClass, $studentId]);

                    // Options (Anonyme/Message)
                    $isAnon = isset($rData['is_anonymous']) ? 1 : 0;
                    $messageIdToSave = !empty($rData['message_id']) ? $rData['message_id'] : NULL;
                    $stmtGift = $pdo->prepare("UPDATE order_recipients SET is_anonymous = ?, message_id = ? WHERE id = ?");
                    $stmtGift->execute([$isAnon, $messageIdToSave, $orderRecipientId]);

                    // GESTION DES ROSES
                    $totalRosesForRecipient = 0;
                    if (isset($rData['roses']) && is_array($rData['roses'])) {
                        foreach ($rData['roses'] as $roseTypeId => $qty) {
                            $roseTypeId = intval($roseTypeId);
                            $qty = intval($qty);
                            if ($qty < 0) $qty = 0;

                            $stmtCheck = $pdo->prepare("SELECT id FROM recipient_roses WHERE recipient_id = ? AND rose_product_id = ?");
                            $stmtCheck->execute([$orderRecipientId, $roseTypeId]);
                            $existingLink = $stmtCheck->fetchColumn();

                            if ($existingLink) {
                                if ($qty > 0) {
                                    $pdo->prepare("UPDATE recipient_roses SET quantity = ? WHERE id = ?")->execute([$qty, $existingLink]);
                                    $totalRosesForRecipient += $qty;
                                } else {
                                    $pdo->prepare("DELETE FROM recipient_roses WHERE id = ?")->execute([$existingLink]);
                                }
                            } else {
                                if ($qty > 0) {
                                    $pdo->prepare("INSERT INTO recipient_roses (recipient_id, rose_product_id, quantity) VALUES (?, ?, ?)")
                                        ->execute([$orderRecipientId, $roseTypeId, $qty]);
                                    $totalRosesForRecipient += $qty;
                                }
                            }
                        }
                    }

                    // Calcul Prix
                    if ($totalRosesForRecipient > 0) {
                        if (isset($rosesPriceTable[$totalRosesForRecipient])) {
                            $calculatedTotalPrice += floatval($rosesPriceTable[$totalRosesForRecipient]);
                        } else {
                            $basePrice = $rosesPriceTable[$maxQtyDefined] ?? ($totalRosesForRecipient * 2); 
                            $calculatedTotalPrice += ($totalRosesForRecipient > $maxQtyDefined) ? ($totalRosesForRecipient * 2.00) : $basePrice;
                        }
                    }

                    // MISE À JOUR DE L'EMPLOI DU TEMPS
                    if (isset($rData['schedule']) && is_array($rData['schedule'])) {
                        $schedUpdates = [];
                        $schedValues = [];
                        foreach ($rData['schedule'] as $hour => $roomId) {
                            if ($hour >= 8 && $hour <= 17) {
                                $colName = 'h' . str_pad($hour, 2, '0', STR_PAD_LEFT);
                                $schedUpdates[] = "$colName = ?";
                                $schedValues[] = !empty($roomId) ? $roomId : null;
                            }
                        }
                        if (!empty($schedUpdates)) {
                            $sqlSched = "UPDATE schedules SET " . implode(', ', $schedUpdates) . " WHERE recipient_id = ?";
                            $schedValues[] = $studentId;
                            $pdo->prepare($sqlSched)->execute($schedValues);
                        }
                    }
                }
            }

            // Update Prix Total
            $stmtPrice = $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?");
            $stmtPrice->execute([$calculatedTotalPrice, $orderId]);

            // [LOG] 2. Capture de l'état APRÈS modif
            $stateAfter = getOrderSnapshot($pdo, $orderId);
            
            // [LOG] 3. Enregistrement (Uniquement si ça a changé)
            if ($stateBefore != $stateAfter) {
                logAction($_SESSION['user_id'], 'order', $orderId, 'ORDER_UPDATED', $stateBefore, $stateAfter, "Modification contenu commande");
            }

            $pdo->commit();
            $msgSuccess = "Commande #$orderId modifiée avec succès.";
        }

        if ($msgSuccess) {
            header("Location: manage_orders.php?msg_success=" . urlencode($msgSuccess));
        } else {
            header("Location: manage_orders.php");
        }
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: manage_orders.php?msg_error=" . urlencode("Erreur : " . $e->getMessage()));
        exit;
    }
}

// --- 2. STATS ---
$totalRevenue = $pdo->query("SELECT SUM(total_price) FROM orders WHERE is_paid = 1")->fetchColumn() ?: 0;
$totalRoses = $pdo->query("SELECT SUM(quantity) FROM recipient_roses")->fetchColumn() ?: 0;
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;
$countUnpaid = $pdo->query("SELECT COUNT(*) FROM orders WHERE is_paid = 0")->fetchColumn();
$percentPaid = ($totalOrders > 0) ? round((($totalOrders - $countUnpaid) / $totalOrders) * 100) : 0;

// --- 3. RECHERCHE ET AFFICHAGE ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "
    SELECT 
        o.id as order_id, o.created_at, o.total_price, o.is_paid, o.paid_at, 
        u.nom as buyer_nom, u.prenom as buyer_prenom, u.class_id as buyer_class_id, c_buy.name as buyer_class_name,
        ort.id as order_recipient_id, ort.is_anonymous, ort.message_id, ort.is_distributed, ort.distributed_at, ort.is_prepared, 
        r.id as student_id, r.nom as dest_nom, r.prenom as dest_prenom, r.class_id as dest_class_id,
        c_dest.name as dest_class_name, pm.content as message_content
    FROM orders o
    JOIN users u ON o.user_id = u.user_id 
    JOIN order_recipients ort ON o.id = ort.order_id
    JOIN recipients r ON ort.recipient_id = r.id 
    LEFT JOIN classes c_buy ON u.class_id = c_buy.id
    LEFT JOIN classes c_dest ON r.class_id = c_dest.id
    LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
";

$params = [];
if (!empty($search)) {
    $sql .= " WHERE 
        o.id LIKE :s OR u.nom LIKE :s OR u.prenom LIKE :s OR CONCAT(u.prenom, ' ', u.nom) LIKE :s OR 
        c_buy.name LIKE :s OR r.nom LIKE :s OR r.prenom LIKE :s OR CONCAT(r.prenom, ' ', r.nom) LIKE :s OR c_dest.name LIKE :s
    ";
    $params[':s'] = '%' . $search . '%';
}

$sql .= " ORDER BY o.is_paid ASC, o.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Erreur SQL : " . $e->getMessage()); }

// --- 4. REGROUPEMENT ---
$groupedOrders = [];
foreach ($raw_results as $row) {
    $orderId = $row['order_id'];

    if (!isset($groupedOrders[$orderId])) {
        $groupedOrders[$orderId] = [
            'info' => [
                'id' => $row['order_id'],
                'date' => $row['created_at'],
                'buyer_nom' => $row['buyer_nom'],
                'buyer_prenom' => $row['buyer_prenom'],
                'buyer_class_id' => $row['buyer_class_id'],
                'buyer_class_name' => $row['buyer_class_name'],
                'total_price' => $row['total_price'],
                'is_paid' => $row['is_paid'],
                'paid_at' => $row['paid_at']
            ],
            'recipients' => []
        ];
    }

    $stmtRoses = $pdo->prepare("SELECT rr.id as rose_link_id, rr.quantity, rr.rose_product_id, rp.name FROM recipient_roses rr JOIN rose_products rp ON rr.rose_product_id = rp.id WHERE rr.recipient_id = ?");
    $stmtRoses->execute([$row['order_recipient_id']]);
    $roses = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);

    $stmtSchedule = $pdo->prepare("SELECT h08, h09, h10, h11, h12, h13, h14, h15, h16, h17 FROM schedules WHERE recipient_id = ?");
    $stmtSchedule->execute([$row['student_id']]);
    $schedRow = $stmtSchedule->fetch(PDO::FETCH_ASSOC);

    $scheduleMap = [];
    $isAbsent = false; 

    if ($schedRow) {
        $countStage = 0;
        foreach ($schedRow as $col => $roomId) {
            if ($roomId == 180) $countStage++;
        }
        if ($countStage === 10) $isAbsent = true;

        foreach ($schedRow as $col => $roomId) {
            $hour = intval(substr($col, 1)); 
            if (!empty($roomId) && isset($allRooms[$roomId])) {
                $scheduleMap[$hour] = $allRooms[$roomId];
            } else {
                $scheduleMap[$hour] = '';
            }
        }
    }

    $groupedOrders[$orderId]['recipients'][] = [
        'order_recipient_id' => $row['order_recipient_id'],
        'student_id' => $row['student_id'],
        'nom' => $row['dest_nom'],
        'prenom' => $row['dest_prenom'],
        'class_id' => $row['dest_class_id'],
        'class_name' => $row['dest_class_name'],
        'is_anonymous' => $row['is_anonymous'],
        'message_id' => $row['message_id'],
        'message_content' => $row['message_content'],
        'is_prepared' => $row['is_prepared'],  
        'is_distributed' => $row['is_distributed'],
        'distributed_at' => $row['distributed_at'],
        'roses' => $roses,
        'schedule_map' => $scheduleMap,
        'is_absent' => $isAbsent
    ];
}

// =================================================================
// FONCTION UTILITAIRE POUR LES LOGS (SNAPSHOT)
// =================================================================
function getOrderSnapshot($pdo, $orderId) {
    // Infos Acheteur
    $stmt = $pdo->prepare("SELECT u.nom, u.prenom, u.class_id, u.email FROM orders o JOIN users u ON o.user_id = u.user_id WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $buyer = $stmt->fetch(PDO::FETCH_ASSOC);

    // Infos Destinataires et Roses
    $recipients = [];
    $sql = "
        SELECT 
            ort.id as link_id,          -- ID unique de la ligne destinataire dans la commande
            r.id as student_id,
            r.nom, 
            r.prenom, 
            r.class_id,
            ort.is_anonymous,           -- Info Anonyme
            pm.content as message_text  -- Info Message
        FROM order_recipients ort
        JOIN recipients r ON ort.recipient_id = r.id
        LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
        WHERE ort.order_id = ?
    ";
    $stmtRec = $pdo->prepare($sql);
    $stmtRec->execute([$orderId]);
    
    while ($row = $stmtRec->fetch(PDO::FETCH_ASSOC)) {
        // On utilise link_id (l'ID dans order_recipients) car c'est lui qui est lié aux roses
        $stmtRoses = $pdo->prepare("SELECT rose_product_id, quantity FROM recipient_roses WHERE recipient_id = ?");
        $stmtRoses->execute([$row['link_id']]);
        $roses = $stmtRoses->fetchAll(PDO::FETCH_ASSOC);
        
        // On nettoie un peu le tableau pour le log
        $row['roses'] = $roses;
        unset($row['link_id']); // On enlève l'ID technique, pas utile pour la lecture du log
        
        $recipients[] = $row;
    }

    return ['buyer' => $buyer, 'recipients' => $recipients];
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Admin - Saint Valentin</title>
    <?php include 'head_imports.php'; ?>
    <style>
        .card-stat { border:none; border-radius: 15px; color: white; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .bg-money { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .bg-roses { background: linear-gradient(135deg, #ff512f, #dd2476); }
        .bg-orders { background: linear-gradient(135deg, #4568DC, #B06AB3); }
        .table-admin th { font-size: 0.85rem; text-transform: uppercase; background-color: #f8f9fa; }
        .recipient-row { border-bottom: 1px dashed #dee2e6; padding: 10px 0; }
        .recipient-row:last-child { border-bottom: none; }
        .pulse-button { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); } 70% { transform: scale(1.02); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); } }
        @media (max-width: 768px) {
            .table-admin th:nth-child(1), .table-admin td:nth-child(1) { width: 50% !important; }
            .table-admin th:nth-child(2), .table-admin td:nth-child(2) { width: 50% !important; }
            .modal-xl { margin: 10px; }
            .border-end-md { border-right: none !important; border-bottom: 1px solid #dee2e6; padding-bottom: 1rem; margin-bottom: 1rem; }
        }
        @media (min-width: 768px) { .border-end-md { border-right: 1px solid #dee2e6 !important; } }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>
<?php include 'toast_notifications.php'; ?>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-primary mb-0">Tableau de bord CVL <i class="fas fa-clipboard-list ms-2"></i></h2>
            <span class="text-muted small">Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['prenom'] ?? 'Admin'); ?></strong></span>
        </div>
        <div class="d-flex gap-2">
            <a href="stats.php" class="btn btn-outline-primary shadow-sm"><i class="fas fa-chart-pie me-2"></i>Stats</a>
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="admin.php" class="btn btn-dark shadow-sm"><i class="fas fa-cogs"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: white;">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1" style="font-size: 0.8rem;">Cagnotte Totale</h6>
                        <h2 class="fw-bold mb-0"><?php echo number_format($totalRevenue, 2); ?> €</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;"><i class="fas fa-euro-sign fa-2x"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-white">
                <div class="card-body p-3 border-start border-5 border-<?php echo ($countUnpaid > 0) ? 'warning' : 'success'; ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1" style="font-size: 0.8rem;">En attente paiement</h6>
                            <h2 class="fw-bold mb-0 text-dark">
                                <span id="unpaid-counter"><?php echo $countUnpaid; ?></span> 
                                <small class="text-muted fs-6">commandes</small>
                            </h2>
                        </div>
                        <div class="text-<?php echo ($countUnpaid > 0) ? 'warning' : 'success'; ?>"><i class="fas fa-exclamation-circle fa-2x"></i></div>
                    </div>
                    <div class="progress" style="height: 4px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $percentPaid; ?>%"></div>
                        <div class="progress-bar bg-warning" style="width: <?php echo 100 - $percentPaid; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%); color: white;">
                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1" style="font-size: 0.8rem;">Volume Roses</h6>
                        <h2 class="fw-bold mb-0"><?php echo $totalRoses; ?> 🌹</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;"><i class="fas fa-box-open fa-2x"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 mb-4">
        <a href="preparation.php" class="btn btn-lg btn-warning fw-bold shadow-sm flex-grow-1"><i class="fas fa-boxes"></i> Mode Préparation</a>
        <a href="delivery.php" class="btn btn-lg btn-success fw-bold shadow-sm flex-grow-1"><i class="fas fa-truck"></i> Mode Distribution</a>
    </div>

    <div class="card shadow border-0 rounded-3">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <h5 class="mb-0 fw-bold text-primary">📦 Commandes & Livraisons</h5>
                <?php if(!empty($search)): ?><span class="badge bg-warning text-dark"><?php echo count($groupedOrders); ?> résultat(s)</span><?php endif; ?>
            </div>
            <form method="GET" action="manage_orders.php" class="d-flex mt-2 mt-md-0" style="max-width: 350px;">
                <div class="input-group">
                    <input type="text" name="q" class="form-control rounded-start-pill border-end-0 bg-light" placeholder="Rechercher..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if(!empty($search)): ?><a href="manage_orders.php" class="btn btn-light border border-start-0 border-end-0 text-danger"><i class="fas fa-times"></i></a><?php endif; ?>
                    <button class="btn btn-primary rounded-end-pill px-3" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>

        <div class="card-body p-3 bg-light">
            <?php if (empty($groupedOrders)): ?>
                <div class="text-center p-5 text-muted bg-white rounded shadow-sm"><i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>Aucune commande trouvée.</div>
            <?php else: ?>
                <div class="d-flex flex-column gap-4">
                    <?php foreach($groupedOrders as $order): ?>
                        
                        <div class="card border-0 shadow-sm overflow-hidden">
                            <div class="row g-0 align-items-stretch">
                                <div class="col-lg-3 col-12 bg-light border-end d-flex flex-column">
                                    <div id="order-header-<?php echo $order['info']['id']; ?>" class="p-3 <?php echo $order['info']['is_paid'] ? 'bg-success text-white' : 'bg-warning text-dark'; ?> bg-opacity-10 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge bg-dark">#<?php echo str_pad($order['info']['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                            <small class="fw-bold"><?php echo date('d/m H:i', strtotime($order['info']['date'])); ?></small>
                                        </div>
                                    </div>
                                    <div class="p-3 flex-grow-1">
                                        <div class="mb-3">
                                            <label class="small text-muted text-uppercase fw-bold">Acheteur</label>
                                            <div class="fs-5 fw-bold text-dark lh-sm mb-1"><?php echo htmlspecialchars($order['info']['buyer_prenom'] . ' ' . $order['info']['buyer_nom']); ?></div>
                                            <span class="badge bg-white text-dark border shadow-sm"><?php echo htmlspecialchars($order['info']['buyer_class_name'] ?? '?'); ?></span>
                                        </div>
                                        <div class="card bg-white border mb-3">
                                            <div class="card-body p-2 text-center">
                                                <div class="small text-muted mb-1">Total à payer</div>
                                                <div class="fs-4 fw-bold text-primary mb-2"><?php echo number_format($order['info']['total_price'], 2); ?> €</div>

                                                <div id="payment-box-<?php echo $order['info']['id']; ?>">
                                                    <?php if($order['info']['is_paid']): ?>
                                                        <div class="alert alert-success py-1 px-2 mb-2 small d-flex align-items-center justify-content-center">
                                                            <i class="fas fa-check-circle me-1"></i> 
                                                            <span>Payé le <?php echo date('d/m H:i', strtotime($order['info']['paid_at'])); ?></span>
                                                        </div>
                                                        <form method="POST" class="ajax-form" onsubmit="return confirm('⚠️ Attention : Annuler le paiement ?');">
                                                            <input type="hidden" name="action" value="cancel_payment">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm w-100" style="font-size: 0.8rem;">
                                                                Annuler paiement
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" class="ajax-form">
                                                            <input type="hidden" name="action" value="validate_payment">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">
                                                            <button type="submit" class="btn btn-danger w-100 fw-bold shadow-sm pulse-button">
                                                                <i class="fas fa-hand-holding-dollar me-1"></i> Encaisser
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn btn-outline-dark btn-sm w-100" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $order['info']['id']; ?>">
                                            <i class="fas fa-pencil-alt me-1"></i> Modifier commande
                                        </button>
                                    </div>
                                </div>

                                <div class="col-lg-9 col-12 bg-white d-flex flex-column justify-content-center">
                                    <?php 
                                    $countRecipients = count($order['recipients']);
                                    foreach($order['recipients'] as $index => $dest): 
                                        $borderClass = ($index < $countRecipients - 1) ? 'border-bottom' : '';
                                    ?>
                                    <div class="p-3 <?php echo $borderClass; ?>">
                                        <div class="row g-3">
                                            <div class="col-md-4 border-end-md d-flex flex-column justify-content-center">
                                                <div class="d-flex align-items-center justify-content-between mb-1">
                                                    <span class="fw-bold text-primary fs-5"><?php echo htmlspecialchars($dest['prenom'] . ' ' . $dest['nom']); ?></span>
                                                    <?php if($dest['is_anonymous']): ?><span class="badge bg-dark" title="Anonyme"><i class="fas fa-user-secret"></i></span><?php endif; ?>
                                                </div>
                                                <div class="mb-3"><span class="badge bg-info text-dark"><i class="fas fa-graduation-cap me-1"></i><?php echo htmlspecialchars($dest['class_name'] ?? '?'); ?></span></div>
                                                <div id="status-label-<?php echo $order['info']['id']; ?>">
                                                    <?php 
                                                    if (!$order['info']['is_paid']) {
                                                        echo '<div class="alert alert-danger py-1 px-2 mb-0 small"><i class="fas fa-hand-holding-usd me-2"></i> À payer</div>';
                                                    } elseif ($dest['is_distributed']) {
                                                        // Note : Si tu es dans une boucle de destinataires, attention, ce code ne mettra à jour que le statut global
                                                        echo '<div class="alert alert-success py-1 px-2 mb-0 small"><i class="fas fa-check-circle me-2"></i> Distribué</div>';
                                                    } elseif ($dest['is_prepared']) {
                                                        echo '<div class="alert alert-info py-1 px-2 mb-0 small text-dark"><i class="fas fa-truck me-2"></i> Prêt</div>';
                                                    } else {
                                                        echo '<div class="alert alert-warning py-1 px-2 mb-0 small text-dark"><i class="fas fa-box-open me-2"></i> À préparer</div>';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4 border-end-md d-flex flex-column justify-content-center">
                                                <div class="d-flex flex-column gap-2 mb-2">
                                                    <?php foreach($dest['roses'] as $rose): 
                                                        $colorName = mb_strtolower($rose['name']);
                                                        $badgeClass = "bg-secondary text-white"; $emoji = "🌹"; $customStyle = ""; 
                                                        if (strpos($colorName, 'rouge') !== false) { $badgeClass = "bg-danger text-white"; }
                                                        elseif (strpos($colorName, 'blanche') !== false) { $badgeClass = "bg-white text-dark border"; $emoji = "🤍"; }
                                                        elseif (strpos($colorName, 'rose') !== false) { $badgeClass = "text-dark border"; $customStyle = "background-color: #ffc0cb; color: #880e4f; border-color: #ffb6c1;"; $emoji = "🌸"; }
                                                    ?>
                                                    <div class="d-flex align-items-center justify-content-between p-1 border rounded bg-light">
                                                        <span class="badge rounded-pill <?php echo $badgeClass; ?> me-2" style="<?php echo $customStyle; ?>"><?php echo $rose['quantity']; ?> <?php echo $emoji; ?></span>
                                                        <span class="small fw-bold text-muted flex-grow-1"><?php echo htmlspecialchars($rose['name']); ?></span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if($dest['message_content']): ?>
                                                    <div class="bg-light p-2 rounded small text-muted fst-italic border-start border-3 border-secondary">"<?php echo htmlspecialchars($dest['message_content']); ?>"</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4 d-flex flex-column justify-content-center">
                                                <strong class="small text-uppercase text-muted d-block mb-2">📍 Localisation</strong>
                                                <?php 
                                                if (isset($dest['is_absent']) && $dest['is_absent']) {
                                                    echo "<div class='badge bg-info text-dark w-100 py-2'><i class='fas fa-user-slash me-1'></i> Absent(e)</div>";
                                                } else {
                                                    $hasSchedule = false;
                                                    echo '<div class="d-flex flex-wrap gap-1">';
                                                    ksort($dest['schedule_map']);
                                                    foreach($dest['schedule_map'] as $hour => $roomName) {
                                                        if(!empty($roomName)) {
                                                            echo '<span class="badge bg-light text-dark border fw-normal"><b>'.$hour.'h</b> '.htmlspecialchars($roomName).'</span>';
                                                            $hasSchedule = true;
                                                        }
                                                    }
                                                    echo '</div>';
                                                    if(!$hasSchedule) echo "<span class='text-muted small fst-italic'>Aucune salle.</span>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="editModal<?php echo $order['info']['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl"> 
                                <div class="modal-content">
                                    <div class="modal-header bg-dark text-white">
                                        <h5 class="modal-title"><i class="fas fa-edit"></i> Éditer commande #<?php echo $order['info']['id']; ?></h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="update_order">
                                            <input type="hidden" name="order_id" value="<?php echo $order['info']['id']; ?>">
                                            <input type="hidden" name="target_recipient_id" id="target_recipient_<?php echo $order['info']['id']; ?>" value="">

                                            <h6 class="text-primary fw-bold border-bottom pb-2">👤 Acheteur</h6>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Prénom</label>
                                                    <input type="text" name="buyer_prenom" class="form-control" value="<?php echo htmlspecialchars($order['info']['buyer_prenom']); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Nom</label>
                                                    <input type="text" name="buyer_nom" class="form-control" value="<?php echo htmlspecialchars($order['info']['buyer_nom']); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Classe</label>
                                                    <select name="buyer_class_id" class="form-select">
                                                        <option value="">-- Choisir --</option>
                                                        <?php foreach($allClasses as $id => $name): ?>
                                                            <option value="<?php echo $id; ?>" <?php if($order['info']['buyer_class_id'] == $id) echo 'selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <?php foreach($order['recipients'] as $index => $dest): ?>
                                                <div class="card mb-3 bg-light border-0">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                                            <h6 class="text-danger fw-bold mb-0">❤️ Destinataire <?php echo $index + 1; ?></h6>
                                                            <button type="submit" name="action" value="delete_recipient" onclick="document.getElementById('target_recipient_<?php echo $order['info']['id']; ?>').value='<?php echo $dest['order_recipient_id']; ?>'; return confirm('Supprimer ce destinataire ?');" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                        </div>
                                                        <input type="hidden" name="recipients[<?php echo $dest['order_recipient_id']; ?>][student_id]" value="<?php echo $dest['student_id']; ?>">

                                                        <div class="row g-2 mb-3">
                                                            <div class="col-md-3"><label class="small">Prénom</label><input type="text" name="recipients[<?php echo $dest['order_recipient_id']; ?>][prenom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['prenom']); ?>"></div>
                                                            <div class="col-md-3"><label class="small">Nom</label><input type="text" name="recipients[<?php echo $dest['order_recipient_id']; ?>][nom]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dest['nom']); ?>"></div>
                                                            <div class="col-md-3"><label class="small">Classe</label><select name="recipients[<?php echo $dest['order_recipient_id']; ?>][class_id]" class="form-select form-select-sm"><?php foreach($allClasses as $id => $name): ?><option value="<?php echo $id; ?>" <?php if($dest['class_id'] == $id) echo 'selected'; ?>><?php echo htmlspecialchars($name); ?></option><?php endforeach; ?></select></div>
                                                            <div class="col-md-3 pt-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="recipients[<?php echo $dest['order_recipient_id']; ?>][is_anonymous]" value="1" <?php if($dest['is_anonymous']) echo 'checked'; ?>><label class="small">Anonyme</label></div></div>
                                                        </div>

                                                        <div class="row g-2 mb-3">
                                                            <div class="col-md-6">
                                                                <label class="small fw-bold">🌹 Roses</label>
                                                                <div class="row g-1">
                                                                    <?php 
                                                                    // Mapping des roses possédées
                                                                    $existingRoses = [];
                                                                    foreach($dest['roses'] as $r) {
                                                                        // Utilisez rose_product_id qui vient de la DB
                                                                        $pid = $r['rose_product_id']; 
                                                                        $existingRoses[$pid] = $r['quantity'];
                                                                    }
                                                                    // Boucle sur TOUS les types
                                                                    if(isset($allRoseTypes) && is_array($allRoseTypes)):
                                                                        foreach($allRoseTypes as $typeId => $typeName): 
                                                                            $qty = $existingRoses[$typeId] ?? 0;
                                                                    ?>
                                                                        <div class="col-4">
                                                                            <label class="x-small text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($typeName); ?></label>
                                                                            <input type="number" name="recipients[<?php echo $dest['order_recipient_id']; ?>][roses][<?php echo $typeId; ?>]" class="form-control form-control-sm" value="<?php echo $qty; ?>" min="0">
                                                                        </div>
                                                                    <?php endforeach; endif; ?>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="small fw-bold">Message</label>
                                                                <select name="recipients[<?php echo $dest['order_recipient_id']; ?>][message_id]" class="form-select form-select-sm">
                                                                    <option value="">-- Aucun --</option>
                                                                    <?php foreach($allMessages as $msgId => $msgContent): ?>
                                                                        <option value="<?php echo $msgId; ?>" <?php if($dest['message_id'] == $msgId) echo 'selected'; ?>><?php echo htmlspecialchars($msgContent); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div class="border p-2 bg-white rounded">
                                                            <label class="small fw-bold mb-1 d-block">📅 Planning (8h-17h)</label>
                                                            <div class="row g-1">
                                                                <?php for ($h = 8; $h <= 17; $h++): 
                                                                    $currentRoomName = $dest['schedule_map'][$h] ?? ''; ?>
                                                                    <div class="col-md-2 col-4">
                                                                        <label class="x-small text-muted" style="font-size: 0.65rem;"><?php echo $h; ?>h</label>
                                                                        <select name="recipients[<?php echo $dest['order_recipient_id']; ?>][schedule][<?php echo $h; ?>]" class="form-select form-select-sm" style="font-size: 0.7rem;">
                                                                            <option value="">-</option>
                                                                            <?php foreach($allRooms as $rId => $rName): ?>
                                                                                <option value="<?php echo $rId; ?>" <?php if($currentRoomName === $rName) echo 'selected'; ?>><?php echo htmlspecialchars($rName); ?></option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                <?php endfor; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" name="action" value="delete_order" class="btn btn-danger me-auto" onclick="return confirm('Supprimer toute la commande ?');">Supprimer</button>
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                            <button type="submit" name="action" value="update_order" class="btn btn-primary">Enregistrer</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
    <div id="paymentToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="false">
        <div class="toast-header bg-success text-white">
            <i class="fas fa-envelope me-2"></i>
            <strong class="me-auto">Validation en cours</strong>
            <small class="text-white-50">Juste un instant...</small>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body bg-white">
            <p class="mb-2 text-dark">L'e-mail de confirmation sera envoyé dans <strong id="toastCountdown" class="text-primary fs-5">5</strong> secondes.</p>
            <div class="progress mb-3" style="height: 5px;">
                <div id="toastProgressBar" class="progress-bar bg-success" role="progressbar" style="width: 100%; transition: none;"></div>
            </div>
            <button type="button" id="toastCancelBtn" class="btn btn-outline-danger btn-sm w-100 fw-bold">
                <i class="fas fa-undo me-1"></i> ANNULER L'ENVOI
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- GESTION DU COMPTE À REBOURS ET ENVOI MAIL ---
    let timer = null;
    let seconds = 5;
    let pendingFormData = null;

    // Récupération des éléments du Toast
    const toastEl = document.getElementById('paymentToast');
    const toast = new bootstrap.Toast(toastEl);
    const progressBar = document.getElementById('toastProgressBar');
    const countdownSpan = document.getElementById('toastCountdown');
    const cancelBtn = document.getElementById('toastCancelBtn');

    // Fonction pour envoyer la requête AJAX
    function runAjax(formData) {
        const orderId = formData.get('order_id');
        
        // 1. Les éléments visuels de la carte
        const headerEl = document.getElementById('order-header-' + orderId);
        const boxEl = document.getElementById('payment-box-' + orderId);
        const statusLabelEl = document.getElementById('status-label-' + orderId); // L'étiquette qu'on vient de créer
        
        // 2. Le compteur global en haut de page
        const totalBadgeEl = document.getElementById('unpaid-counter');

        fetch('manage_orders', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                
                // --- A. MISE À JOUR DU COMPTEUR GLOBAL ---
                if (data.new_count !== undefined && totalBadgeEl) {
                    totalBadgeEl.innerText = data.new_count;
                    // Animation simple (optionnel)
                    totalBadgeEl.classList.add('pulse-anim'); // Si tu as une anim CSS
                }

                // --- B. MISE À JOUR DE LA CARTE ---
                if (data.is_paid) {
                    // 1. Header Vert
                    headerEl.classList.remove('bg-warning', 'text-dark');
                    headerEl.classList.add('bg-success', 'text-white');
                    
                    // 2. Étiquette : Devient "À préparer" (On suppose qu'après paiement, c'est l'étape suivante)
                    if (statusLabelEl) {
                        statusLabelEl.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 small text-dark"><i class="fas fa-box-open me-2"></i> À préparer</div>';
                    }

                    // 3. Boite de boutons
                    boxEl.innerHTML = `
                        <div class="alert alert-success py-1 px-2 mb-2 small d-flex align-items-center justify-content-center">
                            <i class="fas fa-check-circle me-1"></i> 
                            <span>Payé le ${data.date}</span>
                        </div>
                        <form method="POST" class="ajax-form" onsubmit="return confirm('⚠️ Annuler le paiement ?');">
                            <input type="hidden" name="action" value="cancel_payment">
                            <input type="hidden" name="order_id" value="${orderId}">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100" style="font-size: 0.8rem;">Annuler</button>
                        </form>
                    `;
                } else {
                    // 1. Header Jaune
                    headerEl.classList.remove('bg-success', 'text-white');
                    headerEl.classList.add('bg-warning', 'text-dark');

                    // 2. Étiquette : Redevient "À payer"
                    if (statusLabelEl) {
                        statusLabelEl.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small"><i class="fas fa-hand-holding-usd me-2"></i> À payer</div>';
                    }

                    // 3. Boite de boutons
                    boxEl.innerHTML = `
                        <form method="POST" class="ajax-form">
                            <input type="hidden" name="action" value="validate_payment">
                            <input type="hidden" name="order_id" value="${orderId}">
                            <button type="submit" class="btn btn-danger w-100 fw-bold shadow-sm">
                                <i class="fas fa-hand-holding-dollar me-1"></i> Encaisser
                            </button>
                        </form>
                    `;
                }
            }
        })
        .catch(error => console.error('Erreur:', error));
    }

    // Écouteur global sur les formulaires
    document.body.addEventListener('submit', function(e) {
        if (e.target.classList.contains('ajax-form')) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('ajax', '1'); // Marqueur Ajax
            
            const action = formData.get('action');

            if (action === 'validate_payment') {
                // --- CAS SPÉCIAL : VALIDATION PAIEMENT ---
                // On stocke les données et on lance le timer
                pendingFormData = formData;
                
                // Reset de l'interface du Toast
                seconds = 5;
                countdownSpan.innerText = seconds;
                progressBar.style.width = '100%';
                progressBar.style.transition = 'none'; // Pas d'anim pour le reset
                
                toast.show();
                
                // Petit délai pour réactiver l'animation CSS de la barre
                setTimeout(() => {
                    progressBar.style.transition = 'width 5s linear';
                    progressBar.style.width = '0%';
                }, 10);

                // Démarrage du compte à rebours
                if (timer) clearInterval(timer);
                timer = setInterval(() => {
                    seconds--;
                    countdownSpan.innerText = seconds;
                    
                    if (seconds <= 0) {
                        // FIN DU TIMER : On valide
                        clearInterval(timer);
                        toast.hide();
                        runAjax(pendingFormData);
                    }
                }, 1000);

            } else {
                // --- AUTRES CAS (ex: Annuler paiement) ---
                // Exécution immédiate
                runAjax(formData);
            }
        }
    });

    // Bouton ANNULER dans le Toast
    cancelBtn.addEventListener('click', function() {
        clearInterval(timer); // Stoppe le timer
        toast.hide();         // Cache la notif
        pendingFormData = null; // Oublie la requête
    });
});
</script>
</body>
</html>