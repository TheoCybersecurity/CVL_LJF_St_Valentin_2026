<?php
/**
 * Finalisation de l'Inscription (Premier Accès)
 * setup.php
 * Ce fichier est la première étape obligatoire après une connexion SSO réussie.
 * Il gère :
 * 1. La vérification de l'intégrité des données transmises via URL (Signature HMAC).
 * 2. Le pré-remplissage sécurisé du formulaire (Nom/Prénom).
 * 3. La sélection de la classe (seule donnée manquante du SSO).
 * 4. La création du profil utilisateur en base de données.
 */

require_once 'auth_check.php'; 
require_once 'db.php';
// Chargement de la clé secrète pour la vérification de signature
require_once '/var/www/config/config.php'; 

// Sécurité : Si l'utilisateur n'est pas authentifié via le CAS/SSO, on l'éjecte.
if (!isset($current_user_id)) {
    header("Location: https://projets.marescal.fr");
    exit;
}

// ====================================================
// 1. VÉRIFICATION DE L'EXISTANT
// ====================================================

// Si l'utilisateur existe déjà dans notre table 'users', inutile de rester ici.
$stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
if ($stmt->fetch()) {
    header("Location: order.php"); // Redirection directe vers la commande
    exit;
}

// ====================================================
// 2. SÉCURITÉ & RÉCUPÉRATION DES DONNÉES URL
// ====================================================

$nom_display = '';
$prenom_display = '';
$is_locked = false; // Par défaut, les champs restent modifiables

// Récupération des paramètres transmis par le portail SSO
$nom_url = $_GET['nom'] ?? '';
$prenom_url = $_GET['prenom'] ?? '';
$signature_recue = $_GET['sig'] ?? '';

// Vérification de la signature HMAC (Anti-Tampering)
// On s'assure que le nom/prénom n'ont pas été modifiés dans l'URL par l'utilisateur
if ($nom_url && $prenom_url && $signature_recue) {
    // Reconstitution de la chaîne signée
    $paramsCheck = ['nom' => $nom_url, 'prenom' => $prenom_url];
    $queryStringCheck = http_build_query($paramsCheck);
    
    // Recalcul du hash avec la clé secrète serveur
    $signature_calculee = hash_hmac('sha256', $queryStringCheck, JWT_SECRET);

    if (hash_equals($signature_calculee, $signature_recue)) {
        // SUCCÈS : Données authentiques -> On pré-remplit et on verrouille
        $nom_display = $nom_url;
        $prenom_display = $prenom_url;
        $is_locked = true; 
    }
    // ECHEC : Signature invalide -> On laisse les champs vides et modifiables par sécurité
}

// ====================================================
// 3. TRAITEMENT DU FORMULAIRE (ENREGISTREMENT)
// ====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyage des entrées
    $classId = $_POST['classe'] ?? null;
    
    // Si verrouillé, on garde les valeurs URL fiables. Sinon, on prend la saisie utilisateur.
    $nom_final = $is_locked ? $nom_display : trim($_POST['nom']);
    $prenom_final = $is_locked ? $prenom_display : trim($_POST['prenom']);

    // Validation et Insertion
    if ($classId && !empty($nom_final) && !empty($prenom_final)) {
        try {
            $stmtInsert = $pdo->prepare("INSERT INTO users (user_id, nom, prenom, class_id) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$current_user_id, $nom_final, $prenom_final, $classId]);
            
            // Succès : Direction la prise de commande
            header("Location: order.php");
            exit;
        } catch (PDOException $e) {
            $error = "Une erreur est survenue lors de l'enregistrement.";
        }
    } else {
        $error = "Merci de remplir tous les champs.";
    }
}

// Chargement de la liste des classes pour le sélecteur
$classes = $pdo->query("SELECT * FROM classes ORDER BY name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Finaliser l'inscription - St Valentin</title>
    <?php include 'head_imports.php'; ?>
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .setup-card {
            max-width: 500px;
            width: 100%;
            border-radius: 15px;
            border-top: 5px solid #dc3545;
        }
    </style>
</head>
<body>

<div class="card shadow-lg p-4 m-3 setup-card animate__animated animate__fadeInUp">
    <div class="text-center mb-4">
        <div class="mb-3" style="font-size: 3rem;">🌹</div>
        <h3 class="fw-bold text-dark">
            <?php if($prenom_display): ?>
                Bonjour <?php echo htmlspecialchars($prenom_display); ?> !
            <?php else: ?>
                Bienvenue !
            <?php endif; ?>
        </h3>
        <p class="text-muted small">
            C'est votre première connexion.<br>
            Merci de compléter votre profil pour pouvoir commander.
        </p>
    </div>
    
    <?php if(isset($error)): ?>
        <div class="alert alert-danger text-center p-2 mb-3 small">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label small fw-bold text-secondary">Nom</label>
                <input type="text" name="nom" class="form-control" 
                       value="<?php echo htmlspecialchars($nom_display); ?>" 
                       <?php echo $is_locked ? 'readonly style="background-color: #e9ecef; cursor:not-allowed;"' : 'required placeholder="Votre nom"'; ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold text-secondary">Prénom</label>
                <input type="text" name="prenom" class="form-control" 
                       value="<?php echo htmlspecialchars($prenom_display); ?>" 
                       <?php echo $is_locked ? 'readonly style="background-color: #e9ecef; cursor:not-allowed;"' : 'required placeholder="Votre prénom"'; ?>>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold text-danger">Votre Classe actuelle <span class="text-danger">*</span></label>
            <select name="classe" class="form-select form-select-lg border-danger" required autofocus>
                <option value="">-- Choisir dans la liste --</option>
                <?php foreach($classes as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text small">Cette information nous sert à vous retrouver en cas de souci.</div>
        </div>

        <button type="submit" class="btn btn-danger w-100 py-3 fw-bold shadow-sm hover-scale">
            Valider et Commander 🚀
        </button>
    </form>
</div>

</body>
</html>