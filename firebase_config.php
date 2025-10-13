<?php
// firebase_config.php
class FirebaseConfig {
    private $databaseUrl;
    private $authToken; // optional: DB secret/custom token; leave empty for public rules

    public function __construct() {
        $this->databaseUrl = 'https://spcc-database-default-rtdb.firebaseio.com';
        $this->authToken   = ''; // e.g. 'eyJhbGciOi...' or leave '' if rules are public
    }

    private function buildUrl(string $path): string {
        $path = ltrim($path, '/');                        // normalize
        $url  = rtrim($this->databaseUrl, '/')."/{$path}.json";
        if ($this->authToken !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&')."auth={$this->authToken}";
        }
        return $url;
    }

    public function makeRequest(string $path, string $method = 'GET', $data = null): array {
        $url = $this->buildUrl($path);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if (in_array($method, ['POST','PUT','PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'status'   => $status ?: 0,
            'error'    => $error ?: null,
            'response' => $response !== false ? $response : null,
            'url'      => $url,
            'method'   => $method,
        ];
    }

    public function pushData($path, $data)   { return $this->makeRequest($path, 'POST',  $data); }
    public function setData($path, $data)    { return $this->makeRequest($path, 'PUT',   $data); }
    public function updateData($path, $data) { return $this->makeRequest($path, 'PATCH', $data); }
    public function getData($path)           { return $this->makeRequest($path, 'GET'); }
    public function deleteData($path)        { return $this->makeRequest($path, 'DELETE'); }
}

$firebaseConfig = new FirebaseConfig();
