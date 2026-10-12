<?php
// Spike client: signs requests the way the panel will. Usage: php spike/client.php <pairing code> <METHOD> <route> [--fallback]
[$self, $code, $method, $route] = array_pad($argv, 4, '');
$fallback = in_array('--fallback', $argv, true);
$payload = json_decode(base64_decode(strtr(substr($code, strlen('sbwc1.')), '-_', '+/')), true);
if (! is_array($payload) || empty($payload['u']) || empty($payload['s'])) { fwrite(STDERR, "bad pairing code\n"); exit(2); }
$base = rtrim($payload['u'], '/');
$url = $fallback ? $base . '/?site-buddy-connector=' . $route : $base . '/wp-json/site-buddy/v1/' . $route;
$body = getenv('SBWC_BODY') ?: '';
$time = (string) time();
$sig = hash_hmac('sha256', $time . "\n" . strtoupper($method) . "\n" . $route . "\n" . hash('sha256', $body), $payload['s']);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => getenv('SBWC_INSECURE') ? false : true, CURLOPT_SSL_VERIFYHOST => getenv('SBWC_INSECURE') ? 0 : 2,
    CURLOPT_POSTFIELDS => $body !== '' ? $body : null, CURLOPT_HTTPHEADER => ['X-Site-Buddy-Time: ' . $time, 'X-Site-Buddy-Signature: ' . $sig, 'Accept: application/json', 'Content-Type: application/json']]);
$out = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
fwrite(STDERR, "HTTP $status ($url)\n");
echo $out === false ? curl_error($ch) : $out, "\n";
