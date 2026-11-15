<?php
/*
 * Orbit styleguide — a living reference for the design system, visible to admins only.
 *
 *   /?module=common&action=orbit-styleguide
 *
 * It is deliberately NOT linked from the site and carries noindex: it is a workbench, not a page.
 * It is built on views/common/orbit-shell.php, so the shell is exercised by a real page — and nothing
 * here touches the existing client views. When the frontend work is approved, the rebuilt pages adopt
 * the same shell and the same classes.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../../models/is_admin.php";

require_once __DIR__ . "/orbit-shell.php";
require_once __DIR__ . "/chart.php";
require_once __DIR__ . "/pagination.php";

/* The shell provides the document, the top bar and the footer; the styleguide only adds its own bits. */
$orbit = array(
    "title" => "Orbit design system (admin preview)",
    "noindex" => true,
    "head" => '<link rel="stylesheet" href="' . BASE_URL("public/client/orbit/music-player.css") . '?v='
        . orbit_shell_asset_version("public/client/orbit/music-player.css") . '">',
    "actions_html" => '<div class="segmented" role="group" aria-label="Theme">'
        . '<button type="button" data-theme-toggle="dark" class="is-active">Dark</button>'
        . '<button type="button" data-theme-toggle="light">Light</button></div>',
    "scripts" => '<script>(function(){document.querySelectorAll("[data-theme-toggle]").forEach(function(b){'
        . 'b.addEventListener("click",function(){document.documentElement.setAttribute("data-theme",b.getAttribute("data-theme-toggle"));'
        . 'document.querySelectorAll("[data-theme-toggle]").forEach(function(o){o.classList.toggle("is-active",o===b);});});});'
        . 'document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){'
        . 'var i=b.parentNode.querySelector("input");if(!i)return;i.select();if(navigator.clipboard)navigator.clipboard.writeText(i.value);'
        . 'b.textContent="Copied";window.setTimeout(function(){b.textContent="Copy";},1500);});});})();</script>'
        /* The table section advertises sorting/filtering; without this file its buttons did nothing. */
        . '<script defer src="' . BASE_URL("public/client/orbit/table.js") . '?v='
        . orbit_shell_asset_version("public/client/orbit/table.js") . '"></script>',
);
orbit_shell_open($orbit);
orbit_chart_assets();
?>
<div class="stack stack--lg">
  <header>
    <h1 style="margin-bottom: var(--space-1)">Orbit design system</h1>
    <p class="muted" style="margin:0">Tokens, layout and components — the shell the rebuilt pages will use. Admin-only, not linked anywhere.</p>
  </header>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Colour</h2><span class="pill">tokens.css</span></div>
    <div class="card__body">
      <div class="grid grid--4">
        <?php
        $orbitSwatches = array(
            "bg" => "Background", "surface" => "Surface", "surface-2" => "Surface 2", "border" => "Border",
            "text" => "Text", "text-muted" => "Muted", "accent" => "Accent", "ok" => "Success",
            "warn" => "Warning", "danger" => "Danger", "info" => "Info",
        );
        foreach ($orbitSwatches as $orbitToken => $orbitLabel) {
            echo '<div><div style="height:56px;border-radius:var(--radius);border:1px solid var(--border);background:var(--' . $orbitToken . ')"></div>'
                . '<p class="small muted" style="margin:var(--space-2) 0 0"><span class="mono">--' . $orbitToken . '</span><br>' . $orbitLabel . '</p></div>';
        }
        ?>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Typography</h2><span class="pill">base.css</span></div>
    <div class="card__body">
      <h1>Heading 1 — the page promise</h1>
      <h2>Heading 2 — a section</h2>
      <h3>Heading 3 — a group</h3>
      <p class="lead">Lead paragraph for the one sentence that matters most on a screen.</p>
      <p>Body text at 16px with 1.55 line height. <a href="#">A link looks like this</a>, and
        <span class="mono">inline code</span> looks like that. <span class="muted">Muted text</span> carries
        secondary detail; <span class="faint">faint text</span> is for timestamps and captions.</p>
      <p class="small muted">Small print — terms, footnotes, rate limits.</p>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Buttons</h2><span class="pill">components.css</span></div>
    <div class="card__body stack">
      <div class="btn-group">
        <button class="btn btn--primary" type="button">Primary action</button>
        <button class="btn" type="button">Secondary</button>
        <button class="btn btn--ghost" type="button">Ghost</button>
        <button class="btn btn--danger" type="button">Destructive</button>
        <button class="btn" type="button" disabled>Disabled</button>
        <button class="btn btn--link" type="button">Link button</button>
      </div>
      <div class="btn-group">
        <button class="btn btn--sm" type="button">Small</button>
        <button class="btn btn--primary btn--lg" type="button">Large primary</button>
        <a class="btn btn--ghost" href="#">Anchor styled as a button</a>
      </div>
      <div><button class="btn btn--primary btn--block" type="button">Block button (mobile-first forms)</button></div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Forms</h2><span class="pill">components.css</span></div>
    <div class="card__body">
      <div class="grid grid--2">
        <div>
          <label class="field">
            <span class="field__label">Email</span>
            <input class="input" type="email" placeholder="you@example.com">
            <span class="field__help">We only use this to reach you about orders.</span>
          </label>
          <label class="field">
            <span class="field__label">Licence key</span>
            <input class="input mono input--invalid" value="ORBIT-XXXXX-…" aria-describedby="key-error">
            <span class="field__error" id="key-error">That key is not valid for this domain.</span>
          </label>
          <label class="field">
            <span class="field__label">Quantity</span>
            <select class="select"><option>1</option><option>5</option><option>10</option></select>
          </label>
          <label class="check"><input type="checkbox" checked> Email me when the order is ready</label>
        </div>
        <div>
          <div class="search">
            <input class="input" type="search" placeholder="Search products…">
            <button class="btn btn--primary" type="button">Search</button>
          </div>
          <div class="copy-row" style="margin-top: var(--space-4)">
            <input class="input" value="ORBIT-8F2KD-…-QW" readonly>
            <button class="btn btn--ghost" type="button" data-copy>Copy</button>
          </div>
          <p class="field__help">Copy needs JavaScript; without it the value is still selectable.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Alerts &amp; pills</h2></div>
    <div class="card__body stack">
      <div class="alert alert--ok"><p class="alert__title">Order completed</p><p>The accounts were delivered to your email.</p></div>
      <div class="alert alert--warn"><p class="alert__title">Check the amount</p><p>The transfer arrived with a different value; the order stays in manual review.</p></div>
      <div class="alert alert--danger"><p class="alert__title">Payment not confirmed</p><p>Nothing was credited. Please contact support with the reference.</p></div>
      <div class="row">
        <span class="pill pill--ok">active</span>
        <span class="pill pill--warn">pending</span>
        <span class="pill pill--danger">revoked</span>
        <span class="pill pill--accent">new</span>
        <span class="pill"><span class="dot dot--ok"></span> online</span>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Table</h2><span class="pill">responsive wrapper</span></div>
    <div class="card__body" style="padding:0">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Licence</th><th>Status</th><th>Expires</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <tr><td class="mono">ORBIT-8F2KD-****-****-QW</td><td><span class="pill pill--ok">active</span></td><td>2026-12-31</td><td class="num price">1.500.000₫</td></tr>
            <tr><td class="mono">ORBIT-1PLMQ-****-****-ZR</td><td><span class="pill pill--warn">in 12 days</span></td><td>2026-10-17</td><td class="num price">900.000₫</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="grid grid--2">
    <div class="card">
      <div class="card__header"><h2 class="card__title">Empty state</h2></div>
      <div class="card__body">
        <div class="empty">
          <p class="empty__title">No orders yet</p>
          <p class="small">When you buy a licence it appears here, with the key and the expiry date.</p>
          <div class="empty__actions"><button class="btn btn--primary" type="button">Browse products</button></div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__header"><h2 class="card__title">Loading &amp; key/value</h2></div>
      <div class="card__body stack">
        <div class="skeleton skeleton--title"></div>
        <div class="skeleton skeleton--text"></div>
        <div class="skeleton skeleton--text" style="width:80%"></div>
        <hr class="divider">
        <dl class="kv">
          <dt>Domain</dt><dd class="mono">shop.example.com</dd>
          <dt>Hardware ID</dt><dd class="mono">machine-abcdefgh</dd>
          <dt>Last check-in</dt><dd>2 minutes ago</dd>
        </dl>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">KPI tiles &amp; deltas</h2><span class="pill">.stat</span></div>
    <div class="card__body">
      <div class="stats">
        <div class="stat stat--accent">
          <p class="stat__label">Doanh thu</p>
          <p class="stat__value">3.600.000đ</p>
          <p class="stat__foot"><span class="delta delta--up">50,0%</span> <span class="delta__note">so với kỳ trước</span></p>
        </div>
        <div class="stat stat--ok">
          <p class="stat__label">Lợi nhuận</p>
          <p class="stat__value">1.200.000đ</p>
          <p class="stat__foot"><span class="delta delta--down">12,5%</span> <span class="delta__note">biên 33,3%</span></p>
        </div>
        <div class="stat">
          <p class="stat__label">Đơn hàng</p>
          <p class="stat__value">12</p>
          <p class="stat__foot"><span class="delta delta--flat">chưa có kỳ trước</span></p>
        </div>
      </div>
      <p class="small muted" style="margin-top:var(--space-4)">Một con số, một nhãn, một dòng so sánh. Không có ô nào trống: khi thiếu dữ liệu để so sánh, ô đó nói thẳng là chưa có kỳ trước thay vì hiện +100%.</p>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Charts</h2><span class="pill">libs/chart.php + chart.js</span></div>
    <div class="card__body stack">
      <?php
      orbit_chart(array(
          "type" => "area",
          "title" => "Doanh thu theo ngày",
          "caption" => "Sinh từ dữ liệu mẫu trong styleguide — math tính ở PHP, chart.js chỉ vẽ",
          "labels" => range(1, 30),
          "series" => array(array("name" => "Doanh thu", "values" => array_map(function ($i) {
              return 900000 + sin($i / 3) * 350000 + $i * 12000;
          }, range(1, 30)), "color" => "accent")),
          "height" => 220,
          "smooth" => true,
          "format" => "currency",
          "suffix" => "đ",
          "formatter" => function ($value) {
              return format_currency($value);
          },
          "label_head" => "Ngày",
      ));
      ?>
      <div class="grid grid--2">
        <?php
        orbit_chart(array(
            "type" => "bar",
            "title" => "Số đơn theo ngày",
            "labels" => range(1, 14),
            "series" => array(array("name" => "Đơn hàng", "values" => array(3, 5, 4, 6, 8, 7, 5, 9, 6, 4, 7, 10, 8, 6), "color" => "info")),
            "height" => 180,
            "label_head" => "Ngày",
        ));
        orbit_chart(array(
            "type" => "donut",
            "title" => "Tiền nạp theo phương thức",
            "slices" => array("payOS" => 8600000, "Ngân hàng" => 4200000, "MoMo" => 1350000, "Khác" => 720000),
            "height" => 220,
            "format" => "currency",
            "formatter" => function ($value) {
                return format_currency($value);
            },
        ));
        ?>
      </div>
      <p class="small muted">Không JavaScript: mỗi biểu đồ tự thay bằng bảng số liệu (bảng nằm trong DOM, chỉ bị ẩn về mặt hình ảnh khi biểu đồ vẽ xong). Không có thư viện ngoài, không gọi mạng.</p>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Bảng dữ liệu</h2><span class="pill">table.js (tùy chọn)</span></div>
    <div class="card__body" style="padding:0">
      <div class="toolbar">
        <div class="toolbar__field">
          <label class="toolbar__label" for="sg-search">Tìm trong bảng</label>
          <input class="input" type="search" id="sg-search" placeholder="Nhập để lọc…">
        </div>
        <div class="toolbar__spacer"></div>
        <span class="toolbar__count" id="sg-count" data-template="Hiển thị %shown% / %total% dòng"></span>
      </div>
      <div class="table-wrap">
        <table class="table table--zebra table--sticky table--responsive"
               data-table data-sortable data-table-filter="#sg-search" data-table-count="#sg-count">
          <caption>Bấm vào tiêu đề để sắp xếp; gõ để lọc. Không có JavaScript thì bảng vẫn đầy đủ.</caption>
          <thead>
            <tr>
              <th><button class="table__sort" type="button">Sản phẩm</button></th>
              <th class="num"><button class="table__sort" type="button">Đơn</button></th>
              <th class="num"><button class="table__sort" type="button">Doanh thu</button></th>
              <th>Tỷ trọng</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="cell-main">Netflix Premium 1 tháng</span><span class="cell-sub mono">#5</span></td>
              <td class="num" data-sort-value="42">42</td>
              <td class="num" data-sort-value="5040000">5.040.000đ</td>
              <td><div class="progress-row"><div class="progress"><span class="progress__bar" style="width:48%"></span></div><span class="progress-row__value">48,0%</span></div></td>
            </tr>
            <tr>
              <td><span class="cell-main">Spotify Premium 3 tháng</span><span class="cell-sub mono">#6</span></td>
              <td class="num" data-sort-value="28">28</td>
              <td class="num" data-sort-value="2940000">2.940.000đ</td>
              <td><div class="progress-row"><div class="progress"><span class="progress__bar progress__bar--ok" style="width:28%"></span></div><span class="progress-row__value">28,0%</span></div></td>
            </tr>
            <tr>
              <td><span class="cell-main">Canva Pro 1 năm</span><span class="cell-sub mono">#7</span></td>
              <td class="num" data-sort-value="12">12</td>
              <td class="num" data-sort-value="1500000">1.500.000đ</td>
              <td><div class="progress-row"><div class="progress"><span class="progress__bar progress__bar--warn" style="width:14%"></span></div><span class="progress-row__value">14,3%</span></div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php
      orbit_pagination(array(
          "page" => 2,
          "per_page" => 20,
          "total" => 95,
          "aria" => "Phân trang ví dụ",
          "href" => function ($page) {
              return "?module=common&amp;action=orbit-styleguide&amp;page=" . (int) $page;
          },
      ));
      ?>
    </div>
  </section>

  <section class="grid grid--2">
    <div class="card">
      <div class="card__header"><h2 class="card__title">Timeline</h2></div>
      <div class="card__body">
        <ol class="timeline">
          <li class="timeline__item timeline__item--done">
            <p class="timeline__title">Đơn hàng đã tạo</p>
            <p class="timeline__meta">09:15 · mã ORD9001</p>
          </li>
          <li class="timeline__item timeline__item--done">
            <p class="timeline__title">Thanh toán đã nhận</p>
            <p class="timeline__meta">09:16 · payOS</p>
          </li>
          <li class="timeline__item timeline__item--active">
            <p class="timeline__title">Đang giao tài khoản</p>
            <p class="timeline__meta">09:16 · tự động</p>
          </li>
          <li class="timeline__item">
            <p class="timeline__title">Hoàn tất</p>
            <p class="timeline__meta">chờ hệ thống xác nhận</p>
          </li>
        </ol>
      </div>
    </div>

    <div class="card">
      <div class="card__header"><h2 class="card__title">Công tắc, accordion, tooltip</h2></div>
      <div class="card__body stack">
        <label class="switch">
          <input type="checkbox" checked>
          <span class="switch__track"></span>
          <span class="switch__label">Bật thông báo đơn mới</span>
        </label>
        <label class="switch">
          <input type="checkbox">
          <span class="switch__track"></span>
          <span class="switch__label">Tắt thông báo (đang tắt)</span>
        </label>
        <details class="accordion" open>
          <summary class="accordion__summary">Tuỳ chọn nâng cao</summary>
          <div class="accordion__body"><p class="small muted" style="margin:0">Nội dung ẩn mặc định vẫn nằm trong DOM và đọc được bằng bàn phím — dùng <span class="mono">&lt;details&gt;</span>, không cần JavaScript.</p></div>
        </details>
        <p class="small muted" style="margin:0">Di chuột hoặc tab vào đây:
          <span class="pill" data-tip="Tooltip thuần CSS, hiện cả khi dùng bàn phím" tabindex="0">?</span>
        </p>
        <div class="alert alert--warn" style="margin:0"><p style="margin:0">Cảnh báo dùng cho việc cần chú ý nhưng không chặn: ví dụ tiền nạp lệch, đơn đang chờ duyệt.</p></div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Music player</h2><span class="pill">off until a licensed track exists</span></div>
    <div class="card__body stack">
      <p class="muted">The player renders nothing at all while <span class="mono">public/music/playlist.json</span>
        is empty. Below is the collapsed control and the expanded panel, drawn statically — this preview has no audio
        and never autoplays.</p>
      <div class="row" style="gap: var(--space-6)">
        <div class="om om--static" style="align-items:flex-start">
          <button class="om__toggle" type="button" aria-label="Music player">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M10 16V9l5-1v7"/></svg>
          </button>
          <div class="om__panel">
            <div class="om__head">
              <div><p class="om__title">Calm theme 01</p><p class="om__meta">Orbit Studio · CC0 · 1/3</p></div>
              <button type="button" class="om__close" aria-label="Close player">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
              </button>
            </div>
            <div class="om__controls">
              <button type="button" class="om__btn" aria-label="Previous track"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6v12L9 12z" fill="currentColor"/><path d="M7 6v12" stroke="currentColor" stroke-width="1.8"/></svg></button>
              <button type="button" class="om__btn om__btn--main" aria-label="Play"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></button>
              <button type="button" class="om__btn" aria-label="Next track"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6v12l9-6z" fill="currentColor"/><path d="M17 6v12" stroke="currentColor" stroke-width="1.8"/></svg></button>
            </div>
            <div class="om__row"><span aria-hidden="true">Vol</span><input type="range" min="0" max="1" step="0.05" value="0.6" aria-label="Volume"><button type="button" class="btn--link">Mute</button></div>
            <p class="om__status"></p>
          </div>
        </div>
        <div>
          <button class="om__resume" type="button">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
            <span>Continue listening</span>
          </button>
          <p class="field__help">Shown after a page change when the visitor had music playing — one click, never autoplay.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card__header"><h2 class="card__title">Rules this system encodes</h2></div>
    <div class="card__body">
      <ul class="small muted">
        <li>Only tokens: no page invents a colour, a spacing value or a font size.</li>
        <li>No third-party requests, no build step, no JavaScript in the critical path.</li>
        <li>Every interactive element is reachable by keyboard with a visible focus ring.</li>
        <li>Motion only when the visitor has not asked for reduced motion.</li>
        <li>Images carry width/height; the player reserves its own space, so nothing shifts.</li>
      </ul>
      <p class="small faint" style="margin-top: var(--space-4)">Next: pages adopt <span class="mono">tokens.css → base.css → components.css</span> and compose these classes. Nothing in the existing client views changes until you ask for it.</p>
    </div>
  </section>

</div><!-- /.stack -->

<?php
orbit_shell_close($orbit);

