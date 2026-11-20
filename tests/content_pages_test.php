<?php
/* CLI only: these files are test code, not a route. Without this, a deployment that does not
   honour .htaccess (nginx, a static host) would serve them; the 404 tells a scanner nothing. */
if (PHP_SAPI !== "cli" && PHP_SAPI !== "phpdbg") {
    http_response_code(404);
    exit;
}
/*
 * Content pages — FAQ, policy, blog index, one post.
 *
 *   php tests/content_pages_test.php
 *
 * The four pages are admin-authored content on the store shell. The checks prove: the real row is
 * printed, an empty setting renders the honest empty state (never a blank box), the blog keeps its
 * filters and pagination, no inline style or third-party script comes back, and no PHP warning escapes.
 */

define("IN_SITE", true);

if (!function_exists("BASE_URL")) {
    function BASE_URL($path = "")
    {
        return "https://shop.example/" . ltrim((string) $path, "/");
    }
}
if (!function_exists("base_url")) {
    function base_url($path = "")
    {
        return BASE_URL($path);
    }
}
if (!function_exists("check_string")) {
    function check_string($data)
    {
        return trim(htmlspecialchars(addslashes((string) $data)));
    }
}
if (!function_exists("__")) {
    function __($text)
    {
        return $text;
    }
}
if (!function_exists("format_cash")) {
    function format_cash($number, $suffix = "")
    {
        return number_format((float) $number, 0, ",", ".") . (string) $suffix;
    }
}
if (!function_exists("format_currency")) {
    function format_currency($amount)
    {
        return number_format((float) $amount, 0, ",", ".") . "đ";
    }
}
/* `redirect()` calls exit() in production; in a test that would end the process before the summary.
   Throw a marker instead and let render_page() turn it into the same "REDIRECT:" string. */
class RedirectSignal extends Exception
{
}
if (!function_exists("redirect")) {
    function redirect($url)
    {
        throw new RedirectSignal((string) $url);
    }
}

class ContentStub
{
    public $posts;
    public $faq;
    public $policy;
    public $counted = 0;

    public function __construct()
    {
        $this->posts = array(
            array("id" => 2, "slug" => "bao-hanh", "title" => "Bảo hành hoạt động thế nào?",
                "content" => base64_encode("<p>Gửi mã đơn cho hỗ trợ.</p>"), "image" => "",
                "category_id" => 1, "status" => 1, "view" => 351, "create_gettime" => "2026-09-24 14:40:00"),
            array("id" => 1, "slug" => "doi-mat-khau", "title" => "Đổi mật khẩu lần đầu",
                "content" => base64_encode("<p>Đổi ngay sau khi nhận.</p>"), "image" => "",
                "category_id" => 1, "status" => 1, "view" => 128, "create_gettime" => "2026-09-10 08:05:00"),
        );
        $this->faq = "<h2>Giao hàng thế nào?</h2><p>Thông tin hiện trong Lịch sử.</p>";
        $this->policy = "<h2>Bảo hành</h2><p>Theo thời hạn trên trang sản phẩm.</p>";
    }

    public function site($key)
    {
        $map = array("title" => "Orbit Shop", "description" => "Shop bán tài khoản", "keywords" => "",
            "page_faq" => $this->faq, "page_policy" => $this->policy, "hotline" => "0909 123 456",
            "email" => "support@orbit.example", "address" => "", "bank_status" => "1");
        return isset($map[$key]) ? $map[$key] : "";
    }

    public function get_row($sql)
    {
        if (strpos($sql, "FROM `posts`") !== false && preg_match("/`slug` = '([^']+)'/", $sql, $match)) {
            foreach ($this->posts as $post) {
                if ($post["slug"] === $match[1] && (int) $post["status"] === 1) {
                    return $post;
                }
            }
        }
        return false;
    }

    public function get_list($sql)
    {
        if (strpos($sql, "FROM `posts`") !== false && strpos($sql, "GROUP BY") === false) {
            return $this->posts;
        }
        if (strpos($sql, "FROM `post_category`") !== false) {
            return array(array("id" => 1, "name" => "Hướng dẫn", "status" => 1));
        }
        return array();
    }

    public function num_rows($sql)
    {
        return strpos($sql, "FROM `posts`") !== false ? count($this->posts) : 0;
    }

    public function cong($table, $field, $value, $where = "")
    {
        if ($table === "posts" && $field === "view") {
            ++$this->counted;
        }
        return true;
    }

    public function update($table, $data, $where = "")
    {
        return true;
    }

    public function insert($table, $data)
    {
        return true;
    }
}

$CMSNT = new ContentStub();
$GLOBALS["CMSNT"] = $CMSNT;

$pass = 0;
$fail = 0;
function check($name, $condition)
{
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "  ok   " . $name . "\n";
    } else {
        ++$fail;
        echo "  FAIL " . $name . "\n";
    }
}

function render_page($view, $get)
{
    /* The views read the router's $CMSNT; an include inside a function has its own scope. */
    global $warnings, $CMSNT;
    $_GET = $get;
    $warnings = array();
    set_error_handler(function ($severity, $message, $file, $line) use (&$warnings) {
        $warnings[] = $message . " @ " . basename($file) . ":" . $line;
        return true;
    });
    ob_start();
    try {
        include __DIR__ . "/../views/client/" . $view . ".php";
        $html = ob_get_clean();
    } catch (RedirectSignal $signal) {
        ob_end_clean();
        $html = "REDIRECT:" . $signal->getMessage();
    }
    restore_error_handler();
    return $html;
}

function common_checks($html, $label, $warnings)
{
    check($label . ": one h1", preg_match_all('/<h1[\s>]/', $html) === 1);
    check($label . ": on the store shell", strpos($html, 'data-theme="light"') !== false && strpos($html, "store.css") !== false);
    check($label . ": no inline style", strpos($html, 'style="') === false);
    check($label . ": no third-party host", stripos($html, "cdnjs") === false && stripos($html, "googleapis") === false
        && stripos($html, "fonts.gstatic") === false);
    check($label . ": no PHP warning", $warnings === array());
    check($label . ": footer names the shop, not the page", strpos($html, "© 20") !== false);
}

/* --- FAQ ------------------------------------------------------------------ */

echo "FAQ\n";
$html = render_page("faq", array("module" => "client", "action" => "faq"));
common_checks($html, "faq", $warnings);
check("faq: the owner's answer is printed", strpos($html, "Thông tin hiện trong Lịch sử.") !== false);
check("faq: the answer keeps its heading", strpos($html, "<h2>Giao hàng thế nào?</h2>") !== false);
$GLOBALS["CMSNT"]->faq = "";
$emptyFaq = render_page("faq", array("module" => "client", "action" => "faq"));
check("faq: an empty setting shows an honest empty state", strpos($emptyFaq, "Shop chưa đăng câu hỏi nào") !== false);
$GLOBALS["CMSNT"]->faq = "<h2>Giao hàng thế nào?</h2><p>Thông tin hiện trong Lịch sử.</p>";

/* --- policy --------------------------------------------------------------- */

echo "\nPolicy\n";
$html = render_page("policy", array("module" => "client", "action" => "policy"));
common_checks($html, "policy", $warnings);
check("policy: the owner's terms are printed", strpos($html, "Theo thời hạn trên trang sản phẩm.") !== false);
$GLOBALS["CMSNT"]->policy = "";
$emptyPolicy = render_page("policy", array("module" => "client", "action" => "policy"));
check("policy: an empty setting shows an honest empty state", strpos($emptyPolicy, "Shop chưa đăng chính sách") !== false);
$GLOBALS["CMSNT"]->policy = "<h2>Bảo hành</h2><p>Theo thời hạn trên trang sản phẩm.</p>";

/* --- blog index ----------------------------------------------------------- */

echo "\nBlog index\n";
$html = render_page("blogs", array("module" => "client", "action" => "blogs"));
common_checks($html, "blogs", $warnings);
check("blogs: both posts are listed", strpos($html, "Bảo hành hoạt động thế nào?") !== false
    && strpos($html, "Đổi mật khẩu lần đầu") !== false);
check("blogs: the category chip is a link", strpos($html, "?action=blogs&amp;category=1") !== false);
check("blogs: the sort form keeps the page size", strpos($html, 'name="limit" value="10"') !== false);
check("blogs: the empty state exists when there is nothing", strpos(file_get_contents(__DIR__ . "/../views/client/blogs.php"), "Chưa có bài viết nào") !== false);
check("blogs: the old per-category count query is gone", strpos(file_get_contents(__DIR__ . "/../views/client/blogs.php"), "COUNT(id) FROM `posts` WHERE `category_id`") === false);
check("blogs: the index shows an excerpt, not the post markup", strpos($html, "<p>Gửi mã đơn cho hỗ trợ.</p>") === false
    && strpos($html, "Gửi mã đơn cho hỗ trợ.") !== false);

/* --- one post ------------------------------------------------------------- */

echo "\nBlog post\n";
$html = render_page("blog", array("module" => "client", "action" => "blog", "slug" => "bao-hanh"));
common_checks($html, "blog", $warnings);
check("blog: the post body is printed", strpos($html, "Gửi mã đơn cho hỗ trợ.") !== false);
check("blog: the view counter is incremented once", $GLOBALS["CMSNT"]->counted === 1);
check("blog: other posts are suggested", strpos($html, "Đổi mật khẩu lần đầu") !== false);
check("blog: breadcrumbs are the component list", strpos($html, '<ol class="breadcrumbs">') !== false);
$unknown = render_page("blog", array("module" => "client", "action" => "blog", "slug" => "khong-ton-tai"));
check("blog: an unknown slug goes back to the index", strpos($unknown, "REDIRECT:https://shop.example/blogs") === 0);
$noSlug = render_page("blog", array("module" => "client", "action" => "blog"));
check("blog: a missing slug goes back to the index", strpos($noSlug, "REDIRECT:https://shop.example/blogs") === 0);

echo "\ncontent_pages_test: " . $pass . " passed, " . $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
