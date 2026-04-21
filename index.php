<?php
session_start();
require 'config.php';

// ========================================================================
// --- SYSTEM AUTO-DEPLOYMENT: Automatically builds missing tables ---
// ========================================================================

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#b30000'
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) DEFAULT 'General'
) ENGINE=InnoDB");

$pdo->exec("CREATE TABLE IF NOT EXISTS scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    event_id INT NOT NULL,
    points INT DEFAULT 0,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE(team_id, event_id)
) ENGINE=InnoDB");

// Auto-inject default Admin and User if the system is completely new
if ($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() == 0) {
    $admin_pw = password_hash('admin123', PASSWORD_DEFAULT);
    $user_pw = password_hash('user123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (username, password, role) VALUES ('admin', '$admin_pw', 'admin'), ('user', '$user_pw', 'user')");
}

// Auto-Update Legacy Database Schema for Categories (Safe Patch)
try {
    $pdo->exec("ALTER TABLE events ADD COLUMN category VARCHAR(50) DEFAULT 'General'");
} catch (PDOException $e) {
    // Column already exists, safe to ignore
}

// ========================================================================
// --- APPLICATION LOGIC & ROUTING ---
// ========================================================================

// Logout handling
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Login handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php?page=dashboard");
        exit;
    } else {
        $login_error = "Invalid username or password!";
    }
}

// Require login routing
if (!isset($_SESSION['user_id'])) {
    $page = 'login';
} else {
    $page = $_GET['page'] ?? 'dashboard';
}

// Role-based Access Control
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// --- EXPORT TO EXCEL (CSV) GENERATOR ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'export_leaderboard') {
        $catFilter = $_GET['category'] ?? '';
        $filename = 'Leaderboard_Report_' . ($catFilter ? $catFilter . '_' : '') . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel compatibility
        
        fputcsv($output, ['Rank', 'Team Name', 'Total Points' . ($catFilter ? " ($catFilter)" : "")]);
        
        if ($catFilter) {
            $stmt = $pdo->prepare("SELECT t.name, COALESCE(SUM(fs.points), 0) as total_points FROM teams t LEFT JOIN (SELECT s.team_id, s.points FROM scores s JOIN events e ON s.event_id = e.id WHERE e.category = ?) fs ON t.id = fs.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
            $stmt->execute([$catFilter]);
        } else {
            $stmt = $pdo->query("SELECT t.name, COALESCE(SUM(s.points), 0) as total_points FROM teams t LEFT JOIN scores s ON t.id = s.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
        }
        
        $rank = 1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$rank++, $row['name'], $row['total_points']]);
        }
        fclose($output);
        exit;
    }
    
    if ($_GET['action'] == 'export_scores' && $isAdmin) {
        $catFilter = $_GET['category'] ?? '';
        $filename = 'Detailed_Scores_' . ($catFilter ? $catFilter . '_' : '') . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
        
        fputcsv($output, ['Category', 'Event Name', 'Team Name', 'Points Awarded']);
        
        if ($catFilter) {
            $stmt = $pdo->prepare("SELECT e.category, e.name as event_name, t.name as team_name, s.points FROM scores s JOIN events e ON s.event_id = e.id JOIN teams t ON s.team_id = t.id WHERE e.category = ? ORDER BY e.category ASC, e.name ASC, s.points DESC");
            $stmt->execute([$catFilter]);
        } else {
            $stmt = $pdo->query("SELECT e.category, e.name as event_name, t.name as team_name, s.points FROM scores s JOIN events e ON s.event_id = e.id JOIN teams t ON s.team_id = t.id ORDER BY e.category ASC, e.name ASC, s.points DESC");
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [$row['category'], $row['event_name'], $row['team_name'], $row['points']]);
        }
        fclose($output);
        exit;
    }
}

// --- SECURE CRUD OPERATIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isAdmin) {
    
    // Adds
    if ($_POST['action'] == 'add_team') {
        $stmt = $pdo->prepare("INSERT INTO teams (name, color) VALUES (?, ?)");
        $stmt->execute([$_POST['name'], $_POST['color']]);
        header("Location: index.php?page=teams&success=Team successfully added!");
        exit;
    }
    if ($_POST['action'] == 'add_event') {
        $stmt = $pdo->prepare("INSERT INTO events (name, description, category) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['description'], $_POST['category'] ?? 'General']);
        header("Location: index.php?page=events&success=Event successfully added!");
        exit;
    }
    if ($_POST['action'] == 'add_score') {
        $stmt = $pdo->prepare("INSERT INTO scores (team_id, event_id, points) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE points = ?");
        $stmt->execute([$_POST['team_id'], $_POST['event_id'], $_POST['points'], $_POST['points']]);
        header("Location: index.php?page=scores&success=Score successfully recorded!");
        exit;
    }
    if ($_POST['action'] == 'tally_score') {
        $inc = (int)$_POST['points'];
        $stmt = $pdo->prepare("INSERT INTO scores (team_id, event_id, points) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE points = points + ?");
        $stmt->execute([$_POST['team_id'], $_POST['event_id'], $inc, $inc]);
        header("Location: index.php?page=tally&event_id=" . $_POST['event_id'] . "&success=Tally successfully updated!");
        exit;
    }

    // Edits
    if ($_POST['action'] == 'edit_team') {
        $stmt = $pdo->prepare("UPDATE teams SET name = ?, color = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $_POST['color'], $_POST['id']]);
        header("Location: index.php?page=teams&success=Team successfully updated!");
        exit;
    }
    if ($_POST['action'] == 'edit_event') {
        $stmt = $pdo->prepare("UPDATE events SET name = ?, description = ?, category = ? WHERE id = ?");
        $stmt->execute([$_POST['name'], $_POST['description'], $_POST['category'] ?? 'General', $_POST['id']]);
        header("Location: index.php?page=events&success=Event successfully updated!");
        exit;
    }

    // Deletes (With Security Validations)
    if ($_POST['action'] == 'delete_team') {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (password_verify($_POST['admin_password'], $user['password'])) {
            $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            header("Location: index.php?page=teams&success=Team and related scores removed!");
            exit;
        } else {
            header("Location: index.php?page=teams&error=Incorrect admin password! Team deletion canceled.");
            exit;
        }
    }
    if ($_POST['action'] == 'delete_event') {
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header("Location: index.php?page=events&success=Event and related scores removed!");
        exit;
    }
    if ($_POST['action'] == 'delete_score') {
        $stmt = $pdo->prepare("DELETE FROM scores WHERE team_id = ? AND event_id = ?");
        $stmt->execute([$_POST['team_id'], $_POST['event_id']]);
        header("Location: index.php?page=scores&success=Score record removed!");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Event Tally System</title>
    
    <!-- Added Favicon Logo -->
    <link rel="icon" href="logo.png" type="image/png">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Chart.js for Bar Graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-red: #b30000;
            --primary-red-hover: #8a0000;
            --bg-color: #f4f7f6;
            --text-main: #2c3e50;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-color); 
            color: var(--text-main); 
            overflow-x: hidden; 
        }
        
        /* Theme Overrides */
        .text-theme { color: var(--primary-red) !important; }
        .bg-theme { background-color: var(--primary-red) !important; }
        .btn-theme { background-color: var(--primary-red); color: white; border: none; font-weight: 500; transition: all 0.3s ease; }
        .btn-theme:hover { background-color: var(--primary-red-hover); color: white; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(179,0,0,0.2); }
        .btn-outline-theme { border-color: var(--primary-red); color: var(--primary-red); }
        .btn-outline-theme:hover { background-color: var(--primary-red); color: white; }
        
        /* Layout Structure */
        #wrapper { display: flex; width: 100%; align-items: stretch; min-height: 100vh; }
        
        /* Sidebar Styles */
        #sidebar {
            min-width: 260px;
            max-width: 260px;
            background: linear-gradient(180deg, #990000 0%, #660000 100%);
            color: #fff;
            transition: all 0.3s;
            box-shadow: 4px 0 15px rgba(0,0,0,0.05);
            z-index: 10;
        }
        .sidebar-header { padding: 1.5rem; background: rgba(0,0,0,0.1); margin-bottom: 1rem; }
        #sidebar .nav-link {
            color: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            padding: 0.8rem 1.2rem;
            transition: all 0.2s;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        #sidebar .nav-link i { margin-right: 12px; font-size: 1.2rem; }
        #sidebar .nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
        #sidebar .nav-link.active { background: #fff; color: var(--primary-red); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        /* Main Content & Cards */
        #page-content { width: 100%; padding: 2rem 3rem; }
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.04); transition: transform 0.2s ease; }
        .card-hover:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        
        /* Tables */
        .table-custom { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.04); }
        .table-custom table { margin-bottom: 0; }
        .table-custom th { border-bottom: 2px solid #edf2f7; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; color: #718096; padding: 1rem; }
        .table-custom td { padding: 1rem; vertical-align: middle; border-bottom: 1px solid #edf2f7; }
        .table-custom tbody tr:hover { background-color: #f8fafc; }
        
        /* Form Inputs */
        .form-control, .form-select { border-radius: 8px; border: 1px solid #dee2e6; padding: 0.6rem 1rem; }
        .form-control:focus, .form-select:focus { border-color: var(--primary-red); box-shadow: 0 0 0 0.25rem rgba(179,0,0,0.15); }
        
        /* Login Card & Matrix Canvas */
        .login-wrapper { position: relative; background-color: var(--bg-color); min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        #matrixCanvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; opacity: 0.7; }
        .login-wrapper .container, .login-wrapper .container-fluid { position: relative; z-index: 1; }
        .login-card { border-radius: 1rem; box-shadow: 0 20px 40px rgba(0,0,0,0.15); overflow: hidden; background: rgba(255, 255, 255, 0.90); backdrop-filter: blur(8px); }

        /* Color Dot Indicator */
        .color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 0 1px rgba(0,0,0,0.1); }

        @media (max-width: 768px) {
            #wrapper { flex-direction: column; }
            #sidebar { min-width: 100%; min-height: auto; }
            #page-content { padding: 1.5rem; }
        }
    </style>
</head>
<body>

<?php if($page == 'login'): ?>
    <!-- ======================= LOGIN VIEW ======================= -->
    <?php
    // Prepare Data for Login Leaderboard Graph
    $stmt = $pdo->query("SELECT t.name, t.color, COALESCE(SUM(s.points), 0) as total_points FROM teams t LEFT JOIN scores s ON t.id = s.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
    $loginLeaderboard = $stmt->fetchAll();
    
    $loginTeamNames = [];
    $loginTeamPoints = [];
    $loginTeamColors = [];
    
    foreach($loginLeaderboard as $row) {
        $loginTeamNames[] = $row['name'];
        $loginTeamPoints[] = $row['total_points'];
        $loginTeamColors[] = $row['color'];
    }
    ?>
    <div class="login-wrapper py-4 py-lg-5">
        <!-- Red & White Matrix Canvas -->
        <canvas id="matrixCanvas"></canvas>
        
        <!-- Expanded Widescreen Container -->
        <div class="container-fluid px-4 px-xl-5" style="max-width: 1600px;">
            <div class="row justify-content-center align-items-stretch g-4 g-xl-5">
                
                <!-- Live Leaderboard Graph on Landing Page (Left Side) -->
                <div class="col-lg-7 col-xl-8 mb-5 mb-lg-0 order-2 order-lg-1 d-flex flex-column">
                    
                    <!-- Landing Page Header -->
                    <div class="text-center text-lg-start mb-4">
                        <h1 class="fw-bold display-4 text-dark" style="text-shadow: 0 0 25px rgba(255,255,255,1), 0 0 10px rgba(255,255,255,0.8); letter-spacing: -1px;">Live Event Leaderboard</h1>
                        <p class="fs-4 text-dark fw-medium" style="text-shadow: 0 0 15px rgba(255,255,255,1);">Track real-time points, rankings, and tournament results.</p>
                    </div>
                    
                    <!-- Chart Card -->
                    <div class="card p-4 p-xl-5 border-0 shadow-lg flex-grow-1" style="background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(12px); border-radius: 1.5rem;">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h3 class="fw-bold text-dark mb-0"><i class="bi bi-bar-chart-fill text-theme me-2"></i> Current Standings</h3>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill fs-6 shadow-sm">
                                <i class="bi bi-broadcast me-1"></i> LIVE
                            </span>
                        </div>
                        <?php if(count($loginLeaderboard) > 0): ?>
                            <!-- Taller Chart Container -->
                            <div style="height: 50vh; min-height: 450px; width: 100%;">
                                <canvas id="landingLeaderboardChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="text-muted text-center py-5 rounded-4 border border-dashed flex-grow-1 d-flex flex-column justify-content-center">
                                <i class="bi bi-inbox fs-1 text-muted opacity-50 mb-3"></i>
                                <p class="mb-0 fs-5">No teams registered yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Login Form (Right Side) -->
                <div class="col-md-8 col-lg-5 col-xl-4 order-1 order-lg-2 d-flex">
                    <div class="card login-card p-4 p-xl-5 w-100 d-flex flex-column justify-content-center shadow-lg" style="border-radius: 1.5rem; background: rgba(255, 255, 255, 0.92);">
                        <div class="card-body p-2">
                            <div class="text-center mb-5">
                                <img src="logo.png" alt="CET Logo" style="width: 120px; height: 120px; object-fit: contain; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.1));" class="mb-3">
                                <h3 class="fw-bold text-dark mb-1">CET-TALLY</h3>
                                <p class="text-muted fs-6">Sign in to manage events</p>
                            </div>
                            
                            <?php if(isset($login_error)): ?>
                                <div class="alert alert-danger text-center shadow-sm rounded-3 py-2 text-sm"><i class="bi bi-exclamation-circle me-2"></i><?= $login_error ?></div>
                            <?php endif; ?>

                            <form method="POST">
                                <input type="hidden" name="login_submit" value="1">
                                <div class="form-floating mb-3">
                                    <input type="text" class="form-control form-control-lg bg-light border-0 shadow-none" id="username" name="username" placeholder="Username" required>
                                    <label for="username" class="text-muted">Username</label>
                                </div>
                                <div class="form-floating mb-4">
                                    <input type="password" class="form-control form-control-lg bg-light border-0 shadow-none" id="password" name="password" placeholder="Password" required>
                                    <label for="password" class="text-muted">Password</label>
                                </div>
                                <button type="submit" class="btn btn-theme btn-lg w-100 py-3 mb-3 rounded-pill fw-bold shadow-sm">
                                    Sign In <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </form>
                            
                            <div class="mt-4 pt-4 border-top text-center">
                                <small class="text-muted d-block mb-3 fw-medium">System Accounts</small>
                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                    <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill"><i class="bi bi-shield-lock text-danger me-1"></i> admin / admin123</span>
                                    <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill"><i class="bi bi-person text-secondary me-1"></i> user / user123</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Landing Page Leaderboard Chart Initialization
            <?php if(count($loginLeaderboard) > 0): ?>
            document.addEventListener("DOMContentLoaded", function() {
                const ctxLanding = document.getElementById('landingLeaderboardChart').getContext('2d');
                new Chart(ctxLanding, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($loginTeamNames) ?>,
                        datasets: [{
                            label: 'Total Points',
                            data: <?= json_encode($loginTeamPoints) ?>,
                            backgroundColor: <?= json_encode($loginTeamColors) ?>,
                            borderColor: <?= json_encode($loginTeamColors) ?>,
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleFont: { size: 14, family: "'Inter', sans-serif" },
                                bodyFont: { size: 14, family: "'Inter', sans-serif", weight: 'bold' },
                                padding: 12,
                                callbacks: {
                                    label: function(context) {
                                        return ' ' + context.parsed.y + ' Points';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: '#edf2f7', drawBorder: false },
                                ticks: { font: { family: "'Inter', sans-serif", size: 14 }, color: '#718096', precision: 0 }
                            },
                            x: {
                                grid: { display: false, drawBorder: false },
                                ticks: { font: { family: "'Inter', sans-serif", size: 15, weight: '600' }, color: '#2c3e50' }
                            }
                        },
                        animation: { duration: 1500, easing: 'easeOutQuart' }
                    }
                });
            });
            <?php endif; ?>

            // Matrix Digital Rain Effect - Red & White Theme
            const canvas = document.getElementById('matrixCanvas');
            const ctx = canvas.getContext('2d');

            function resizeCanvas() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            const matrixChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%^&*()<>[]{}';
            const fontSize = 16;
            let columns = Math.floor(canvas.width / fontSize);
            let drops = [];

            // Initialize drops with random vertical positions to scatter the starting rain
            for (let x = 0; x < columns; x++) {
                drops[x] = Math.random() * canvas.height;
            }

            function drawMatrix() {
                // Re-calculate columns in case of browser resize
                columns = Math.floor(canvas.width / fontSize);
                while (drops.length < columns) drops.push(Math.random() * canvas.height);

                // Fading white background for the trail effect
                ctx.fillStyle = 'rgba(244, 247, 246, 0.15)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Red text color
                ctx.fillStyle = '#b30000';
                ctx.font = fontSize + 'px monospace';

                for (let i = 0; i < drops.length; i++) {
                    const text = matrixChars.charAt(Math.floor(Math.random() * matrixChars.length));
                    ctx.fillText(text, i * fontSize, drops[i] * fontSize);

                    if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
                        drops[i] = 0;
                    }
                    drops[i]++;
                }
            }

            setInterval(drawMatrix, 50);
        </script>
    </div>
<?php else: ?>

    <!-- ======================= MAIN LAYOUT ======================= -->
    <div id="wrapper">
        
        <!-- Sidebar Panel -->
        <nav id="sidebar" class="d-flex flex-column">
            <div class="sidebar-header text-center">
                <!-- Replaced trophy icon with your custom logo in the sidebar -->
                <img src="logo.png" alt="CET Logo" style="width: 65px; height: 65px; object-fit: contain;" class="mb-2 bg-white rounded-circle p-1 shadow-sm">
                <h5 class="fw-bold m-0 d-flex align-items-center justify-content-center mt-1">
                    CET-TALLY SYSTEM
                </h5>
            </div>
            
            <div class="px-3 flex-grow-1">
                <ul class="nav flex-column mb-auto">
                    <li class="nav-item">
                        <a href="index.php?page=dashboard" class="nav-link <?= $page=='dashboard'?'active':'' ?>">
                            <i class="bi bi-grid-1x2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="index.php?page=results" class="nav-link <?= $page=='results'?'active':'' ?>">
                            <i class="bi bi-file-earmark-bar-graph"></i> Results & Reports
                        </a>
                    </li>
                    <?php if($isAdmin): ?>
                        <li class="mt-3 mb-1 text-uppercase text-white-50 small fw-bold px-3" style="letter-spacing: 1px; font-size: 0.75rem;">Management</li>
                        <li class="nav-item">
                            <a href="index.php?page=teams" class="nav-link <?= $page=='teams'?'active':'' ?>">
                                <i class="bi bi-people"></i> Teams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="index.php?page=events" class="nav-link <?= $page=='events'?'active':'' ?>">
                                <i class="bi bi-calendar-event"></i> Events
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="index.php?page=tally" class="nav-link <?= $page=='tally'?'active':'' ?>">
                                <i class="bi bi-calculator"></i> Live Tally
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="index.php?page=scores" class="nav-link <?= $page=='scores'?'active':'' ?>">
                                <i class="bi bi-123"></i> Manual Points
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <!-- Page Content -->
        <div id="page-content">
            
            <!-- Top Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <h3 class="m-0 fw-bold text-dark">
                    <?php 
                        if($page == 'dashboard') echo 'Dashboard Overview';
                        elseif($page == 'results') echo 'Results & Reports';
                        elseif($page == 'teams') echo 'Team Management';
                        elseif($page == 'events') echo 'Event Management';
                        elseif($page == 'tally') echo 'Live Tally Board';
                        elseif($page == 'scores') echo 'Manual Point Entry';
                    ?>
                </h3>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle rounded-pill border shadow-sm px-3 d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                        <div class="bg-theme rounded-circle text-white d-inline-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px; font-size: 0.8rem;">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </div>
                        <span class="fw-medium me-1"><?= htmlspecialchars($_SESSION['username']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                        <li><h6 class="dropdown-header">Role: <?= ucfirst($_SESSION['role']) ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="index.php?action=logout"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>

            <!-- Alerts -->
            <?php if(isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-4 border-success rounded-3" role="alert">
                    <i class="bi bi-check-circle-fill text-success me-2"></i> <?= htmlspecialchars($_GET['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-4 border-danger rounded-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i> <?= htmlspecialchars($_GET['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if($page == 'dashboard'): ?>
                <!-- ======================= DASHBOARD ======================= -->
                <?php
                $totalTeams = $pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn();
                $totalEvents = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
                $totalPoints = $pdo->query("SELECT SUM(points) FROM scores")->fetchColumn() ?? 0;
                ?>
                
                <!-- Quick Stats -->
                <div class="row g-4 mb-5">
                    <div class="col-md-4">
                        <div class="card card-hover h-100 p-4 border-start border-4 border-primary">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted fw-semibold mb-1">Total Teams</h6>
                                    <h2 class="fw-bold mb-0 text-dark"><?= $totalTeams ?></h2>
                                </div>
                                <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                                    <i class="bi bi-people fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card card-hover h-100 p-4 border-start border-4 border-warning">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted fw-semibold mb-1">Total Events</h6>
                                    <h2 class="fw-bold mb-0 text-dark"><?= $totalEvents ?></h2>
                                </div>
                                <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                                    <i class="bi bi-calendar-event fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card card-hover h-100 p-4 border-start border-4 border-success">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="text-muted fw-semibold mb-1">Total Points Awarded</h6>
                                    <h2 class="fw-bold mb-0 text-dark"><?= $totalPoints ?></h2>
                                </div>
                                <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                                    <i class="bi bi-graph-up-arrow fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Leaderboard Data Prep -->
                <?php
                $stmt = $pdo->query("SELECT t.name, t.color, COALESCE(SUM(s.points), 0) as total_points FROM teams t LEFT JOIN scores s ON t.id = s.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
                $leaderboard = $stmt->fetchAll();
                
                $teamNames = [];
                $teamPoints = [];
                $teamColors = [];
                
                foreach($leaderboard as $row) {
                    $teamNames[] = $row['name'];
                    $teamPoints[] = $row['total_points'];
                    $teamColors[] = $row['color'];
                }
                ?>

                <!-- Graphical Leaderboard (Bar Graph) -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-bar-chart-fill text-theme me-2"></i> Overall Leaderboard</h5>
                </div>

                <div class="card p-4 shadow-sm border-0 mb-5 bg-white position-relative">
                    <?php if(count($leaderboard) > 0): ?>
                        <div style="height: 400px; width: 100%;">
                            <canvas id="leaderboardChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="text-muted text-center py-5 rounded-4 border border-dashed">
                            <i class="bi bi-inbox fs-1 text-muted opacity-50 mb-2"></i>
                            <p class="mb-0">No teams registered yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Specific Events Breakdown -->
                <h5 class="fw-bold text-dark mb-4"><i class="bi bi-star text-theme me-2"></i> Event Highlights</h5>
                <div class="row g-4">
                    <?php
                    $eventsData = $pdo->query("SELECT * FROM events")->fetchAll();
                    foreach($eventsData as $ed):
                        $topTeam = $pdo->prepare("SELECT t.name, t.color, s.points FROM scores s JOIN teams t ON s.team_id = t.id WHERE s.event_id = ? ORDER BY s.points DESC LIMIT 1");
                        $topTeam->execute([$ed['id']]);
                        $top = $topTeam->fetch();
                    ?>
                    <div class="col-md-6">
                        <div class="card h-100 p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="d-flex align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 text-dark me-2"><?= htmlspecialchars($ed['name']) ?></h6>
                                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.65rem; font-weight: 500;"><?= htmlspecialchars($ed['category'] ?? 'General') ?></span>
                                    </div>
                                    <?php if($top): ?>
                                        <div class="d-flex align-items-center">
                                            <span class="text-muted small me-2">Current Leader:</span>
                                            <span class="color-dot" style="background-color: <?= $top['color'] ?>"></span>
                                            <strong class="small text-dark"><?= htmlspecialchars($top['name']) ?></strong>
                                        </div>
                                    <?php else: ?>
                                        <small class="text-muted fst-italic">Pending results</small>
                                    <?php endif; ?>
                                </div>
                                <?php if($top): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fs-6"><?= $top['points'] ?> pts</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Initialize Chart.js -->
                <?php if(count($leaderboard) > 0): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const ctx = document.getElementById('leaderboardChart').getContext('2d');
                        
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: <?= json_encode($teamNames) ?>,
                                datasets: [{
                                    label: 'Total Points',
                                    data: <?= json_encode($teamPoints) ?>,
                                    backgroundColor: <?= json_encode($teamColors) ?>,
                                    borderColor: <?= json_encode($teamColors) ?>,
                                    borderWidth: 1,
                                    borderRadius: 6, /* Rounded corners on top of bars */
                                    borderSkipped: false
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: false // Hide the generic "Total Points" legend box
                                    },
                                    tooltip: {
                                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                        titleFont: { size: 14, family: "'Inter', sans-serif" },
                                        bodyFont: { size: 14, family: "'Inter', sans-serif", weight: 'bold' },
                                        padding: 12,
                                        callbacks: {
                                            label: function(context) {
                                                return ' ' + context.parsed.y + ' Points';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: '#edf2f7',
                                            drawBorder: false
                                        },
                                        ticks: {
                                            font: { family: "'Inter', sans-serif", size: 12 },
                                            color: '#718096',
                                            precision: 0 // Avoid decimal points
                                        }
                                    },
                                    x: {
                                        grid: { display: false, drawBorder: false },
                                        ticks: {
                                            font: { family: "'Inter', sans-serif", size: 13, weight: '500' },
                                            color: '#2c3e50'
                                        }
                                    }
                                },
                                animation: {
                                    duration: 1500,
                                    easing: 'easeOutQuart'
                                }
                            }
                        });
                    });
                </script>
                <?php endif; ?>

            <?php elseif($page == 'results'): ?>
                <!-- ======================= RESULTS & REPORTS ======================= -->
                <?php
                $catFilter = $_GET['category'] ?? '';
                // Fetch distinct categories available
                $categories = $pdo->query("SELECT DISTINCT category FROM events WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
                ?>
                
                <!-- Category Filter Selection -->
                <div class="d-flex align-items-center gap-2 mb-4 overflow-auto pb-2">
                    <span class="text-muted small fw-bold text-uppercase me-2"><i class="bi bi-funnel-fill me-1"></i> Filter:</span>
                    <a href="index.php?page=results" class="btn btn-sm <?= empty($catFilter) ? 'btn-theme shadow-sm' : 'bg-white border text-muted' ?> rounded-pill px-4 fw-medium transition-all">All Categories</a>
                    <?php foreach($categories as $c): ?>
                        <a href="index.php?page=results&category=<?= urlencode($c) ?>" class="btn btn-sm <?= $catFilter === $c ? 'btn-theme shadow-sm' : 'bg-white border text-muted' ?> rounded-pill px-4 fw-medium transition-all"><?= htmlspecialchars($c) ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="row g-4">
                    <!-- Overall Leaderboard Data -->
                    <div class="col-12">
                        <div class="card p-4 shadow-sm border-0">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="fw-bold text-dark mb-0">
                                    <i class="bi bi-trophy text-theme me-2"></i> 
                                    <?= empty($catFilter) ? 'Overall Leaderboard Data' : htmlspecialchars($catFilter) . ' Leaderboard' ?>
                                </h5>
                                <a href="index.php?action=export_leaderboard&category=<?= urlencode($catFilter) ?>" class="btn btn-success rounded-pill px-4 shadow-sm fw-medium">
                                    <i class="bi bi-file-earmark-excel me-1"></i> Export Leaderboard
                                </a>
                            </div>
                            <div class="table-custom">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="10%">Rank</th>
                                            <th width="50%">Team Name</th>
                                            <th width="40%">Total Points</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($catFilter) {
                                            $stmt = $pdo->prepare("SELECT t.name, t.color, COALESCE(SUM(fs.points), 0) as total_points FROM teams t LEFT JOIN (SELECT s.team_id, s.points FROM scores s JOIN events e ON s.event_id = e.id WHERE e.category = ?) fs ON t.id = fs.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
                                            $stmt->execute([$catFilter]);
                                        } else {
                                            $stmt = $pdo->query("SELECT t.name, t.color, COALESCE(SUM(s.points), 0) as total_points FROM teams t LEFT JOIN scores s ON t.id = s.team_id GROUP BY t.id ORDER BY total_points DESC, t.name ASC");
                                        }
                                        
                                        $rank = 1;
                                        $hasData = false;
                                        while($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                                            $hasData = true;
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-light text-muted border px-3 py-2 rounded-pill fw-medium">#<?= $rank++ ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="color-dot" style="background-color: <?= $row['color'] ?>"></span>
                                                    <span class="fw-medium text-dark fs-6"><?= htmlspecialchars($row['name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="fw-bold text-theme fs-5"><?= $row['total_points'] ?> pts</td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if(!$hasData): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No data available.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Score Breakdown -->
                    <div class="col-12">
                        <div class="card p-4 shadow-sm border-0 mt-2">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-columns-reverse text-theme me-2"></i> Detailed Score Breakdown</h5>
                                <?php if($isAdmin): ?>
                                <a href="index.php?action=export_scores&category=<?= urlencode($catFilter) ?>" class="btn btn-success rounded-pill px-4 shadow-sm fw-medium">
                                    <i class="bi bi-file-earmark-excel me-1"></i> Export Detailed Scores
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="table-custom">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="15%">Category</th>
                                            <th width="30%">Event</th>
                                            <th width="35%">Team</th>
                                            <th width="20%">Points Awarded</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($catFilter) {
                                            $stmt = $pdo->prepare("SELECT s.team_id, s.event_id, e.name as event_name, e.category, t.name as team_name, s.points, t.color FROM scores s JOIN events e ON s.event_id = e.id JOIN teams t ON s.team_id = t.id WHERE e.category = ? ORDER BY e.name ASC, s.points DESC");
                                            $stmt->execute([$catFilter]);
                                            $scores = $stmt->fetchAll();
                                        } else {
                                            $scores = $pdo->query("SELECT s.team_id, s.event_id, e.name as event_name, e.category, t.name as team_name, s.points, t.color FROM scores s JOIN events e ON s.event_id = e.id JOIN teams t ON s.team_id = t.id ORDER BY e.category ASC, e.name ASC, s.points DESC")->fetchAll();
                                        }
                                        
                                        if(count($scores) > 0):
                                            $currentCategory = null;
                                            foreach($scores as $s): 
                                                // Check if the category has changed to insert a separator row
                                                if ($s['category'] !== $currentCategory):
                                                    $currentCategory = $s['category'];
                                        ?>
                                            <tr style="background-color: #f8fafc;">
                                                <td colspan="4" class="fw-bold text-secondary py-2 border-bottom border-2">
                                                    <i class="bi bi-tag-fill text-theme me-2 opacity-75"></i> 
                                                    <span class="text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem;">
                                                        <?= htmlspecialchars($currentCategory ?? 'General') ?> Events
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php   endif; ?>
                                            <tr>
                                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 rounded-pill"><?= htmlspecialchars($s['category'] ?? 'General') ?></span></td>
                                                <td class="fw-medium text-dark"><?= htmlspecialchars($s['event_name']) ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="color-dot" style="background-color: <?= $s['color'] ?>"></span>
                                                        <span class="fw-medium text-dark"><?= htmlspecialchars($s['team_name']) ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fs-6">
                                                        +<?= $s['points'] ?> pts
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach;
                                        else: ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4">No scores recorded yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif($page == 'teams' && $isAdmin): ?>
                <!-- ======================= TEAMS ======================= -->
                <div class="card p-4 mb-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Register New Team</h6>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_team">
                        <div class="row align-items-end g-3">
                            <div class="col-md-5">
                                <label class="form-label text-muted small fw-semibold">Team / College Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g., College of Engineering" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Identifier Color</label>
                                <div class="d-flex align-items-center">
                                    <input type="color" name="color" class="form-control form-control-color" value="#0d6efd" required style="max-width: 60px;">
                                    <span class="ms-2 small text-muted">Used for charts & badges</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-theme w-100"><i class="bi bi-check2 me-1"></i> Save Team</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="table-custom">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th width="10%">ID</th><th>Team Name</th><th width="20%">Color</th><th width="15%" class="text-end">Action</th></tr></thead>
                        <tbody>
                            <?php foreach($pdo->query("SELECT * FROM teams")->fetchAll() as $t): ?>
                            <tr>
                                <td class="text-muted">#<?= $t['id'] ?></td>
                                <td class="fw-medium text-dark"><?= htmlspecialchars($t['name']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="color-dot" style="background-color: <?= $t['color'] ?>"></span>
                                        <span class="small text-muted text-uppercase"><?= $t['color'] ?></span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-pill me-1" 
                                            onclick="openEditTeamModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['name'])) ?>', '<?= $t['color'] ?>')">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-pill"
                                            onclick="openDeleteTeamModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['name'])) ?>')">
                                        <i class="bi bi-trash3"></i> Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif($page == 'events' && $isAdmin): ?>
                <!-- ======================= EVENTS ======================= -->
                <div class="card p-4 mb-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Create New Event</h6>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_event">
                        <div class="row align-items-end g-3">
                            <div class="col-md-3">
                                <label class="form-label text-muted small fw-semibold">Event Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g., Debate Championship" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small fw-semibold">Category</label>
                                <select name="category" class="form-select" required>
                                    <option value="" disabled selected>-- Select --</option>
                                    <option value="Academic">Academic</option>
                                    <option value="Sports">Sports</option>
                                    <option value="eSports">eSports</option>
                                    <option value="Cultural">Cultural</option>
                                    <option value="Pageant">Pageant</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold">Description (Optional)</label>
                                <input type="text" name="description" class="form-control" placeholder="Rules, criteria, etc.">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-theme w-100"><i class="bi bi-check2 me-1"></i> Save Event</button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="table-custom">
                    <table class="table table-hover">
                        <thead class="table-light"><tr><th width="30%">Event Name</th><th width="15%">Category</th><th>Description</th><th width="20%" class="text-end">Action</th></tr></thead>
                        <tbody>
                            <?php foreach($pdo->query("SELECT * FROM events")->fetchAll() as $e): ?>
                            <tr>
                                <td class="fw-medium text-dark"><?= htmlspecialchars($e['name']) ?></td>
                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 rounded-pill"><?= htmlspecialchars($e['category'] ?? 'General') ?></span></td>
                                <td class="text-muted small"><?= htmlspecialchars($e['description']) ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-pill me-1" 
                                            onclick="openEditEventModal(<?= $e['id'] ?>, '<?= htmlspecialchars(addslashes($e['name'])) ?>', '<?= htmlspecialchars(addslashes($e['description'])) ?>', '<?= htmlspecialchars(addslashes($e['category'] ?? 'General')) ?>')">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <form method="POST" onsubmit="return confirm('WARNING: Deleting this event removes all scores associated with it. Proceed?');" style="display:inline-block;">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger px-3 rounded-pill"><i class="bi bi-trash3"></i> Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif($page == 'tally' && $isAdmin): ?>
                <!-- ======================= LIVE TALLY ======================= -->
                <div class="card p-4 mb-4 border-start border-4 border-primary shadow-sm">
                    <form method="GET" class="row align-items-end g-3">
                        <input type="hidden" name="page" value="tally">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-semibold">Select Event to Tally</label>
                            <select name="event_id" class="form-select border-primary" onchange="this.form.submit()" required>
                                <option value="" disabled selected>-- Choose an Event --</option>
                                <?php foreach($pdo->query("SELECT * FROM events")->fetchAll() as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= (isset($_GET['event_id']) && $_GET['event_id'] == $e['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($e['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>

                <?php if(isset($_GET['event_id'])): ?>
                    <?php
                    $eventId = (int)$_GET['event_id'];
                    $stmt = $pdo->query("SELECT name, category FROM events WHERE id = $eventId");
                    $eventData = $stmt->fetch();
                    $eventName = $eventData['name'];
                    $eventCat = $eventData['category'] ?? 'General';
                    $teams = $pdo->query("SELECT * FROM teams ORDER BY name ASC")->fetchAll();
                    ?>
                    
                    <h5 class="fw-bold text-dark mb-4">
                        Tallying for: <span class="text-theme"><?= htmlspecialchars($eventName) ?></span>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary ms-2 align-middle border px-2 py-1 fs-6 rounded-pill"><?= htmlspecialchars($eventCat) ?></span>
                    </h5>
                    
                    <div class="row g-4">
                        <?php foreach($teams as $t): 
                            $stmt = $pdo->prepare("SELECT points FROM scores WHERE team_id = ? AND event_id = ?");
                            $stmt->execute([$t['id'], $eventId]);
                            $currentScore = $stmt->fetchColumn();
                            if($currentScore === false) $currentScore = 0;
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 shadow-sm card-hover" style="border-top: 5px solid <?= htmlspecialchars($t['color']) ?> !important;">
                                <div class="card-body text-center p-4">
                                    <h6 class="fw-bold mb-3 text-dark"><?= htmlspecialchars($t['name']) ?></h6>
                                    <h1 class="display-3 fw-bold text-theme mb-4"><?= $currentScore ?></h1>
                                    
                                    <div class="d-flex justify-content-center gap-2">
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="tally_score">
                                            <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="points" value="-5">
                                            <button type="submit" class="btn btn-outline-danger btn-sm fw-bold px-3">-5</button>
                                        </form>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="tally_score">
                                            <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="points" value="-1">
                                            <button type="submit" class="btn btn-outline-danger btn-sm fw-bold px-3">-1</button>
                                        </form>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="tally_score">
                                            <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="points" value="1">
                                            <button type="submit" class="btn btn-outline-success btn-sm fw-bold px-3">+1</button>
                                        </form>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="tally_score">
                                            <input type="hidden" name="team_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <input type="hidden" name="points" value="5">
                                            <button type="submit" class="btn btn-outline-success btn-sm fw-bold px-3">+5</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5 bg-white rounded-4 border border-dashed text-muted mt-4">
                        <i class="bi bi-hand-index-thumb fs-1 opacity-50 mb-2 d-block"></i>
                        <p class="mb-0">Please select an event from the dropdown above to start tallying.</p>
                    </div>
                <?php endif; ?>

            <?php elseif($page == 'scores' && $isAdmin): ?>
                <!-- ======================= SCORES ======================= -->
                <?php
                $teams = $pdo->query("SELECT * FROM teams")->fetchAll();
                $events = $pdo->query("SELECT * FROM events")->fetchAll();
                ?>
                
                <div class="card p-4 mb-4 border-start border-4 border-success">
                    <h6 class="fw-bold mb-3"><i class="bi bi-pencil-square me-2"></i>Record or Update Score</h6>
                    <form method="POST">
                         <input type="hidden" name="action" value="add_score">
                         <div class="row align-items-end g-3">
                             <div class="col-md-4">
                                 <label class="form-label text-muted small fw-semibold">Event</label>
                                 <select name="event_id" class="form-select" required>
                                     <option value="" disabled selected>-- Select Event --</option>
                                     <?php foreach($events as $e): ?>
                                         <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['name']) ?></option>
                                     <?php endforeach; ?>
                                 </select>
                             </div>
                             <div class="col-md-4">
                                 <label class="form-label text-muted small fw-semibold">Team</label>
                                 <select name="team_id" class="form-select" required>
                                     <option value="" disabled selected>-- Select Team --</option>
                                     <?php foreach($teams as $t): ?>
                                         <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                     <?php endforeach; ?>
                                 </select>
                             </div>
                             <div class="col-md-2">
                                 <label class="form-label text-muted small fw-semibold">Points</label>
                                 <input type="number" name="points" class="form-control" placeholder="e.g. 50" required>
                             </div>
                             <div class="col-md-2">
                                 <button type="submit" class="btn btn-success w-100"><i class="bi bi-check2 me-1"></i> Submit</button>
                             </div>
                         </div>
                    </form>
                </div>

                <h6 class="fw-bold mb-3 mt-4 text-dark">Recorded Scores History</h6>

                <div class="table-custom">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr><th width="30%">Event</th><th width="30%">Team</th><th width="20%">Points</th><th width="20%" class="text-end">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $scores = $pdo->query("SELECT s.team_id, s.event_id, e.name as event_name, e.category, t.name as team_name, s.points, t.color FROM scores s JOIN events e ON s.event_id = e.id JOIN teams t ON s.team_id = t.id ORDER BY e.category ASC, e.name ASC, s.points DESC")->fetchAll();
                            
                            $currentCategory = null;
                            foreach($scores as $s): 
                                // Insert separator row when category changes
                                if ($s['category'] !== $currentCategory):
                                    $currentCategory = $s['category'];
                            ?>
                            <tr style="background-color: #f8fafc;">
                                <td colspan="4" class="fw-bold text-secondary py-2 border-bottom border-2">
                                    <i class="bi bi-tag-fill text-theme me-2 opacity-75"></i> 
                                    <span class="text-uppercase" style="letter-spacing: 1px; font-size: 0.8rem;">
                                        <?= htmlspecialchars($currentCategory ?? 'General') ?> Events
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="fw-medium text-dark"><?= htmlspecialchars($s['event_name']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="color-dot" style="background-color: <?= $s['color'] ?>"></span>
                                        <span class="fw-medium text-dark"><?= htmlspecialchars($s['team_name']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fs-6">
                                        +<?= $s['points'] ?> pts
                                    </span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" onsubmit="return confirm('Remove this score record?');">
                                        <input type="hidden" name="action" value="delete_score">
                                        <input type="hidden" name="team_id" value="<?= $s['team_id'] ?>">
                                        <input type="hidden" name="event_id" value="<?= $s['event_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger px-3 rounded-pill"><i class="bi bi-x-circle me-1"></i> Remove</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif(!$isAdmin && in_array($page, ['teams', 'events', 'scores', 'tally'])): ?>
                <div class="d-flex flex-column align-items-center justify-content-center py-5 mt-5">
                    <div class="bg-danger bg-opacity-10 p-4 rounded-circle mb-4 text-danger">
                        <i class="bi bi-shield-lock fs-1"></i>
                    </div>
                    <h2 class="fw-bold text-dark mb-2">Access Denied</h2>
                    <p class="text-muted text-center" style="max-width: 400px;">Your user account does not have administrator privileges to view or modify this data.</p>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php endif; ?>

<!-- Team Management Modals -->
<?php if($page == 'teams' && $isAdmin): ?>
    <!-- Edit Team Modal -->
    <div class="modal fade" id="editTeamModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Team</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_team">
                        <input type="hidden" name="id" id="edit_team_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Team Name</label>
                            <input type="text" name="name" id="edit_team_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Identifier Color</label>
                            <div class="d-flex align-items-center">
                                <input type="color" name="color" id="edit_team_color" class="form-control form-control-color" required style="max-width: 60px;">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Team Password Modal -->
    <div class="modal fade" id="deleteTeamModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Security Verification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body text-center pt-4">
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="id" id="delete_team_id">
                        
                        <div class="mb-3">
                            <p class="mb-1">You are about to delete:</p>
                            <h4 class="fw-bold text-dark" id="delete_team_name_display">Team Name</h4>
                            <p class="text-danger small mt-2 fw-semibold">Warning: All scores associated with this team will be permanently lost.</p>
                        </div>
                        
                        <div class="form-floating mb-2 mt-4 text-start">
                            <input type="password" name="admin_password" class="form-control border-danger" id="admin_pw_input" placeholder="Admin Password" required>
                            <label for="admin_pw_input">Enter Admin Password to confirm</label>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 justify-content-center bg-light rounded-bottom">
                        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger px-4 fw-bold">Confirm Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditTeamModal(id, name, color) {
            document.getElementById('edit_team_id').value = id;
            document.getElementById('edit_team_name').value = name;
            document.getElementById('edit_team_color').value = color;
            var modal = new bootstrap.Modal(document.getElementById('editTeamModal'));
            modal.show();
        }

        function openDeleteTeamModal(id, name) {
            document.getElementById('delete_team_id').value = id;
            document.getElementById('delete_team_name_display').innerText = name;
            document.getElementById('admin_pw_input').value = ''; 
            var modal = new bootstrap.Modal(document.getElementById('deleteTeamModal'));
            modal.show();
        }
    </script>
<?php endif; ?>

<?php if($page == 'events' && $isAdmin): ?>
    <!-- Edit Event Modal -->
    <div class="modal fade" id="editEventModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_event">
                        <input type="hidden" name="id" id="edit_event_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Event Name</label>
                            <input type="text" name="name" id="edit_event_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Category</label>
                            <select name="category" id="edit_event_category" class="form-select" required>
                                <option value="Academic">Academic</option>
                                <option value="Sports">Sports</option>
                                <option value="eSports">eSports</option>
                                <option value="Cultural">Cultural</option>
                                <option value="Pageant">Pageant</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Description (Optional)</label>
                            <input type="text" name="description" id="edit_event_desc" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditEventModal(id, name, desc, category) {
            document.getElementById('edit_event_id').value = id;
            document.getElementById('edit_event_name').value = name;
            document.getElementById('edit_event_desc').value = desc;
            document.getElementById('edit_event_category').value = category;
            var modal = new bootstrap.Modal(document.getElementById('editEventModal'));
            modal.show();
        }
    </script>
<?php endif; ?>

<script>
    // --- Untampered Watermark Protection ---
    // This script automatically injects your signature and defends against Inspect Element deletion and CSS hiding.
    (function() {
        // Base64 Encoded to prevent simple "Ctrl+F" removal by beginners
        const wmHTML = atob('RGV2ZWxvcGVkIGJ5IDxzdHJvbmc+SmF5c29uU0M8L3N0cm9uZz4=');
        
        function enforceWatermark() {
            let el = document.getElementById('sys-wm-jsc');
            if (!el) {
                el = document.createElement('div');
                el.id = 'sys-wm-jsc';
                el.innerHTML = wmHTML;
                document.body.appendChild(el);
            }
            
            // Force inline styles securely so it can't be hidden via external CSS
            el.style.cssText = 'position:fixed;bottom:15px;right:15px;background:rgba(179,0,0,0.9);color:#fff;padding:8px 18px;border-radius:20px;font-family:"Inter",sans-serif;font-size:12px;z-index:2147483647;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,0.2);backdrop-filter:blur(5px);opacity:1!important;display:block!important;visibility:visible!important;transform:none!important;';
        }
        
        enforceWatermark(); // Initial render
        setInterval(enforceWatermark, 1000); // Aggressive continuous check
        
        // MutationObserver to instantly restore if removed from DOM via Inspect Element
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.removedNodes) {
                    for (let i = 0; i < mutation.removedNodes.length; i++) {
                        if (mutation.removedNodes[i].id === 'sys-wm-jsc') {
                            enforceWatermark();
                        }
                    }
                }
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    })();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>