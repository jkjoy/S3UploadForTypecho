<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 流式上传处理器
 * 
 * @package S3Upload
 * @author jkjoy
 * @version 1.1.0
 * @date 2025-03-25
 */
class S3Upload_StreamUploader
{
    private $chunkSize = 5242880; // 5MB
    private $s3Client;
    private $options;

    public function __construct()
    {
        $this->s3Client = S3Upload_S3Client::getInstance();
        $this->options = \Typecho\Widget::widget('Widget\Options')->plugin('S3Upload');
    }

    /**
     * 处理文件上传
     * 
     * @param array $file 上传的文件信息
     * @return array|bool
     */
    public function handleUpload($file)
    {
        try {
            // 记录开始上传的详细信息
            S3Upload_Utils::log("开始处理文件上传: " . print_r($file, true), 'debug');

            // 验证文件
            if (!$this->validateFile($file)) {
                S3Upload_Utils::log("文件验证失败", 'error');
                throw new Exception('文件验证失败');
            }

            // 生成基础存储路径（年月/文件名）
            $path = $this->s3Client->generatePath($file);
            S3Upload_Utils::log("生成存储路径: {$path}", 'debug');

            // 构建完整的存储路径（包含自定义路径前缀）
            $fullPath = $path;
            if (!empty($this->options->customPath)) {
                $customPath = trim($this->options->customPath, '/');
                $fullPath = $customPath . '/' . $path;
                S3Upload_Utils::log("完整存储路径: {$fullPath}", 'debug');
            }

            // 上传到S3
            S3Upload_Utils::log("开始上传到S3: {$fullPath}", 'debug');
            $result = $this->s3Client->putObject($fullPath, $file['tmp_name']);
            S3Upload_Utils::log("S3上传结果: " . print_r($result, true), 'debug');

            // 保存本地备份（如果需要）
            if ($this->shouldSaveLocal()) {
                $this->saveLocalCopy($file, $path);
            }

            // 使用S3Client获取正确的URL
            $url = $this->s3Client->getObjectUrl($path);

            // 获取文件扩展名和MIME类型
            $extension = $this->getFileExt($file['name']);
            $mimeType = $this->getMimeType($file['tmp_name']);

            S3Upload_Utils::log("文件上传成功: 路径={$path}, URL={$url}");

            return array(
                'name' => $file['name'],
                'path' => $path,
                'size' => $file['size'],
                'type' => $extension,
                'mime' => $mimeType,
                'extension' => $extension,
                'url'  => $url
            );

        } catch (Exception $e) {
            S3Upload_Utils::log("上传失败: " . $e->getMessage(), 'error');
            S3Upload_Utils::log("错误堆栈: " . $e->getTraceAsString(), 'error');
            throw new Exception('文件上传失败: ' . $e->getMessage());
        }
    }


    /**
     * 验证文件
     * 
     * @param array $file
     * @return bool
     */
    private function validateFile($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        if (!isset($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return false;
        }

        if (!isset($file['size']) || $file['size'] <= 0) {
            return false;
        }

        return true;
    }

    /**
     * 获取文件扩展名
     * 
     * @param string $filename
     * @return string
     */
    private function getFileExt($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return $ext ? strtolower($ext) : '';
    }

    /**
     * 获取MIME类型
     * 
     * @param string $file
     * @return string
     */
    private function getMimeType($file)
    {
        return S3Upload_Utils::getMimeType($file);
    }

    /**
     * 是否保存本地备份
     * 
     * @return bool
     */
    private function shouldSaveLocal()
    {
        return $this->options->saveLocal === 'true';
    }

    /**
     * 保存本地备份
     * 
     * @param array $file
     * @param string $path
     * @return bool
     */
    private function saveLocalCopy($file, $path)
    {
        $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/';
        $localPath = $uploadDir . $path;
        $localDir = dirname($localPath);
    
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }
    
        if (!copy($file['tmp_name'], $localPath)) {
            S3Upload_Utils::log("无法保存本地备份: " . $localPath, 'warning');
            return false;
        }
    
        return true;
    }

}