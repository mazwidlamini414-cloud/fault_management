<?php
session_start();

function isLoggedIn(){
    return isset($_SESSION['user_id']);
}

function requireLogin($redirect = '/login.php'){
    if(!isLoggedIn()){
        header("Location: $redirect");
        exit();
    }
}
?>


