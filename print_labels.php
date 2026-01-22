<?php
// print_labels.php
require('fpdf.php');
require_once 'db.php';
require_once 'auth_check.php';
checkAccess('cvl');

// Fonction pour nettoyer les émojis et caractères non supportés par FPDF
function cleanText($text) {
    // Convertir en Windows-1252 (standard FPDF) et supprimer ce qui ne passe pas
    // Si iconv échoue, on retourne le texte brut (ou vide) pour éviter une erreur fatale
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
    return $converted ?: ''; 
}

// --- 1. RÉCUPÉRATION DES DONNÉES ---
$levelFilter = isset($_GET['level']) ? $_GET['level'] : 'all';

// Alias utilisés :
// ort = order_recipients (Le "colis" / L'étiquette)
// r   = recipients (L'élève destinataire)
// o   = orders
// c   = classes
// cl  = class_levels

$sql = "
    SELECT 
        ort.id as unique_gift_id, 
        r.nom as dest_nom, 
        r.prenom as dest_prenom, 
        ort.is_anonymous, 
        pm.content as message_content,
        c.name as class_name,
        cl.group_alias, 
        u.prenom as buyer_prenom, -- On récupère le prénom depuis la table users
        u.nom as buyer_nom        -- On récupère le nom depuis la table users
    FROM order_recipients ort
    JOIN recipients r ON ort.recipient_id = r.id
    JOIN orders o ON ort.order_id = o.id
    JOIN users u ON o.user_id = u.user_id  -- <-- C'est ici que l'on fait le lien !
    LEFT JOIN classes c ON r.class_id = c.id
    LEFT JOIN class_levels cl ON c.level_id = cl.id
    LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
    WHERE o.is_paid = 1 
    AND ort.is_prepared = 0 
";

// Filtrage
$params = [];
if ($levelFilter !== 'all') {
    $allowedFilters = ['2nde', '1ere', 'term', 'autre'];
    if (in_array($levelFilter, $allowedFilters)) {
        $sql .= " AND cl.group_alias = :filter ";
        $params['filter'] = $levelFilter;
    }
}

// Tri par Classe puis par Nom
$sql .= " ORDER BY c.name ASC, r.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$labels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. CONFIGURATION PDF ---
// Si aucune étiquette, on évite de générer un PDF vide qui ferait erreur
if (count($labels) === 0) {
    die("Aucune étiquette à imprimer pour le filtre sélectionné.");
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false); // Important : on gère les sauts de page manuellement
$pdf->AddPage();

// Paramètres de la grille (A4 = 210mm x 297mm)
$startX = 10; // Marge Gauche
$startY = 10; // Marge Haute
$colWidth = 63; // Largeur étiquette (63 * 3 = 189mm)
$rowHeight = 38; // Hauteur étiquette
$colsPerPage = 3;
$rowsPerPage = 7;

$col = 0;
$row = 0;

foreach ($labels as $label) {
    
    // Calcul précis des coordonnées X et Y pour cette étiquette
    $currentX = $startX + ($col * $colWidth);
    $currentY = $startY + ($row * $rowHeight);
    
    // Placer le curseur au début de la case
    $pdf->SetXY($currentX, $currentY);
    
    // DESSIN DU CADRE (Aide à la découpe)
    $pdf->Rect($currentX, $currentY, $colWidth, $rowHeight);
    
    // -- CONTENU DE L'ÉTIQUETTE --
    
    // 1. ID (Haut Droite) -> Utilisation de unique_gift_id
    $pdf->SetXY($currentX, $currentY + 1); // Petit décalage interne
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(150);
    $pdf->Cell($colWidth - 2, 4, '#' . $label['unique_gift_id'], 0, 1, 'R');
    
    // 2. Destinataire (Gras)
    $pdf->SetXY($currentX + 1, $currentY + 5);
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', 10);
    $destName = mb_strimwidth(strtoupper($label['dest_nom']) . ' ' . $label['dest_prenom'], 0, 30, "...");
    $pdf->Cell($colWidth - 2, 5, cleanText("Pour : " . $destName), 0, 1, 'L');
    
    // 3. Classe
    $pdf->SetXY($currentX + 1, $currentY + 10);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell($colWidth - 2, 4, cleanText("Classe : " . ($label['class_name'] ?? 'Inconnue')), 0, 1, 'L');
    
    // 4. Ligne de séparation
    $pdf->Line($currentX, $currentY + 15, $currentX + $colWidth, $currentY + 15);
    
    // 5. Message / Expéditeur
    $pdf->SetXY($currentX + 1, $currentY + 17);
    $pdf->SetFont('Arial', '', 8); // Police un peu plus petite pour le message
    
    $senderName = $label['buyer_prenom'] . ' ' . $label['buyer_nom'];
    $text = "";
    
    if ($label['is_anonymous']) {
        if ($label['message_content']) {
            $text = "Message : \"" . $label['message_content'] . "\"";
        } else {
            $text = "Un admirateur secret vous a envoye une rose.";
        }
    } else {
        $text = "De la part de : " . $senderName . ".";
        if ($label['message_content']) {
            $text .= "\nMessage : \"" . $label['message_content'] . "\"";
        }
    }
    
    // MultiCell gère les retours à la ligne
    $pdf->MultiCell($colWidth - 3, 3.5, cleanText($text), 0, 'L');

    // -- GESTION DE LA GRILLE --
    $col++;
    
    // Si on arrive au bout de la ligne (3 colonnes)
    if ($col >= $colsPerPage) {
        $col = 0;
        $row++;
    }
    
    // Si on arrive au bas de la page (7 lignes)
    if ($row >= $rowsPerPage) {
        $pdf->AddPage();
        $col = 0;
        $row = 0;
    }
}

$pdf->Output('I', 'Etiquettes_St_Valentin.pdf');
?>