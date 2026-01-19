<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 简单的 S3 客户端实现
 */
class S3Upload_S3Client
{
    private static $instance = null;
    private $options = null;
    private $endpoint;
    private $bucket;
    private $region;
    private $accessKey;
    private $secretKey;

    /**
     * 私有构造函数
     */
    private function __construct()
    {
        $options = \Typecho\Widget::widget('Widget\Options');
        $this->options = $options->plugin('S3Upload');
        $this->endpoint = $this->options->endpoint;
        $this->bucket = $this->options->bucket;
        $this->region = $this->options->region;
        $this->accessKey = $this->options->accessKey;
        $this->secretKey = $this->options->secretKey;
    }

    /**
     * 获取实例
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 上传文件到 S3
     */
    public function putObject($path, $file)
    {
        S3Upload_Utils::log("S3Client::putObject 开始 - 路径: {$path}", 'debug');
        
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);

        $fileHandle = fopen($file, 'rb');
        $fileSize = filesize($file);

        $contentType = S3Upload_Utils::getMimeType($file);
        $contentSha256 = hash_file('sha256', $file);

        // 准备请求
        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        $canonical_querystring = '';

        S3Upload_Utils::log("请求URI: {$canonical_uri}, Content-Type: {$contentType}", 'debug');

        // 准备请求头
        $headers = array(
            'content-length' => $fileSize,
            'content-type' => $contentType,
            'host' => $this->options->customDomain,
            'x-amz-content-sha256' => $contentSha256,
            'x-amz-date' => $date
        );

        // 签名
        $signature = $this->getSignature(
            'PUT',
            $canonical_uri,
            $canonical_querystring,
            $headers,
            $contentSha256,
            $shortDate
        );

        // 准备 cURL 请求
        $ch = curl_init();
        $url = 'http://' . $this->endpoint . $canonical_uri;

        S3Upload_Utils::log("上传URL: {$url}", 'debug');

        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        // 获取SSL验证设置
        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';
        S3Upload_Utils::log("SSL验证设置: " . ($sslVerify ? '启用' : '禁用'), 'debug');

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_UPLOAD => true,       // 明确这是一个上传
            CURLOPT_INFILE => $fileHandle, // 告诉cURL从这个文件句柄读取数据
            CURLOPT_INFILESIZE => $fileSize // 告诉cURL要上传的总字节数
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fileHandle);

        S3Upload_Utils::log("HTTP响应码: {$httpCode}", 'debug');

        if ($httpCode !== 200) {
            $errorMsg = "上传失败，HTTP状态码：{$httpCode}";
            if ($curlError) {
                $errorMsg .= "，cURL错误：{$curlError}";
            }
            $errorMsg .= "\n请求URL：{$url}\n响应：{$response}";
            S3Upload_Utils::log($errorMsg, 'error');
            throw new Exception($errorMsg);
        }

        S3Upload_Utils::log("上传成功", 'debug');

        return array(
            'path' => $path,
            'url' => $this->getObjectUrl($path)
        );
    }

    /**
     * 删除 S3 对象
     */
    public function deleteObject($path)
    {
        S3Upload_Utils::log("S3Client::deleteObject 开始 - 路径: {$path}", 'debug');
        
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        
        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        $canonical_querystring = '';
        
        // 使用 endpoint 作为 host（和 putObject 保持一致）
        $host = $this->endpoint;
        
        $headers = array(
            'host' => $host,
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => $date
        );
        
        $signature = $this->getSignature(
            'DELETE',
            $canonical_uri,
            $canonical_querystring,
            $headers,
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $shortDate
        );
        
        $ch = curl_init();
        $protocol = $this->options->useHttps === 'true' ? 'https://' : 'http://';
        $url = $protocol . $this->endpoint . $canonical_uri;
        
        S3Upload_Utils::log("删除URL: {$url}", 'debug');
        
        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;

        // 获取SSL验证设置
        $sslVerify = isset($this->options->sslVerify) && $this->options->sslVerify === 'true';

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HEADER => true
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        S3Upload_Utils::log("删除响应码: {$httpCode}", 'debug');
        
        if ($curlError) {
            S3Upload_Utils::log("删除cURL错误: {$curlError}", 'error');
            return false;
        }
        
        // S3 删除成功返回 204，有些兼容S3的服务可能返回 200
        $success = $httpCode === 204 || $httpCode === 200;
        
        if (!$success) {
            S3Upload_Utils::log("删除失败，HTTP状态码: {$httpCode}，响应: {$response}", 'error');
        } else {
            S3Upload_Utils::log("删除成功", 'debug');
        }
        
        return $success;
    }

    /**
     * 获取签名
     */
    private function getSignature($method, $uri, $querystring, $headers, $payload_hash, $shortDate)
    {
        $algorithm = 'AWS4-HMAC-SHA256';
        $service = 's3';
        
        // Canonical Request
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        $canonical_request = $method . "\n"
            . $uri . "\n"
            . $querystring . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;
        
        // String to Sign
        $credential_scope = $shortDate . '/' . $this->region . '/' . $service . '/aws4_request';
        $string_to_sign = $algorithm . "\n"
            . $headers['x-amz-date'] . "\n"
            . $credential_scope . "\n"
            . hash('sha256', $canonical_request);
        
        // Signing
        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
        
        return $algorithm 
            . ' Credential=' . $this->accessKey . '/' . $credential_scope
            . ',SignedHeaders=' . $signed_headers
            . ',Signature=' . $signature;
    }

    /**
     * 获取对象URL
     * 
     * @param string $path 对象路径
     * @return string
     */
    public function getObjectUrl($path)
    {
        $protocol = $this->options->useHttps === 'true' ? 'https://' : 'http://';
        $path = ltrim($path, '/');
        
        // 处理自定义路径前缀
        $customPath = !empty($this->options->customPath) ? trim($this->options->customPath, '/') : '';
        
        // 如果设置了自定义域名
        if (!empty($this->options->customDomain)) {
            $domain = rtrim($this->options->customDomain, '/');
            
            if ($this->options->urlStyle === 'virtual') { 
                return $protocol . $domain . '/' . $path;
            }
            else {
                return $protocol . $domain . '/' . $this->bucket . '/' . $path;
            }
        }

        // 没有自定义域名时，根据URL风格生成地址
        if ($this->options->urlStyle === 'virtual') {
            return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $path;
        }

        return $protocol . $this->endpoint . '/' . $this->bucket . '/' . $path;
    }

    /**
     * 生成存储路径
     */
    public function generatePath($file)
    {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $ext = $ext ? strtolower($ext) : '';

        $date = new \Typecho\Date();
        $path = $date->year . '/' . $date->month;

        // 生成文件名
        $fileName = sprintf('%u', crc32(uniqid())) . ($ext ? '.' . $ext : '');

        // 合并路径
        return $path . '/' . $fileName;
    }

    /**
     * 生成预签名上传URL (用于浏览器直传S3)
     * 
     * @param string $path 对象路径
     * @param string $contentType MIME类型
     * @param int $expires 过期时间(秒)，默认3600秒
     * @return array 包含上传URL和相关信息
     */
    public function getPresignedUploadUrl($path, $contentType = 'application/octet-stream', $expires = 3600)
    {
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $expireTime = time() + $expires;
        
        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $shortDate . '/' . $this->region . '/' . $service . '/aws4_request';
        
        // 构建canonical URI
        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        
        // 构建查询字符串参数
        $params = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $this->accessKey . '/' . $credential_scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'content-type;host'
        ];
        
        // 按字母顺序排序
        ksort($params);
        $canonical_querystring = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        
        // 使用 endpoint 作为 host
        $host = $this->endpoint;
        
        // Canonical headers
        $canonical_headers = "content-type:" . $contentType . "\n" . "host:" . $host . "\n";
        $signed_headers = 'content-type;host';
        
        // 对于预签名URL，payload是UNSIGNED-PAYLOAD
        $payload_hash = 'UNSIGNED-PAYLOAD';
        
        // Canonical Request
        $canonical_request = "PUT\n"
            . $canonical_uri . "\n"
            . $canonical_querystring . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;
        
        // String to Sign
        $string_to_sign = $algorithm . "\n"
            . $date . "\n"
            . $credential_scope . "\n"
            . hash('sha256', $canonical_request);
        
        // Signing Key
        $kSecret = 'AWS4' . $this->secretKey;
        $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
        
        // 构建最终URL
        $protocol = $this->options->useHttps === 'true' ? 'https://' : 'http://';
        $presignedUrl = $protocol . $host . $canonical_uri . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;
        
        return [
            'uploadUrl' => $presignedUrl,
            'path' => $path,
            'contentType' => $contentType,
            'expires' => $expireTime,
            'objectUrl' => $this->getObjectUrl($path)
        ];
    }

    /**
     * 根据文件名生成完整的存储路径（包含自定义前缀）
     * 
     * @param string $filename 文件名
     * @return string 完整路径
     */
    public function generateFullPath($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $ext = $ext ? strtolower($ext) : '';

        $date = new \Typecho\Date();
        $basePath = $date->year . '/' . $date->month;

        // 生成文件名
        $newFileName = sprintf('%u', crc32(uniqid())) . ($ext ? '.' . $ext : '');
        $path = $basePath . '/' . $newFileName;

        // 添加自定义路径前缀
        if (!empty($this->options->customPath)) {
            $customPath = trim($this->options->customPath, '/');
            $path = $customPath . '/' . $path;
        }

        return $path;
    }
}