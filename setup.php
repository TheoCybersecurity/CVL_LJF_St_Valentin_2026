<?php
require_once 'auth_check.php'; 
require_once 'db.php';
require_once '/var/www/config/config.php'; 

if (!isset($current_user_id)) {
    header("Location: https://projets.marescal.fr");
    exit;
}

// ---------------------------------------------------------
// SÉCURITÉ & RÉCUPÉRATION DES DONNÉES
// ---------------------------------------------------------

$nom_display = '';
$prenom_display = '';
$is_locked = false; // Par défaut, on laisse l'utilisateur écrire

// On récupère les infos de l'URL si elles existent
$nom_url = $_GET['nom'] ?? '';
$prenom_url = $_GET['prenom'] ?? '';
$signature_recue = $_GET['sig'] ?? '';

// On tente de vérifier la signature
if ($nom_url && $prenom_url && $signature_recue) {
    $paramsCheck = ['nom' => $nom_url, 'prenom' => $prenom_url];
    $queryStringCheck = http_build_query($paramsCheck);
    $signature_calculee = hash_hmac('sha256', $queryStringCheck, JWT_SECRET);

    if (hash_equals($signature_calculee, $signature_recue)) {
        // SUCCÈS : C'est authentique, on remplit et on verrouille
        $nom_display = $nom_url;
        $prenom_display = $prenom_url;
        $is_locked = true; 
    }
}
// SI ÉCHEC : On ne fait rien, on laisse les variables vides et $is_locked à false.
// L'utilisateur devra remplir le formulaire lui-même.

// ---------------------------------------------------------
// VÉRIFICATION EXISTANT
// ---------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
if ($stmt->fetch()) {
    header("Location: index.php");
    exit;
}

// Récupération des classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();

// ---------------------------------------------------------
// TRAITEMENT DU FORMULAIRE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classId = $_POST['classe'];
    // On prend ce que le formulaire nous envoie (soit les champs locked, soit ce que l'user a tapé)
    $nom_final = trim($_POST['nom']);
    $prenom_final = trim($_POST['prenom']);

    if ($classId && $nom_final && $prenom_final) {
        $stmtInsert = $pdo->prepare("INSERT INTO users (user_id, nom, prenom, class_id) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([$current_user_id, $nom_final, $prenom_final, $classId]);
        
        header("Location: index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Finaliser l'inscription</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">

<div class="card shadow-sm p-4 m-3" style="max-width: 500px; width: 100%;">
    <div class="text-center mb-4">
        <h2 style="font-size: 3rem;">🌹</h2>
        <h3 class="mb-2">Bonjour <?php echo $prenom_display ? htmlspecialchars($prenom_display) : ''; ?> !</h3>
        <p class="text-muted">Pour terminer, indiquez-nous vos informations.</p>
    </div>
    
    <form method="post">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Nom <span class="text-danger">*</span></label>
                <input type="text" name="nom" class="form-control" 
                       value="<?php echo htmlspecialchars($nom_display); ?>" 
                       <?php echo $is_locked ? 'readonly style="background-color: #e9ecef;"' : 'required'; ?>>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Prénom <span class="text-danger">*</span></label>
                <input type="text" name="prenom" class="form-control" 
                       value="<?php echo htmlspecialchars($prenom_display); ?>" 
                       <?php echo $is_locked ? 'readonly style="background-color: #e9ecef;"' : 'required'; ?>>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold">Votre Classe <span class="text-danger">*</span></label>
            <select name="classe" class="form-select" required autofocus>
                <option value="">-- Sélectionner votre classe --</option>
                <?php foreach($classes as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-danger w-100 py-2">Valider et Commander 🚀</button>
    </form>
</div>

</body>
</html>