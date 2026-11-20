<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
$body = ["title" => "Theme", "desc" => "CMSNT Panel", "keyword" => "cmsnt, CMSNT, cmsnt.co,"];
$body["header"] = "\n\n\n\n";
$body["footer"] = "\n\n";
require_once __DIR__ . "/../../models/is_admin.php";
require_once __DIR__ . "/header.php";
require_once __DIR__ . "/sidebar.php";
require_once __DIR__ . "/nav.php";
if(!checkPermission($getUser["admin"], "edit_theme")) {
    exit("<script type=\"text/javascript\">if(!alert(\"Bạn không có quyền sử dụng tính năng này\")){window.history.back();}</script>");
}
if(isset($_POST["SaveSettings"])) {
    if($CMSNT->site("status_demo") != 0) {
        exit("<script type=\"text/javascript\">if(!alert(\"" . __("This function cannot be used because this is a demo site") . "\")){window.history.back().location.reload();}</script>");
    }
    if(check_img("logo_light")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["logo_light"], "logo_light_");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("logo_light"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'logo_light' ");
        }
    }
    if(check_img("logo_dark")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["logo_dark"], "logo_dark_");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("logo_dark"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'logo_dark' ");
        }
    }
    if(check_img("favicon")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["favicon"], "favicon_");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("favicon"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'favicon' ");
        }
    }
    if(check_img("image")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["image"], "image_");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("image"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'image' ");
        }
    }
    if(check_img("default_product_image")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["default_product_image"], "default_product_image");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("default_product_image"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'default_product_image' ");
        }
    }
    if(check_img("banner_singer")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["banner_singer"], "banner_singer");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("banner_singer"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'banner_singer' ");
        }
    }
    if(check_img("avatar")) {
        /* Store the new file first: a failed upload must not leave the setting pointing at a deleted path. */
        $stored = orbit_store_image($_FILES["avatar"], "avatar");
        if($stored !== false) {
            orbit_delete_upload($CMSNT->site("avatar"));
            $CMSNT->update("settings", ["value" => $stored], " `name` = 'avatar' ");
        }
    }
    $my_text = $CMSNT->site("noti_action");
    $my_text = str_replace("{domain}", $_SERVER["SERVER_NAME"], $my_text);
    $my_text = str_replace("{username}", $getUser["username"], $my_text);
    $my_text = str_replace("{action}", __("Thay đổi ảnh trong giao diện"), $my_text);
    $my_text = str_replace("{ip}", myip(), $my_text);
    $my_text = str_replace("{time}", gettime(), $my_text);
    sendMessAdmin($my_text);
    exit("<script type=\"text/javascript\">if(!alert(\"Save successfully!\")){window.history.back().location.reload();}</script>");
}
echo "\n<div class=\"main-content app-content\">\n    <div class=\"container-fluid\">\n        <div class=\"d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb\">\n            <h1 class=\"page-title fw-semibold fs-4 mb-0\"><i class=\"fa-solid fa-image me-2\"></i>Theme</h1>\n        </div>\n\n        <div class=\"row\">\n            <div class=\"col-xl-12\">\n                <div class=\"card custom-card shadow-sm\">\n                    <div class=\"card-header justify-content-between\">\n                        <div class=\"card-title\">\n                            THAY ĐỔI GIAO DIỆN WEBSITE\n                        </div>\n                    </div>\n                    <div class=\"card-body\">\n                        <form action=\"\" method=\"POST\" enctype=\"multipart/form-data\">\n                            <div class=\"row\">\n                                <!-- Logo Light -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"logo_light\" class=\"form-label\">Logo Light</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"logo_light\" id=\"logo_light\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded\" src=\"";
echo BASE_URL($CMSNT->site("logo_light"));
echo "\" alt=\"Logo Light\">\n                                    </div>\n                                </div>\n\n                                <!-- Logo Dark -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"logo_dark\" class=\"form-label\">Logo Dark</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"logo_dark\" id=\"logo_dark\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded\" src=\"";
echo BASE_URL($CMSNT->site("logo_dark"));
echo "\" alt=\"Logo Dark\">\n                                    </div>\n                                </div>\n\n                                <!-- Favicon -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"favicon\" class=\"form-label\">Favicon</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"favicon\" id=\"favicon\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"50px\" class=\"rounded-circle\" src=\"";
echo BASE_URL($CMSNT->site("favicon"));
echo "\" alt=\"Favicon\">\n                                    </div>\n                                </div>\n\n                                <!-- Image -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"image\" class=\"form-label\">Image</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"image\" id=\"image\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded\" src=\"";
echo BASE_URL($CMSNT->site("image"));
echo "\" alt=\"Image\">\n                                    </div>\n                                </div>\n\n                                <!-- Default Product Image -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"default_product_image\" class=\"form-label\">Ảnh sản phẩm mặc định</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"default_product_image\" id=\"default_product_image\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded\" src=\"";
echo BASE_URL($CMSNT->site("default_product_image"));
echo "\" alt=\"Default Product Image\">\n                                    </div>\n                                </div>\n\n                                <!-- Banner Singer -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"banner_singer\" class=\"form-label\">Banner Singer</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"banner_singer\" id=\"banner_singer\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded\" src=\"";
echo BASE_URL($CMSNT->site("banner_singer"));
echo "\" alt=\"Banner Singer\">\n                                    </div>\n                                </div>\n\n                                <!-- Avatar -->\n                                <div class=\"col-lg-6 mb-4\">\n                                    <div class=\"form-group\">\n                                        <label for=\"avatar\" class=\"form-label\">Avatar</label>\n                                        <input type=\"file\" class=\"form-control\" name=\"avatar\" id=\"avatar\">\n                                    </div>\n                                    <div class=\"mt-2\">\n                                        <img width=\"250px\" class=\"rounded-circle\" src=\"";
echo BASE_URL($CMSNT->site("avatar"));
echo "\" alt=\"Avatar\">\n                                    </div>\n                                </div>\n                            </div>\n\n                            <div class=\"text-end\">\n                                <button name=\"SaveSettings\" class=\"btn btn-primary\" type=\"submit\">\n                                    <i class=\"fas fa-save\"></i> Lưu Ngay\n                                </button>\n                            </div>\n                        </form>\n                    </div>\n                </div>\n            </div>\n        </div>\n    </div>\n</div>\n\n\n\n\n\n\n";
require_once __DIR__ . "/footer.php";

?>