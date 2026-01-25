<?php
declare(strict_types=1);

(function (): void {
    global $auth, $db;

    if (!$auth instanceof auth || !$db instanceof db) {
        http_response_code(500);
        echo '<div class="container my-4"><div class="alert alert-danger">Auth/DB not available.</div></div>';
        return;
    }

    if ($auth->check()) {
        header('Location: /profile');
        exit;
    }

    $error = '';
    $username = '';
    $name = '';
    $email = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $pass     = (string) ($_POST['password'] ?? '');

        if ($username === '' || $name === '' || $email === '' || $pass === '') {
            $error = 'All fields are required.';
        } else {
            $conn = $db->connect();
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $role_id = 1; // Explicitly User role

            $stmt = $conn->prepare("INSERT INTO users (username, name, email, password_hash, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt) {
                $stmt->bind_param('ssssi', $username, $name, $email, $hash, $role_id);
                if (!$stmt->execute()) {
                    $error = 'Username or Email already exists.';
                }
                $stmt->close();
            } else {
                $error = 'Internal DB error.';
            }

            if ($error === '') {
                header('Location: /login');
                exit;
            }
        }
    }
?>

<div class="container my-5 signup-box">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <h2 class="mb-3">Create Account</h2>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-2">
                    <label class="small fw-bold">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($username); ?>" required>
                </div>
                <div class="mb-2">
                    <label class="small fw-bold">Display Name (Real Name)</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name); ?>" required>
                </div>
                <div class="mb-2">
                    <label class="small fw-bold">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($email); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign Up</button>
                <div class="mt-3 text-center">
                    <a href="/login" class="small text-muted text-decoration-none">Already have an account? Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
})();
