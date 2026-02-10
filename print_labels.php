<?php
// print_labels.php
require('fpdf.php');
require_once 'db.php';
require_once 'auth_check.php';
checkAccess('cvl');

// Fonction pour nettoyer les émojis et caractères non supportés par FPDF
function cleanText($text) {
    return @iconv('UTF-8', 'windows-1252//TRANSLIT', $text) ?: ''; 
}

// --- 1. RÉCUPÉRATION DES DONNÉES ---
$levelFilter = isset($_GET['level']) ? $_GET['level'] : 'all';

$sql = "
    SELECT 
        ort.id as unique_gift_id, 
        r.nom as dest_nom, 
        r.prenom as dest_prenom, 
        ort.is_anonymous, 
        pm.content as message_content,
        c.name as class_name,
        cl.group_alias, 
        u.prenom as buyer_prenom, 
        u.nom as buyer_nom
    FROM order_recipients ort
    JOIN recipients r ON ort.recipient_id = r.id
    JOIN orders o ON ort.order_id = o.id
    JOIN users u ON o.user_id = u.user_id
    LEFT JOIN classes c ON r.class_id = c.id
    LEFT JOIN class_levels cl ON c.level_id = cl.id
    LEFT JOIN predefined_messages pm ON ort.message_id = pm.id
    WHERE o.is_paid = 1 
    AND ort.is_prepared = 0 
";

$params = [];
if ($levelFilter !== 'all') {
    $allowedFilters = ['2nde', '1ere', 'term', 'autre'];
    if (in_array($levelFilter, $allowedFilters)) {
        $sql .= " AND cl.group_alias = :filter ";
        $params['filter'] = $levelFilter;
    }
}

$sql .= " ORDER BY c.name ASC, r.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$labels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. CONFIGURATION PDF ---
if (count($labels) === 0) {
    die("Aucune etiquette a imprimer pour le filtre selectionne.");
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false); 
$pdf->AddPage();

// Paramètres de la grille
$startX = 10; 
$startY = 10; 
$colWidth = 63; 
$rowHeight = 38; 
$colsPerPage = 3;
$rowsPerPage = 7;

$col = 0;
$row = 0;

// Marge interne (padding) pour éviter que le texte touche le bord du cadre
$padding = 2; 
$maxWidth = $colWidth - ($padding * 2);

foreach ($labels as $label) {
    
    // Coordonnées de base
    $currentX = $startX + ($col * $colWidth);
    $currentY = $startY + ($row * $rowHeight);
    
    // Positionnement initial
    $pdf->SetXY($currentX, $currentY);
    
    // Cadre
    $pdf->Rect($currentX, $currentY, $colWidth, $rowHeight);
    
    // --- 1. ID (Haut Droite) ---
    $pdf->SetXY($currentX + $padding, $currentY + 1);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(150);
    $pdf->Cell($maxWidth, 4, '#' . $label['unique_gift_id'], 0, 1, 'R');
    
    // --- 2. DESTINATAIRE (AUTO-SIZE) ---
    // On prépare le texte complet
    $fullDestName = cleanText("Pour : " . strtoupper($label['dest_nom']) . ' ' . $label['dest_prenom']);
    
    // On commence avec une police taille 10
    $fontSize = 10;
    $pdf->SetFont('Arial', 'B', $fontSize);
    $pdf->SetTextColor(0);
    
    // TANT QUE le texte est plus large que la case ET que la police est > 6
    while ($pdf->GetStringWidth($fullDestName) > $maxWidth && $fontSize > 6) {
        $fontSize -= 0.5; // On réduit la police de 0.5
        $pdf->SetFontSize($fontSize);
    }
    
    $pdf->SetXY($currentX + $padding, $currentY + 5);
    // On affiche (si ça dépasse encore malgré la taille 6, FPDF coupera visuellement mais c'est rare)
    $pdf->Cell($maxWidth, 5, $fullDestName, 0, 1, 'L');
    
    // --- 3. CLASSE (AUTO-SIZE) ---
    $fullClassName = cleanText("Classe : " . ($label['class_name'] ?? 'Inconnue'));
    
    $fontSize = 9;
    $pdf->SetFont('Arial', 'I', $fontSize);
    
    while ($pdf->GetStringWidth($fullClassName) > $maxWidth && $fontSize > 6) {
        $fontSize -= 0.5;
        $pdf->SetFontSize($fontSize);
    }
    
    $pdf->SetXY($currentX + $padding, $currentY + 10);
    $pdf->Cell($maxWidth, 4, $fullClassName, 0, 1, 'L');
    
    // --- 4. SÉPARATEUR ---
    $pdf->Line($currentX, $currentY + 15, $currentX + $colWidth, $currentY + 15);
    
    // --- 5. MESSAGE / EXPÉDITEUR ---
    $pdf->SetXY($currentX + $padding, $currentY + 17);
    $pdf->SetFont('Arial', '', 8); // Police standard pour le corps
    
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
    
    // MultiCell avec largeur sécurisée
    // On force la largeur à $maxWidth pour ne pas sortir à droite
    $pdf->MultiCell($maxWidth, 3.5, cleanText($text), 0, 'L');

    // -- GESTION PAGINATION --
    $col++;
    if ($col >= $colsPerPage) {
        $col = 0;
        $row++;
    }
    if ($row >= $rowsPerPage) {
        $pdf->AddPage();
        $col = 0;
        $row = 0;
    }
}

$pdf->Output('I', 'Etiquettes_St_Valentin.pdf');
?>