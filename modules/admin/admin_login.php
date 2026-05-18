<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

$error = "";

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $username = trim($_POST['username']);
    $password_input = trim($_POST['password']);
    
    // Debug mode - remove after testing
    error_log("Login attempt - Username: " . $username . ", Password: " . $password_input);
    
    // Query the database
    $query = "SELECT * FROM admin WHERE USERNAME = ?";
    $stmt = $conn->prepare($query);
    
    if(!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0){
        $admin = $result->fetch_assoc();
        
        error_log("Admin found - Stored hash: " . $admin['PASSWORD_HASH']);
        error_log("Verifying password: " . $password_input . " against hash: " . $admin['PASSWORD_HASH']);
        
        // Verify the password
        if(password_verify($password_input, $admin['PASSWORD_HASH'])){
            error_log("Password verified successfully!");
            $_SESSION['admin_id'] = $admin['ADMIN_ID'];
            $_SESSION['admin_username'] = $admin['USERNAME'];
            $_SESSION['role'] = 'Admin';
            header("Location: admin_dashboard.php");
            exit;
        } else {
            error_log("Password verification failed!");
            $error = "Invalid username or password";
        }
    } else {
        error_log("Admin user not found");
        $error = "Invalid username or password";
    }
    
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root{
            --bg-dark:#0f172a;
            --primary:#16a34a;
            --secondary:#38bdf8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #0f172a, #1e293b);
        }

        .login-box {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            padding: 50px 40px;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            color: white;
            box-shadow: 0 12px 48px rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.2);
        }

        h2 {
            font-size: 2rem;
            margin-bottom: 30px;
        }

        input {
            width: 100%;
            padding: 15px;
            margin: 12px 0;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            transition: 0.3s;
        }

        input:focus {
            outline: none;
            box-shadow: 0 0 15px var(--primary);
        }

        button {
            width: 100%;
            padding: 15px;
            margin-top: 20px;
            background: linear-gradient(45deg, var(--primary), #22c55e);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 600;
            transition: 0.3s;
        }

        button:hover {
            transform: translateY(-3px);
        }

        button:active {
            transform: scale(0.97);
        }

        .error {
            color: #ef4444;
            margin-bottom: 20px;
            font-size: 1rem;
        }

        a {
            color: var(--secondary);
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            font-size: 1rem;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Login</h2>
        
        <?php if($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        
        <a href="<?= BASE_URL ?>/modules/staff/staff_login.php">Back to Staff Login</a>
    </div>
</body>
</html>


