<?php
// admin_team.php
require_once 'db.php';
require_once 'auth_check.php';

// SÉCURITÉ : Seul l'admin passe
checkAccess('admin');

// --- TRAITEMENT ---

// 1. Ajouter un membre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user_id'])) {
    $userIdToAdd = intval($_POST['add_user_id']);
    $roleToAdd = $_POST['role']; 
    
    if ($userIdToAdd > 0) {
        $stmt = $pdo->prepare("INSERT INTO cvl_members (user_id, role) VALUES (?, ?) ON DUPLICATE KEY UPDATE role = ?");
        $stmt->execute([$userIdToAdd, $roleToAdd, $roleToAdd]);
    }
    header("Location: admin_team.php");
    exit;
}

// 2. Supprimer
if (isset($_GET['delete_id'])) {
    $idToDelete = intval($_GET['delete_id']);
    // On s'empêche de se supprimer soi-même ET on protège le Super Admin (ID 2)
    if ($idToDelete != $_SESSION['user_id'] && $idToDelete != 2) {
        $stmt = $pdo->prepare("DELETE FROM cvl_members WHERE user_id = ?");
        $stmt->execute([$idToDelete]);
    }
    header("Location: admin_team.php");
    exit;
}

// --- AFFICHAGE ---

// A. Liste des membres actuels
// CORRECTION ICI : On sélectionne explicitement cm.user_id pour éviter le conflit
$stmt = $pdo->query("
    SELECT cm.user_id, cm.role, u.nom, u.prenom 
    FROM cvl_members cm 
    LEFT JOIN project_users u ON cm.user_id = u.user_id 
    ORDER BY cm.role ASC, u.nom ASC
");
$teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// B. Liste pour le menu déroulant
// Ici aussi on est explicite : u.user_id
$stmt = $pdo->query("
    SELECT u.user_id, u.nom, u.prenom 
    FROM project_users u 
    LEFT JOIN cvl_members cm ON u.user_id = cm.user_id 
    WHERE cm.user_id IS NULL 
    ORDER BY u.nom ASC
");
$potentialMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion Équipe - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2 class="fw-bold mb-4">👮 Gestion des accès Admin & CVL</h2>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Membres actuels</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nom</th>
                                <th>Rôle</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($teamMembers as $member): ?>
                            <tr>
                                <td class="fw-bold">
                                    <?php 
                                        echo htmlspecialchars(($member['prenom'] ?? 'Inconnu') . ' ' . ($member['nom'] ?? '')); 
                                        if (empty($member['prenom'])) echo " <small class='text-muted'>(ID: {$member['user_id']})</small>";
                                    ?>
                                </td>
                                <td>
                                    <?php if($member['role'] === 'admin'): ?>
                                        <span class="badge bg-danger">ADMIN</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark">MEMBER (CVL)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <td>
                                        <?php if($member['user_id'] == $_SESSION['user_id']): ?>
                                            <span class="text-muted small">C'est vous</span>
                                        <?php elseif($member['user_id'] == 2): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-crown"></i> Super Admin</span>
                                        <?php else: ?>
                                            <a href="admin_team.php?delete_id=<?php echo $member['user_id']; ?>" 
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Retirer les droits ?');">Retirer</a>
                                        <?php endif; ?>
                                    </td>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm bg-white">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">Ajouter un membre</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Utilisateur (Local)</label>
                            <select name="add_user_id" class="form-select" required>
                                <option value="" selected disabled>Choisir...</option>
                                <?php foreach($potentialMembers as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rôle</label>
                            <select name="role" class="form-select">
                                <option value="cvl">Membre CVL</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Donner l'accès</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>