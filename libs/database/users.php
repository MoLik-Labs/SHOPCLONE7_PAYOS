<?php

if(!defined("IN_SITE")) {
    exit("The Request Not Found");
}
class users extends DB
{
    protected $_table_name = "users";
    /* Outcome of the last AddCreditsChecked() call: "credited", "duplicate" or "failed". */
    private $credit_outcome = "failed";
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
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        $this->credit_outcome = "failed";
        /* Ledger row + balance move together: a failed balance update must not leave a ledger entry. */
        $ok = $this->transaction(function () use ($user_id, $amount, $reason, $transid) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " ");
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
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        return $this->transaction(function () use ($user_id, $amount, $reason, $transid) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " ");
            $before = $balance ? $balance["money"] : 0;
            if($this->ledger_claim($user_id, $amount, $reason, $transid, $before) !== 1) {
                return false;
            }
            return (bool) parent::cong("users", "money", $amount, " `id` = " . $user_id . " ");
        });
    }
    public function RemoveCredits($user_id, $amount, $reason, $transid = NULL)
    {
        if($transid == NULL) {
            $transid = uniqid() . "_" . mt_rand(0, 9999999);
        }
        return $this->transaction(function () use ($user_id, $amount, $reason, $transid) {
            $user_id = (int) $user_id;
            $balance = $this->get_row("SELECT `money` FROM `users` WHERE `id` = " . $user_id . " ");
            $before = $balance ? $balance["money"] : 0;
            if($this->ledger_claim($user_id, -$amount, $reason, $transid, $before) !== 1) {
                return false;
            }
            return (bool) parent::tru("users", "money", $amount, " `id` = " . $user_id . " ");
        });
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
    public function AddCommission($ref_id, $user_id, $amount, $reason)
    {
        parent::insert("aff_log", ["sotientruoc" => getUser($ref_id, "ref_price"), "sotienthaydoi" => $amount, "sotienhientai" => getUser($ref_id, "ref_price") + $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $ref_id]);
        $isUpdate = parent::cong("users", "ref_price", $amount, " `id` = '" . $ref_id . "' ");
        if($isUpdate) {
            parent::cong("users", "ref_total_price", $amount, " `id` = '" . $ref_id . "' ");
            parent::cong("users", "ref_amount", $amount, " `id` = '" . $user_id . "' ");
            return true;
        }
        return false;
    }
    public function RemoveCommission($user_id, $amount, $reason)
    {
        parent::insert("aff_log", ["sotientruoc" => getUser($user_id, "ref_price"), "sotienthaydoi" => $amount, "sotienhientai" => getUser($user_id, "ref_price") - $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $user_id]);
        $isRemove = parent::tru("users", "ref_price", $amount, " `id` = '" . $user_id . "' ");
        if($isRemove) {
            return true;
        }
        return false;
    }
    public function RefundCommission($user_id, $amount, $reason)
    {
        parent::insert("aff_log", ["sotientruoc" => getUser($user_id, "ref_price"), "sotienthaydoi" => $amount, "sotienhientai" => getUser($user_id, "ref_price") + $amount, "create_gettime" => gettime(), "reason" => $reason, "user_id" => $user_id]);
        $isUpdate = parent::cong("users", "ref_price", $amount, " `id` = '" . $user_id . "' ");
        if($isUpdate) {
            return true;
        }
        return false;
    }
}

?>