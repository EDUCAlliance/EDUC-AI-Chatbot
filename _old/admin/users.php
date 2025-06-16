<?php
/**
 * EDUC AI TalkBot - Admin Users Management Page
 */

session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

checkSetup();
requireAuth();

$pageTitle = 'Admin Users';
$pageIcon = 'bi bi-people';

$db = \EDUC\Database\Database::getInstance();
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !\EDUC\Utils\Security::validateCSRFToken($_POST['csrf_token'])) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_user':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role = $_POST['role'] ?? 'admin';
                
                if (empty($username) || empty($password)) {
                    throw new Exception('Username and password are required');
                }
                
                if (strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                
                // Check if username already exists
                $connection = $db->getConnection();
                $stmt = $connection->prepare("SELECT id FROM {$db->getTablePrefix()}admin_users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    throw new Exception('Username already exists');
                }
                
                // Create user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $connection->prepare("
                    INSERT INTO {$db->getTablePrefix()}admin_users (username, password_hash, full_name, email, role, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$username, $hashedPassword, $fullName, $email, $role]);
                
                $message = "User '$username' created successfully!";
                $messageType = 'success';
                break;
                
            case 'delete_user':
                $userId = intval($_POST['user_id']);
                $currentUserId = getCurrentUser()['id'];
                
                if ($userId === $currentUserId) {
                    throw new Exception('Cannot delete your own account');
                }
                
                $connection = $db->getConnection();
                $stmt = $connection->prepare("DELETE FROM {$db->getTablePrefix()}admin_users WHERE id = ?");
                $stmt->execute([$userId]);
                
                $message = "User deleted successfully!";
                $messageType = 'success';
                break;
                
            case 'update_role':
                $userId = intval($_POST['user_id']);
                $role = $_POST['role'] ?? 'admin';
                $currentUserId = getCurrentUser()['id'];
                
                if ($userId === $currentUserId) {
                    throw new Exception('Cannot modify your own role');
                }
                
                $connection = $db->getConnection();
                $stmt = $connection->prepare("UPDATE {$db->getTablePrefix()}admin_users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $userId]);
                
                $message = "User role updated successfully!";
                $messageType = 'success';
                break;
                
            default:
                throw new Exception('Unknown action');
        }
        
    } catch (Exception $e) {
        \EDUC\Utils\Logger::error('Users page error', ['error' => $e->getMessage()]);
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get all admin users
function getAllUsers() {
    $db = \EDUC\Database\Database::getInstance();
    $connection = $db->getConnection();
    $stmt = $connection->query("
        SELECT id, username, full_name, email, role, last_login, created_at 
        FROM {$db->getTablePrefix()}admin_users 
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$users = getAllUsers();
$currentUser = getCurrentUser();
$csrfToken = \EDUC\Utils\Security::generateCSRFToken();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'error' ? 'danger' : $messageType ?> alert-dismissible fade show">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- User Statistics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-icon primary">
                <i class="bi bi-people"></i>
            </div>
            <h3 class="stats-value"><?= count($users) ?></h3>
            <p class="stats-label">Total Admin Users</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card success">
            <div class="stats-icon success">
                <i class="bi bi-person-check"></i>
            </div>
            <h3 class="stats-value"><?= count(array_filter($users, fn($u) => $u['last_login'] !== null)) ?></h3>
            <p class="stats-label">Active Users</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card warning">
            <div class="stats-icon warning">
                <i class="bi bi-shield-check"></i>
            </div>
            <h3 class="stats-value"><?= count(array_filter($users, fn($u) => $u['role'] === 'super_admin')) ?></h3>
            <p class="stats-label">Super Admins</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card secondary">
            <div class="stats-icon secondary">
                <i class="bi bi-person-plus"></i>
            </div>
            <h3 class="stats-value"><?= count(array_filter($users, fn($u) => $u['role'] === 'admin')) ?></h3>
            <p class="stats-label">Regular Admins</p>
        </div>
    </div>
</div>

<!-- Add User Button -->
<div class="row mb-4">
    <div class="col-12">
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-people"></i> Admin Users Management
                </h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus"></i> Add New User
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="row">
    <div class="col-12">
        <div class="stats-card">
            <?php if (empty($users)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-people" style="font-size: 3rem; color: #e5e7eb;"></i>
                    <p class="text-muted mt-2">No admin users found</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="bi bi-person-plus"></i> Add First User
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Last Login</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr class="<?= $user['id'] == $currentUser['id'] ? 'table-primary' : '' ?>">
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                                            <?php if ($user['id'] == $currentUser['id']): ?>
                                                <span class="badge bg-info ms-2">You</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($user['full_name']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($user['full_name']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($user['email']): ?>
                                            <div><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $user['role'] === 'super_admin' ? 'danger' : 'primary' ?>">
                                            <?= ucfirst(str_replace('_', ' ', $user['role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <small><?= date('M j, Y \a\t g:i A', strtotime($user['last_login'])) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?= date('M j, Y', strtotime($user['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($user['id'] != $currentUser['id']): ?>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                                    Actions
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <button class="dropdown-item" type="button" onclick="changeRole(<?= $user['id'] ?>, '<?= $user['role'] ?>')">
                                                            <i class="bi bi-shield"></i> Change Role
                                                        </button>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <button class="dropdown-item text-danger" type="button" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                            <i class="bi bi-trash"></i> Delete User
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">Current User</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Admin User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="password" name="password" required minlength="8">
                        <small class="form-text text-muted">Minimum 8 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name">
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Add User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Forms for Actions -->
<form id="deleteForm" method="post" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="user_id" id="deleteUserId">
</form>

<form id="roleForm" method="post" action="" style="display: none;">
    <input type="hidden" name="action" value="update_role">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="user_id" id="roleUserId">
    <input type="hidden" name="role" id="newRole">
</form>

<script>
function deleteUser(userId, username) {
    if (confirm(`Are you sure you want to delete user "${username}"? This action cannot be undone.`)) {
        document.getElementById('deleteUserId').value = userId;
        document.getElementById('deleteForm').submit();
    }
}

function changeRole(userId, currentRole) {
    const newRole = currentRole === 'admin' ? 'super_admin' : 'admin';
    const roleName = newRole === 'super_admin' ? 'Super Admin' : 'Admin';
    
    if (confirm(`Change user role to ${roleName}?`)) {
        document.getElementById('roleUserId').value = userId;
        document.getElementById('newRole').value = newRole;
        document.getElementById('roleForm').submit();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?> 