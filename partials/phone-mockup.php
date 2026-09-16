<?php
/**
 * Reusable Android phone mockup (pure HTML/CSS, no image assets needed).
 * Renders a realistic frame (notch island) plus one of four close in-app
 * screen mockups. Drop into the hero as a two-phone stack, or as a single
 * card in the "flow" section.
 *
 * Params (set before include):
 *   $phone_variant  'dashboard' | 'exam' | 'results' | 'login'   (default dashboard)
 *   $phone_class    extra CSS class, e.g. 'front' / 'back' (hero depth),
 *                   or 'static' for flow cards (position: static in CSS)
 *   $phone_status   optional status bar line / caption override
 *
 * Screen mockups are approximations of the real Android screens (the user
 * can drop real PNG screenshots into assets/img later and swap the inner
 * markup for <img> tags — the frame/notch stays identical either way).
 */
$phone_variant = isset($phone_variant) ? $phone_variant : 'dashboard';
$phone_class   = isset($phone_class)   ? $phone_class   : '';
?>
<div class="phone <?php echo e($phone_class !== '' ? $phone_class : 'center'); ?>">
  <div class="phone-screen">
    <span class="phone-notch" aria-hidden="true"></span>

    <!-- shared status bar -->
    <div class="phone-statusbar">
      <span>9:41</span>
      <span class="dots" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    </div>

    <?php if ($phone_variant === 'dashboard'): ?>
      <!-- Student dashboard: greeting + upcoming exam + exam list -->
      <div class="scr" style="background:#F5F7FC;">
        <div class="scr-head">
          <h4>Hello, Juan! &#128075;</h4>
          <p>You have 1 exam scheduled for today.</p>
        </div>

        <div style="padding:12px;">
          <div class="scr-card">
            <div class="t">QUIZ 1 — Prepositions</div>
            <div class="s" style="margin-top:2px;">English &middot; 10 items &middot; 20 min</div>
            <div style="margin-top:8px;display:flex;gap:6px;align-items:center;">
              <span class="meta" style="background:rgba(47,92,255,.14);color:#2448D6;">TODAY 1:00 PM</span>
              <span class="meta ok">READY</span>
            </div>
          </div>

          <!-- recently taken -->
          <div class="scr-card" style="margin-top:8px;opacity:.9;">
            <div class="s" style="font-weight:700;color:var(--muted);letter-spacing:.04em;">RECENT RESULTS</div>
            <div style="margin-top:5px;">
              <span style="font-size:10px;color:var(--ink);font-weight:700;">Midterm Exam</span>
              <span style="float:right;font-size:10px;color:#1E9E5A;font-weight:800;">PASSED</span>
              <div class="scr-bar" style="margin-top:4px;width:100%;"><i style="width:84%;"></i></div>
            </div>
          </div>

          <!-- bottom nav -->
          <div style="display:flex;justify-content:space-around;margin-top:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:8px 2px;">
            <span style="font-size:9px;color:var(--accent);font-weight:700;">&#128203; Home</span>
            <span style="font-size:9px;color:var(--muted);">&#128221; Exams</span>
            <span style="font-size:9px;color:var(--muted);">&#128200; Results</span>
          </div>
        </div>
      </div>

    <?php elseif ($phone_variant === 'exam'): ?>
      <!-- Taking the exam: question + selectable options -->
      <div class="scr" style="background:#F5F7FC;">
        <div class="scr-head">
          <h4>QUIZ 1 — Prepositions</h4>
          <p>Question 4 of 10 <span style="float:right;font-family:'IBM Plex Mono',monospace;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:0 8px;font-size:9px;line-height:16px;">12:34</span></p>
        </div>
        <div style="padding:12px;">
          <div class="scr-card">
            <div class="t" style="font-size:11.5px;">Choose the correct preposition:</div>
            <p style="font-size:11px;color:var(--ink);margin:6px 0 2px;line-height:1.4;">She has lived in Manila ___ 2015.</p>
          </div>

          <div style="margin-top:10px;display:grid;gap:7px;">
            <?php $opts = [['A','since'],['B','for'],['C','during'],['D','at']]; foreach ($opts as $k => [$l, $t]): ?>
              <div style="display:flex;align-items:center;gap:9px;background:#fff;border:1px solid <?php echo $k === 0 ? 'var(--accent)' : 'var(--line)'; ?>;border-radius:11px;padding:8px 10px;">
                <span style="width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:9px;font-weight:800;<?php echo $k === 0 ? 'background:var(--accent);color:#fff;' : 'background:#EEF1F8;color:var(--muted);'; ?>"><?php echo $l; ?></span>
                <span style="font-size:11px;font-weight:600;color:var(--ink);"><?php echo $t; ?></span>
                <?php if ($k === 0): ?><span style="margin-left:auto;font-size:10px;color:var(--accent);font-weight:800;">&#10003;</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="display:flex;gap:8px;margin-top:12px;">
            <span style="flex:1;text-align:center;padding:9px 0;border:1px solid var(--line);border-radius:999px;font-size:10px;font-weight:700;color:var(--muted);background:#fff;">&#8592; Previous</span>
            <span style="flex:1;text-align:center;padding:9px 0;border-radius:999px;font-size:10px;font-weight:800;color:#fff;background:var(--accent);">Next &#8594;</span>
          </div>
        </div>
      </div>

    <?php elseif ($phone_variant === 'results'): ?>
      <!-- Results screen: score donut + pass state -->
      <div class="scr" style="background:#F5F7FC;">
        <div class="scr-head">
          <h4>QUIZ 1 — Prepositions</h4>
          <p>Submission saved successfully.</p>
        </div>
        <div style="padding:14px;display:grid;justify-items:center;">
          <div class="scr-pie"><b>84%</b></div>
          <span class="meta ok" style="margin-top:8px;font-size:10px;">&#10003; PASSED</span>

          <div style="width:100%;margin-top:14px;display:grid;gap:7px;">
            <div class="scr-card" style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10px;color:var(--muted);">Score</span>
              <span style="font-size:12px;font-weight:800;color:var(--ink);">842 / 1000</span>
            </div>
            <div class="scr-card" style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10px;color:var(--muted);">Time used</span>
              <span style="font-size:12px;font-weight:800;color:var(--ink);">14 min 32 s</span>
            </div>
            <div class="scr-card" style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-size:10px;color:var(--muted);">Correct</span>
              <span style="font-size:12px;font-weight:800;color:var(--ink);">8 of 10</span>
            </div>
          </div>
        </div>
      </div>

    <?php else: ?>
      <!-- Student login screen (flow step 1) -->
      <div class="scr" style="background:var(--navy-grad);align-items:center;justify-content:center;padding:0 22px;">
        <?php $logo_src  = 'assets/img/app-icon.jpeg';
              $logo_size = 64;
              include __DIR__ . '/logo.php'; ?>
        <p style="color:#fff;font-weight:800;font-size:15px;margin-top:10px;">RMC SecureAssess</p>
        <p style="color:rgba(255,255,255,.6);font-size:9.5px;margin-bottom:16px;">Regis Marie College</p>

        <div style="width:100%;display:grid;gap:8px;">
          <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:9px 12px;font-size:10px;color:rgba(255,255,255,.55);">Student ID / Username</div>
          <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:9px 12px;font-size:10px;color:rgba(255,255,255,.55);">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</div>
          <div style="text-align:center;background:var(--accent);border-radius:999px;padding:10px 0;font-size:11px;font-weight:800;color:#fff;margin-top:4px;">Sign in</div>
        </div>
      </div>
      <?php unset($logo_src, $logo_size); ?>
    <?php endif; ?>

  </div>
</div>
<?php unset($phone_variant, $phone_class); ?>