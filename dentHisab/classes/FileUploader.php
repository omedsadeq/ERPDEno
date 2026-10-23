<?php
/**
 * File Uploader Helper Class
 * سیستەمی زیرەکی کلینیکی ددان
 */

class FileUploader {
    private $uploadDir;
    private $allowedExtensions;
    private $maxFileSize;

    public function __construct($uploadDir = 'uploads/expenses/', $maxFileSize = 5242880) {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';
        $this->maxFileSize = $maxFileSize; // 5MB default
        $this->allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    }

    /**
     * Upload a file
     * @param array $file The $_FILES array element
     * @return array ['success' => bool, 'message' => string, 'filename' => string]
     */
    public function upload($file) {
        // Check if file was uploaded
        if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'message' => 'No file uploaded', 'filename' => null];
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error: ' . $file['error'], 'filename' => null];
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            return ['success' => false, 'message' => 'File too large (max 5MB)', 'filename' => null];
        }

        // Get file extension
        $filename = $file['name'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Check file extension
        if (!in_array($extension, $this->allowedExtensions)) {
            return ['success' => false, 'message' => 'Invalid file type', 'filename' => null];
        }

        // Generate unique filename
        $newFilename = uniqid() . '_' . time() . '.' . $extension;
        $targetPath = APP_ROOT . '/' . $this->uploadDir . $newFilename;

        // Create directory if it doesn't exist
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return [
                'success' => true,
                'message' => 'File uploaded successfully',
                'filename' => $this->uploadDir . $newFilename
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to move uploaded file', 'filename' => null];
        }
    }

    /**
     * Delete a file
     * @param string $filename The relative path to the file
     * @return bool
     */
    public function delete($filename) {
        if (empty($filename)) {
            return false;
        }

        $filePath = APP_ROOT . '/' . $filename;
        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }
}
