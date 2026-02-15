<?php
/**
 * Générateur d'Étiquettes PDF
 * print_labels.php
 * Ce fichier génère une grille d'étiquettes (format A4) pour l'impression physique.
 * Il gère :
 * 1. La récupération des commandes payées mais non préparées.
 * 2. Le nettoyage des données (Encodage pour FPDF qui ne gère pas l'UTF-8 natif).
 * 3. La mise en page dynamique (Calcul de grille X/Y).
 * 4. L'ajustement automatique de la taille de police pour les noms longs.
 */

require('fpdf.php'); // Assurez-vous que le fichier fpdf.php est à la racine ou ajustez le chemin
require_once 'db.php';
require_once 'auth_check.php';
checkAccess('cvl');

// ====================================================
// 1. FONCTIONS UTILITAIRES (Encodage & Nettoyage)
// ====================================================

/**
 * Convertit l'UTF-8 vers ISO-8859-1 (Windows-1252) pour FPDF.
 * Supprime les émojis qui feraient planter la génération.
 */
function cleanText($text) {
    // Translit permet de convertir certains caractères spéciaux en équivalent ASCII
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
}

// ====================================================
// 2. RÉCUPÉRATION DES DONNÉES
// ====================================================

$levelFilter = isset($_GET['level']) ? $_GET['level'] : 'all';

// On sélectionne uniquement les roses payées et NON encore préparées
// (Pour éviter de réimprimer des étiquettes déjà traitées, sauf demande spécifique)
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

// Application du filtre par niveau (2nde, 1ere, Term...)
if ($levelFilter !== 'all') {
    $allowedFilters = ['2nde', '1ere', 'term', 'autre'];
    if (in_array($levelFilter, $allowedFilters)) {
        $sql .= " AND cl.group_alias = :filter ";
        $params['filter'] = $levelFilter;
    }
}

// Tri par Classe puis par Nom pour faciliter la distribution
$sql .= " ORDER BY c.name ASC, r.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$labels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Arrêt si aucune donnée
if (count($labels) === 0) {
    die("Aucune étiquette à imprimer pour le filtre sélectionné (" . htmlspecialchars($levelFilter) . ").");
}

// ====================================================
// 3. CONFIGURATION DE LA MISE EN PAGE PDF
// ====================================================

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false); // Important pour gérer nous-mêmes le saut de page
$pdf->AddPage();

// Paramètres de la grille (Ajustez selon votre planche d'étiquettes autocollantes)
// Exemple pour une planche de 21 étiquettes (3 colonnes x 7 lignes)
$startX = 10;       // Marge Gauche
$startY = 10;       // Marge Haute
$colWidth = 63.5;   // Largeur étiquette
$rowHeight = 38.1;  // Hauteur étiquette
$colsPerPage = 3;
$rowsPerPage = 7;
$padding = 2;       // Marge interne au cadre

$col = 0;
$row = 0;

// ====================================================
// 4. GÉNÉRATION DES ÉTIQUETTES
// ====================================================

foreach ($labels as $label) {
    
    // Calcul des coordonnées X et Y pour l'étiquette courante
    $currentX = $startX + ($col * $colWidth);
    $currentY = $startY + ($row * $rowHeight);
    
    // Dessin du contour (Optionnel : commentez cette ligne si vous avez des planches pré-découpées)
    $pdf->Rect($currentX, $currentY, $colWidth, $rowHeight);
    
    // --- ZONE 1 : ID Unique (Haut Droite) ---
    // Utile pour la traçabilité lors de la préparation
    $pdf->SetXY($currentX + $padding, $currentY + 1);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(150, 150, 150); // Gris clair
    $pdf->Cell($colWidth - ($padding*2), 4, '#' . $label['unique_gift_id'], 0, 1, 'R');
    
    // --- ZONE 2 : Destinataire (Taille Auto-adaptative) ---
    $fullDestName = cleanText("Pour : " . strtoupper($label['dest_nom']) . ' ' . $label['dest_prenom']);
    
    $fontSize = 10; // Taille de départ
    $pdf->SetFont('Arial', 'B', $fontSize);
    $pdf->SetTextColor(0, 0, 0); // Noir
    
    // Réduction de la police tant que le texte dépasse la largeur disponible
    $maxWidth = $colWidth - ($padding * 2);
    while ($pdf->GetStringWidth($fullDestName) > $maxWidth && $fontSize > 6) {
        $fontSize -= 0.5;
        $pdf->SetFontSize($fontSize);
    }
    
    $pdf->SetXY($currentX + $padding, $currentY + 6);
    $pdf->Cell($maxWidth, 5, $fullDestName, 0, 1, 'L');
    
    // --- ZONE 3 : Classe ---
    $fullClassName = cleanText("Classe : " . ($label['class_name'] ?? 'Inconnue'));
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetXY($currentX + $padding, $currentY + 11);
    $pdf->Cell($maxWidth, 4, $fullClassName, 0, 1, 'L');
    
    // --- Ligne de séparation ---
    $lineY = $currentY + 16;
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line($currentX + 2, $lineY, $currentX + $colWidth - 2, $lineY);
    $pdf->SetDrawColor(0, 0, 0); // Reset couleur noir
    
    // --- ZONE 4 : Message & Expéditeur ---
    $pdf->SetXY($currentX + $padding, $lineY + 2);
    $pdf->SetFont('Arial', '', 8);
    
    // Construction du texte du message
    $text = "";
    if ($label['is_anonymous']) {
        if ($label['message_content']) {
            $text = "Message :\n\"" . $label['message_content'] . "\"";
        } else {
            $text = "Un admirateur secret vous a envoye une rose.";
        }
    } else {
        $senderName = $label['buyer_prenom'] . ' ' . $label['buyer_nom'];
        $text = "De la part de : " . $senderName;
        if ($label['message_content']) {
            $text .= "\nMessage : \"" . $label['message_content'] . "\"";
        }
    }
    
    // Utilisation de MultiCell pour le retour à la ligne automatique
    // Hauteur de ligne réduite (3.5) pour faire tenir plus de texte
    $pdf->MultiCell($maxWidth, 3.5, cleanText($text), 0, 'L');

    // ====================================================
    // 5. GESTION DE LA PAGINATION (Grille)
    // ====================================================
    $col++;
    
    // Si on dépasse le nombre de colonnes, on passe à la ligne suivante
    if ($col >= $colsPerPage) {
        $col = 0;
        $row++;
    }
    
    // Si on dépasse le nombre de lignes, on crée une nouvelle page
    if ($row >= $rowsPerPage) {
        $pdf->AddPage();
        $col = 0;
        $row = 0;
    }
}

// Envoi du PDF au navigateur
$pdf->Output('I', 'Etiquettes_St_Valentin_' . date('His') . '.pdf');
?>