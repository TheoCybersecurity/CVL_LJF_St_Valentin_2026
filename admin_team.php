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

        // Notification de succès
        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Nouveau membre ajouté à l\'équipe avec succès !'
        ];
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

        // Notification de suppression (type warning pour attirer l'attention)
        $_SESSION['toast'] = [
            'type' => 'warning',
            'message' => 'Les droits d\'accès ont été retirés à cet utilisateur.'
        ];
    } else {
        // Tentative de suppression interdite
        $_SESSION['toast'] = [
            'type' => 'danger',
            'message' => 'Action impossible sur cet utilisateur.'
        ];
    }
    header("Location: admin_team.php");
    exit;
}

// --- AFFICHAGE ---

// A. Liste des membres actuels
$stmt = $pdo->query("
    SELECT cm.user_id, cm.role, u.nom, u.prenom 
    FROM cvl_members cm 
    LEFT JOIN users u ON cm.user_id = u.user_id 
    ORDER BY FIELD(cm.role, 'admin', 'cvl'), u.nom ASC
");
$teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// B. Liste pour le menu déroulant (ceux qui ne sont PAS encore membres)
$stmt = $pdo->query("
    SELECT u.user_id, u.nom, u.prenom 
    FROM users u 
    LEFT JOIN cvl_members cm ON u.user_id = cm.user_id 
    WHERE cm.user_id IS NULL 
    ORDER BY u.nom ASC
");
$potentialMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion Équipe - Admin</title>
    <?php include 'head_imports.php'; ?>
    <style>
        .role-badge { width: 100px; text-align: center; }
        .avatar-initials {
            width: 40px; height: 40px;
            background-color: #e9ecef;
            color: #495057;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; font-weight: bold;
            margin-right: 15px;
        }
        .bg-superadmin { background-color: #ffc107; color: #000; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="admin.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Retour Hub</a>
            <h2 class="fw-bold mt-2">👮 Gestion de l'équipe</h2>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-users me-2"></i>Membres ayant accès</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Membre</th>
                                <th>Rôle</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($teamMembers as $member): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-initials">
                                            <?php 
                                                $p = !empty($member['prenom']) ? strtoupper(substr($member['prenom'], 0, 1)) : '?';
                                                $n = !empty($member['nom']) ? strtoupper(substr($member['nom'], 0, 1)) : '';
                                                echo $p.$n;
                                            ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold">
                                                <?php echo htmlspecialchars(($member['prenom'] ?? 'Inconnu') . ' ' . ($member['nom'] ?? '')); ?>
                                            </div>
                                            <?php if($member['user_id'] == $_SESSION['user_id']): ?>
                                                <small class="text-success fst-italic">C'est vous</small>
                                            <?php else: ?>
                                                <small class="text-muted">ID: <?php echo $member['user_id']; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <?php if($member['user_id'] == 2): // Super Admin (Théo) ?>
                                        <span class="badge bg-warning text-dark role-badge"><i class="fas fa-crown"></i> BOSS</span>
                                    <?php elseif($member['role'] === 'admin'): ?>
                                        <span class="badge bg-danger role-badge">ADMIN</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark role-badge">CVL</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-end pe-4">
                                    <?php if($member['user_id'] == $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-secondary" disabled>Retirer</button>
                                    <?php elseif($member['user_id'] == 2): ?>
                                        <button class="btn btn-sm btn-secondary" disabled title="Intouchable">Retirer</button>
                                    <?php else: ?>
                                        <a href="admin_team.php?delete_id=<?php echo $member['user_id']; ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Êtes-vous sûr de vouloir retirer les droits d\'accès à cette personne ?');">
                                           <i class="fas fa-trash-alt"></i> Retirer
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 sticky-top" style="top: 20px; z-index: 900;">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Donner un accès</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">1. Sélectionner l'élève</label>
                            <select name="add_user_id" class="form-select" required>
                                <option value="" selected disabled>Rechercher dans la liste...</option>
                                <?php foreach($potentialMembers as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>">
                                        <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Seuls les élèves connectés au moins une fois apparaissent ici.</div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">2. Choisir le rôle</label>
                            <div class="d-grid gap-2">
                                <input type="radio" class="btn-check" name="role" id="role_cvl" value="cvl" checked>
                                <label class="btn btn-outline-info" for="role_cvl">Membre CVL (Limité)</label>

                                <input type="radio" class="btn-check" name="role" id="role_admin" value="admin">
                                <label class="btn btn-outline-danger" for="role_admin">Administrateur (Total)</label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-dark w-100 py-2">
                            <i class="fas fa-check-circle me-2"></i>Valider l'ajout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'toast_notifications.php'; ?>
</body>
</html>