<?php

namespace App\Services;

use App\Utils\Logger;
use Exception;

class MediaService {
    
    public static function compressImage(string $sourcePath, string $targetPath, int $quality = 75, int $maxWidth = 1200): bool {
        try {
            if (!extension_loaded('gd')) {
                return copy($sourcePath, $targetPath);
            }

            $info = getimagesize($sourcePath);
            if (!$info) {
                return copy($sourcePath, $targetPath);
            }

            $mime = $info['mime'];
            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    // Preserve transparency for PNG
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($sourcePath);
                    break;
                default:
                    // Fallback to simple copy for unsupported types like HEIC if Imagick is absent
                    return copy($sourcePath, $targetPath);
            }

            if (!$image) {
                return copy($sourcePath, $targetPath);
            }

            // Resize if wider than maxWidth
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int)($height * ($maxWidth / $width));
                
                $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                
                if ($mime === 'image/png' || $mime === 'image/webp') {
                    imagealphablending($resizedImage, false);
                    imagesavealpha($resizedImage, true);
                }

                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resizedImage;
            }

            // Save as WebP for high performance and compression
            $result = imagewebp($image, $targetPath, $quality);
            imagedestroy($image);
            return $result;

        } catch (Exception $e) {
            Logger::error("Image compression error: " . $e->getMessage());
            return copy($sourcePath, $targetPath);
        }
    }

    public static function generateThumbnail(string $sourcePath, string $targetPath, int $thumbSize = 150): bool {
        try {
            if (!extension_loaded('gd')) {
                return false;
            }

            $info = getimagesize($sourcePath);
            if (!$info) {
                return false;
            }

            $mime = $info['mime'];
            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($sourcePath);
                    break;
                default:
                    return false;
            }

            if (!$image) {
                return false;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            // Maintain aspect ratio, fit inside square of thumbSize
            if ($width > $height) {
                $newWidth = $thumbSize;
                $newHeight = (int)($height * ($thumbSize / $width));
            } else {
                $newHeight = $thumbSize;
                $newWidth = (int)($width * ($thumbSize / $height));
            }

            $thumbImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Enable transparency
            imagealphablending($thumbImage, false);
            imagesavealpha($thumbImage, true);

            imagecopyresampled($thumbImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            $result = imagewebp($thumbImage, $targetPath, 60); // Low quality for fast load
            
            imagedestroy($image);
            imagedestroy($thumbImage);

            return $result;

        } catch (Exception $e) {
            Logger::error("Thumbnail generation error: " . $e->getMessage());
            return false;
        }
    }

    public static function extractVideoThumbnail(string $videoPath, string $targetPath): bool {
        // Safe check for shell commands and FFmpeg
        $ffmpeg = self::getFfmpegPath();
        if ($ffmpeg) {
            $cmd = escapeshellcmd("$ffmpeg -i " . escapeshellarg($videoPath) . " -ss 00:00:01 -vframes 1 -f image2 " . escapeshellarg($targetPath) . " 2>&1");
            exec($cmd, $output, $resultCode);
            if ($resultCode === 0) {
                // Compress thumbnail to WebP
                $tempPng = $targetPath . '.png';
                rename($targetPath, $tempPng);
                self::compressImage($tempPng, $targetPath, 60, 300);
                unlink($tempPng);
                return true;
            }
        }
        
        // Fallback: copy a default video icon placeholder
        $defaultPlaceholder = dirname(__DIR__, 2) . '/public/uploads/default_video_thumb.webp';
        if (!file_exists($defaultPlaceholder)) {
            // Create a small blank image as fallback
            $im = imagecreatetruecolor(150, 150);
            $bgColor = imagecolorallocate($im, 50, 50, 50);
            imagefill($im, 0, 0, $bgColor);
            // Draw a simple play triangle inside the fallback
            $white = imagecolorallocate($im, 255, 255, 255);
            $points = [
                60, 50,  // top left
                100, 75, // middle right
                60, 100  // bottom left
            ];
            imagefilledpolygon($im, $points, 3, $white);
            if (!is_dir(dirname($defaultPlaceholder))) {
                mkdir(dirname($defaultPlaceholder), 0755, true);
            }
            imagewebp($im, $defaultPlaceholder, 60);
            imagedestroy($im);
        }

        return copy($defaultPlaceholder, $targetPath);
    }

    public static function generateVoiceWaveform(string $audioPath): array {
        // WhatsApp displays waveform as normalized floats (0.0 to 1.0)
        // We synthesize a consistent waveform curve representing voice modulation
        $points = 40;
        $waveform = [];
        // Use hash of audio path or size to make it consistent for the same file
        $seed = crc32($audioPath);
        mt_srand($seed);

        for ($i = 0; $i < $points; $i++) {
            // Create voice-like spikes: rising and falling amplitudes
            $sineVal = abs(sin(($i / $points) * M_PI));
            $randomNoise = mt_rand(20, 100) / 100;
            $waveform[] = round($sineVal * $randomNoise, 2);
        }
        return $waveform;
    }

    public static function getVideoDuration(string $videoPath): int {
        $ffprobe = self::getFfprobePath();
        if ($ffprobe) {
            $cmd = escapeshellcmd("$ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($videoPath));
            $duration = shell_exec($cmd);
            if ($duration !== null) {
                return (int)round((float)$duration);
            }
        }
        return 0; // Default fallback if no ffprobe
    }

    private static function getFfmpegPath(): ?string {
        $path = shell_exec('which ffmpeg');
        return $path ? trim($path) : null;
    }

    private static function getFfprobePath(): ?string {
        $path = shell_exec('which ffprobe');
        return $path ? trim($path) : null;
    }
}
