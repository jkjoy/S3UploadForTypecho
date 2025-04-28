<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Widget\Helper\Form;
use Utils\Helper;
use Widget\Upload;

class S3Upload_FileHandler
{
    const MIN_COMPRESS_SIZE = 102400; // 100KB

    /**
     * 上传文件处理函数
     */
    public static function uploadHandle($file)
    {
        try {
            if (empty($file['name'])) {
                return false;
            }

            $ext = self::getSafeName($file['name']);

            if (!Upload::checkFileType($ext)) {
                return false;
            }

            $options = Helper::options()->plugin('S3Upload');
            $mime = $file['type'] ?? mime_content_type($file['tmp_name'] ?? '');
            $isImage = self::isImage($mime);

            $tempFile = null;
            $webpName = null;
            // 只处理大于100KB的图片
            if (
                $isImage &&
                isset($options->compressImages) && $options->compressImages == '1' &&
                isset($file['tmp_name']) && is_file($file['tmp_name']) && filesize($file['tmp_name']) > self::MIN_COMPRESS_SIZE
            ) {
                $quality = isset($options->compressQuality) ? (int)$options->compressQuality : 85;
                $tempFile = tempnam(sys_get_temp_dir(), 'webp_') . '.webp';
                if (self::convertToWebp($file['tmp_name'], $tempFile, $mime, $quality)) {
                    $file['tmp_name'] = $tempFile;
                    $file['size'] = filesize($tempFile);
                    $file['type'] = 'image/webp';
                    $webpName = self::replaceExtToWebp($file['name']);
                    $ext = 'webp';
                } else {
                    @unlink($tempFile);
                    $tempFile = null;
                }
            }

            if ($webpName) {
                $file['name'] = $webpName;
            }

            $uploader = new S3Upload_StreamUploader();
            $result = $uploader->handleUpload($file);

            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            if ($result) {
                // 保证 webp 后缀
                if ($webpName) {
                    $result['name'] = $webpName;
                    $result['type'] = 'webp';
                    $result['mime'] = 'image/webp';
                    $result['extension'] = 'webp';
                }
                return [
                    'name'      => $result['name'],
                    'path'      => $result['path'],
                    'size'      => $result['size'],
                    'type'      => $result['type'],
                    'mime'      => $result['mime'],
                    'extension' => $result['extension'],
                    'created'   => time(),
                    'attachment'=> (object)['path' => $result['path']],
                    'isImage'   => self::isImage($result['mime']),
                    'url'       => $result['url']
                ];
            }

            return false;
        } catch (Exception $e) {
            S3Upload_Utils::log("上传处理错误: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function attachmentHandle(array $content)
    {
        $options = Helper::options()->plugin('S3Upload');
        $path = $content['attachment']->path ?? ($content['path'] ?? '');
        if (empty($path)) return '';
        $s3Client = S3Upload_S3Client::getInstance();
        return $s3Client->getObjectUrl($path);
    }

    public static function attachmentDataHandle(array $content)
    {
        return $content;
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) return false;
        $result = self::uploadHandle($file);
        if ($result) {
            self::deleteHandle($content);
            return $result;
        }
        return false;
    }

    public static function deleteHandle($content)
    {
        $path = $content['attachment']->path ?? ($content['path'] ?? '');
        if (empty($path)) return false;
        try {
            $client = S3Upload_S3Client::getInstance();
            $client->deleteObject($path);

            $localFile = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . $path;
            if (file_exists($localFile)) {
                @unlink($localFile);
            }
            return true;
        } catch (Exception $e) {
            S3Upload_Utils::log("删除文件失败: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function getSafeName($name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function isImage($mime)
    {
        return in_array($mime, [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'
        ]);
    }

    /**
     * 压缩并转为webp
     * @param string $src 源图片
     * @param string $dest 目标webp
     * @param string $mime 源图片mime
     * @param int $quality
     * @return bool
     */
    private static function convertToWebp($src, $dest, $mime, $quality = 85)
    {
        if (!function_exists('imagewebp')) return false;
        if (!file_exists($src)) return false;
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($src);
                break;
            case 'image/png':
                $image = imagecreatefrompng($src);
                // 透明背景处理
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($src);
                break;
            case 'image/bmp':
                $image = imagecreatefrombmp($src);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($src);
                break;
            default:
                return false;
        }
        if (!$image) return false;
        $result = imagewebp($image, $dest, $quality);
        imagedestroy($image);
        return $result && file_exists($dest);
    }

    /**
     * 替换文件名扩展为webp
     */
    private static function replaceExtToWebp($filename)
    {
        $info = pathinfo($filename);
        $base = isset($info['filename']) ? $info['filename'] : (isset($info['basename']) ? $info['basename'] : $filename);
        return $base . '.webp';
    }
}