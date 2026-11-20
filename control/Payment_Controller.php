<?php
    session_start();

    require_once __DIR__ . '/../modal/order.php';
    require_once __DIR__ . '/../modal/payment.php';
    require_once __DIR__ . '/../includes/mpesa.php';

    class PaymentControl {

        private static function fail($code, $message = null, $http = 400) {
            http_response_code($http);
            return json_encode(['success' => false, 'message' => $code, 'detail' => $message]);
        }

        /** POST /index.php/payment/stkpush  {"phone": "0712345678"} */
        public static function stkPush($phone) {
            if (!isset($_SESSION['user_id'])) {
                return self::fail('notLoggedIn', 'Sign in first.', 401);
            }
            $user_id = (int) $_SESSION['user_id'];

            if (empty($_SESSION['cart'])) {
                return self::fail('cartEmpty', 'Your cart is empty.');
            }

            $msisdn = Mpesa::normalizePhone((string) $phone);
            if ($msisdn === null) {
                return self::fail('invalidPhone', 'Enter a valid Safaricom number, e.g. 0712345678.');
            }

            // Don't send a second prompt while the first is still on the phone.
            $pending = Payment::recentPendingForUser($user_id);
            if ($pending !== null) {
                return json_encode([
                    'success' => true,
                    'checkout_request_id' => $pending['checkout_request_id'],
                    'message' => 'A payment request is already on your phone. Enter your M-Pesa PIN.',
                ]);
            }

            // The amount is always worked out here from the session cart and
            // database prices, never taken from the browser.
            $total = 0;
            foreach ($_SESSION['cart'] as $product_id => $qty) {
                $product = Product::getProductByID((int) $product_id);
                if ($product === null || (int) $qty < 1) {
                    return self::fail('badCart', 'An item in your cart is no longer available.');
                }
                if ($product->get_stock() < $qty) {
                    return self::fail('outOfStock', $product->get_productName() . ' is out of stock.');
                }
                $total += $product->get_price() * $qty;
            }
            $amount = (int) ceil($total);
            if ($amount < 1) {
                return self::fail('badAmount', 'Order total must be at least KSh 1.');
            }

            // Reserve the stock under a pending order before asking for money.
            $order = Order::createPending($user_id);
            if ($order === null) {
                return self::fail('orderFailed', 'Could not create your order.', 500);
            }
            foreach ($_SESSION['cart'] as $product_id => $qty) {
                if (!$order->addtoOrder((int) $product_id, (int) $qty)) {
                    $order->cancelOrder();
                    return self::fail('outOfStock', 'An item went out of stock. Please review your cart.');
                }
            }
            $order_no = $order->get_orderNum();

            try {
                $response = (new Mpesa())->stkPush($msisdn, $amount, "MD$order_no", "Order $order_no");
            } catch (RuntimeException $e) {
                error_log('[mpesa] stkPush: ' . $e->getMessage());
                $order->cancelOrder();
                return self::fail('mpesaUnavailable', 'M-Pesa is unavailable right now. Please try again.', 502);
            }

            if (($response['ResponseCode'] ?? null) !== '0') {
                error_log('[mpesa] stkPush rejected: ' . json_encode($response));
                $order->cancelOrder();
                return self::fail('stkRejected', $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'M-Pesa rejected the request.', 502);
            }

            Payment::create($order_no, $user_id, $response['MerchantRequestID'], $response['CheckoutRequestID'], $msisdn, $amount);

            return json_encode([
                'success' => true,
                'checkout_request_id' => $response['CheckoutRequestID'],
                'amount' => $amount,
                'message' => 'Check your phone and enter your M-Pesa PIN.',
            ]);
        }

        /** GET /index.php/payment/status/{CheckoutRequestID}  (polled by the checkout page) */
        public static function status($checkout_id) {
            if (!isset($_SESSION['user_id'])) {
                return self::fail('notLoggedIn', null, 401);
            }

            $payment = Payment::getByCheckoutId($checkout_id);
            if ($payment === null || (int) $payment['user_id'] !== (int) $_SESSION['user_id']) {
                return self::fail('notFound', null, 404);
            }

            // If Safaricom's callback hasn't arrived after 20s, ask them directly.
            if ($payment['status'] === 'pending' && (int) $payment['age_seconds'] >= 20) {
                try {
                    $q = (new Mpesa())->stkQuery($checkout_id);
                    // While the customer is still on the PIN screen Daraja returns an errorCode instead of ResultCode.
                    if (isset($q['ResultCode'])) {
                        self::applyResult($payment, (int) $q['ResultCode'], $q['ResultDesc'] ?? '', null, null, json_encode($q));
                        $payment = Payment::getByCheckoutId($checkout_id);
                    }
                } catch (RuntimeException $e) {
                    error_log('[mpesa] stkQuery: ' . $e->getMessage());
                }
            }

            if ($payment['status'] === 'success') {
                unset($_SESSION['cart']);
            }

            return json_encode([
                'success' => true,
                'status'  => $payment['status'],
                'receipt' => $payment['mpesa_receipt'],
                'order_no'=> $payment['order_no'],
                'detail'  => $payment['result_desc'],
            ]);
        }

        /** POST /index.php/payment/callback/{token}  (called by Safaricom, not the browser) */
        public static function callback($token, $raw) {
            $ack = json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

            try {
                $expected = (new Mpesa())->callbackToken();
            } catch (RuntimeException $e) {
                error_log('[mpesa] callback: ' . $e->getMessage());
                http_response_code(500);
                return json_encode(['ResultCode' => 1, 'ResultDesc' => 'Not configured']);
            }

            // Daraja callbacks aren't signed, so the secret in the URL is what
            // stops anyone else from posting fake "payment succeeded" messages.
            if (!hash_equals($expected, (string) $token)) {
                http_response_code(403);
                return json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
            }

            $data = json_decode($raw, true);
            $cb = $data['Body']['stkCallback'] ?? null;
            if (!is_array($cb) || !isset($cb['CheckoutRequestID'], $cb['ResultCode'])) {
                http_response_code(400);
                return json_encode(['ResultCode' => 1, 'ResultDesc' => 'Bad payload']);
            }

            $payment = Payment::getByCheckoutId($cb['CheckoutRequestID']);
            if ($payment === null) {
                return $ack; // unknown request: acknowledge so Safaricom stops retrying
            }

            $meta = [];
            foreach ($cb['CallbackMetadata']['Item'] ?? [] as $item) {
                if (isset($item['Name'])) {
                    $meta[$item['Name']] = $item['Value'] ?? null;
                }
            }

            self::applyResult(
                $payment,
                (int) $cb['ResultCode'],
                $cb['ResultDesc'] ?? '',
                isset($meta['MpesaReceiptNumber']) ? (string) $meta['MpesaReceiptNumber'] : null,
                $meta['Amount'] ?? null,
                $raw
            );

            return $ack;
        }

        private static function applyResult($payment, $code, $desc, $receipt, $amount_paid, $payload) {
            if ($code === 0 && $amount_paid !== null && (int) round($amount_paid) !== (int) $payment['amount']) {
                $code = -1;
                $desc = "Amount mismatch: paid $amount_paid, expected {$payment['amount']}";
            }

            $status = match (true) {
                $code === 0    => 'success',
                $code === 1032 => 'cancelled', // customer pressed Cancel
                default        => 'failed',
            };

            // Only the first result to arrive gets to change the order.
            if (!Payment::finish($payment['checkout_request_id'], $status, $code, $desc, $receipt, $payload)) {
                return;
            }
            if ($payment['order_no'] === null) {
                return;
            }

            $order = new Order((int) $payment['order_no'], (int) $payment['user_id'], 'pending');
            if ($status === 'success') {
                $order->confirmOrder();
            } else {
                $order->cancelOrder(); // puts the reserved stock back
            }
        }
    }
?>
