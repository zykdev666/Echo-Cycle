<?php
/**
 * EcoCycle — logout.
 */
require_once __DIR__ . '/includes/functions.php';

logoutUser();
session_start(); // fresh session so we can flash a message
setFlash('info', 'You have been logged out. See you soon! 🌍');
header('Location: index.php');
exit;
