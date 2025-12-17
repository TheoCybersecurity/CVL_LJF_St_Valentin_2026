<?php
require_once 'auth_check.php'; 
require_once 'db.php';
// On a besoin de config.php pour récupérer JWT_SECRET afin de vérifier la signature
require_once '/var/www/config/config.php'; 

if (!isset($current_user_id)) {
    header("Location: https://projets.marescal.fr");
    exit;
}

// ---------------------------------------------------------
// SÉCURITÉ ANTI-TRICHE
// ---------------------------------------------------------

// 1. On récupère les données brutes
$nom_url = $_GET['nom'] ?? '';
$prenom_url = $_GET['prenom'] ?? '';
$signature_recue = $_GET['sig'] ?? '';

// 2. On reconstruit la chaîne exactement comme dans register.php
$paramsCheck = [
    'nom' => $nom_url,
    'prenom' => $prenom_url
];
$queryStringCheck = http_build_query($paramsCheck);

// 3. On recalcule ce que DEVRAIT être la signature avec ces données
$signature_calculee = hash_hmac('sha256', $queryStringCheck, JWT_SECRET);

// 4. Comparaison : La signature reçue correspond-elle à notre calcul ?
// hash_equals est une fonction sécurisée pour comparer deux chaînes
if (!hash_equals($signature_calculee, $signature_recue)) {
    // AIE ! Ça ne matche pas. L'utilisateur a probablement modifié l'URL.
    die("Erreur de sécurité : Les données de l'URL ont été modifiées ou sont invalides.");
}

// SI ON ARRIVE ICI, C'EST QUE LE NOM ET PRÉNOM SONT AUTHENTIQUES
// ---------------------------------------------------------

$stmt = $pdo->prepare("SELECT * FROM project_users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
if ($stmt->fetch()) {
    header("Location: index.php");
    exit;
}

// Le reste du code ne change pas, sauf qu'on utilise $nom_url sécurisé
$classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classId = $_POST['classe'];
    // On réutilise les variables sécurisées $nom_url et $prenom_url
    // On ignore ce qui est posté dans 'nom'/'prenom' du formulaire HTML au cas où
    
    if ($classId && $nom_url && $prenom_url) {
        $stmtInsert = $pdo->prepare("INSERT INTO project_users (user_id, nom, prenom, class_id) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([$current_user_id, $nom_url, $prenom_url, $classId]);
        
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
        <h3 class="mb-2">Bonjour <?php echo htmlspecialchars($prenom_url); ?> !</h3>
        <p class="text-muted">Pour terminer, indiquez-nous simplement votre classe.</p>
    </div>
    
    <form method="post">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Nom</label>
                <input type="text" name="nom" class="form-control" 
                       value="<?php echo htmlspecialchars($nom_url); ?>" 
                       readonly 
                       style="background-color: #e9ecef; cursor: not-allowed;">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Prénom</label>
                <input type="text" name="prenom" class="form-control" 
                       value="<?php echo htmlspecialchars($prenom_url); ?>" 
                       readonly 
                       style="background-color: #e9ecef; cursor: not-allowed;">
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