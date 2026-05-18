<?php
session_start();

require_once __DIR__ . '/../../config/database.php';

$error = "";

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $username = trim($_POST['username']);
    $password_input = trim($_POST['password']);
    
    $query = "SELECT * FROM employee WHERE USERNAME = ? AND ROLE = 'Accountant'";
    $stmt = $conn->prepare($query);
    
    if(!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0){
        $employee = $result->fetch_assoc();
        
        if(password_verify($password_input, $employee['PASSWORD_HASH'])){
            $_SESSION['emp_id'] = $employee['EMP_ID'];
            $_SESSION['emp_username'] = $employee['USERNAME'];
            $_SESSION['emp_name'] = $employee['FULL_NAME'];
            $_SESSION['role'] = 'Accountant';
            header("Location: accountant_dashboard.php");
            exit;
        } else {
            $error = "Invalid username or password";
        }
    } else {
        $error = "Invalid username or password";
    }
    
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Accountant Login - Busiquip</title>
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
            animation: fadeIn 1s ease;
        }

        @keyframes fadeIn{
            from{opacity:0; transform:translateY(20px);}
            to{opacity:1; transform:translateY(0);}
        }

        h2 {
            font-size: 2rem;
            margin-bottom: 30px;
        }

        .role-badge {
            display: inline-block;
            background: #f59e0b;
            padding: 5px 15px;
            border-radius: 20px;
            margin-bottom: 20px;
            font-size: 0.9rem;
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
        <div class="role-badge">💼 ACCOUNTANT</div>
        <h2>Accountant Login</h2>
        
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

