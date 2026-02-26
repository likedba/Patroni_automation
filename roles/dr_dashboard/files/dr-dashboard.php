<?php
/**
 * Plugin Name: DR Dashboard
 * Description: Demo HA/DR dashboard for WordPress: frontend/backend identification, DB write/read test (Patroni), Media upload test (NFS), Patroni primary/roles, event log.
 * Version: 1.0.0
 * Author: DR Demo
 */

if (!defined('ABSPATH')) exit;

class DR_Dashboard_Plugin {
  const OPTION_LAST = 'drd_last_result';
  const OPTION_DB_KEY = 'drd_last_write_value';
  const DB_TABLE_SUFFIX = 'drd_events';

  // Patroni endpoints provided by user
  private static array $patroni_urls = [
    'http://192.168.1.135:8008/patroni',
    'http://192.168.1.136:8008/patroni',
    'http://192.168.1.137:8008/patroni',
  ];

  public static function init(): void {
    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);

    add_shortcode('dr_dashboard', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

    add_action('wp_ajax_drd_status', [__CLASS__, 'ajax_status']);
    add_action('wp_ajax_nopriv_drd_status', [__CLASS__, 'ajax_status']);

    add_action('wp_ajax_drd_db_write', [__CLASS__, 'ajax_db_write']);
    add_action('wp_ajax_nopriv_drd_db_write', [__CLASS__, 'ajax_db_write']);

    add_action('wp_ajax_drd_media_upload', [__CLASS__, 'ajax_media_upload']);
    add_action('wp_ajax_nopriv_drd_media_upload', [__CLASS__, 'ajax_media_upload']);
  }

  /* -------------------- Activation: create log table -------------------- */

  public static function on_activate(): void {
    global $wpdb;
    $table = self::events_table();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "
      CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts_utc DATETIME NOT NULL,
        action VARCHAR(64) NOT NULL,
        ok TINYINT(1) NOT NULL,
        backend VARCHAR(255) NOT NULL,
        details LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY ts_utc (ts_utc),
        KEY action (action),
        KEY ok (ok)
      ) {$charset_collate};
    ";
    dbDelta($sql);
  }

  private static function events_table(): string {
    global $wpdb;
    return $wpdb->prefix . self::DB_TABLE_SUFFIX;
  }

  private static function log_event(string $action, bool $ok, string $backend, array $details = []): void {
    global $wpdb;
    $table = self::events_table();

    $wpdb->insert(
      $table,
      [
        'ts_utc'  => gmdate('Y-m-d H:i:s'),
        'action'  => $action,
        'ok'      => $ok ? 1 : 0,
        'backend' => $backend,
        'details' => !empty($details) ? wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      ],
      ['%s','%s','%d','%s','%s']
    );
  }

  private static function get_last_events(int $limit = 20): array {
    global $wpdb;
    $table = self::events_table();
    $limit = max(1, min(200, $limit));
    $rows = $wpdb->get_results("SELECT id, ts_utc, action, ok, backend, details FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A);
    if (!$rows) return [];
    foreach ($rows as &$r) {
      if (!empty($r['details'])) {
        $decoded = json_decode($r['details'], true);
        $r['details'] = is_array($decoded) ? $decoded : $r['details'];
      }
      $r['ok'] = (int)$r['ok'];
    }
    return $rows;
  }

  /* -------------------- Assets & UI -------------------- */

  public static function enqueue_assets(): void {
    if (!is_singular()) return;
    global $post;
    if (!$post || stripos((string)$post->post_content, '[dr_dashboard') === false) return;

    wp_enqueue_script('jquery');

    // Bootstrap 5 (CDN) for a presentable dashboard
    wp_enqueue_style('drd_bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', [], '5.3.3');
    wp_enqueue_script('drd_bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', [], '5.3.3', true);

    wp_add_inline_script('jquery', self::inline_js());
    wp_add_inline_style('drd_bootstrap', self::inline_css());
  }

  private static function inline_css(): string {
    return <<<CSS
.drd-card-title { letter-spacing: .2px; }
.drd-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.drd-kpi { font-size: 1.05rem; }
.drd-small { font-size: .85rem; }
CSS;
  }

  public static function shortcode(): string {
    $nonce = wp_create_nonce('drd_nonce');

    // Prevent caching of demo page
    if (!headers_sent()) {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
    }

    ob_start(); ?>
    <div class="container py-4">
      <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
          <h2 class="mb-1">DR / HA Demo Dashboard</h2>
          <div class="text-muted drd-small">
            Visual checks: Frontend nginx &rarr; Backend WP/nginx &rarr; DB (Patroni) &rarr; Media uploads (NFS)
          </div>
        </div>
        <div class="text-end">
          <div class="text-muted drd-small">Local time</div>
          <div id="drd-now" class="fw-semibold drd-mono"></div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6 col-lg-3">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div class="fw-semibold drd-card-title">Frontend</div>
                <span id="drd-frontend-badge" class="badge text-bg-secondary">?</span>
              </div>
              <div class="mt-2 text-muted drd-small">X-Frontend-Host</div>
              <div id="drd-frontend-host" class="fw-semibold drd-mono drd-kpi">&mdash;</div>
            </div>
          </div>
        </div>

        <div class="col-md-6 col-lg-3">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div class="fw-semibold drd-card-title">Backend</div>
                <span id="drd-backend-badge" class="badge text-bg-secondary">?</span>
              </div>
              <div class="mt-2 text-muted drd-small">WP host (gethostname)</div>
              <div id="drd-backend-host" class="fw-semibold drd-mono drd-kpi">&mdash;</div>
              <div class="mt-2 text-muted drd-small">X-Backend-Host</div>
              <div id="drd-backend-hdr" class="fw-semibold drd-mono">&mdash;</div>
            </div>
          </div>
        </div>

        <div class="col-md-6 col-lg-3">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div class="fw-semibold drd-card-title">Database</div>
                <span id="drd-db-badge" class="badge text-bg-secondary">?</span>
              </div>
              <div class="mt-2 text-muted drd-small">Last write</div>
              <div id="drd-db-last" class="fw-semibold drd-mono">&mdash;</div>
              <div class="mt-2 text-muted drd-small">Writer backend</div>
              <div id="drd-db-writer" class="fw-semibold drd-mono">&mdash;</div>
            </div>
          </div>
        </div>

        <div class="col-md-6 col-lg-3">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div class="fw-semibold drd-card-title">Media / NFS</div>
                <span id="drd-media-badge" class="badge text-bg-secondary">?</span>
              </div>
              <div class="mt-2 text-muted drd-small">Last upload</div>
              <div id="drd-media-last" class="fw-semibold drd-mono">&mdash;</div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between">
                <div class="fw-semibold">Actions</div>
                <span id="drd-loading" class="text-muted drd-small" style="display:none;">Working&hellip;</span>
              </div>

              <div class="d-flex flex-wrap gap-2 mt-3">
                <button id="drd-btn-db" class="btn btn-primary">DB write + read test</button>
                <button id="drd-btn-media" class="btn btn-success">Upload test image</button>
                <button id="drd-btn-refresh" class="btn btn-outline-secondary">Refresh status</button>
              </div>

              <div class="mt-3">
                <div class="text-muted drd-small mb-1">Last response</div>
                <pre id="drd-log" class="bg-light border rounded p-3 mb-0 drd-small" style="min-height:140px; white-space:pre-wrap;">&mdash;</pre>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="fw-semibold mb-2">Uploaded preview</div>
              <div class="ratio ratio-4x3 bg-light border rounded d-flex align-items-center justify-content-center">
                <img id="drd-preview" src="" alt="" style="max-width:100%; max-height:100%; display:none;">
                <div id="drd-preview-placeholder" class="text-muted">No image yet</div>
              </div>
              <div class="mt-2 drd-small text-muted" id="drd-preview-meta">&mdash;</div>
            </div>
          </div>

          <div class="card shadow-sm mt-3">
            <div class="card-body">
              <div class="fw-semibold mb-2">Patroni</div>
              <div class="text-muted drd-small">Detected primary</div>
              <div id="drd-patroni-primary" class="fw-semibold drd-mono">&mdash;</div>

              <div class="mt-3">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th>Node</th>
                      <th>Role</th>
                      <th>Sync</th>
                    </tr>
                  </thead>
                  <tbody id="drd-patroni-rows">
                    <tr><td colspan="3" class="text-muted">&mdash;</td></tr>
                  </tbody>
                </table>
              </div>

              <div class="mt-2 text-muted drd-small">
                Endpoints: <?php echo esc_html(implode(', ', array_map(fn($u)=>parse_url($u, PHP_URL_HOST), self::$patroni_urls))); ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="fw-semibold mb-2">Event log (last 20)</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>UTC</th>
                  <th>Action</th>
                  <th>Result</th>
                  <th>Backend</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody id="drd-events">
                <tr><td colspan="5" class="text-muted">&mdash;</td></tr>
              </tbody>
            </table>
          </div>
          <div class="text-muted drd-small">
            Tip: open DevTools &rarr; Network &rarr; headers to show <code>X-Frontend-Host</code> / <code>X-Backend-Host</code>.
          </div>
        </div>
      </div>

      <script>
        window.DRD = {
          ajax: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
          nonce: "<?php echo esc_js($nonce); ?>"
        };
      </script>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function inline_js(): string {
    return <<<JS
(function($){
  function badge($el, ok, text){
    $el.removeClass('text-bg-secondary text-bg-success text-bg-danger');
    if(ok === null || ok === undefined){
      $el.addClass('text-bg-secondary').text(text || '?');
      return;
    }
    $el.addClass(ok ? 'text-bg-success' : 'text-bg-danger').text(text || (ok ? 'OK' : 'FAIL'));
  }

  function fmt(obj){
    try { return JSON.stringify(obj, null, 2); } catch(e){ return String(obj); }
  }

  function setNow(){
    var d = new Date();
    $('#drd-now').text(d.toLocaleString());
  }

  function setLoading(on){
    $('#drd-loading').toggle(!!on);
    $('#drd-btn-db, #drd-btn-media, #drd-btn-refresh').prop('disabled', !!on);
  }

  function ajax(action, data){
    return $.ajax({
      url: window.DRD.ajax,
      method: 'POST',
      dataType: 'json',
      data: Object.assign({ action: action, _ajax_nonce: window.DRD.nonce }, data || {})
    });
  }

  function renderPatroniRows(rows){
    if(!rows || !rows.length){
      $('#drd-patroni-rows').html('<tr><td colspan="3" class="text-muted">—</td></tr>');
      return;
    }
    var html = '';
    rows.forEach(function(x){
      var node = (x.name || x.host || x.url || '—');
      var role = (x.role || '—');
      var sync = (x.sync_standby === true) ? 'yes' : ((x.sync_standby === false) ? 'no' : '—');
      html += '<tr><td class="drd-mono">' + String(node) + '</td><td class="drd-mono">' + String(role) + '</td><td class="drd-mono">' + String(sync) + '</td></tr>';
    });
    $('#drd-patroni-rows').html(html);
  }

  function renderEvents(ev){
    if(!ev || !ev.length){
      $('#drd-events').html('<tr><td colspan="5" class="text-muted">—</td></tr>');
      return;
    }
    var html = '';
    ev.forEach(function(e){
      var ok = (e.ok === 1);
      var badgeClass = ok ? 'text-bg-success' : 'text-bg-danger';
      var details = e.details ? fmt(e.details) : '';
      if(details.length > 140) details = details.slice(0, 140) + '…';
      html += '<tr>' +
        '<td class="drd-mono drd-small">' + String(e.ts_utc || '') + '</td>' +
        '<td class="drd-mono drd-small">' + String(e.action || '') + '</td>' +
        '<td><span class="badge ' + badgeClass + '">' + (ok ? 'OK' : 'FAIL') + '</span></td>' +
        '<td class="drd-mono drd-small">' + String(e.backend || '') + '</td>' +
        '<td class="drd-small"><code style="white-space:pre-wrap;">' + $('<div/>').text(details).html() + '</code></td>' +
      '</tr>';
    });
    $('#drd-events').html(html);
  }

  function refresh(){
    return ajax('drd_status').done(function(r){
      if(!r || !r.ok){
        $('#drd-log').text('Status error: ' + fmt(r));
        return;
      }

      badge($('#drd-frontend-badge'), true, 'OK');
      $('#drd-frontend-host').text(r.frontend_host || '—');

      badge($('#drd-backend-badge'), true, 'OK');
      $('#drd-backend-host').text(r.backend_host || '—');
      $('#drd-backend-hdr').text(r.backend_hdr || '—');

      badge($('#drd-db-badge'), r.db_ok, (r.db_ok === null || r.db_ok === undefined) ? '?' : (r.db_ok ? 'OK' : 'FAIL'));
      $('#drd-db-last').text(r.db_last || '—');
      $('#drd-db-writer').text(r.db_writer || '—');

      badge($('#drd-media-badge'), r.media_ok, (r.media_ok === null || r.media_ok === undefined) ? '?' : (r.media_ok ? 'OK' : 'FAIL'));
      $('#drd-media-last').text(r.media_last || '—');

      $('#drd-patroni-primary').text(r.patroni_primary || '—');
      renderPatroniRows(r.patroni_rows || []);

      renderEvents(r.events || []);

      $('#drd-log').text(fmt(r));
    }).fail(function(xhr){
      $('#drd-log').text('AJAX failed: ' + xhr.status + ' ' + xhr.statusText);
    });
  }

  function doAction(action){
    setLoading(true);
    return ajax(action).done(function(r){
      $('#drd-log').text(fmt(r));
      if(action === 'drd_media_upload' && r && r.ok && r.url){
        $('#drd-preview').attr('src', r.url + '&t=' + Date.now()).show();
        $('#drd-preview-placeholder').hide();
        $('#drd-preview-meta').text('URL: ' + r.url + ' | Path: ' + (r.path || '—'));
      }
    }).always(function(){
      setLoading(false);
      refresh();
    });
  }

  $(function(){
    setNow();
    refresh();
    setInterval(setNow, 1000);
    setInterval(refresh, 5000);

    $('#drd-btn-refresh').on('click', function(){ refresh(); });
    $('#drd-btn-db').on('click', function(){ doAction('drd_db_write'); });
    $('#drd-btn-media').on('click', function(){ doAction('drd_media_upload'); });
  });
})(jQuery);
JS;
  }

  /* -------------------- Helpers -------------------- */

  private static function require_nonce(): void {
    if (!check_ajax_referer('drd_nonce', '_ajax_nonce', false)) {
      wp_send_json(['ok' => false, 'error' => 'bad_nonce'], 403);
    }
  }

  private static function backend_host(): string {
    return gethostname() ?: php_uname('n');
  }

  private static function header_value(string $name): string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
      if (strcasecmp($k, $name) === 0) return (string)$v;
    }
    return '';
  }

  private static function frontend_host_header(): string {
    return self::header_value('X-Frontend-Host');
  }

  private static function backend_host_header(): string {
    return self::header_value('X-Backend-Host');
  }

  /* -------------------- Patroni -------------------- */

  private static function patroni_status(): array {
    $rows = [];
    $primary = '';

    if (empty(self::$patroni_urls)) {
      return ['primary' => '', 'rows' => []];
    }

    foreach (self::$patroni_urls as $url) {
      $host = (string)parse_url($url, PHP_URL_HOST);
      $resp = wp_remote_get($url, ['timeout' => 1.5]);
      if (is_wp_error($resp)) {
        $rows[] = ['url' => $url, 'host' => $host, 'name' => '', 'role' => 'unreachable', 'sync_standby' => null];
        continue;
      }

      $code = wp_remote_retrieve_response_code($resp);
      $body = wp_remote_retrieve_body($resp);

      if ($code < 200 || $code >= 300 || !$body) {
        $rows[] = ['url' => $url, 'host' => $host, 'name' => '', 'role' => 'bad_response', 'sync_standby' => null];
        continue;
      }

      $j = json_decode($body, true);
      if (!is_array($j)) {
        $rows[] = ['url' => $url, 'host' => $host, 'name' => '', 'role' => 'invalid_json', 'sync_standby' => null];
        continue;
      }

      $role = (string)($j['role'] ?? '');
      // IMPORTANT: your JSON has name in patroni.name
      $name = (string)($j['patroni']['name'] ?? '');
      $sync = $j['sync_standby'] ?? null;

      $rows[] = [
        'url' => $url,
        'host' => $host,
        'name' => $name ?: $host,
        'role' => $role ?: 'unknown',
        'sync_standby' => is_bool($sync) ? $sync : null,
      ];

      $is_primary = (
        stripos($role, 'primary') !== false ||
        stripos($role, 'leader') !== false ||
        stripos($role, 'master') !== false
      );

      if ($is_primary && ($name || $host)) {
        $primary = ($name ?: $host) . " ({$role})";
      }
    }

    return ['primary' => $primary ?: 'not_detected', 'rows' => $rows];
  }

  /* -------------------- AJAX: Status -------------------- */

  public static function ajax_status(): void {
    self::require_nonce();

    $backend = self::backend_host();
    $last = get_option(self::OPTION_LAST, []);

    $db_ok = array_key_exists('db_ok', $last) ? (bool)$last['db_ok'] : null;
    $media_ok = array_key_exists('media_ok', $last) ? (bool)$last['media_ok'] : null;

    $pat = self::patroni_status();
    $events = self::get_last_events(20);

    wp_send_json([
      'ok' => true,
      'frontend_host' => self::frontend_host_header(),
      'backend_host' => $backend,
      'backend_hdr' => self::backend_host_header(),
      'db_ok' => $db_ok,
      'db_last' => $last['db_last'] ?? '',
      'db_writer' => $last['db_writer'] ?? '',
      'media_ok' => $media_ok,
      'media_last' => $last['media_last'] ?? '',
      'patroni_primary' => $pat['primary'],
      'patroni_rows' => $pat['rows'],
      'events' => $events,
      'time_utc' => gmdate('c'),
    ]);
  }

  /* -------------------- AJAX: DB write/read test -------------------- */

  public static function ajax_db_write(): void {
    self::require_nonce();

    $backend = self::backend_host();
    $ts = gmdate('c');
    $value = $ts . ' | backend=' . $backend;

    // REAL DB write/read
    $ok_write = update_option(self::OPTION_DB_KEY, $value, false);
    $read = get_option(self::OPTION_DB_KEY, '');

    $ok = ($read === $value);

    $last = get_option(self::OPTION_LAST, []);
    $last['db_ok'] = $ok;
    $last['db_last'] = $value;
    $last['db_writer'] = $backend;
    update_option(self::OPTION_LAST, $last, false);

    self::log_event('db_write_read', $ok, $backend, [
      'written' => $value,
      'read_back' => $read,
      'ok_write_return' => $ok_write,
    ]);

    wp_send_json([
      'ok' => $ok,
      'action' => 'db_write_read',
      'written' => $value,
      'read_back' => $read,
      'backend' => $backend,
      'time_utc' => $ts,
    ], $ok ? 200 : 500);
  }

  /* -------------------- AJAX: Media upload test -------------------- */

  private static function make_png(string $text): string {
    if (!function_exists('imagecreatetruecolor')) {
      return '';
    }
    $w = 900; $h = 500;
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, 245, 247, 250);
    $fg = imagecolorallocate($im, 30, 41, 59);
    $accent = imagecolorallocate($im, 16, 185, 129);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    imagefilledrectangle($im, 0, 0, $w, 14, $accent);

    imagestring($im, 5, 24, 40, "DR DASHBOARD - MEDIA UPLOAD TEST", $fg);
    imagestring($im, 4, 24, 100, $text, $fg);
    imagestring($im, 3, 24, 160, "If this image still loads after failover: NFS/uploads + nginx path are OK.", $fg);

    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    imagedestroy($im);
    return $png;
  }

  public static function ajax_media_upload(): void {
    self::require_nonce();

    $backend = self::backend_host();
    $ts = gmdate('c');

    $label = "time_utc={$ts} | backend={$backend}";
    $png = self::make_png($label);

    if ($png === '') {
      self::log_event('media_upload', false, $backend, ['error' => 'GD not available. Install/enable php-gd.']);
      wp_send_json(['ok' => false, 'error' => 'GD not available (php-gd)'], 500);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error'])) {
      self::log_event('media_upload', false, $backend, ['error' => $upload_dir['error']]);
      wp_send_json(['ok' => false, 'error' => $upload_dir['error']], 500);
    }

    $safe_backend = preg_replace('/[^a-zA-Z0-9\\-]/', '', $backend);
    $filename = 'drd-upload-' . gmdate('Ymd-His') . '-' . $safe_backend . '.png';
    $path = trailingslashit($upload_dir['path']) . $filename;

    $bytes = @file_put_contents($path, $png);
    if ($bytes === false) {
      self::log_event('media_upload', false, $backend, ['error' => 'failed_write_file', 'path' => $path]);
      wp_send_json(['ok' => false, 'error' => 'failed_write_file', 'path' => $path], 500);
    }

    $filetype = wp_check_filetype($filename, null);
    $attachment = [
      'post_mime_type' => $filetype['type'] ?: 'image/png',
      'post_title' => $filename,
      'post_content' => '',
      'post_status' => 'inherit'
    ];

    $attach_id = wp_insert_attachment($attachment, $path);
    if (is_wp_error($attach_id) || !$attach_id) {
      self::log_event('media_upload', false, $backend, ['error' => 'wp_insert_attachment_failed', 'path' => $path]);
      wp_send_json(['ok' => false, 'error' => 'wp_insert_attachment_failed'], 500);
    }

    $attach_data = wp_generate_attachment_metadata($attach_id, $path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    $url = wp_get_attachment_url($attach_id);

    $ok = (bool)$url && file_exists($path);

    $last = get_option(self::OPTION_LAST, []);
    $last['media_ok'] = $ok;
    $last['media_last'] = $ts . ' | ' . $filename . ' | backend=' . $backend;
    update_option(self::OPTION_LAST, $last, false);

    self::log_event('media_upload', $ok, $backend, [
      'filename' => $filename,
      'path' => $path,
      'url' => $url,
      'bytes' => $bytes,
      'attach_id' => $attach_id,
    ]);

    wp_send_json([
      'ok' => $ok,
      'action' => 'media_upload',
      'backend' => $backend,
      'time_utc' => $ts,
      'filename' => $filename,
      'path' => $path,
      'url' => $url,
      'bytes' => $bytes,
      'attach_id' => $attach_id,
    ], $ok ? 200 : 500);
  }
}

DR_Dashboard_Plugin::init();
