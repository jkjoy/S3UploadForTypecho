<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Widget\Options;
use Utils\Helper;

/**
 * S3 协议上传插件
 * 
 * @package S3Upload
 * @author 老孙
 * @version 1.2.0
 * @link https://www.imsun.org
 * @dependence 1.2-*
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
        
        \Typecho\Plugin::factory('Widget\Upload')->uploadHandle = ['S3Upload_FileHandler', 'uploadHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->modifyHandle = ['S3Upload_FileHandler', 'modifyHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->deleteHandle = ['S3Upload_FileHandler', 'deleteHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentHandle = ['S3Upload_FileHandler', 'attachmentHandle'];
        \Typecho\Plugin::factory('Widget\Upload')->attachmentDataHandle = ['S3Upload_FileHandler', 'attachmentDataHandle'];
        
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
        return _t('插件已被禁用');
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

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Password(
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
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }
}