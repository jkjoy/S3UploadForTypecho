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
        $options = \Widget\Options::alloc();
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
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        
        $payload = file_get_contents($file);
        if ($payload === false) {
            throw new Exception('无法读取文件');
        }

        $contentType = S3Upload_Utils::getMimeType($file);
        $contentSha256 = hash('sha256', $payload);
        
        // 准备请求
        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        $canonical_querystring = '';
        
        // 准备请求头
        $headers = array(
            'content-length' => strlen($payload),
            'content-type' => $contentType,
            'host' => $this->endpoint,
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
        $url = 'https://' . $this->endpoint . $canonical_uri;
        
        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('上传失败，HTTP状态码：' . $httpCode);
        }
        
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
        $date = gmdate('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        
        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        $canonical_querystring = '';
        
        $headers = array(
            'host' => $this->endpoint,
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
        $url = 'https://' . $this->endpoint . $canonical_uri;
        
        $curlHeaders = array();
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $signature;
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 204 || $httpCode === 200;
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
            
            // 构建完整路径
            if (!empty($customPath)) {
                return $protocol . $domain . '/' . $customPath . '/' . $path;
            }
            
            return $protocol . $domain . '/' . $path;
        }

        // 没有自定义域名时，根据URL风格生成地址
        if ($this->options->urlStyle === 'virtual') {
            if (!empty($customPath)) {
                return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $customPath . '/' . $path;
            }
            return $protocol . $this->bucket . '.' . $this->endpoint . '/' . $path;
        }

        // 路径形式
        if (!empty($customPath)) {
            return $protocol . $this->endpoint . '/' . $this->bucket . '/' . $customPath . '/' . $path;
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
        
        $date = new Typecho_Date();
        $path = $date->year . '/' . $date->month;
        
        // 生成文件名
        $fileName = sprintf('%u', crc32(uniqid())) . ($ext ? '.' . $ext : '');
        
        // 合并路径
        return $path . '/' . $fileName;
    }
}