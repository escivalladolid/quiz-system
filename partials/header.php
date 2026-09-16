<?php
/**
 * Shared head + sticky nav for the RMC Quiz & Examination landing page.
 * The landing is a single page, so nav links are same-page anchors
 * (#about, #features, ...) while the Admin Login button is a real route.
 *
 * Expected callers set:
 *   $page_title   page <title> suffix
 *   $nav_active   optional nav key to highlight (unused on a one-pager)
 */
$page_title = isset($page_title) ? $page_title : 'RMC Quiz &amp; Examination — Secure Mobile-Based Exams';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $page_title; ?></title>
<meta name="description" content="RMC Quiz &amp; Examination — the official Regis Marie College mobile-based quiz and examination platform. Teacher exam builder, automatic grading, and tamper-proof results.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
<link rel="icon" href="assets/img/rmc-favicon.png" type="image/png" sizes="any">
</head>
<body>

<nav class="nav" id="top">
  <div class="container nav-inner">
    <a class="brand" href="#top" aria-label="RMC Quiz and Examination home">
      <?php $logo_src  = 'assets/img/app-icon.jpeg';
            $logo_size = 34;
            include __DIR__ . '/logo.php'; ?>
      <span class="wordmark">RMC Quiz &amp; Examination<small>Regis Marie College</small></span>
    </a>

    <ul class="nav-links" id="navLinks">
      <li><a href="#top">Home</a></li>
      <li><a href="#about">About</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="#security">Security</a></li>
      <li><a href="#how">How It Works</a></li>
      <li><a href="#download">Download</a></li>
      <li><a class="btn btn-accent nav-cta" href="admin/login.php">Admin Login</a></li>
    </ul>

    <button class="nav-burger" id="navBurger" aria-label="Toggle menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>
<?php unset($logo_src, $logo_size); ?>