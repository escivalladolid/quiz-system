<?php
/**
 * Shared footer for the RMC Quiz & Examination landing page.
 * Closes the <body> opened in partials/header.php.
 */
?>
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="brand" href="#top" aria-label="RMC Quiz and Examination home">
          <?php $logo_src  = 'assets/img/app-icon.jpeg';
                $logo_size = 38;
                include __DIR__ . '/logo.php'; ?>
          <span class="wordmark">RMC Quiz &amp; Examination</span>
        </a>
        <p class="tag">The official mobile-based quiz and examination platform of Regis Marie College — secure assessments, right on Android.</p>
      </div>

      <div>
        <h4>Quick Links</h4>
        <ul>
          <li><a href="#top">Home</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#security">Security</a></li>
          <li><a href="#download">Download</a></li>
        </ul>
      </div>

      <div>
        <h4>College</h4>
        <ul>
          <li><a href="https://www.regismariecollege.com/" target="_blank" rel="noopener">Regis Marie College</a></li>
          <li><a href="admin/login.php">Admin Portal</a></li>
          <li><a href="mailto:regismariecollege@gmail.com">Contact Us</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <span>&copy; 2026 Regis Marie College · RMC Quiz &amp; Examination System</span>
      <span><a href="#top">Privacy Policy</a><a href="#top">Terms of Service</a></span>
    </div>
  </div>
</footer>

<script src="assets/js/site.js" defer></script>
</body>
</html>
<?php unset($logo_src, $logo_size); ?>