<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * S3 直传 Action 处理器
 * 
 * @package S3Upload
 * @author OpenCode
 * @version 1.0.0
 */
class S3Upload_Action extends \Typecho\Widget implements \Widget\ActionInterface
{
    /**
     * 执行Action
     */
    public function action()
    {
        // 验证用户权限
        $user = \Typecho\Widget::widget('Widget\User');
        if (!$user->pass('contributor', true)) {
            $this->response->setStatus(403);
            $this->response->throwJson(['error' => '没有权限']);
            return;
        }
        
        $do = $this->request->get('do');
        
        switch ($do) {
            case 'getUploadUrl':
                $this->getUploadUrl();
                break;
            case 'confirmUpload':
                $this->confirmUpload();
                break;
            case 'delete':
                $this->deleteFile();
                break;
            default:
                $this->response->setStatus(400);
                $this->response->throwJson(['error' => '未知操作']);
        }
    }
    
    /**
     * 获取预签名上传URL
     */
    private function getUploadUrl()
    {
        try {
            // 验证安全令牌
            $security = \Typecho\Widget::widget('Widget\Security');
            $security->protect();
            
            // 获取请求数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (empty($data['filename'])) {
                throw new Exception('文件名不能为空');
            }
            
            $filename = $data['filename'];
            $contentType = $data['contentType'] ?? 'application/octet-stream';
            $fileSize = $data['size'] ?? 0;
            
            // 验证文件类型
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $options = \Typecho\Widget::widget('Widget\Options');
            
            if (!in_array($ext, $options->allowedAttachmentTypes)) {
                throw new Exception('不支持的文件类型: ' . $ext);
            }
            
            // 获取S3客户端
            $s3Client = S3Upload_S3Client::getInstance();
            
            // 生成存储路径
            $path = $s3Client->generateFullPath($filename);
            
            // 生成预签名URL
            $presignedData = $s3Client->getPresignedUploadUrl($path, $contentType);
            
            S3Upload_Utils::log("生成预签名URL: " . print_r($presignedData, true), 'debug');
            
            $this->response->throwJson($presignedData);
            
        } catch (Exception $e) {
            S3Upload_Utils::log("获取上传URL失败: " . $e->getMessage(), 'error');
            $this->response->setStatus(500);
            $this->response->throwJson(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 确认上传完成，写入数据库
     */
    private function confirmUpload()
    {
        try {
            // 验证安全令牌
            $security = \Typecho\Widget::widget('Widget\Security');
            $security->protect();
            
            // 获取请求数据
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (empty($data['path']) || empty($data['name'])) {
                throw new Exception('缺少必要参数');
            }
            
            $path = $data['path'];
            $name = $data['name'];
            $size = $data['size'] ?? 0;
            $contentType = $data['type'] ?? 'application/octet-stream';
            $parentCid = !empty($data['cid']) ? intval($data['cid']) : null;
            
            // 获取文件扩展名
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            // 获取S3客户端生成URL
            $s3Client = S3Upload_S3Client::getInstance();
            $url = $s3Client->getObjectUrl($path);
            
            // 判断是否为图片
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
            
            // 构建附件数据
            $attachment = [
                'name' => $name,
                'path' => $path,
                'size' => $size,
                'type' => $ext,
                'mime' => $contentType,
                'isImage' => $isImage ? 1 : 0,
                'url' => $url
            ];
            
            // 写入数据库
            $user = \Typecho\Widget::widget('Widget\User');
            $db = \Typecho\Db::get();
            
            $struct = [
                'title' => $name,
                'slug' => $name,
                'created' => time(),
                'modified' => time(),
                'type' => 'attachment',
                'status' => 'publish',
                'authorId' => $user->uid,
                'text' => json_encode($attachment),
                'allowComment' => 1,
                'allowPing' => 0,
                'allowFeed' => 1
            ];
            
            if ($parentCid) {
                // 验证父文章是否存在且有权限
                $parent = $db->fetchRow($db->select()->from('table.contents')
                    ->where('cid = ?', $parentCid)
                    ->where('type = ?', 'post'));
                    
                if ($parent) {
                    $struct['parent'] = $parentCid;
                }
            }
            
            $insertId = $db->query($db->insert('table.contents')->rows($struct));
            
            S3Upload_Utils::log("附件已写入数据库, cid: {$insertId}", 'debug');
            
            // 返回结果
            $this->response->throwJson([
                'success' => true,
                'attachment' => [
                    'cid' => $insertId,
                    'title' => $name,
                    'type' => $ext,
                    'size' => $size,
                    'bytes' => number_format(ceil($size / 1024)) . ' Kb',
                    'isImage' => $isImage,
                    'url' => $url,
                    'permalink' => $url
                ]
            ]);
            
        } catch (Exception $e) {
            S3Upload_Utils::log("确认上传失败: " . $e->getMessage(), 'error');
            $this->response->setStatus(500);
            $this->response->throwJson(['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 删除文件
     */
    private function deleteFile()
    {
        try {
            // 验证安全令牌
            $security = \Typecho\Widget::widget('Widget\Security');
            $security->protect();
            
            // 获取cid参数
            $cid = $this->request->get('cid');
            if (empty($cid)) {
                throw new Exception('缺少文件ID');
            }
            
            $cid = intval($cid);
            $user = \Typecho\Widget::widget('Widget\User');
            $db = \Typecho\Db::get();
            
            // 查询附件信息
            $attachment = $db->fetchRow($db->select()->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type = ?', 'attachment'));
            
            if (!$attachment) {
                throw new Exception('附件不存在');
            }
            
            // 检查权限：只有管理员或作者本人可以删除
            if (!$user->pass('administrator', true) && $attachment['authorId'] != $user->uid) {
                throw new Exception('没有权限删除此附件');
            }
            
            // 解析附件数据获取S3路径
            $attachmentData = json_decode($attachment['text'], true);
            $s3Path = $attachmentData['path'] ?? '';
            
            S3Upload_Utils::log("准备删除附件, cid: {$cid}, 路径: {$s3Path}", 'debug');
            S3Upload_Utils::log("附件数据: " . print_r($attachmentData, true), 'debug');
            
            $s3Deleted = false;
            
            // 从S3删除文件
            if (!empty($s3Path)) {
                $s3Client = S3Upload_S3Client::getInstance();
                $s3Deleted = $s3Client->deleteObject($s3Path);
                
                if ($s3Deleted) {
                    S3Upload_Utils::log("已从S3删除文件: {$s3Path}", 'debug');
                } else {
                    S3Upload_Utils::log("从S3删除文件失败: {$s3Path}", 'warning');
                }
            } else {
                S3Upload_Utils::log("附件没有S3路径，跳过S3删除", 'warning');
            }
            
            // 从数据库删除记录
            $db->query($db->delete('table.contents')->where('cid = ?', $cid));
            
            S3Upload_Utils::log("附件数据库记录已删除, cid: {$cid}", 'debug');
            
            $this->response->throwJson([
                'success' => true,
                'message' => '删除成功',
                's3Deleted' => $s3Deleted,
                's3Path' => $s3Path
            ]);
            
        } catch (Exception $e) {
            S3Upload_Utils::log("删除文件失败: " . $e->getMessage(), 'error');
            $this->response->setStatus(500);
            $this->response->throwJson(['error' => $e->getMessage()]);
        }
    }
}
