<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

session_start();
$pdo = (new Database())->connection();

$error = null;
$success = null;

// Обработка входа в админку
if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['gjp2_hash']) && in_array($admin['role'], ['admin', 'owner'], true)) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_id'] = $admin['account_id'];
        $_SESSION['admin_name'] = $admin['username'];
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Неверные данные или недостаточно прав (требуется роль Admin/Owner).';
    }
}

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Проверка авторизации
$isLogged = $_SESSION['admin_logged'] ?? false;

// Действия администратора (только если авторизован)
if ($isLogged && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Изменение роли пользователя
    if (isset($_POST['action']) && $_POST['action'] === 'set_role') {
        $targetId = (int)$_POST['account_id'];
        $newRole = $_POST['role'];
        if (in_array($newRole, ['user', 'moderator', 'elder', 'admin', 'owner'], true)) {
            $stmt = $pdo->prepare('UPDATE accounts SET role = :role WHERE account_id = :id');
            $stmt->execute(['role' => $newRole, 'id' => $targetId]);
            $success = 'Роль пользователя успешно обновлена!';
        }
    }

    // Удаление уровня
    if (isset($_POST['action']) && $_POST['action'] === 'delete_level') {
        $levelId = (int)$_POST['level_id'];
        $stmt = $pdo->prepare('UPDATE levels SET is_deleted = 1 WHERE level_id = :id');
        $stmt->execute(['id' => $levelId]);
        $success = 'Уровень успешно удален!';
    }
}

// Получение данных для дашборда
$stats = [];
$users = [];
$levels = [];

if ($isLogged) {
    $stats['users'] = $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $stats['levels'] = $pdo->query('SELECT COUNT(*) FROM levels WHERE is_deleted = 0')->fetchColumn();
    $stats['songs'] = $pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn();
    $stats['comments'] = $pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn();

    $users = $pdo->query('
        SELECT a.account_id, a.username, a.role, p.stars, p.demons, p.creator_points, a.created_at 
        FROM accounts a 
        LEFT JOIN profiles p ON p.account_id = a.account_id 
        ORDER BY a.account_id DESC LIMIT 50
    ')->fetchAll(PDO::FETCH_ASSOC);

    $levels = $pdo->query('
        SELECT l.level_id, l.name, l.stars, l.featured, l.epic, a.username as author, l.created_at 
        FROM levels l 
        LEFT JOIN accounts a ON a.account_id = l.account_id 
        WHERE l.is_deleted = 0 
        ORDER BY l.level_id DESC LIMIT 50
    ')->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MuchoCore - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-body-tertiary">

<?php if (!$isLogged): ?>
    <div class="container d-flex align-items-center justify-content-center min-vh-100">
        <div class="card shadow-lg p-4 border-0" style="width: 400px;">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-primary">MuchoCore Admin</h3>
                <p class="text-muted small">Панель управления сервером Geometry Dash</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small">Логин администратора</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Пароль</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" name="login" class="btn btn-primary w-100">Войти в панель</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold text-primary" href="#"><i class="bi bi-controller"></i> MuchoCore Dashboard</a>
            <div class="d-flex align-items-center">
                <span class="text-secondary me-3 small">Админ: <strong class="text-light"><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
                <a href="?logout=1" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Выход</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm bg-dark text-light border-start border-primary border-4">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small">Пользователи</h6>
                        <h2 class="fw-bold mb-0"><?= $stats['users'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm bg-dark text-light border-start border-success border-4">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small">Уровни</h6>
                        <h2 class="fw-bold mb-0"><?= $stats['levels'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm bg-dark text-light border-start border-warning border-4">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small">Музыкальные треки</h6>
                        <h2 class="fw-bold mb-0"><?= $stats['songs'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm bg-dark text-light border-start border-info border-4">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small">Комментарии</h6>
                        <h2 class="fw-bold mb-0"><?= $stats['comments'] ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Навигационные вкладки -->
        <ul class="nav nav-tabs mb-3" id="adminTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Пользователи и роли</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="levels-tab" data-bs-toggle="tab" data-bs-target="#levels" type="button" role="tab">Уровни сервера</button>
            </li>
        </ul>

        <div class="tab-content" id="adminTabsContent">
            <!-- Вкладка пользователей -->
            <div class="tab-pane fade show active" id="users" role="tabpanel">
                <div class="card border-0 shadow-sm bg-dark text-light">
                    <div class="card-header bg-transparent border-secondary py-3">
                        <h5 class="mb-0">Управление пользователями</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Имя</th>
                                        <th>Роль</th>
                                        <th>Звезды</th>
                                        <th>Демоны</th>
                                        <th>CP</th>
                                        <th>Регистрация</th>
                                        <th class="text-end">Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><?= $u['account_id'] ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($u['username']) ?></td>
                                            <td>
                                                <form method="POST" class="d-flex align-items-center gap-2">
                                                    <input type="hidden" name="action" value="set_role">
                                                    <input type="hidden" name="account_id" value="<?= $u['account_id'] ?>">
                                                    <select name="role" class="form-select form-select-sm bg-secondary text-light border-0" style="width: 130px;">
                                                        <?php foreach (['user', 'moderator', 'elder', 'admin', 'owner'] as $r): ?>
                                                            <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2">ОК</button>
                                                </form>
                                            </td>
                                            <td><?= $u['stars'] ?? 0 ?></td>
                                            <td><?= $u['demons'] ?? 0 ?></td>
                                            <td><?= $u['creator_points'] ?? 0 ?></td>
                                            <td class="text-muted small"><?= $u['created_at'] ?></td>
                                            <td class="text-end">
                                                <!-- Дополнительные действия при необходимости -->
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Вкладка уровней -->
            <div class="tab-pane fade" id="levels" role="tabpanel">
                <div class="card border-0 shadow-sm bg-dark text-light">
                    <div class="card-header bg-transparent border-secondary py-3">
                        <h5 class="mb-0">Список уровней</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Название</th>
                                        <th>Автор</th>
                                        <th>Звезды</th>
                                        <th>Статус</th>
                                        <th>Дата</th>
                                        <th class="text-end">Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($levels as $l): ?>
                                        <tr>
                                            <td><?= $l['level_id'] ?></td>
                                            <td class="fw-bold"><?= htmlspecialchars($l['name']) ?></td>
                                            <td><?= htmlspecialchars($l['author'] ?? 'Unknown') ?></td>
                                            <td><?= $l['stars'] ?></td>
                                            <td>
                                                <?php if ($l['epic'] > 0): ?><span class="badge bg-danger">Epic/Legendary</span>
                                                <?php elseif ($l['featured'] > 0): ?><span class="badge bg-warning text-dark">Featured</span>
                                                <?php else: ?><span class="badge bg-secondary">Rated/Normal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small"><?= $l['created_at'] ?></td>
                                            <td class="text-end">
                                                <form method="POST" onsubmit="return confirm('Удалить этот уровень?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_level">
                                                    <input type="hidden" name="level_id" value="<?= $l['level_id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Удалить</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
</body>
</html>
