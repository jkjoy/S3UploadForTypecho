<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Options;
use Typecho\Common;
use Typecho\Widget\Helper\Layout;

/**
 * S3 协议上传插件
 * 
 * @package S3Upload
 * @author 老孙
 * @version 1.3.1
 * @link https://www.imsun.org
 * @dependence 1.3-*
 */
class S3Upload_Plugin implements PluginInterface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 检查依赖
        self::checkDependencies();
        
        // 注册钩子 - 使用新的Typecho 1.3.0方式
        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle = ['S3Upload_FileHandler', 'uploadHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->modifyHandle = ['S3Upload_FileHandler', 'modifyHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->deleteHandle = ['S3Upload_FileHandler', 'deleteHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentHandle = ['S3Upload_FileHandler', 'attachmentHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentDataHandle = ['S3Upload_FileHandler', 'attachmentDataHandle'];
        
        // 注册直传S3的前端脚本注入
        \Typecho\Plugin::factory('admin/footer.php')->end = ['S3Upload_Plugin', 'injectDirectUploadScript'];
        
        // 添加自定义Action路由 (通过actionTable)
        \Utils\Helper::addAction('s3upload', 'S3Upload_Action');
        
        return _t('插件已经激活，请设置 S3 配置信息');
    }

    /**
     * 检查依赖
     */
    private static function checkDependencies()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('PHP cURL 扩展未安装'));
        }
        

    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        // 移除自定义Action
        \Utils\Helper::removeAction('s3upload');
        return _t('插件已被禁用');
    }

    /**
     * 注入直传S3的前端脚本
     */
    public static function injectDirectUploadScript()
    {
        $options = \Typecho\Widget::widget('Widget\Options');
        $security = \Typecho\Widget::widget('Widget\Security');
        
        // 获取插件配置
        $pluginOptions = $options->plugin('S3Upload');
        
        // 获取允许的文件类型
        $allowedTypes = json_encode($options->allowedAttachmentTypes);
        
        // 获取PHP最大上传大小
        $phpMaxFilesize = function_exists('ini_get') ? trim(ini_get('upload_max_filesize')) : '0';
        if (preg_match("/^([0-9]+)([a-z]{1,2})?$/i", $phpMaxFilesize, $matches)) {
            $size = intval($matches[1]);
            $unit = $matches[2] ?? 'b';
            $phpMaxFilesize = round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        
        // 构建API URL - 使用 Common::url 确保正确格式
        $apiUrl = \Typecho\Common::url('action/s3upload', $options->index);
        
        // 获取管理后台URL
        $adminUrl = $options->adminUrl;
        
        echo <<<SCRIPT
<script>
$(document).ready(function () {
    (function() {
        // 只在有上传区域的页面执行
        if (!document.querySelector('.upload-area')) return;
        
        const S3DirectUpload = {
            apiUrl: '{$apiUrl}',
            adminUrl: '{$adminUrl}',
            allowedTypes: {$allowedTypes},
            maxSize: {$phpMaxFilesize},
            
            // 从页面上传URL中获取安全令牌
            getSecurityToken: function() {
                const uploadArea = document.querySelector('.upload-area');
                if (uploadArea) {
                    const url = uploadArea.dataset.url || '';
                    const match = url.match(/[?&]_=([a-f0-9]+)/);
                    if (match) return match[1];
                }
                return '';
            },
            
            // 获取预签名URL
            async getPresignedUrl(filename, contentType, fileSize) {
                const token = this.getSecurityToken();
                const response = await fetch(this.apiUrl + '?do=getUploadUrl&_=' + token, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        filename: filename,
                        contentType: contentType,
                        size: fileSize
                    }),
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    throw new Error(err.error || '获取上传URL失败');
                }
                
                return response.json();
            },
            
            // 直接上传到S3
            async uploadToS3(presignedData, file) {
                const response = await fetch(presignedData.uploadUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': presignedData.contentType
                    },
                    body: file,
                    mode: 'cors'
                });
                
                if (!response.ok) {
                    throw new Error('上传到S3失败: ' + response.status);
                }
                
                return true;
            },
            
            // 确认上传完成
            async confirmUpload(presignedData, file, cid) {
                const token = this.getSecurityToken();
                const response = await fetch(this.apiUrl + '?do=confirmUpload&_=' + token, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        path: presignedData.path,
                        name: file.name,
                        size: file.size,
                        type: presignedData.contentType,
                        cid: cid || null
                    }),
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    throw new Error(err.error || '确认上传失败');
                }
                
                return response.json();
            },
            
            // 完整上传流程
            async upload(file, cid) {
                // 1. 获取预签名URL
                const presignedData = await this.getPresignedUrl(file.name, file.type || 'application/octet-stream', file.size);
                
                // 2. 直接上传到S3
                await this.uploadToS3(presignedData, file);
                
                // 3. 确认上传，写入数据库
                const result = await this.confirmUpload(presignedData, file, cid);
                
                return result;
            },
            
            // 删除文件
            async deleteFile(cid) {
                const token = this.getSecurityToken();
                const response = await fetch(this.apiUrl + '?do=delete&cid=' + cid + '&_=' + token, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    throw new Error(err.error || '删除失败');
                }
                
                return response.json();
            }
        };
        
        // 覆盖原有的Typecho.uploadFile方法
        let uploadIndex = 0;
        const uploadQueue = [];
        
        function processQueue() {
            const item = uploadQueue.shift();
            if (!item) return;
            
            const { file, cid } = item;
            
            S3DirectUpload.upload(file, cid)
                .then(result => {
                    if (result && result.attachment) {
                        // 触发上传完成事件
                        const li = document.getElementById(file.id);
                        if (li) {
                            li.classList.remove('loading');
                            li.dataset.cid = result.attachment.cid;
                            li.dataset.url = result.attachment.url;
                            li.dataset.image = result.attachment.isImage;
                            li.innerHTML = '<input type="hidden" name="attachment[]" value="' + result.attachment.cid + '" />'
                                + '<a class="insert" target="_blank" href="###" title="点击插入文件">'
                                + result.attachment.title + '</a><div class="info">' + result.attachment.bytes
                                + ' <a class="file" target="_blank" href="' + S3DirectUpload.adminUrl + 'media.php?cid=' 
                                + result.attachment.cid + '" title="编辑"><i class="i-edit"></i></a>'
                                + ' <a class="delete" href="###" title="删除"><i class="i-delete"></i></a></div>';
                            
                            // 绑定事件
                            attachS3Events(li);
                            updateAttachmentNumber();
                            
                            if (typeof Typecho.uploadComplete === 'function') {
                                Typecho.uploadComplete(result.attachment);
                            }
                        }
                    }
                    processQueue();
                })
                .catch(error => {
                    console.error('S3直传失败:', error);
                    const li = document.getElementById(file.id);
                    if (li) {
                        li.classList.remove('loading');
                        li.innerHTML = file.name + ' 上传失败<br />' + error.message;
                        li.style.backgroundColor = '#FBC2C4';
                        setTimeout(() => li.remove(), 3000);
                    }
                    processQueue();
                });
        }
        
        function attachS3Events(el) {
            const insertBtn = el.querySelector('.insert');
            if (insertBtn) {
                insertBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const li = this.closest('li');
                    Typecho.insertFileToEditor(this.textContent, li.dataset.url, li.dataset.image === 'true' || li.dataset.image === '1');
                });
            }
            
            const deleteBtn = el.querySelector('.delete');
            if (deleteBtn) {
                // 移除原有事件（如果有的话）
                const newDeleteBtn = deleteBtn.cloneNode(true);
                deleteBtn.parentNode.replaceChild(newDeleteBtn, deleteBtn);
                
                newDeleteBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const li = this.closest('li');
                    const fileName = li.querySelector('.insert').textContent;
                    if (confirm('确认要删除文件 ' + fileName + ' 吗?')) {
                        const cid = li.dataset.cid;
                        S3DirectUpload.deleteFile(cid)
                            .then(() => {
                                li.style.opacity = '0';
                                li.style.transition = 'opacity 0.3s';
                                setTimeout(() => {
                                    li.remove();
                                    updateAttachmentNumber();
                                }, 300);
                            })
                            .catch(err => {
                                console.error('删除失败:', err);
                                alert('删除失败: ' + err.message);
                            });
                    }
                });
            }
        }
        
        function updateAttachmentNumber() {
            const btn = document.getElementById('tab-files-btn');
            if (!btn) return;
            
            let balloon = btn.querySelector('.balloon');
            const count = document.querySelectorAll('#file-list li .insert').length;
            
            if (count > 0) {
                if (!balloon) {
                    btn.innerHTML = btn.innerHTML.trim() + ' ';
                    balloon = document.createElement('span');
                    balloon.className = 'balloon';
                    btn.appendChild(balloon);
                }
                balloon.textContent = count;
            } else if (balloon) {
                balloon.remove();
            }
        }
        
        // 替换上传方法
        Typecho.uploadFile = function(file) {
            file.id = 'upload-' + (uploadIndex++);
            
            // 检查文件大小
            if (file.size > S3DirectUpload.maxSize) {
                showUploadError('size', file);
                return;
            }
            
            // 检查文件类型
            const match = file.name.match(/\\.([a-z0-9]+)\$/i);
            if (!match || S3DirectUpload.allowedTypes.indexOf(match[1].toLowerCase()) < 0) {
                showUploadError('type', file);
                return;
            }
            
            // 显示上传中状态
            const fileList = document.getElementById('file-list');
            const li = document.createElement('li');
            li.id = file.id;
            li.className = 'loading';
            li.textContent = file.name;
            fileList.appendChild(li);
            
            // 获取当前文章cid
            const cidInput = document.querySelector('input[name=cid]');
            const cid = cidInput ? cidInput.value : null;
            
            // 加入队列
            uploadQueue.push({ file, cid });
            if (uploadQueue.length === 1) {
                processQueue();
            }
        };
        
        function showUploadError(type, file) {
            let word = '上传出现错误';
            switch (type) {
                case 'size': word = '文件大小超过限制'; break;
                case 'type': word = '文件扩展名不被支持'; break;
            }
            
            const fileList = document.getElementById('file-list');
            const li = document.createElement('li');
            li.innerHTML = file.name + ' 上传失败<br />' + word;
            li.style.backgroundColor = '#FBC2C4';
            fileList.appendChild(li);
            setTimeout(() => li.remove(), 3000);
        }
        
        // 接管页面上已有附件的删除事件
        document.querySelectorAll('#file-list li').forEach(function(li) {
            attachS3Events(li);
        });
        
        console.log('S3 Direct Upload 已启用');
    })();
});
</script>
SCRIPT;
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Form $form)
    {
        // S3基本设置
        $endpoint = new \Typecho\Widget\Helper\Form\Element\Text(
            'endpoint', 
            null,
            's3.amazonaws.com',
            _t('S3 Endpoint'),
            _t('S3 服务器地址，例如：s3.amazonaws.com')
        );
        $form->addInput($endpoint->addRule('required', _t('必须填写 Endpoint')));

        $bucket = new \Typecho\Widget\Helper\Form\Element\Text(
            'bucket',
            null,
            '',
            _t('Bucket'),
            _t('存储桶名称')
        );
        $form->addInput($bucket->addRule('required', _t('必须填写 Bucket')));

        $region = new \Typecho\Widget\Helper\Form\Element\Text(
            'region',
            null,
            'us-east-1',
            _t('Region'),
            _t('区域，例如：us-east-1')
        );
        $form->addInput($region->addRule('required', _t('必须填写 Region')));

        $accessKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'accessKey',
            null,
            '',
            _t('Access Key'),
            _t('访问密钥 ID')
        );
        $form->addInput($accessKey->addRule('required', _t('必须填写 Access Key')));

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text(
            'secretKey',
            null,
            '',
            _t('Secret Key'),
            _t('访问密钥密码')
        );
        $form->addInput($secretKey->addRule('required', _t('必须填写 Secret Key')));

        // CDN设置
        $customDomain = new \Typecho\Widget\Helper\Form\Element\Text(
            'customDomain',
            null,
            '',
            _t('自定义域名'),
            _t('设置自定义域名，例如：cdn.example.com（不要包含 http:// 或 https://）')
        );
        $form->addInput($customDomain);

        $useHttps = new \Typecho\Widget\Helper\Form\Element\Radio(
            'useHttps',
            [
                'true' => _t('使用'),
                'false' => _t('不使用'),
            ],
            'true',
            _t('使用HTTPS'),
            _t('是否使用HTTPS协议')
        );
        $form->addInput($useHttps);

        // 高级设置
        $customPath = new \Typecho\Widget\Helper\Form\Element\Text(
            'customPath',
            null,
            '/',
            _t('自定义路径前缀'),
            _t('设置文件存储路径前缀，例如：uploads/（以/结尾）')
        );
        $form->addInput($customPath);

        $saveLocal = new \Typecho\Widget\Helper\Form\Element\Radio(
            'saveLocal',
            [
                'true' => _t('保存'),
                'false' => _t('不保存'),
            ],
            'false',
            _t('保存本地备份'),
            _t('是否在本地保存文件备份')
        );
        $form->addInput($saveLocal);

        $urlStyle = new \Typecho\Widget\Helper\Form\Element\Radio(
            'urlStyle',
            [
                'path' => _t('路径形式'),
                'virtual' => _t('虚拟主机形式'),
            ],
            'path',
            _t('URL访问方式'),
            _t('路径形式：http(s)://endpoint/bucket/object<br/>虚拟主机形式：http(s)://bucket.endpoint/object')
        );
        $form->addInput($urlStyle);

        // 图片压缩设置
        $compressImages = new \Typecho\Widget\Helper\Form\Element\Radio(
            'compressImages',
            [
                '1' => _t('启用'),
                '0' => _t('禁用'),
            ],
            '0',
            _t('图片压缩'),
            _t('是否对上传的图片进行自动压缩')
        );
        $form->addInput($compressImages);

        $compressQuality = new \Typecho\Widget\Helper\Form\Element\Text(
            'compressQuality',
            null,
            '85',
            _t('压缩质量'),
            _t('图片压缩质量 (1-100)，数值越大质量越好但文件越大')
        );
        $compressQuality->addRule('isInteger', _t('请输入整数'));
        $compressQuality->addRule('min', _t('请输入不小于1的数字'), 1);
        $compressQuality->addRule('max', _t('请输入不大于100的数字'), 100);
        $form->addInput($compressQuality);

        // SSL证书验证设置
        $sslVerify = new \Typecho\Widget\Helper\Form\Element\Radio(
            'sslVerify',
            [
                'true' => _t('启用'),
                'false' => _t('禁用'),
            ],
            'false',
            _t('SSL证书验证'),
            _t('是否验证S3服务器的SSL证书。如果上传失败且服务器SSL证书配置有问题，可以尝试禁用此选项')
        );
        $form->addInput($sslVerify);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }


}