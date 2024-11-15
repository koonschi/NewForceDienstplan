<?php 

include_once 'database.php';

function ensureLoggedIn()
{
    if (isset($_SESSION['user_id'])) {
        if (!getUserActive($_SESSION['user_id'])){
            session_destroy();
        }
    }

    if(!(isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true)){
        backToLogin();
        exit;
    }
}

function backToLogin($msg = "not logged in")
{
    echo $msg;
    echo '<a href="index.php">Back To Login</a>';
}

function jsUserId()
{
    echo "<script>";
    echo "var loggedInUserLogin = " . json_encode($_SESSION['login']) . ";";
    echo "var loggedInUserId = " . json_encode($_SESSION['user_id']) . ";";
    echo "</script>";
}

function jsErrorMessage($errorMessage)
{
    echo "<script>";
    echo "var phpError = " . json_encode($errorMessage) . ";";
    echo "</script>";
}

?>
