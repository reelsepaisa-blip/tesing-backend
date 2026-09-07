<?php

namespace App\Services;

use App\Models\SystemConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

use Illuminate\Support\Facades\Storage;
use ZipArchive;

class SystemUpdateService
{
    /**
     * Process the system update
     * Expected structure:
     * - Main ZIP: 1.0.2-to-1.0.3.zip (old-to-new pattern)
     * - Inside main ZIP: source_code.zip and version_info.json (preferred) or version_info.php (legacy, parsed as text only)
     * - source_code.zip contains files directly (app, resources, etc.)
     */
    public function processUpdate(string $filePath, string $purchaseCode): array
    {
        $mainExtractPath = null;
        $sourceCodeExtractPath = null;

        try {
            $fullPath = Storage::disk('local')->path($filePath);

            // Step 1: Validate main ZIP file
            if (!$this->validateZipFile($fullPath)) {
                return [
                    'success' => false,
                    'message' => 'Invalid ZIP file format'
                ];
            }

            // Step 2: Extract main ZIP file (e.g., 1.0.2-to-1.0.3.zip)
            $mainExtractPath = $this->extractZipFile($fullPath);
            if (!$mainExtractPath) {
                return [
                    'success' => false,
                    'message' => 'Failed to extract main ZIP file'
                ];
            }

            // Step 3: Validate update package structure
            if (!$this->validateUpdatePackage($mainExtractPath)) {
                File::deleteDirectory($mainExtractPath);
                return [
                    'success' => false,
                    'message' => 'Invalid update package structure. Expected: source_code.zip and version_info.json or version_info.php'
                ];
            }

            if (!$this->verifySignedUpdateManifestIfConfigured($mainExtractPath)) {
                File::deleteDirectory($mainExtractPath);
                return [
                    'success' => false,
                    'message' => 'Update package signature verification failed. Ensure the package is official and SYSTEM_UPDATE_PACKAGE_PUBLIC_KEY_PEM matches the publisher key.',
                ];
            }

            // Step 4: Extract source_code.zip
            $sourceCodeZipPath = $mainExtractPath . '/source_code.zip';
            if (!file_exists($sourceCodeZipPath)) {
                File::deleteDirectory($mainExtractPath);
                return [
                    'success' => false,
                    'message' => 'source_code.zip not found in update package'
                ];
            }

            $sourceCodeExtractPath = $this->extractSourceCodeZip($sourceCodeZipPath);
            if (!$sourceCodeExtractPath) {
                File::deleteDirectory($mainExtractPath);
                return [
                    'success' => false,
                    'message' => 'Failed to extract source_code.zip'
                ];
            }

            // Step 5: Get version from version_info.json or legacy version_info.php (never executed as PHP)
            $newVersion = $this->getVersionFromInfoFile($mainExtractPath);
            if (!$newVersion) {
                File::deleteDirectory($mainExtractPath);
                File::deleteDirectory($sourceCodeExtractPath);
                return [
                    'success' => false,
                    'message' => 'Could not read version from version_info.json or version_info.php'
                ];
            }

            // Step 6: Verify purchase code
            if (!$this->verifyPurchaseCode($purchaseCode)) {
                File::deleteDirectory($mainExtractPath);
                File::deleteDirectory($sourceCodeExtractPath);
                return [
                    'success' => false,
                    'message' => 'Purchase code validation failed. Please verify your purchase code.'
                ];
            }

            // Step 7: Backup current system (optional but recommended)
            $this->createBackup();

            // Step 8: Run migrations if needed
            $this->runMigrations($sourceCodeExtractPath);

            // Step 9: Update files from source_code.zip (files are directly in extract path, not in source_code folder)
            $this->updateFiles($sourceCodeExtractPath);

            // Step 10: Clear caches
            $this->clearCaches();

            // Step 11: Update system version
            $this->updateSystemVersion($newVersion);

            // Step 12: Cleanup
            File::deleteDirectory($mainExtractPath);
            File::deleteDirectory($sourceCodeExtractPath);

            return [
                'success' => true,
                'message' => 'System updated successfully',
                'version' => $newVersion
            ];
        } catch (\Exception $e) {
            // Cleanup on error
            if ($mainExtractPath && File::exists($mainExtractPath)) {
                File::deleteDirectory($mainExtractPath);
            }
            if ($sourceCodeExtractPath && File::exists($sourceCodeExtractPath)) {
                File::deleteDirectory($sourceCodeExtractPath);
            }

            return [
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate ZIP file
     */
    private function validateZipFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $zip = new ZipArchive();
        $result = $zip->open($filePath);

        if ($result !== true) {
            return false;
        }

        $zip->close();
        return true;
    }

    /**
     * Extract main update ZIP — only whitelisted root-level entries (no ZipArchive::extractTo path traversal).
     */
    private function extractZipFile(string $filePath): ?string
    {
        $extractPath = storage_path('app/updates/extracted/' . uniqid());

        if (!File::makeDirectory($extractPath, 0755, true)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $allowedRootFiles = [
            'source_code.zip' => true,
            'version_info.json' => true,
            'version_info.php' => true,
            'update.manifest.json' => true,
            'update.sig' => true,
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', (string) $filename);
            $normalized = trim($normalized, '/');

            if ($normalized === '' || str_contains($normalized, '/')) {
                continue;
            }

            if (!isset($allowedRootFiles[$normalized])) {
                continue;
            }

            if (substr((string) $filename, -1) === '/') {
                continue;
            }

            $targetPath = $extractPath . '/' . $normalized;
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            File::put($targetPath, $content);
        }

        $zip->close();

        if (!is_file($extractPath . '/source_code.zip')) {
            File::deleteDirectory($extractPath);

            return null;
        }

        return $extractPath;
    }

    /**
     * Validate update package structure
     * Should contain: source_code.zip and version_info.json or version_info.php
     */
    private function validateUpdatePackage(string $extractPath): bool
    {
        if (!file_exists($extractPath . '/source_code.zip')) {
            return false;
        }

        return file_exists($extractPath . '/version_info.json')
            || file_exists($extractPath . '/version_info.php');
    }

    /**
     * When services.system_update.package_public_key_pem is set, require a signed manifest
     * (update.manifest.json + update.sig) and matching SHA256 for each listed root file.
     */
    private function verifySignedUpdateManifestIfConfigured(string $extractPath): bool
    {
        $pem = config('services.system_update.package_public_key_pem');
        if ($pem === null || $pem === '') {
            return true;
        }

        $manifestPath = $extractPath . '/update.manifest.json';
        $sigPath = $extractPath . '/update.sig';
        if (!is_file($manifestPath) || !is_file($sigPath)) {
            return false;
        }

        $manifestBody = File::get($manifestPath);
        $signature = File::get($sigPath);
        $publicKeyPem = $this->resolveUpdatePackagePublicKeyPem((string) $pem);
        if ($publicKeyPem === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        $verified = openssl_verify($manifestBody, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        unset($publicKey);

        if ($verified !== 1) {
            return false;
        }

        try {
            $data = json_decode($manifestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return false;
        }

        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return false;
        }

        $allowedManifestNames = [
            'source_code.zip' => true,
            'version_info.json' => true,
            'version_info.php' => true,
        ];

        foreach ($data['files'] as $name => $expectedHash) {
            if (!is_string($name) || !isset($allowedManifestNames[$name])) {
                return false;
            }
            if (!is_string($expectedHash) || !preg_match('/^[a-f0-9]{64}$/i', $expectedHash)) {
                return false;
            }
            $path = $extractPath . '/' . $name;
            if (!is_file($path)) {
                return false;
            }
            if (!hash_equals(strtolower($expectedHash), hash_file('sha256', $path))) {
                return false;
            }
        }

        if (!isset($data['files']['source_code.zip'])) {
            return false;
        }

        $hasVersion = isset($data['files']['version_info.json']) || isset($data['files']['version_info.php']);

        return $hasVersion;
    }

    private function resolveUpdatePackagePublicKeyPem(string $pemOrPath): ?string
    {
        if ($pemOrPath !== '' && is_file($pemOrPath)) {
            $contents = @file_get_contents($pemOrPath);

            return $contents !== false ? $contents : null;
        }

        if (str_contains($pemOrPath, '-----BEGIN')) {
            return $pemOrPath;
        }

        return null;
    }

    /**
     * Resolve a relative ZIP entry path to an absolute path under $extractRoot, or null if traversal escapes root.
     */
    private function safePathUnderExtractRoot(string $extractRoot, string $relativePath): ?string
    {
        $rootReal = realpath($extractRoot);
        if ($rootReal === false) {
            return null;
        }

        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $segments = explode('/', $relativePath);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($resolved)) {
                    return null;
                }
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }

        if (empty($resolved)) {
            return null;
        }

        $suffix = implode(DIRECTORY_SEPARATOR, $resolved);
        $full = $rootReal . DIRECTORY_SEPARATOR . $suffix;

        $rootNorm = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rootReal));
        $fullNorm = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $full));

        if ($fullNorm !== $rootNorm && !str_starts_with($fullNorm, $rootNorm . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $full;
    }

    /**
     * Extract source_code.zip
     * Files should be directly in extract path (not in source_code folder)
     * Handles Windows backslashes; rejects path traversal outside extract directory.
     */
    private function extractSourceCodeZip(string $sourceCodeZipPath): ?string
    {
        $extractPath = storage_path('app/updates/source_code/' . uniqid());

        if (!File::makeDirectory($extractPath, 0755, true)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($sourceCodeZipPath) !== true) {
            return null;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if ($filename === false || $filename === '') {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $filename);

            // Directory entries
            if (substr($normalizedPath, -1) === '/') {
                $dirOnly = rtrim($normalizedPath, '/');
                if ($dirOnly !== '') {
                    $dirPath = $this->safePathUnderExtractRoot($extractPath, $dirOnly);
                    if ($dirPath !== null && !File::isDirectory($dirPath)) {
                        File::makeDirectory($dirPath, 0755, true);
                    }
                }

                continue;
            }

            $destinationPath = $this->safePathUnderExtractRoot($extractPath, $normalizedPath);
            if ($destinationPath === null) {
                continue;
            }

            if (File::isDirectory($destinationPath)) {
                continue;
            }

            $destinationDir = dirname($destinationPath);
            if (!File::isDirectory($destinationDir)) {
                File::makeDirectory($destinationDir, 0755, true);
            }

            $fileContent = $zip->getFromIndex($i);
            if ($fileContent === false) {
                continue;
            }

            File::put($destinationPath, $fileContent);
        }

        $zip->close();

        return $extractPath;
    }

    /**
     * Verify purchase code with wrteam validator
     */
    private function verifyPurchaseCode(string $purchaseCode): bool
    {
        try {
            $result = $this->validatePurchaseCode($purchaseCode);
            return $result['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate purchase code with wrteam validator API
     *
     * @param string $purchaseCode
     * @return array ['success' => bool, 'message' => string]
     */
    public function validatePurchaseCode(string $purchaseCode): array
    {
        try {
            // Get app URL and clean it
            $appUrl = config('app.url', 'http://localhost');
            if (empty($appUrl) || $appUrl === 'http://localhost') {
                // Fallback to request URL if config is not set
                if (request()) {
                    $appUrl = request()->getSchemeAndHttpHost();
                } else {
                    $appUrl = 'http://localhost';
                }
            }
            $appUrl = preg_replace('#^https?://#i', '', $appUrl);

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL            => 'https://validator.wrteam.in/etaxi_validator?purchase_code=' . urlencode($purchaseCode) . '&domain_url=' . urlencode($appUrl),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ));

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($curlError) {
                return [
                    'success' => false,
                    'message' => 'Connection error: ' . $curlError
                ];
            }

            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'message' => 'Validation server returned error code: ' . $httpCode
                ];
            }

            $response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (isset($response['error']) && $response['error']) {
                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Invalid purchase code'
                ];
            }

            return [
                'success' => true,
                'message' => $response['message'] ?? 'Purchase code validated successfully'
            ];
        } catch (\JsonException $e) {
            return [
                'success' => false,
                'message' => 'Invalid response from validation server'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create backup of current system
     */
    private function createBackup(): void
    {
        try {
            $backupPath = storage_path('app/backups/' . date('Y-m-d_H-i-s'));
            File::makeDirectory($backupPath, 0755, true);

            // Backup important directories
            $directoriesToBackup = [
                'app' => base_path('app'),
                'config' => base_path('config'),
                'database' => base_path('database'),
            ];

            foreach ($directoriesToBackup as $name => $path) {
                if (File::exists($path)) {
                    File::copyDirectory($path, $backupPath . '/' . $name);
                }
            }
        } catch (\Exception $e) {
            // Log error if needed
        }
    }

    /**
     * Run migrations from update package
     */
    private function runMigrations(string $extractPath): void
    {
        $migrationsPath = $extractPath . '/database/migrations';

        if (File::exists($migrationsPath)) {
            // Copy migrations to database/migrations
            $targetMigrationsPath = base_path('database/migrations');
            File::copyDirectory($migrationsPath, $targetMigrationsPath);

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    /**
     * Update application files
     * Only files present in ZIP will be updated (incremental update)
     */
    private function updateFiles(string $extractPath): void
    {


        // List all directories in extract path for debugging
        if (File::isDirectory($extractPath)) {
            $directories = File::directories($extractPath);
        }

        // Update app files (only changed files)
        $appPath = $extractPath . '/app';

        if (File::exists($appPath)) {
            $this->copyFilesRecursively($appPath, base_path('app'));
        } else {
            $extractContents = [];
            if (File::exists($extractPath) && File::isDirectory($extractPath)) {
                try {
                    $files = File::allFiles($extractPath);
                    $extractContents = array_map(function ($file) {
                        return $file->getRelativePathname();
                    }, $files);
                } catch (\Exception $e) {
                    $extractContents = 'Error listing files: ' . $e->getMessage();
                }
            } else {
                $extractContents = 'Extract path does not exist or is not a directory';
            }
        }

        // Update config files (be careful with this)
        $configPath = $extractPath . '/config';
        if (File::exists($configPath)) {
            // Only update non-sensitive config files
            $safeConfigFiles = ['app.php', 'cache.php', 'database.php'];
            foreach ($safeConfigFiles as $configFile) {
                $sourceFile = $configPath . '/' . $configFile;
                if (File::exists($sourceFile)) {
                    File::copy($sourceFile, base_path('config/' . $configFile));
                }
            }
        }

        // Update public assets (only changed files)
        $publicPath = $extractPath . '/public';
        if (File::exists($publicPath)) {
            $this->copyFilesRecursively($publicPath, public_path());
        }

        // Update resources (only changed files)
        $resourcesPath = $extractPath . '/resources';
        if (File::exists($resourcesPath)) {
            $this->copyFilesRecursively($resourcesPath, base_path('resources'));
        }

        // Update database migrations (only new migrations)
        $migrationsPath = $extractPath . '/database/migrations';
        if (File::exists($migrationsPath)) {
            $this->copyFilesRecursively($migrationsPath, base_path('database/migrations'));
        }

        // Update root files if any (like .env.example, composer.json, etc.)
        $rootFiles = ['composer.json', '.env.example', 'package.json', 'README.md'];
        foreach ($rootFiles as $rootFile) {
            $sourceFile = $extractPath . '/' . $rootFile;
            if (File::exists($sourceFile)) {
                File::copy($sourceFile, base_path($rootFile));
            }
        }
    }

    /**
     * Copy files recursively - only files that exist in source will be copied
     * This ensures incremental updates (only changed files are updated)
     */
    private function copyFilesRecursively(string $source, string $destination): void
    {
        if (!File::isDirectory($source)) {

            return;
        }

        // Create destination directory if it doesn't exist
        if (!File::isDirectory($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        $files = File::allFiles($source);

        if (empty($files)) {

            return;
        }


        foreach ($files as $file) {
            try {
                $relativePath = $file->getRelativePathname();
                $destinationFile = $destination . '/' . $relativePath;
                $destinationDir = dirname($destinationFile);

                // Create directory structure if needed
                if (!File::isDirectory($destinationDir)) {
                    File::makeDirectory($destinationDir, 0755, true);
                }

                // Copy only the file (not entire directory)
                File::copy($file->getPathname(), $destinationFile);
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Clear all caches
     */
    private function clearCaches(): void
    {
        try {
            Artisan::call('optimize:clear');
            Artisan::call('view:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('cache:clear');
        } catch (\Exception $e) {
            // Log error if needed
        }
    }

    /**
     * Read version from version_info.json (preferred) or legacy version_info.php.
     * PHP files are never executed (no include/require) — only static patterns are parsed from file contents.
     */
    private function getVersionFromInfoFile(string $extractPath): ?string
    {
        $jsonFile = $extractPath . '/version_info.json';
        if (File::exists($jsonFile)) {
            try {
                $data = json_decode(File::get($jsonFile), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                    $v = trim($data['version']);

                    return $v !== '' ? $v : null;
                }
            } catch (\Throwable $e) {
                return null;
            }

            return null;
        }

        $versionInfoFile = $extractPath . '/version_info.php';
        if (!File::exists($versionInfoFile)) {
            return null;
        }

        try {
            $content = File::get($versionInfoFile);

            if (preg_match("/return\s+['\"]([^'\"]+)['\"]/", $content, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match("/define\s*\(\s*['\"]VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match("/\$version\s*=\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                return trim($matches[1]);
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update system version
     */
    private function updateSystemVersion(string $version): void
    {
        SystemConfiguration::updateOrCreate(
            ['key' => 'system_version'],
            [
                'value' => $version,
                'category' => 'system',
                'description' => 'Current system version',
                'is_active' => true,
                'is_encrypted' => false,
            ]
        );
    }
}
