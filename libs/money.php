<?php
/*
 * Money arithmetic that must be identical everywhere.
 *
 * Kept out of helper.php so a page-render test can load the real functions without booting the whole
 * legacy helper (which opens the database and sends headers). helper.php requires this file.
 */

if (!function_exists("orbit_percent")) {
    /**
     * A percentage, held to 0–100.
     *
     * `products.discount`, `product_discount.discount`, `users.discount`, `promotions.discount` and the
     * affiliate rate all arrive as text from an admin form or from the database. The storefront clamped
     * them; the checkout did not, so one bad row (discount = 150) produced a negative unit price and a
     * negative total — and `RemoveCredits(-x)` adds money to the buyer.
     */
    function orbit_percent($value)
    {
        $value = (float) $value;
        if($value < 0) {
            return 0.0;
        }
        if($value > 100) {
            return 100.0;
        }
        return $value;
    }
    /**
     * The unit price to charge for one item, after the product's own discount, never below zero.
     */
    function orbit_unit_price($product)
    {
        $price = isset($product["price"]) ? (float) $product["price"] : 0.0;
        if($price < 0) {
            $price = 0.0;
        }
        $discount = orbit_percent(isset($product["discount"]) ? $product["discount"] : 0);
        return $price - ($price * $discount / 100);
    }
}
