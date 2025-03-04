<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Widget\Helper\Form;
use Utils\Helper;
use Widget\Upload;

/**
 * 文件处理类
 */
class S3Upload_FileHandler
{
    /**
     * 上传文件处理函数
     * 
     * @access public
     * @param array $file 上传的文件
     * @return array|bool
     */
    public static function uploadHandle($file)
    {
        try {
            if (empty($file['name'])) {
                return false;
            }

            $ext = self::getSafeName($file['name']);
            
            // 使用新版本的检查方法
            if (!Upload::checkFileType($ext)) {
                return false;
            }

            // 处理文件上传
            $uploader = new S3Upload_StreamUploader();
            $result = $uploader->handleUpload($file);

            if ($result) {
                return [
                    'name' => $result['name'],
                    'path' => $result['path'],
                    'size' => $result['size'],
                    'type' => $result['type'],
                    'mime' => $result['mime'],
                    // Typecho 1.2 需要这些字段
                    'extension' => $ext,
                    'created' => time(),
                    'attachment' => new \stdClass(),
                    'isImage' => self::isImage($result['mime']),
                    'url' => $result['url']
                ];
            }

            return false;

        } catch (Exception $e) {
            S3Upload_Utils::log("上传处理错误: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * 获取实际文件URL
     * 
     * @access public
     * @param array $content 内容数据
     * @return string
     */
    public static function attachmentHandle(array $content)
    {
        // 获取S3配置
        $options = Helper::options()->plugin('S3Upload');
        
        // 构建URL
        $path = $content['attachment']->path;
        if (empty($path)) {
            return '';
        }

        // 使用S3Client获取URL
        $s3Client = S3Upload_S3Client::getInstance();
        return $s3Client->getObjectUrl($path);
    }

    /**
     * 获取实际文件数据
     * 
     * @access public
     * @param array $content 内容数据
     * @return array
     */
    public static function attachmentDataHandle(array $content)
    {
        return $content;
    }

    /**
     * 修改文件处理函数
     * 
     * @access public
     * @param array $content 旧文件
     * @param array $file 新上传的文件
     * @return array|bool
     */
    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $result = self::uploadHandle($file);

        if ($result) {
            self::deleteHandle($content);
            return $result;
        }

        return false;
    }

    /**
     * 删除文件
     * 
     * @access public
     * @param array $content 文件信息
     * @return bool
     */
    public static function deleteHandle($content)
    {
        if (!isset($content['attachment']->path)) {
            return false;
        }

        try {
            $client = S3Upload_S3Client::getInstance();
            $client->deleteObject($content['attachment']->path);

            // 删除本地备份
            $localFile = __TYPECHO_ROOT_DIR__ . '/usr/uploads/' . $content['attachment']->path;
            if (file_exists($localFile)) {
                @unlink($localFile);
            }

            return true;

        } catch (Exception $e) {
            S3Upload_Utils::log("删除文件失败: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 获取安全的文件名
     * 
     * @access private
     * @param string $name
     * @return string
     */
    private static function getSafeName($name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 判断是否为图片
     * 
     * @access private
     * @param string $mime
     * @return bool
     */
    private static function isImage($mime)
    {
        return in_array($mime, array(
            'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'
        ));
    }
}