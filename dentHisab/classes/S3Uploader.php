<?php
/**
 * S3 Uploader Class for iDrive e2 (S3-Compatible Storage)
 * Handles server-side uploads to iDrive e2
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Uploader {
    private static $instance = null;
    private $s3Client;
    private $bucket;
    private $endpoint;

    private function __construct() {
        $this->endpoint = $_ENV['S3_ENDPOINT'] ?? getenv('S3_ENDPOINT');
        $this->bucket = $_ENV['S3_BUCKET'] ?? getenv('S3_BUCKET');

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $_ENV['S3_REGION'] ?? getenv('S3_REGION') ?? 'eu-west-4',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => filter_var(
                $_ENV['S3_USE_PATH_STYLE'] ?? getenv('S3_USE_PATH_STYLE') ?? true,
                FILTER_VALIDATE_BOOLEAN
            ),
            'credentials' => [
                'key' => $_ENV['S3_ACCESS_KEY'] ?? getenv('S3_ACCESS_KEY'),
                'secret' => $_ENV['S3_SECRET_KEY'] ?? getenv('S3_SECRET_KEY'),
            ],
        ]);
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Upload a file to S3
     *
     * @param string $localFilePath Path to the local file
     * @param string $s3Key The key (path) in S3 bucket
     * @param string $contentType MIME type of the file
     * @return array ['success' => bool, 'url' => string|null, 'key' => string|null, 'message' => string|null]
     */
    public function uploadFile(string $localFilePath, string $s3Key, string $contentType = 'application/octet-stream'): array {
        try {
            if (!file_exists($localFilePath)) {
                return [
                    'success' => false,
                    'message' => 'Local file does not exist: ' . $localFilePath
                ];
            }

            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'SourceFile' => $localFilePath,
                'ContentType' => $contentType,
                // Note: ACL removed - iDrive e2 may not support it. Use presigned URLs instead.
            ]);

            // Construct the URL
            $url = $this->getObjectUrl($s3Key);

            return [
                'success' => true,
                'url' => $url,
                'key' => $s3Key,
                'etag' => $result['ETag'] ?? null
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => 'S3 Upload Error: ' . $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Upload Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload from file data (POST upload)
     *
     * @param array $file The $_FILES array element
     * @param string $folder The folder in the bucket (e.g., 'xray/')
     * @param string|null $customFilename Optional custom filename
     * @return array
     */
    public function uploadFromPost(array $file, string $folder = '', ?string $customFilename = null): array {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return [
                'success' => false,
                'message' => 'Invalid uploaded file'
            ];
        }

        // Generate unique filename if not provided
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = $customFilename ?? uniqid() . '_' . time() . '.' . $extension;

        // Build S3 key
        $s3Key = rtrim($folder, '/');
        if (!empty($s3Key)) {
            $s3Key .= '/';
        }
        $s3Key .= $filename;

        // Determine content type
        $contentType = $this->getContentType($extension, $file['type'] ?? 'application/octet-stream');

        return $this->uploadFile($file['tmp_name'], $s3Key, $contentType);
    }

    /**
     * Delete a file from S3
     *
     * @param string $s3Key The key (path) of the file to delete
     * @return array
     */
    public function deleteFile(string $s3Key): array {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);

            return ['success' => true];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => 'S3 Delete Error: ' . $e->getAwsErrorMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Delete Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if a file exists in S3
     *
     * @param string $s3Key The key (path) of the file
     * @return bool
     */
    public function fileExists(string $s3Key): bool {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Get the public URL for an S3 object
     *
     * @param string $s3Key The key (path) of the file
     * @return string
     */
    public function getObjectUrl(string $s3Key): string {
        // For iDrive e2, construct the URL manually
        $endpoint = rtrim($this->endpoint, '/');
        return $endpoint . '/' . $this->bucket . '/' . ltrim($s3Key, '/');
    }

    /**
     * Get a pre-signed URL for temporary access
     *
     * @param string $s3Key The key (path) of the file
     * @param int $expiration Expiration time in minutes (default: 60)
     * @return string
     */
    public function getPresignedUrl(string $s3Key, int $expiration = 60): string {
        $cmd = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
        ]);

        $request = $this->s3Client->createPresignedRequest($cmd, "+{$expiration} minutes");

        return (string) $request->getUri();
    }

    /**
     * Get content type based on file extension
     *
     * @param string $extension File extension
     * @param string $default Default content type
     * @return string
     */
    private function getContentType(string $extension, string $default = 'application/octet-stream'): string {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
        ];

        return $mimeTypes[strtolower($extension)] ?? $default;
    }

    /**
     * Get the S3 client instance (for advanced operations)
     *
     * @return S3Client
     */
    public function getClient(): S3Client {
        return $this->s3Client;
    }

    /**
     * Get bucket name
     *
     * @return string
     */
    public function getBucket(): string {
        return $this->bucket;
    }

    /**
     * Extract S3 key from a full URL
     *
     * @param string $url The full S3 URL
     * @return string|null The S3 key or null if not a valid S3 URL
     */
    public function extractKeyFromUrl(string $url): ?string {
        $endpoint = rtrim($this->endpoint, '/');
        $prefix = $endpoint . '/' . $this->bucket . '/';

        if (strpos($url, $prefix) === 0) {
            return substr($url, strlen($prefix));
        }

        return null;
    }
}
