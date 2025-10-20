<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Widget\Helper\Form;
use Typecho\Common;

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

            // Typecho 1.3.0 文件类型检查
            $allowedTypes = ['jpg', 'jpeg', 'gif', 'png', 'webp', 'bmp', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'mp3', 'wav', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar'];
            if (!in_array(strtolower($ext), $allowedTypes)) {
                return false;
            }

            $options = \Typecho\Widget::widget('Widget\Options')->plugin('S3Upload');
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

    public static function attachmentHandle($content)
    {
        // 支持 Typecho\Config 对象和数组两种类型
        if ($content instanceof \Typecho\Config) {
            $path = $content->path ?? '';
        } else if (is_array($content)) {
            $path = $content['attachment']->path ?? ($content['path'] ?? '');
        } else {
            return '';
        }

        if (empty($path)) return '';
        $s3Client = S3Upload_S3Client::getInstance();
        return $s3Client->getObjectUrl($path);
    }

    public static function attachmentDataHandle($content)
    {
        // 支持 Typecho\Config 对象和数组两种类型
        if ($content instanceof \Typecho\Config) {
            $path = $content->path ?? '';
        } else if (is_array($content)) {
            $path = $content['attachment']->path ?? ($content['path'] ?? '');
        } else {
            return '';
        }

        if (empty($path)) return '';
        $s3Client = S3Upload_S3Client::getInstance();
        return $s3Client->getObjectUrl($path);
    }

    public static function modifyHandle($content, $file)
    {
        try {
            $uploader = new S3Upload_StreamUploader();
            return $uploader->handleUpload($file);
        } catch (Exception $e) {
            S3Upload_Utils::log("修改文件错误: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public static function deleteHandle($content)
    {
        try {
            $path = $content['attachment']->path ?? ($content['path'] ?? '');
            if (empty($path)) return false;
            
            $s3Client = S3Upload_S3Client::getInstance();
            $result = $s3Client->deleteObject($path);
            
            // 如果配置了本地备份，也删除本地文件
            $options = \Typecho\Widget::widget('Widget\Options')->plugin('S3Upload');
            if (isset($options->saveLocal) && $options->saveLocal == 'true') {
                $localPath = dirname(__FILE__) . '/../../../' . $path;
                if (file_exists($localPath)) {
                    @unlink($localPath);
                }
            }
            
            return $result;
        } catch (Exception $e) {
            S3Upload_Utils::log("删除文件错误: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function getSafeName($name)
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        return $ext ? strtolower($ext) : '';
    }

    private static function isImage($mime)
    {
        return strpos($mime, 'image/') === 0;
    }

    private static function convertToWebp($src, $dest, $mime, $quality = 85)
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $image = null;
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($src);
                break;
            case 'image/png':
                $image = imagecreatefrompng($src);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($src);
                break;
            default:
                return false;
        }

        if (!$image) {
            return false;
        }

        // 保持透明度
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $result = imagewebp($image, $dest, $quality);
        imagedestroy($image);

        return $result;
    }

    private static function replaceExtToWebp($filename)
    {
        $info = pathinfo($filename);
        $dirname = isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
        return $dirname . $info['filename'] . '.webp';
    }
}