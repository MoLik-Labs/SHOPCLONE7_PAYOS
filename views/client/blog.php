<?php
/*
 * Blog post — one `posts` row by slug, with the shop's other posts beside it.
 *
 * The body is raw HTML written in the admin (`content` is base64), like the legacy page. The view
 * count is incremented once per render with the same `cong()` call the old page used.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!isset($_GET["slug"])) {
    redirect(base_url("blogs"));
}
$storePost = $CMSNT->get_row(" SELECT * FROM `posts` WHERE `slug` = '" . check_string($_GET["slug"]) . "' AND `status` = 1 ");
if (!$storePost) {
    redirect(base_url("blogs"));
}
$CMSNT->cong("posts", "view", 1, " `id` =  '" . (int) $storePost["id"] . "' ");

require_once __DIR__ . "/../common/store-shell.php";

$storeContent = base64_decode((string) $storePost["content"]);
$storeOthers = $CMSNT->get_list(
    " SELECT * FROM `posts` WHERE `status` = 1 AND `id` != " . (int) $storePost["id"] . " ORDER BY `id` DESC LIMIT 5 "
);

$storeShell = array(
    "title" => $storePost["title"] . " | " . $CMSNT->site("title"),
    "desc" => trim(preg_replace('/\s+/', ' ', strip_tags($storeContent))),
    "image" => $storePost["image"],
    "nav" => store_nav_default($CMSNT),
    "body_class" => "page-blog",
);
store_shell_open($storeShell);
?>
<article class="section section--tight">
  <div class="container">
    <nav aria-label="Đường dẫn">
      <ol class="breadcrumbs">
        <li><a href="<?php echo BASE_URL("client/home"); ?>">Trang chủ</a></li>
        <li><a href="<?php echo BASE_URL("blogs"); ?>">Bài viết</a></li>
        <li aria-current="page"><?php echo store_e($storePost["title"]); ?></li>
      </ol>
    </nav>

    <header class="blog-head">
      <h1><?php echo store_e($storePost["title"]); ?></h1>
      <p class="blog-item__meta">
        <time datetime="<?php echo store_e(date("c", strtotime($storePost["create_gettime"]))); ?>"><?php echo store_e(date("d/m/Y", strtotime($storePost["create_gettime"]))); ?></time>
        <span aria-hidden="true">·</span><span><?php echo format_cash((int) $storePost["view"] + 1); ?> lượt xem</span>
      </p>
    </header>

    <div class="blog-layout">
      <div class="panel">
        <div class="panel__body prose">
          <?php echo $storeContent; ?>
        </div>
      </div>

<?php if ($storeOthers) { ?>
      <aside class="blog-side" aria-labelledby="blog-more">
        <h2 class="blog-side__title" id="blog-more">Bài khác</h2>
        <ul class="blog-side__list">
<?php foreach ($storeOthers as $storeOther) { ?>
          <li><a href="<?php echo BASE_URL("blog/" . $storeOther["slug"]); ?>"><?php echo store_e($storeOther["title"]); ?></a></li>
<?php } ?>
        </ul>
        <a class="btn btn--ghost btn--block" href="<?php echo BASE_URL("blogs"); ?>">Tất cả bài viết</a>
      </aside>
<?php } ?>
    </div>

    <p class="muted orders-foot">Cần hỗ trợ sau khi đọc? <a href="<?php echo BASE_URL("client/contact"); ?>">Liên hệ shop</a>.</p>
  </div>
</article>
<?php
store_shell_close($storeShell);
