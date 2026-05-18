<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
$error = "";

if(isset($_POST['role'])){
    $role = $_POST['role'];

    if($role == "Admin"){
        header("Location: " . BASE_URL . "/modules/admin/admin_login.php");
        exit;
    } elseif($role == "Technician"){
        header("Location: " . BASE_URL . "/modules/staff/technician_login.php");
        exit;
    } elseif($role == "Accountant"){
        header("Location: " . BASE_URL . "/modules/staff/accountant_login.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Busiquip Staff Login</title>

<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

<style>
:root{
    --bg-dark:#0f172a;
    --bg-light:#f1f5f9;
    --card:rgba(255,255,255,0.1);
    --text-dark:#fff;
    --text-light:#111;
    --primary:#16a34a;
    --secondary:#38bdf8;
}

/* RESET */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}

/* BODY */
body{
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background: linear-gradient(135deg,#0f172a,#1e293b);
    color:white;
    transition:0.3s;
}

/* LIGHT MODE */
body.light{
    background: linear-gradient(135deg,#e2e8f0,#cbd5f5);
    color:black;
}

/* CARD - FULL SCREEN STYLE */
.login-box{
    background:var(--card);
    backdrop-filter:blur(20px);
    padding:60px 40px;
    border-radius:30px;
    width:80%;
    max-width:700px;
    text-align:center;
    box-shadow:0 12px 48px rgba(0,0,0,0.4);
    border:1px solid rgba(255,255,255,0.2);
    animation:fadeIn 1s ease;
}

/* FADE IN ANIMATION */
@keyframes fadeIn{
    from{opacity:0; transform:translateY(20px);}
    to{opacity:1; transform:translateY(0);}
}

/* TITLE */
h2{
    font-size:2.5rem;
    margin-bottom:40px;
}

/* SELECT */
select{
    width:100%;
    padding:18px;
    border-radius:15px;
    border:none;
    outline:none;
    margin-bottom:30px;
    font-size:1.2rem;
    transition:0.3s;
    color:#111;
}

select:focus{
    box-shadow:0 0 15px var(--primary);
}

/* BUTTON */
button{
    width:100%;
    padding:18px;
    border:none;
    border-radius:15px;
    background:linear-gradient(45deg,var(--primary),#22c55e);
    color:white;
    font-size:1.5rem;
    cursor:pointer;
    transition:0.3s;
    position:relative;
}

button:hover{
    transform:translateY(-3px);
}

button:active{
    transform:scale(0.97);
}

/* LINK */
a{
    color:var(--secondary);
    text-decoration:none;
    display:inline-block;
    margin-top:20px;
    font-size:1.1rem;
}

a:hover{
    text-decoration:underline;
}

/* TOGGLE SWITCHES */
.top-bar{
    position:absolute;
    top:20px;
    right:20px;
    display:flex;
    gap:10px;
    z-index:100;
}

.toggle{
    cursor:pointer;
    padding:8px 12px;
    border-radius:12px;
    background:rgba(255,255,255,0.2);
    font-size:1.2rem;
}

/* TOAST */
.toast{
    position:fixed;
    bottom:20px;
    right:20px;
    background:#ef4444;
    color:white;
    padding:12px 18px;
    border-radius:12px;
    display:none;
    font-size:1.1rem;
}

/* LOADING */
.loading{
    pointer-events:none;
    opacity:0.7;
}

</style>
</head>

<body>

<!-- TOP CONTROLS -->
<div class="top-bar">
    <div class="toggle" onclick="toggleLang()">🌍</div>
    <div class="toggle" onclick="toggleMode()">🌓</div>
</div>

<!-- LOGIN CARD -->
<div class="login-box">

<h2 id="title">Staff Login</h2>

<form method="POST" onsubmit="return validateForm()">

<select name="role" id="role">
    <option value="">Select Role</option>
    <option value="Admin">Admin</option>
    <option value="Technician">Technician</option>
    <option value="Accountant">Accountant</option>
</select>

<button id="btn" type="submit">Continue</button>

</form>

<a href="<?= BASE_URL ?>/index.php" id="back">
    Back to Home
</a>

</div>

<!-- TOAST -->
<div class="toast" id="toast">Please select a role</div>

<script>

/* DARK / LIGHT MODE */
function toggleMode(){
    document.body.classList.toggle("light");
}

/* LANGUAGE TOGGLE */
let lang = "en";

function toggleLang(){
    lang = (lang === "en") ? "ss" : "en";

    if(lang === "ss"){
        document.getElementById("title").innerText = "Ngungena Kwabasebenti";
        document.getElementById("btn").innerText = "Chubeka";
        document.getElementById("back").innerText = "Buyela Ekhaya";
        document.getElementById("toast").innerText = "Khetsa luhlobo";
    } else {
        document.getElementById("title").innerText = "Staff Login";
        document.getElementById("btn").innerText = "Continue";
        document.getElementById("back").innerText = "Back to Home";
        document.getElementById("toast").innerText = "Please select a role";
    }
}

/* VALIDATION */
function validateForm(){
    let role = document.getElementById("role").value;

    if(role === ""){
        let toast = document.getElementById("toast");
        toast.style.display = "block";

        setTimeout(()=>{
            toast.style.display = "none";
        },2000);

        return false;
    }

    // loading effect
    document.getElementById("btn").innerText = "Loading...";
    document.getElementById("btn").classList.add("loading");

    return true;
}

</script>

</body>
</html>
