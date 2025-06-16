<?php
/**
 * EDUC AI TalkBot - Admin Users Management
 */

session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check authentication
checkSetup();
requireAuth();

// Handle form submissions
$message = '';
$messageType = '';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_user':
            $message = 'User creation functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'update_user':
            $message = 'User update functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'delete_user':
            $message = 'User deletion functionality will be implemented';
            $messageType = 'info';
            break;
            
        case 'change_password':
            $message = 'Password change functionality will be implemented';
            $messageType = 'info';
            break;
    }
}

// Get current user info
$currentUser = getCurrentUser();

// Mock users data for display
$adminUsers = [
    [
        'id' => 1,
        'username' => $currentUser['username'] ?? 'admin',
        'full_name' => $currentUser['full_name'] ?? 'Administrator',
        'email' => $currentUser['email'] ?? 'admin@example.com',
        'role' => 'administrator',
        'status' => 'active',
        'last_login' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s', strtotime('-30 days'))
    ]
];

// Page configuration
$pageTitle = 'Admin Users';
$pageIcon = 'bi bi-people';

// Include header
include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
        <i class="bi bi-info-circle"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Admin Users List -->
        <div class="stats-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="bi bi-people"></i> Administrator Accounts
                </h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-plus"></i> Add Admin User
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminUsers as $user): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-3">
                                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($user['full_name']) ?></div>
                                            <small class="text-muted">@<?= htmlspecialchars($user['username']) ?></small>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?= ucfirst($user['role']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = $user['status'] === 'active' ? 'bg-success' : 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= ucfirst($user['status']) ?></span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= date('M j, Y H:i', strtotime($user['last_login'])) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editUser(<?= $user['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" onclick="changePassword(<?= $user['id'] ?>)">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <?php if ($user['id'] != ($currentUser['id'] ?? 1)): ?>
                                            <button class="btn btn-outline-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Current User Info -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-person-circle"></i> Current Session
            </h5>
            
            <div class="text-center mb-3">
                <div class="user-avatar-large mx-auto mb-3">
                    <?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?>
                </div>
                <h6><?= htmlspecialchars($currentUser['full_name'] ?? 'Administrator') ?></h6>
                <p class="text-muted">@<?= htmlspecialchars($currentUser['username'] ?? 'admin') ?></p>
            </div>
            
            <table class="table table-sm">
                <tr>
                    <td>Role:</td>
                    <td><span class="badge bg-primary"><?= ucfirst($currentUser['role'] ?? 'Administrator') ?></span></td>
                </tr>
                <tr>
                    <td>Email:</td>
                    <td><?= htmlspecialchars($currentUser['email'] ?? 'Not set') ?></td>
                </tr>
                <tr>
                    <td>Session Started:</td>
                    <td><?= date('M j, Y H:i', $_SESSION['login_time'] ?? time()) ?></td>
                </tr>
                <tr>
                    <td>IP Address:</td>
                    <td><?= $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ?></td>
                </tr>
            </table>
            
            <div class="d-grid gap-2 mt-3">
                <button class="btn btn-outline-primary" onclick="editProfile()">
                    <i class="bi bi-pencil"></i> Edit Profile
                </button>
                <button class="btn btn-outline-warning" onclick="changeMyPassword()">
                    <i class="bi bi-key"></i> Change Password
                </button>
                <a href="logout.php" class="btn btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
        
        <!-- Security Settings -->
        <div class="stats-card mb-4">
            <h5 class="mb-3">
                <i class="bi bi-shield"></i> Security Settings
            </h5>
            
            <table class="table table-sm">
                <tr>
                    <td>Session Timeout:</td>
                    <td><?= htmlspecialchars(getenv('ADMIN_SESSION_TIMEOUT') ?: '3600') ?>s</td>
                </tr>
                <tr>
                    <td>Max Login Attempts:</td>
                    <td><?= htmlspecialchars(getenv('MAX_LOGIN_ATTEMPTS') ?: '5') ?></td>
                </tr>
                <tr>
                    <td>Lockout Time:</td>
                    <td><?= htmlspecialchars(getenv('LOGIN_LOCKOUT_TIME') ?: '900') ?>s</td>
                </tr>
                <tr>
                    <td>2FA Enabled:</td>
                    <td><span class="badge bg-warning">Not available</span></td>
                </tr>
            </table>
            
            <div class="mt-3">
                <a href="settings.php" class="btn btn-outline-primary btn-sm w-100">
                    <i class="bi bi-gear"></i> Configure Security
                </a>
            </div>
        </div>
        
        <!-- User Statistics -->
        <div class="stats-card">
            <h5 class="mb-3">
                <i class="bi bi-graph-up"></i> User Statistics
            </h5>
            
            <div class="row text-center">
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-primary mb-1"><?= count($adminUsers) ?></h4>
                        <small class="text-muted">Total Admins</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-success mb-1">1</h4>
                        <small class="text-muted">Active Sessions</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-info mb-1">0</h4>
                        <small class="text-muted">Failed Logins (24h)</small>
                    </div>
                </div>
                <div class="col-6 mb-3">
                    <div class="border rounded p-3">
                        <h4 class="text-warning mb-1">30</h4>
                        <small class="text-muted">Days Active</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Administrator</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="administrator">Administrator</option>
                            <option value="moderator">Moderator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>User editing functionality will be implemented here.</p>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Password change functionality will be implemented here.</p>
            </div>
        </div>
    </div>
</div>

<style>
.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
}

.user-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 2rem;
}
</style>

<script>
function editUser(userId) {
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function changePassword(userId) {
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}

function deleteUser(userId) {
    if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
        // Implementation would go here
        alert('User deletion functionality will be implemented');
    }
}

function editProfile() {
    alert('Profile editing functionality will be implemented');
}

function changeMyPassword() {
    const modal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    modal.show();
}
</script>

<?php
include __DIR__ . '/includes/footer.php';
?> 