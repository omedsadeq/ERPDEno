<?php
/**
 * Typesense REST Client
 * Pure PHP cURL wrapper — no Composer package needed.
 */
class TypesenseClient {

    private $baseUrl;
    private $apiKey;
    private $timeout;

    public function __construct($host = null, $port = null, $protocol = null, $apiKey = null, $timeout = 5) {
        $host     = $host     ?? (defined('TYPESENSE_HOST')     ? TYPESENSE_HOST     : 'localhost');
        $port     = $port     ?? (defined('TYPESENSE_PORT')     ? TYPESENSE_PORT     : 8108);
        $protocol = $protocol ?? (defined('TYPESENSE_PROTOCOL') ? TYPESENSE_PROTOCOL : 'http');
        $apiKey   = $apiKey   ?? (defined('TYPESENSE_API_KEY')  ? TYPESENSE_API_KEY  : '');

        $this->baseUrl = "$protocol://$host:$port";
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    // ---------------------------------------------------------------
    // Core HTTP request
    // ---------------------------------------------------------------
    private function request($method, $path, $body = null, $query = []) {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_HTTPHEADER     => [
                'X-TYPESENSE-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Typesense cURL error: $curlErr");
        }

        return [
            'code' => $httpCode,
            'body' => $response,
            'data' => json_decode($response, true),
        ];
    }

    // ---------------------------------------------------------------
    // Health check
    // ---------------------------------------------------------------
    public function isAvailable() {
        try {
            $r = $this->request('GET', '/health');
            return isset($r['data']['ok']) && $r['data']['ok'] === true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Collections
    // ---------------------------------------------------------------
    public function collectionExists($name) {
        try {
            $r = $this->request('GET', "/collections/$name");
            return $r['code'] === 200;
        } catch (Exception $e) {
            return false;
        }
    }

    public function createCollection($schema) {
        return $this->request('POST', '/collections', $schema);
    }

    public function deleteCollection($name) {
        return $this->request('DELETE', "/collections/$name");
    }

    // ---------------------------------------------------------------
    // Documents
    // ---------------------------------------------------------------
    public function upsertDocument($collection, $document) {
        return $this->request('POST', "/collections/$collection/documents", $document, ['action' => 'upsert']);
    }

    public function deleteDocument($collection, $id) {
        return $this->request('DELETE', "/collections/$collection/documents/$id");
    }

    /**
     * Bulk import via NDJSON — much faster than individual upserts.
     * @param string $collection
     * @param array  $documents   array of associative arrays
     * @return string  NDJSON response lines (one JSON object per line)
     */
    public function importDocuments($collection, $documents) {
        $ndjson = implode("\n", array_map('json_encode', $documents));
        $url    = $this->baseUrl . "/collections/$collection/documents/import?action=upsert";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'X-TYPESENSE-API-KEY: ' . $this->apiKey,
                'Content-Type: text/plain',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $ndjson,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Typesense import cURL error: $curlErr");
        }
        return $response;
    }

    // ---------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------
    /**
     * @param string $collection
     * @param array  $params   Typesense search parameters
     * @return array  parsed JSON response
     * @throws Exception on HTTP error
     */
    public function search($collection, $params) {
        $r = $this->request('GET', "/collections/$collection/documents/search", null, $params);
        if ($r['code'] !== 200) {
            throw new Exception("Typesense search error ({$r['code']}): " . ($r['data']['message'] ?? $r['body']));
        }
        return $r['data'];
    }
}
