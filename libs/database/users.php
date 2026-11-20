<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
class users extends DB
{
    protected $_table_name = "users";
    /* Outcome of the last AddCreditsChecked() call: "credited", "duplicate" or "failed". */
    private $credit_outcome = "failed";
    /* Outcome of the last RefundCreditsChecked() call: "credited", "duplicate" or "failed". */
    private $refund_outcome = "failed";
    /* Outcome of the last DebitCreditsChecked() call. */
    private $debit_outcome = "failed";
    /* Outcome of the last RemoveCommissionChecked() call. */
    private $commission_outcome = "failed";
    protected $_key = "id";
    public function __construct()
    {
        parent::connect();
    }
    public function __destruct()
    {
        parent::dis_connect();
    }
    public function add_new($data)
    {
        return parent::insert($this->_table_name, $data);
    }
    public function delete_by_id($id)
    {
        return $this->remove($this->_table_name, $this->_key . "=" . (int) $id);
    }
    public function update_by_id($data, $id)
    {
        return $this->update($this->_table_name, $data, $this->_key . "=" . (int) $id);
    }
    public function select_by_id($select, $id)
    {
        $sql = "SELECT " . $select . " FROM " . $this->_table_name . " WHERE " . $this->_key . " = " . (int) $id;
        return $this->get_row($sql);
    }
    public function get_row_by_id($where)
    {
        $sql = "SELECT * FROM " . $this->_table_name . " WHERE " . $where;
        return $this->get_row($sql);
    }
    public function get_list_by_id($where)
    {
        $sql = "SELECT * FROM " . $this->_table_name . " WHERE " . $where;
        return $this->get_list($sql);
    }
    public function num_rows_by_id($where)
    {
        $sql = "SELECT * FROM " . $this->_table_name . " WHERE " . $where;
        return $this->num_rows($sql);
    }
    /**
     * Write the ledger row for a keyed movement, but only if that key is not already in the ledger.
     *
     * A payment provider retries its callback; the retry used to arrive after the first credit and
     * add a second one. `transid` is deterministic for every credited event (`PAYOS_…`, `TOPUP_…`,
     * `ORDER_…`), so "same key already present" means "this event was already booked". The check and
     * the insert are one statement so the window is as small as a statement, not a request; the real
     * guarantee is the unique index `tools/db-index-audit.php` recommends. With no key (both callers
     * pass one), the row is written as before.
     */
    private function ledger_claim($user_id, $delta, $reason, $transid, $before)
    {
        $user_id = (int) $user_id;
        $delta = (float) $delta;
        $before = (float) $before;
        if($transid === NULL || $transid === "") {
            return parent::insert("dongtien", ["sotientruoc" => $before, "sotienthaydoi" => $delta, "sotiensau" => $before + $delta, "thoigian" => gettime(), "noidung" => $reason, "user_id" => $user_id, "transid" => $transid]) ? 1 : -1;
        }
        $transid = addslashes((string) $transid);
        $sql = "INSERT INTO `dongtien` (`sotientruoc`,`sotienthaydoi`,`sotiensau`,`thoigian`,`noidung`,`user_id`,`transid`)
                SELECT " . $before . ", " . $delta . ", " . ($before + $delta) . ", '" . addslashes(gettime()) . "', '"
                . addslashes((string) $reason) . "', " . $user_id . ", '" . $transid . "'
                FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `dongtien` WHERE `transid` = '" . $transid . "')";
        if(!parent::query($sql)) {
            return -1;
        }
        return parent::affectedRows() === 1 ? 1 : 0;
    }
    /**
     * Credit a user and say what actually happened: "credited", "duplicate" or "failed".
     *
     * Callbacks need the three cases apart. A retried callback usually finds the ledger row it wrote
     * last time — that is not an error, it is proof the money is already there, and the invoice must
     * still be closed. A real SQL/balance failure is the opposite: the invoice has to stay open so
     * the provider's next retry can finish it. AddCredits() keeps its old boolean contract.
     */
    public function AddCreditsChecked($user_id, $amount, $reason, $transid = NULL)
    {
        /* A zero or negative "credit" is a debit in disguise. Callers compute the amount from posted
           values and provider responses; refusing here keeps one bad caller from moving money the
           wrong way through a method whose name promises the opposite. */
        if((float) $amount <= 0) {
            $this->credit_outcome = "failed";
            return "failed";
        }
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        $this->credit_outcome = "failed";
        /*
         * Ledger row + balance move together: a failed balance update must not leave a ledger entry.
         * The balance read also takes the user row lock, so two concurrent claims for the same
         * transid serialise: the second one sees the first ledger row and reports "duplicate"
         * instead of crediting twice.
         */
        $ok = $this->transaction(function () use ($user_id, $amount, $reason, $transid) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            $before = $balance ? $balance["money"] : 0;
            $claim = $this->ledger_claim($user_id, $amount, $reason, $transid, $before);
            if($claim === -1) {
                return false;
            }
            if($claim === 0) {
                $this->credit_outcome = "duplicate";
                return true;
            }
            $isUpdate = parent::cong("users", "money", $amount, " `id` = " . $user_id . " ");
            if(!$isUpdate) {
                return false;
            }
            if(!parent::cong("users", "total_money", $amount, " `id` = " . $user_id . " ")) {
                return false;
            }
            $this->credit_outcome = "credited";
            return true;
        });
        return $ok === false ? "failed" : $this->credit_outcome;
    }
    public function AddCredits($user_id, $amount, $reason, $transid = NULL)
    {
        return $this->AddCreditsChecked($user_id, $amount, $reason, $transid) === "credited";
    }
    public function RefundCredits($user_id, $amount, $reason, $transid = NULL)
    {
        return $this->RefundCreditsChecked($user_id, $amount, $reason, $transid) === "credited";
    }
    /**
     * Refund a user and say what actually happened: "credited", "duplicate" or "failed".
     *
     * The purchase flow needs the three cases for partial deliveries. A retried request finds its
     * `PARTIAL_<trans_id>` ledger row already written — that is a successful refund, not a failure.
     * A SQL failure is the opposite and must be visible to the caller. RefundCredits() keeps its
     * old boolean contract for the forty-odd existing call sites.
     */
    public function RefundCreditsChecked($user_id, $amount, $reason, $transid = NULL)
    {
        /* Refunding a free order is a zero-value, keyed no-op, not a payment failure. */
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            $this->refund_outcome = "failed";
            return "failed";
        }
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        $this->refund_outcome = "failed";
        $ok = $this->transaction(function () use ($user_id, $amount, $reason, $transid) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            $before = $balance ? $balance["money"] : 0;
            $claim = $this->ledger_claim($user_id, $amount, $reason, $transid, $before);
            if($claim === -1) {
                return false;
            }
            if($claim === 0) {
                $this->refund_outcome = "duplicate";
                return true;
            }
            if(!(bool) parent::cong("users", "money", $amount, " `id` = " . $user_id . " ")) {
                return false;
            }
            $this->refund_outcome = "credited";
            return true;
        });
        return $ok === false ? "failed" : $this->refund_outcome;
    }
    public function RemoveCredits($user_id, $amount, $reason, $transid = NULL)
    {
        return $this->debit_credits($user_id, $amount, $reason, $transid, true) === "debited";
    }
    /**
     * Debit a user for a purchase, refusing when the balance does not cover it.
     *
     * Checkout used to read the balance, compare, then call RemoveCredits() in a second statement.
     * Two purchases started in the same second both passed the comparison and both debited, leaving
     * a negative balance and triggering the fraud ban for an innocent buyer. The balance read and
     * the debit now happen under the same row lock, and the debit reports why it did not happen:
     * "debited", "insufficient", "duplicate" (this transid already moved money) or "failed".
     *
     * @return string one of debited|insufficient|duplicate|failed
     */
    public function DebitCreditsChecked($user_id, $amount, $reason, $transid = NULL)
    {
        return $this->debit_credits($user_id, $amount, $reason, $transid, false);
    }
    private function debit_credits($user_id, $amount, $reason, $transid, $allowNegative)
    {
        /* Free products and 100% coupons legitimately produce zero. They still need a keyed
           ledger claim to prevent duplicate delivery, but must never permit a negative debit. */
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            $this->debit_outcome = "failed";
            return "failed";
        }
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        $this->debit_outcome = "failed";
        $ok = $this->transaction(function () use ($user_id, $amount, $reason, $transid, $allowNegative) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            $before = $balance ? $balance["money"] : 0;
            if(!$allowNegative && (float) $before < (float) $amount) {
                $this->debit_outcome = "insufficient";
                return true;
            }
            $claim = $this->ledger_claim($user_id, -$amount, $reason, $transid, $before);
            if($claim === -1) {
                return false;
            }
            if($claim === 0) {
                $this->debit_outcome = "duplicate";
                return true;
            }
            if(!(bool) parent::tru("users", "money", $amount, " `id` = " . $user_id . " ")) {
                return false;
            }
            $this->debit_outcome = "debited";
            return true;
        });
        return $ok === false ? "failed" : $this->debit_outcome;
    }
    /**
     * Pay down a member's debit (an overdraft left by an admin adjustment) with the money they hold.
     *
     * The old helper read `money` and `debit`, then issued two unconditional updates. Two callbacks
     * for two different deposits could both read the same balances and pay the same debt twice, and
     * a failure between the updates left `debit` reduced but `money` untouched. Both balances are now
     * read under one row lock and both updates share one transaction.
     *
     * @return string one of settled|nothing|failed
     */
    public function SettleDebit($user_id)
    {
        $this->debit_outcome = "failed";
        $ok = $this->transaction(function () use ($user_id) {
            $user_id = (int) $user_id;
            $row = $this->get_row("SELECT `money`, `debit` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            if(!$row) {
                $this->debit_outcome = "nothing";
                return true;
            }
            $money = (float) $row["money"];
            $debit = (float) $row["debit"];
            $pay = min($money, $debit);
            if($pay <= 0) {
                $this->debit_outcome = "nothing";
                return true;
            }
            $transid = "DEBIT_" . uniqid() . "_" . mt_rand(0, 9999999);
            $claim = $this->ledger_claim($user_id, -$pay, __("Thanh toán số tiền ghi nợ"), $transid, $money);
            if($claim !== 1) {
                return false;
            }
            if(!(bool) parent::tru("users", "debit", $pay, " `id` = " . $user_id . " ")) {
                return false;
            }
            if(!(bool) parent::tru("users", "money", $pay, " `id` = " . $user_id . " ")) {
                return false;
            }
            $this->debit_outcome = "settled";
            return true;
        });
        return $ok === false ? "failed" : $this->debit_outcome;
    }
    public function Banned($user_id, $reason)
    {
        $Mobile_Detect = new Detection\MobileDetect();
        parent::insert("logs", ["user_id" => $user_id, "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => "Tài khoản bị khoá lý do (" . $reason . ")"]);
        parent::update("users", ["banned" => 1], " `id` = '" . $user_id . "' ");
    }
    public function AddSpin($user_id, $amount, $reason)
    {
        $Mobile_Detect = new Detection\MobileDetect();
        parent::insert("logs", ["user_id" => $user_id, "ip" => myip(), "device" => $Mobile_Detect->getUserAgent(), "createdate" => gettime(), "action" => $reason]);
        $isUpdate = parent::cong("users", "spin", $amount, " `id` = '" . $user_id . "' ");
        if($isUpdate) {
            return true;
        }
        return false;
    }
    /**
     * Credit a referrer's commission and record it in `aff_log`.
     *
     * The log row used to be written from two unlocked `getUser()` reads before the balance move, so
     * concurrent commissions could record the wrong before/after figures, and a failed credit left a
     * log entry for money that never arrived. The read (under the referrer's row lock), the log row
     * and all three counters now share one transaction; if any step fails the whole movement rolls
     * back and the caller's boolean contract reports it.
     */
    public function AddCommission($ref_id, $user_id, $amount, $reason)
    {
        $amount = (float) $amount;
        if($amount <= 0) {
            return false;
        }
        $ok = $this->transaction(function () use ($ref_id, $user_id, $amount, $reason) {
            $ref_id = (int) $ref_id;
            $balance = $this->get_row("SELECT `ref_price` FROM `users` WHERE `id` = " . $ref_id . " FOR UPDATE");
            if(!$balance) {
                return false;
            }
            $before = (float) $balance["ref_price"];
            $isLog = parent::insert("aff_log", ["sotientruoc" => $before, "sotienthaydoi" => $amount, "sotienhientai" => $before + $amount, "create_gettime" => gettime(), "user_id" => $ref_id, "reason" => $reason]);
            if(!$isLog) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_price", $amount, " `id` = " . $ref_id . " ")) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_total_price", $amount, " `id` = " . $ref_id . " ")) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_amount", $amount, " `id` = " . (int) $user_id . " ")) {
                return false;
            }
            return true;
        });
        return $ok !== false;
    }
    public function RemoveCommission($user_id, $amount, $reason)
    {
        return $this->RemoveCommissionChecked($user_id, $amount, $reason, true) === "removed";
    }
    /**
     * Take commission out of a referrer's withdrawable balance, or say why not.
     *
     * The withdrawal form checked `ref_price >= amount`, then called RemoveCommission() in a second
     * statement. Two requests started together both passed the check, both wrote an aff_log row and
     * both debited, leaving a negative commission balance — which the flow then treated as fraud and
     * banned the member. The check, the log row and the debit now run in one transaction that holds
     * the user row lock, so the second request sees the reduced balance and reports "insufficient".
     *
     * @param bool $allowNegative admin paths may intentionally push the balance below zero
     * @return string removed|insufficient|failed
     */
    public function RemoveCommissionChecked($user_id, $amount, $reason, $allowNegative = false)
    {
        $user_id = (int) $user_id;
        $amount = (float) $amount;
        $this->commission_outcome = "failed";
        if($amount <= 0) {
            $this->commission_outcome = "insufficient";
            return $this->commission_outcome;
        }
        $ok = $this->transaction(function () use ($user_id, $amount, $reason, $allowNegative) {
            $row = $this->get_row("SELECT `ref_price` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            $before = $row ? (float) $row["ref_price"] : 0;
            if(!$allowNegative && $before < $amount) {
                $this->commission_outcome = "insufficient";
                return true;
            }
            if(!parent::insert("aff_log", ["sotientruoc" => $before, "sotienthaydoi" => $amount, "sotienhientai" => $before - $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $user_id])) {
                return false;
            }
            if(!parent::tru("users", "ref_price", $amount, " `id` = " . $user_id . " ")) {
                return false;
            }
            $this->commission_outcome = "removed";
            return true;
        });
        return $ok === false ? "failed" : $this->commission_outcome;
    }
    /**
     * Put commission back after a withdrawal request could not be recorded.
     *
     * The balance was already debited when the `aff_withdraw` insert failed; without this the money
     * was simply gone. The re-credit and its aff_log row move together.
     */
    public function RestoreCommission($user_id, $amount, $reason)
    {
        $user_id = (int) $user_id;
        $amount = (float) $amount;
        if($amount <= 0) {
            return false;
        }
        return (bool) $this->transaction(function () use ($user_id, $amount, $reason) {
            $row = $this->get_row("SELECT `ref_price` FROM `users` WHERE `id` = " . $user_id . " FOR UPDATE");
            $before = $row ? (float) $row["ref_price"] : 0;
            if(!parent::insert("aff_log", ["sotientruoc" => $before, "sotienthaydoi" => $amount, "sotienhientai" => $before + $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $user_id])) {
                return false;
            }
            return (bool) parent::cong("users", "ref_price", $amount, " `id` = " . $user_id . " ");
        });
    }
    /**
     * Reverse the commission a purchase credited to the buyer's referrer.
     *
     * AddCommission() moved three values: the referrer's `ref_price` and `ref_total_price`, and the
     * buyer's `ref_amount` counter. A refund must move all three back, otherwise the shop pays a
     * commission on money it returned. The purchase tags its aff_log row with the order trans_id, so
     * the exact amount is read back from history rather than recomputed from today's rate; an order
     * with no tagged row (legacy purchase) is left alone instead of guessing.
     */
    public function ClawbackCommission($ref_id, $buyer_id, $amount, $reason)
    {
        $ref_id = (int) $ref_id;
        $buyer_id = (int) $buyer_id;
        $amount = (float) $amount;
        if($ref_id <= 0 || $amount <= 0) {
            return false;
        }
        /*
         * Lock the referrer first, then check for the log row: a refund retried by two workers could
         * both pass the "already clawed back?" check and both reverse the commission. The lock makes
         * the second worker wait until the first commits, so its check sees the row.
         */
        return (bool) $this->transaction(function () use ($ref_id, $buyer_id, $amount, $reason) {
            $row = $this->get_row("SELECT `ref_price` FROM `users` WHERE `id` = " . $ref_id . " FOR UPDATE");
            if(!$row) {
                return false;
            }
            if(parent::get_row("SELECT `id` FROM `aff_log` WHERE `user_id` = " . $ref_id . " AND `reason` = '" . addslashes((string) $reason) . "'")) {
                return false;
            }
            $before = (float) $row["ref_price"];
            if(!parent::insert("aff_log", ["sotientruoc" => $before, "sotienthaydoi" => -$amount, "sotienhientai" => $before - $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $ref_id])) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_price", -$amount, " `id` = " . $ref_id . " ")) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_total_price", -$amount, " `id` = " . $ref_id . " ")) {
                return false;
            }
            if(!(bool) parent::cong("users", "ref_amount", -$amount, " `id` = " . $buyer_id . " ")) {
                return false;
            }
            return true;
        });
    }
    public function RefundCommission($user_id, $amount, $reason)
    {
        /* Same movement as a restored withdrawal: log row and re-credit under the user's lock. */
        return $this->RestoreCommission($user_id, $amount, $reason);
    }
}

?>