<?php
/**
 * RMC SecureAssess logo — inline SVG, reusable partial.
 *
 * The app icon: a navy rounded square, white padlock, and inside the shackle
 * a circular RMC seal (crown + quartered shield with key / lion / open book,
 * "REGIS MARIE COLLEGE · 1992" ring text). Rebuilt from the description as
 * SVG so it is crisp at every size and open to animation.
 *
 * Palette rule (v2 decision): the page chrome uses navy #122347 + amber
 * #e8a33d with Fraunces/Inter/IBM Plex Mono. The seal's navy #0A0F8C, gold
 * #D4AF37 and light blue #9FDBFF are LOGO-ONLY colors — they never appear in
 * UI chrome, only inside this art.
 *
 * Usage:
 *   $logo_src = 'assets/img/app-icon.jpeg'; // REAL logo image (preferred)
 *   $logo_size = 72;
 *   $logo_class = 'brand';
 *   include __DIR__ . '/partials/logo.php';
 *
 * When $logo_src is set the real image is rendered (img tag); otherwise the
 * SVG approximation below is used (vector, animation hooks). Set
 * $logo_animate = false to drop the animation hook classes.
 *
 * Animation hooks (used by the hero, see assets/js/site.js + assets/css):
 *   .lq-coin      the seal medallion — coin-drops into place on load
 *   .lq-shackle   the padlock shackle — snaps shut around the coin after it lands
 *   .lq-body      the padlock body — rises with the coin
 *   .lq-keyhole   keyhole accent — appears last
 * Elements are grouped so the whole sequence can be replayed by re-adding the
 * `.play` class to a wrapper (data-replay area) without any page reload.
 */

$logo_size    = isset($logo_size)    ? (int) $logo_size    : 160;
$logo_animate = isset($logo_animate) ? (bool) $logo_animate : true;
$logo_class   = isset($logo_class)   ? $logo_class          : '';
$logo_src     = isset($logo_src)     ? $logo_src            : '';
$lq = $logo_animate ? ' lq' : '';

if ($logo_src !== '') {
    echo '<img src="' . e($logo_src) . '" width="' . $logo_size . '" height="' . $logo_size . '"'
        . ' alt="RMC Quiz and Examination logo"'
        . ' class="rmc-logo' . ($logo_class !== '' ? ' ' . e($logo_class) : '') . '">';
    unset($logo_size, $logo_animate, $logo_class, $logo_src);
    return;
}
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160"
     width="<?php echo $logo_size; ?>" height="<?php echo $logo_size; ?>"
     class="rmc-logo<?php echo $logo_class ? ' ' . e($logo_class) : ''; ?>"
     role="img" aria-label="RMC SecureAssess logo">

  <defs>
    <linearGradient id="lg-coin" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#eccb6f"/>
      <stop offset="0.55" stop-color="#D4AF37"/>
      <stop offset="1" stop-color="#a8861b"/>
    </linearGradient>
    <linearGradient id="lg-body" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/>
      <stop offset="1" stop-color="#e6edf9"/>
    </linearGradient>
    <radialGradient id="lg-sheen" cx="0.32" cy="0.25" r="0.85">
      <stop offset="0" stop-color="#ffffff" stop-opacity="0.5"/>
      <stop offset="0.6" stop-color="#ffffff" stop-opacity="0"/>
    </radialGradient>
    <!-- sealing ring text arc: the long upper arc from bottom-left to
         bottom-right over the top of the medallion (sweep 0 = passes the top,
         so the text reads left-to-right across the crown of the seal). -->
    <path id="lg-ring" d="M60.5,71.5 A 19.5 19.5 0 1 0 99.5,71.5" fill="none"/>
  </defs>

  <!-- rounded-square tile -->
  <rect x="0" y="0" width="160" height="160" rx="36" fill="#122347"/>
  <rect x="2.5" y="2.5" width="155" height="155" rx="33.5"
        fill="none" stroke="#D4AF37" stroke-opacity="0.28" stroke-width="1"/>
  <rect x="0" y="0" width="160" height="160" rx="36" fill="url(#lg-sheen)"/>

  <!-- ============ padlock body (rises with the coin in the animation) ===== -->
  <g class="<?php echo $lq; ?> lq-body">
    <rect x="52" y="76" width="56" height="58" rx="14" fill="url(#lg-body)"/>
    <rect x="52" y="76" width="56" height="58" rx="14"
          fill="none" stroke="#cdd9ec" stroke-width="1"/>
    <!-- keyhole accent -->
    <g class="lq-keyhole">
      <circle cx="80" cy="104" r="3.2" fill="#122347"/>
      <rect x="78.2" y="104" width="3.6" height="7" rx="1.8" fill="#D4AF37"/>
    </g>
  </g>

  <!-- ============ seal medallion ========================================== -->
  <!-- sits entirely inside the shackle hole; bottom edge clears the body top
       (74 < 76) so the "coin in the loop" reads clearly. -->
  <g class="<?php echo $lq; ?> lq-coin">
    <circle cx="80" cy="52" r="22" fill="url(#lg-coin)"/>
    <circle cx="80" cy="52" r="20.4" fill="none" stroke="#fff8e1" stroke-width="0.8"/>

    <!-- ring text -->
    <text font-family="Georgia, 'Times New Roman', serif" font-size="5"
          font-weight="700" letter-spacing="1.15" fill="#6b5210"
          aria-hidden="true">
      <textPath href="#lg-ring" startOffset="50%" text-anchor="middle">
        REGIS MARIE COLLEGE · 1992
      </textPath>
    </text>

    <!-- inner medallion -->
    <circle cx="80" cy="52" r="15.6" fill="#0A0F8C"/>

    <!-- crown -->
    <g fill="#D4AF37">
      <path d="M70 36 L73 31 L76 34 L80 29 L84 34 L87 31 L90 36 Z"/>
      <rect x="70" y="37" width="20" height="3.2" rx="1"/>
      <circle cx="70" cy="34.4" r="1.3"/><circle cx="80" cy="30.4" r="1.3"/><circle cx="90" cy="34.4" r="1.3"/>
    </g>

    <!-- quartered shield: TL key, TR stylised lion, bottom open book -->
    <g>
      <path d="M69 43 L91 43 L91 51.5 Q91 59 80 59 Q69 59 69 51.5 Z" fill="#D4AF37"/>
      <line x1="69" y1="51.5" x2="91" y2="51.5" stroke="#0A0F8C" stroke-width="0.9"/>
      <line x1="80" y1="43" x2="80" y2="59" stroke="#0A0F8C" stroke-width="0.9"/>

      <!-- key (top-left) -->
      <g stroke="#0A0F8C" stroke-width="1.1" fill="none">
        <circle cx="74" cy="45.6" r="1.8"/>
        <path d="M75.8 45.6 h3.2 M79 45.6 v1.3 M79 45.6 v-1.3 M78.2 44.8 L77.6 43.2"/>
      </g>

      <!-- stylised lion head (top-right): mane rays + face -->
      <g fill="#0A0F8C">
        <circle cx="86" cy="45.4" r="2.9"/>
        <circle cx="86" cy="45.4" r="1.3" fill="#D4AF37"/>
        <g stroke="#0A0F8C" stroke-width="0.8" stroke-linecap="round" fill="none">
          <path d="M86 41.7 v-1.6 M82.9 43 v-1.5 M89.1 43 v-1.5 M82 45.4 H80.8 M92 45.4 H90.8"/>
        </g>
        <circle cx="84.4" cy="43.6" r="0.7" fill="#D4AF37"/>
        <circle cx="87.6" cy="43.6" r="0.7" fill="#D4AF37"/>
      </g>

      <!-- open book (bottom, spans both quarters) -->
      <g fill="#9FDBFF" stroke="#0A0F8C" stroke-width="0.8">
        <path d="M75.2 52.5 q-3.2 -2.2 -5 0 v4.6 q1.8 -2.2 5 -0.2 v-4.4 z"/>
        <path d="M84.8 52.5 q3.2 -2.2 5 0 v4.6 q-1.8 -2.2 -5 -0.2 v-4.4 z"/>
        <path d="M75.2 52.5 h4.8 M84.8 52.5 h-4.8" stroke-width="0.5" fill="none"/>
      </g>
    </g>
  </g>

  <!-- ============ padlock shackle (snaps shut over the coin) ============== -->
  <!-- stroke 12, round caps; legs meet the body at its top corners. The inner
       edge of the band clears the coin by a ~1px safety gap. -->
  <path class="<?php echo $lq; ?> lq-shackle" d="M52 76 V50 A28 28 0 0 1 108 50 V76"
        fill="none" stroke="#ffffff" stroke-width="12" stroke-linecap="round"/>
</svg>
<?php unset($logo_size, $logo_animate, $logo_class); ?>