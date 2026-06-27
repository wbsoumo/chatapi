<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\MediaService;
use App\Utils\Response;
use App\Utils\Validator;
use App\Utils\Logger;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use PDO;

class MediaController {
    
    public function uploadMedia(array $userContext): void {
        $userId = $userContext['sub'];

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file was uploaded or an upload error occurred', 400, 4015);
        }

        $file = $_FILES['file'];
        $type = $_POST['type'] ?? 'document'; // image, video, voice, document, sticker, profile
        
        if (!in_array($type, ['image', 'video', 'voice', 'document', 'sticker', 'profile'])) {
            Response::error('Invalid media type specified', 400, 4016);
        }

        // Validate file size limit (default 50MB)
        if (!getenv('MAX_FILE_SIZE_MB')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }
        $maxMb = (int)($_ENV['MAX_FILE_SIZE_MB'] ?? 50);
        if ($file['size'] > ($maxMb * 1024 * 1024)) {
            Response::error("File size exceeds the limit of $maxMb MB", 400, 4017);
        }

        // Prepare destination folder structure
        $baseDir = dirname(__DIR__, 2) . '/public/uploads';
        $subfolder = 'media';
        if ($type === 'profile') {
            $subfolder = 'profiles';
        } elseif ($type === 'sticker') {
            $subfolder = 'stickers';
        }

        $targetDir = $baseDir . '/' . $subfolder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileUuid = self::generateUuid();
        
        // Convert images to WebP
        $outputExt = $extension;
        if ($type === 'image' || $type === 'profile') {
            $outputExt = 'webp';
        }

        $targetName = $fileUuid . '.' . $outputExt;
        $targetPath = $targetDir . '/' . $targetName;
        $relativeUrl = 'uploads/' . $subfolder . '/' . $targetName;

        // Perform upload & post-processing
        $tempPath = $file['tmp_name'];
        $processed = false;

        $thumbnailPath = null;
        $relativeThumbUrl = null;
        $width = null;
        $height = null;
        $duration = 0;
        $waveform = null;

        if ($type === 'image' || $type === 'profile') {
            // Compress image and save directly to target path
            $processed = MediaService::compressImage($tempPath, $targetPath, 75);
            
            // Get size
            $imgSize = getimagesize($targetPath);
            if ($imgSize) {
                $width = $imgSize[0];
                $height = $imgSize[1];
            }

            // Generate thumbnail
            if ($processed) {
                $thumbName = $fileUuid . '_thumb.webp';
                $thumbPath = $targetDir . '/' . $thumbName;
                if (MediaService::generateThumbnail($targetPath, $thumbPath, 150)) {
                    $relativeThumbUrl = 'uploads/' . $subfolder . '/' . $thumbName;
                }
            }
        } elseif ($type === 'video') {
            if (move_uploaded_file($tempPath, $targetPath)) {
                $processed = true;
                // Extract thumbnail frame
                $thumbName = $fileUuid . '_thumb.webp';
                $thumbPath = $targetDir . '/' . $thumbName;
                if (MediaService::extractVideoThumbnail($targetPath, $thumbPath)) {
                    $relativeThumbUrl = 'uploads/' . $subfolder . '/' . $thumbName;
                }
                $duration = MediaService::getVideoDuration($targetPath);
            }
        } elseif ($type === 'voice') {
            if (move_uploaded_file($tempPath, $targetPath)) {
                $processed = true;
                $waveform = json_encode(MediaService::generateVoiceWaveform($targetPath));
            }
        } else {
            // Document / Sticker upload - direct move
            if (move_uploaded_file($tempPath, $targetPath)) {
                $processed = true;
            }
        }

        if (!$processed) {
            Response::error('Failed to process and store media file', 500, 5005);
        }

        // Store file details in Database
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO media_uploads (id, uploader_id, file_path, file_size, mime_type) 
            VALUES (:id, :uploader, :path, :size, :mime)
        ");
        $stmt->execute([
            'id' => $fileUuid,
            'uploader' => $userId,
            'path' => $relativeUrl,
            'size' => $file['size'],
            'mime' => $file['type']
        ]);

        Response::success('File uploaded successfully', [
            'media_id' => $fileUuid,
            'file_name' => $file['name'],
            'file_path' => $relativeUrl,
            'file_size' => $file['size'],
            'mime_type' => $file['type'],
            'thumbnail_path' => $relativeThumbUrl,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'waveform' => $waveform
        ]);
    }

    public function searchGifs(array $userContext): void {
        $query = trim($_GET['query'] ?? '');
        $limit = (int)($_GET['limit'] ?? 20);

        // Tenor API Key setup in .env or fallback mock
        $tenorApiKey = $_ENV['TENOR_API_KEY'] ?? '';
        
        if (empty($tenorApiKey) || str_starts_with($tenorApiKey, 'mock')) {
            // Return structured mock GIFs matching WhatsApp standards
            $mockGifs = $this->getMockGifs($query, $limit);
            Response::success('Mock GIFs retrieved successfully', ['gifs' => $mockGifs]);
        }

        // Proxy request to Giphy/Tenor
        $client = new Client();
        try {
            $response = $client->get("https://tenor.googleapis.com/v2/search", [
                'query' => [
                    'q' => $query,
                    'key' => $tenorApiKey,
                    'client_key' => 'whatsapp_android',
                    'limit' => $limit
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            $results = $body['results'] ?? [];

            $formatted = [];
            foreach ($results as $item) {
                $media = $item['media_formats']['nanogif'] ?? $item['media_formats']['tinygif'] ?? [];
                $formatted[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'gif_url' => $media['url'] ?? '',
                    'width' => $media['dims'][0] ?? 0,
                    'height' => $media['dims'][1] ?? 0
                ];
            }
            Response::success('GIFs retrieved successfully', ['gifs' => $formatted]);
        } catch (\Exception $e) {
            Logger::error("Tenor GIF search failed: " . $e->getMessage());
            Response::success('Fall back to Mock GIFs', ['gifs' => $this->getMockGifs($query, $limit)]);
        }
    }

    public function getTrendingGifs(array $userContext): void {
        $limit = (int)($_GET['limit'] ?? 20);
        $tenorApiKey = $_ENV['TENOR_API_KEY'] ?? '';

        if (empty($tenorApiKey) || str_starts_with($tenorApiKey, 'mock')) {
            Response::success('Mock Trending GIFs retrieved successfully', ['gifs' => $this->getMockGifs('', $limit)]);
        }

        $client = new Client();
        try {
            $response = $client->get("https://tenor.googleapis.com/v2/featured", [
                'query' => [
                    'key' => $tenorApiKey,
                    'client_key' => 'whatsapp_android',
                    'limit' => $limit
                ]
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            $results = $body['results'] ?? [];

            $formatted = [];
            foreach ($results as $item) {
                $media = $item['media_formats']['nanogif'] ?? $item['media_formats']['tinygif'] ?? [];
                $formatted[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'gif_url' => $media['url'] ?? '',
                    'width' => $media['dims'][0] ?? 0,
                    'height' => $media['dims'][1] ?? 0
                ];
            }
            Response::success('Trending GIFs retrieved successfully', ['gifs' => $formatted]);
        } catch (\Exception $e) {
            Logger::error("Tenor GIF trending failed: " . $e->getMessage());
            Response::success('Fall back to Mock GIFs', ['gifs' => $this->getMockGifs('', $limit)]);
        }
    }

    public function getStickerPacks(array $userContext): void {
        // Return standard WhatsApp style sticker packs: static & animated
        $stickerPacks = [
            [
                'pack_id' => 'pack_01',
                'name' => 'Cuppy The Cupcake',
                'publisher' => 'WhatsApp',
                'is_animated' => false,
                'thumbnail' => 'uploads/stickers/cuppy/thumb.webp',
                'stickers' => [
                    ['id' => 'cuppy_01', 'url' => 'uploads/stickers/cuppy/cuppy1.webp'],
                    ['id' => 'cuppy_02', 'url' => 'uploads/stickers/cuppy/cuppy2.webp'],
                    ['id' => 'cuppy_03', 'url' => 'uploads/stickers/cuppy/cuppy3.webp']
                ]
            ],
            [
                'pack_id' => 'pack_02',
                'name' => 'Timo The Animated Dino',
                'publisher' => 'WhatsApp',
                'is_animated' => true,
                'thumbnail' => 'uploads/stickers/dino/thumb.webp',
                'stickers' => [
                    ['id' => 'dino_01', 'url' => 'uploads/stickers/dino/dino1.webp'],
                    ['id' => 'dino_02', 'url' => 'uploads/stickers/dino/dino2.webp']
                ]
            ]
        ];

        Response::success('Sticker packs retrieved successfully', ['sticker_packs' => $stickerPacks]);
    }

    private function getMockGifs(string $query, int $limit): array {
        $gifs = [
            ['id' => 'mock_gif_1', 'title' => 'Happy Dance', 'gif_url' => 'https://media.giphy.com/media/l0MYt5jPR6QX5pnqM/giphy.gif', 'width' => 200, 'height' => 150],
            ['id' => 'mock_gif_2', 'title' => 'Thumbs Up', 'gif_url' => 'https://media.giphy.com/media/3o7abKhOpu0NXS3HWM/giphy.gif', 'width' => 200, 'height' => 200],
            ['id' => 'mock_gif_3', 'title' => 'Mind Blown', 'gif_url' => 'https://media.giphy.com/media/26ufdipQqU2lhNA4g/giphy.gif', 'width' => 200, 'height' => 120],
            ['id' => 'mock_gif_4', 'title' => 'Facepalm', 'gif_url' => 'https://media.giphy.com/media/3og0INyMDTBSIIK4Ew/giphy.gif', 'width' => 200, 'height' => 150]
        ];

        if (!empty($query)) {
            $gifs = array_filter($gifs, function($g) use ($query) {
                return stripos($g['title'], $query) !== false;
            });
        }

        return array_values(array_slice($gifs, 0, $limit));
    }

    private static function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
