<?php

/**
 * Minimal Safaricom Daraja client: OAuth token, STK Push (Lipa Na M-Pesa
 * Online) and STK Push status query.
 *
 * Settings come from config/mpesa.json (not committed; see
 * config/mpesa.example.json). Apache denies web access to /config.
 */
class Mpesa {
    private array $cfg;
    private string $base;

    public function __construct() {
        $path = __DIR__ . '/../config/mpesa.json';
        $cfg = is_readable($path) ? json_decode(file_get_contents($path), true) : null;
        if (!is_array($cfg)) {
            throw new RuntimeException('M-Pesa is not configured (config/mpesa.json missing or invalid)');
        }

        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'passkey', 'callback_url', 'callback_token'] as $key) {
            if (empty($cfg[$key])) {
                throw new RuntimeException("Missing M-Pesa setting: $key");
            }
        }

        $this->cfg = $cfg;
        $this->base = ($cfg['env'] ?? 'sandbox') === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /** Accepts 07.., 01.., 7.., +2547.., 2547.. and returns 2547XXXXXXXX, or null. */
    public static function normalizePhone(string $phone): ?string {
        $digits = preg_replace('/\D/', '', $phone);

        if (preg_match('/^0([17]\d{8})$/', $digits, $m) || preg_match('/^([17]\d{8})$/', $digits, $m)) {
            $digits = '254' . $m[1];
        }

        return preg_match('/^254[17]\d{8}$/', $digits) ? $digits : null;
    }

    public function callbackToken(): string {
        return (string) $this->cfg['callback_token'];
    }

    /** Sends the PIN prompt to the customer's phone. */
    public function stkPush(string $phone, int $amount, string $accountRef, string $description): array {
        [$timestamp, $password] = $this->credentials();

        return $this->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->cfg['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $this->cfg['transaction_type'] ?? 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $this->cfg['party_b'] ?? $this->cfg['shortcode'],
            'PhoneNumber'       => $phone,
            'CallBackURL'       => rtrim($this->cfg['callback_url'], '/') . '/' . $this->callbackToken(),
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($description, 0, 13),
        ]);
    }

    /** Asks Safaricom for the outcome of a push (fallback if the callback never arrives). */
    public function stkQuery(string $checkoutRequestId): array {
        [$timestamp, $password] = $this->credentials();

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->cfg['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }

    private function credentials(): array {
        $timestamp = (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
        $password = base64_encode($this->cfg['shortcode'] . $this->cfg['passkey'] . $timestamp);
        return [$timestamp, $password];
    }

    private function accessToken(): string {
        $basic = base64_encode($this->cfg['consumer_key'] . ':' . $this->cfg['consumer_secret']);
        $data = $this->request('GET', '/oauth/v1/generate?grant_type=client_credentials', null, [
            "Authorization: Basic $basic",
        ]);

        if (empty($data['access_token'])) {
            throw new RuntimeException('Could not get M-Pesa access token (HTTP ' . $data['_http'] . ')');
        }
        return $data['access_token'];
    }

    private function post(string $path, array $body): array {
        return $this->request('POST', $path, $body, [
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
        ]);
    }

    private function request(string $method, string $path, ?array $body, array $headers): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("M-Pesa request failed: $error");
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Unexpected M-Pesa response (HTTP $status)");
        }
        $data['_http'] = $status;
        return $data;
    }
}
?>
