<?php
/*
 * Blog index — the shop's own articles, paginated and searchable.
 *
 *   /blogs
 *   /blogs?category=3&keyword=netflix&shortby=2&limit=20&page=2
 *
 * Reads the same `posts` rows as the legacy page (status = 1), keeps the same filters and page size,
 * and keeps the "popular" list. Unlike the old page it does not run one query per category badge: the
 * counts come from one grouped query, so a shop with 20 categories does not send 21 queries.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

require_once __DIR__ . "/../common/store-shell.php";
require_once __DIR__ . "/../common/pagination.php";

$storeLimit = isset($_GET["limit"]) ? (int) check_string($_GET["limit"]) : 10;
if (!in_array($storeLimit, array(10, 20, 40), true)) {
    $storeLimit = 10;
}
$storePage = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$storeKeyword = isset($_GET["keyword"]) ? trim((string) check_string($_GET["keyword"])) : "";
$storeShortby = isset($_GET["shortby"]) ? (int) $_GET["shortby"] : 1;
$storeCategory = isset($_GET["category"]) ? (int) $_GET["category"] : 0;

$storeWhere = " `status` = 1 ";
if ($storeCategory > 0) {
    $storeWhere .= " AND `category_id` = " . $storeCategory . " ";
}
if ($storeKeyword !== "") {
    $storeWhere .= " AND `title` LIKE \"%" . $storeKeyword . "%\" ";
}
$storeWhere .= $storeShortby === 2 ? " ORDER BY `view` DESC " : " ORDER BY `id` DESC ";

$storeTotal = (int) $CMSNT->num_rows(" SELECT * FROM `posts` WHERE " . $storeWhere . " ");
$storeState = orbit_pagination_state($storeTotal, $storeLimit, $storePage);
$storePosts = $CMSNT->get_list(
    " SELECT * FROM `posts` WHERE " . $storeWhere . " LIMIT " . (int) $storeState["offset"] . "," . (int) $storeLimit . " "
);

$storeCategories = array();
foreach ((array) $CMSNT->get_list(" SELECT * FROM `post_category` WHERE `status` = 1 ") as $storeRow) {
    $storeCategories[] = $storeRow;
}
$storeCounts = array();
foreach ((array) $CMSNT->get_list(" SELECT `category_id`, COUNT(id) AS `total` FROM `posts` WHERE `status` = 1 GROUP BY `category_id` ") as $storeRow) {
    $storeCounts[(int) $storeRow["category_id"]] = (int) $storeRow["total"];
}
$storePopular = $CMSNT->get_list(" SELECT * FROM `posts` WHERE `status` = 1 ORDER BY `view` DESC LIMIT 4 ");

$storeCategoryName = "";
foreach ($storeCategories as $storeRow) {
    if ((int) $storeRow["id"] === $storeCategory) {
        $storeCategoryName = (string) $storeRow["name"];
    }
}

$storeTitle = $storeCategoryName !== "" ? $storeCategoryName : "Bài viết";
$storeHeading = $storeCategoryName !== "" ? $storeCategoryName : "Tất cả bài viết";
$storeBaseUrl = function ($page) use ($storeLimit, $storeKeyword, $storeShortby, $storeCategory) {
    $query = array("action" => "blogs", "page" => (int) $page, "limit" => $storeLimit);
    if ($storeKeyword !== "") {
        $query["keyword"] = $storeKeyword;
    }
    if ($storeShortby !== 1) {
        $query["shortby"] = $storeShortby;
    }
    if ($storeCategory > 0) {
        $query["category"] = $storeCategory;
    }
    return base_url("?" . http_build_query($query));
};

$storeShell = array(
    "title" => $storeTitle . " | " . $CMSNT->site("title"),
    "desc" => $CMSNT->site("description"),
    "nav" => store_nav_default($CMSNT),
    "search" => true,
    "search_value" => $storeKeyword,
    "body_class" => "page-blog",
);
store_shell_open($storeShell);
?>
<section class="section section--tight" aria-labelledby="blog-title">
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Bài viết</p>
        <h1 id="blog-title"><?php echo store_e($storeHeading); ?></h1>
        <p class="muted m0">Hướng dẫn sử dụng, kinh nghiệm mua tài khoản và thông báo từ shop.</p>
      </div>
      <span class="pill"><?php echo (int) $storeTotal; ?> bài viết</span>
    </div>

    <div class="catalog-bar">
      <div class="chips">
        <a class="chip" href="<?php echo store_e($storeBaseUrl(1)); ?>"<?php echo $storeCategory === 0 ? ' aria-current="page"' : ""; ?>>Tất cả</a>
<?php foreach ($storeCategories as $storeRow) { ?>
        <a class="chip" href="<?php echo store_e(base_url("?action=blogs&category=" . (int) $storeRow["id"] . "&limit=" . $storeLimit)); ?>"<?php echo (int) $storeRow["id"] === $storeCategory ? ' aria-current="page"' : ""; ?>><?php echo store_e($storeRow["name"]); ?></a>
<?php } ?>
      </div>
      <div class="catalog-bar__spacer"></div>
      <form class="catalog-bar__sort" action="<?php echo BASE_URL(""); ?>" method="get">
        <input type="hidden" name="action" value="blogs">
        <input type="hidden" name="limit" value="<?php echo (int) $storeLimit; ?>">
<?php if ($storeCategory > 0) { ?>
        <input type="hidden" name="category" value="<?php echo (int) $storeCategory; ?>">
<?php } ?>
        <label class="sr-only" for="blog-sort">Sắp xếp bài viết</label>
        <select class="select" id="blog-sort" name="shortby">
          <option value="1"<?php echo $storeShortby !== 2 ? " selected" : ""; ?>>Mới nhất</option>
          <option value="2"<?php echo $storeShortby === 2 ? " selected" : ""; ?>>Xem nhiều</option>
        </select>
        <button class="btn btn--ghost" type="submit">Áp dụng</button>
      </form>
    </div>

<?php if ($storePosts) { ?>
    <div class="blog-list">
<?php foreach ($storePosts as $storePost) {
    $storeExcerpt = trim(preg_replace('/\s+/', ' ', strip_tags(base64_decode((string) $storePost["content"]))));
    if (function_exists("mb_substr")) {
        $storeExcerpt = mb_substr($storeExcerpt, 0, 180, "UTF-8");
    } else {
        $storeExcerpt = substr($storeExcerpt, 0, 180);
    }
?>
      <article class="blog-item">
        <a class="blog-item__media" href="<?php echo BASE_URL("blog/" . $storePost["slug"]); ?>" tabindex="-1" aria-hidden="true">
<?php if (trim((string) $storePost["image"]) !== "") { ?>
          <img src="<?php echo store_e(BASE_URL($storePost["image"])); ?>" alt="" width="320" height="200" loading="lazy" decoding="async">
<?php } else { ?>
          <span class="blog-item__mark"><?php echo store_e(store_first_char($storePost["title"])); ?></span>
<?php } ?>
        </a>
        <div class="blog-item__body">
          <p class="blog-item__meta">
            <time datetime="<?php echo store_e(date("c", strtotime($storePost["create_gettime"]))); ?>"><?php echo store_e(date("d/m/Y", strtotime($storePost["create_gettime"]))); ?></time>
            <?php if (!empty($storePost["view"])) { ?><span aria-hidden="true">·</span><span><?php echo format_cash($storePost["view"]); ?> lượt xem</span><?php } ?>
          </p>
          <h2 class="blog-item__title"><a href="<?php echo BASE_URL("blog/" . $storePost["slug"]); ?>"><?php echo store_e($storePost["title"]); ?></a></h2>
          <p class="blog-item__desc"><?php echo store_e($storeExcerpt); ?><?php echo $storeExcerpt !== "" ? "…" : ""; ?></p>
          <a class="blog-item__more" href="<?php echo BASE_URL("blog/" . $storePost["slug"]); ?>">Đọc bài</a>
        </div>
      </article>
<?php } ?>
    </div>

    <?php orbit_pagination(array(
        "total" => (int) $storeTotal,
        "per_page" => $storeLimit,
        "page" => (int) $storeState["page"],
        "href" => $storeBaseUrl,
        "labels" => array("summary" => "Hiển thị %from%–%to% trong %total% bài viết"),
    )); ?>
<?php } else { ?>
    <div class="empty">
      <h2 class="empty__title">Chưa có bài viết nào</h2>
      <p class="muted"><?php echo $storeKeyword !== "" ? "Không tìm thấy bài viết khớp từ khoá." : "Shop chưa đăng bài nào ở mục này."; ?></p>
      <p class="empty__actions"><a class="btn" href="<?php echo BASE_URL("client/home"); ?>">Về trang chủ</a></p>
    </div>
<?php } ?>
  </div>
</section>

<?php if ($storePopular && count($storePosts) > 0) { ?>
<section class="section section--plain" aria-labelledby="blog-popular">
  <div class="container">
    <div class="blog-side">
      <h2 class="blog-side__title" id="blog-popular">Đọc nhiều</h2>
      <ul class="blog-side__list">
<?php foreach ($storePopular as $storePost) { ?>
        <li><a href="<?php echo BASE_URL("blog/" . $storePost["slug"]); ?>"><?php echo store_e($storePost["title"]); ?></a></li>
<?php } ?>
      </ul>
    </div>
  </div>
</section>
<?php } ?>
<?php
store_shell_close($storeShell);
