<?php
// includes/response.php — 限流页 + 成功页渲染

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';

function render_rate_limit_page($uiLang, $backUrl) {
    $uiLang = validate_lang($uiLang);
    $t      = localized_copy('rateLimit', $uiLang);

    send_security_headers('response-page');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo build_response_shell_start_html($uiLang, $t['title'])
       . '<div class="ui-card status-panel"><h2>' . h($t['title']) . '</h2>'
       . '<p class="text-help">' . h($t['msg']) . '</p>'
       . '<div class="action-group"><a class="button button--accent" href="' . h($backUrl) . '">' . h($t['cta']) . '</a></div>'
       . '</div></body></html>';
    exit;
}

function render_success_page($uiLang, $subUrl, $backUrl) {
    $uiLang = validate_lang($uiLang);
    $t      = localized_copy('success', $uiLang);

    send_security_headers('response-page');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<?php echo build_response_shell_start_html($uiLang, $t['ok']); ?>
  <div class="ui-card status-panel">
    <h2><?php echo h($t['ok']); ?></h2>
    <div class="form-row">
      <label class="text-help"><?php echo h($t['slug']); ?></label>
      <div id="u" class="domain"><?php echo h($subUrl); ?></div>
      <div class="action-group">
        <button class="button button--accent" onclick="copyU()"><?php echo h($t['copy']); ?></button>
        <a class="button button--ghost" href="<?php echo h($subUrl); ?>" target="_blank" rel="noopener"><?php echo h($t['open']); ?></a>
      </div>
    </div>
    <div class="tips">
      <div class="text-help"><strong><?php echo h($t['tipsTitle']); ?></strong></div>
      <ul>
        <?php foreach ($t['tips'] as $tip) { echo '<li>' . h($tip) . '</li>'; } ?>
      </ul>
    </div>
    <div class="footer">
      <a class="button" href="<?php echo h($backUrl); ?>"><?php echo h($t['back']); ?></a>
    </div>
  </div>
  <script>
  function copyU(){
    var u=document.getElementById('u').textContent;
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(u).then(function(){alert('\u2714')}).catch(fb);
    }else{fb();}
    function fb(){var ta=document.createElement('textarea');ta.value=u;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();alert('\u2714');}
  }
  </script>
</body>
</html>
<?php
    exit;
}

function render_turnstile_error_page($uiLang, $backUrl) {
    $uiLang = validate_lang($uiLang);
    $t      = localized_copy('turnstile', $uiLang);

    send_security_headers('response-page');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(400);
    echo build_response_shell_start_html($uiLang, $t['title'])
       . '<div class="ui-card status-panel"><h2>' . h($t['title']) . '</h2>'
       . '<p class="text-help">' . h($t['msg']) . '</p>'
       . '<div class="action-group"><a class="button button--accent" href="' . h($backUrl) . '">' . h($t['cta']) . '</a></div>'
       . '</div></body></html>';
    exit;
}

function get_footer_html() {
    static $cache = null;
    if ($cache === null) {
        $path = dirname(__DIR__) . '/partials/footer.html';
        $cache = is_file($path) ? file_get_contents($path) : '';
    }
    return $cache;
}
