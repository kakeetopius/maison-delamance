<?php
    require_once __DIR__ . "/../includes/db.php";

    class Payment {

        public static function create($order_no, $user_id, $merchant_id, $checkout_id, $phone, $amount) {
            $db = new DBCon();
            $query = $db->prepareStatement(
                "INSERT INTO payment (order_no, user_id, merchant_request_id, checkout_request_id, phone, amount)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $query->bind_param("iisssi", $order_no, $user_id, $merchant_id, $checkout_id, $phone, $amount);
            return $db->execute_query($query);
        }

        public static function getByCheckoutId($checkout_id) {
            $db = new DBCon();
            $query = $db->prepareStatement(
                "SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
                 FROM payment WHERE checkout_request_id = ?"
            );
            $query->bind_param("s", $checkout_id);
            $rows = $db->execute_and_getResult($query);
            return $rows ? $rows[0] : null;
        }

        /** A push the user started in the last 90 seconds that hasn't finished (stops double-charging on double clicks). */
        public static function recentPendingForUser($user_id) {
            $db = new DBCon();
            $query = $db->prepareStatement(
                "SELECT * FROM payment
                 WHERE user_id = ? AND status = 'pending' AND created_at > NOW() - INTERVAL 90 SECOND
                 ORDER BY payment_id DESC LIMIT 1"
            );
            $query->bind_param("i", $user_id);
            $rows = $db->execute_and_getResult($query);
            return $rows ? $rows[0] : null;
        }

        /**
         * Moves a payment out of 'pending'. Returns true only for the call that
         * actually changed it, so a callback and a status query arriving
         * together can't both act on the order.
         */
        public static function finish($checkout_id, $status, $result_code, $result_desc, $receipt, $payload) {
            $db = new DBCon();
            $query = $db->prepareStatement(
                "UPDATE payment
                 SET status = ?, result_code = ?, result_desc = ?, mpesa_receipt = ?, callback_payload = ?
                 WHERE checkout_request_id = ? AND status = 'pending'"
            );
            $query->bind_param("sissss", $status, $result_code, $result_desc, $receipt, $payload, $checkout_id);
            return $db->execute_query($query) && $db->affectedRows() === 1;
        }
    }
?>
