<?php

    // Record errors from PHP
    function errorLog($errno, $errstr, $errfile, $errline)
    {
        //echo "<pre>";
        //var_dump($errstr);
        //echo "</pre>";
    }

    set_error_handler("errorLog");

    $filename = $argv[1];
    if(isset($argv[2])) {
        parse_str($argv[2], $_GET);
        unset($_GET['get']);
    }
    if(isset($argv[3])) {
        parse_str($argv[3], $_POST);
        unset($_POST['post']);
    }

    require($filename);
?>