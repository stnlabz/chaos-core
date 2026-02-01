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
        
        // Developer option selected? Role 8 if true, Role 1 if false.
        $is_dev = isset($_POST['is_developer']);

        if ($username === '' || $name === '' || $email === '' || $pass === '') {
            $error = 'All fields are required.';
        } else {
            $conn = $db->connect();
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $role_id = $is_dev ? 8 : 1; 

            $stmt = $conn->prepare("INSERT INTO users (username, name, email, password_hash, role_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt) {
                $stmt->bind_param('ssssi', $username, $name, $email, $hash, $role_id);
                if ($stmt->execute()) {
                    $stmt->close();

                    // --- INTEGRATING WORKING MAILER PATTERN ---
                    // This mirrors your Creators module (main.php) logic
                    require_once __DIR__ . '/../../lib/mailer.php'; 
                    $m_lib = new mailer($db); 
                    $m = $m_lib->create(); 
                    
                    try {
                        $m->addAddress($email, $name);
                        $m->isHTML(true);
                        
                        if ($is_dev) {
                            $m->Subject = "Chaos Academy: Developer Induction";
                            $m->Body = "<h2>Welcome to the Engine, $name</h2><p>Your Developer account is active. You now have access to the Academy.</p><p><a href='https://chaoscms.org/academy'>Enter the Academy</a></p>";
                        } else {
                            $m->Subject = "Welcome to Chaos CMS";
                            $m->Body = "<h2>Registration Successful</h2><p>Hi $name, your account is ready. Welcome to the community!</p>";
                        }
                        
                        $m->send();
                    } catch (Exception $e) {
                        // Log it so we know if the SMTP handshake failed
                        error_log("Signup Email Failed for $email: " . $e->getMessage());
                    }

                    header('Location: /login?msg=registered');
                    exit;
                } else {
                    $error = 'Username or Email already exists.';
                }
            } else {
                $error = 'Internal DB error.';
            }
        }
    }
?>

<div class="container my-5 signup-box">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <h2 class="mb-3 text-center">Create Account</h2>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-2">
                    <label class="small fw-bold">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($username); ?>" required>
                </div>
                <div class="mb-2">
                    <label class="small fw-bold">Display Name</label>
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

                <div class="mb-4 p-3 border rounded bg-light border-primary-subtle shadow-sm">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_developer" id="devSwitch" value="1">
                        <label class="form-check-label small fw-bold text-primary" for="devSwitch">
                            Join as Developer
                        </label>
                    </div>
                    <p class="x-small text-muted mb-0 mt-1" style="font-size: 0.72rem;">
                        Enables Access to Academy training and Forge tools.
                    </p>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">Create Account</button>
                <div class="mt-3 text-center">
                    <a href="/login" class="small text-muted text-decoration-none">Existing member? Sign In</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
})();
