<?php
/**
 * 又拍云（UpYun）文件管理，适用于 Typecho 1.3
 *
 * @package UpyunFile
 * @author 棋
 * @version 1.0.0
 * @link https://github.com/imqi1-github/typecho-plugin-UpyunFile
 * @date 2026-01-17
 */

use Typecho\Common;
use Typecho\Config;
use Typecho\Date;
use Typecho\Plugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Request;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Upyun\Config as UpyunConfig;
use Upyun\Upyun;
use Widget\Archive;
use Widget\Base\Comments;
use Widget\Upload;

class UpyunFile_Plugin implements PluginInterface
{

  //上传文件目录
  const UPLOAD_DIR = '/typecho/usr/uploads';
  const IMG_EXT = ['JPG', 'JPEG', 'PNG', 'BMP'];

  /**
   * 激活插件方法,如果激活失败,直接抛出异常
   *
   * @access public
   * @return string
   */
  public static function activate(): string
  {
    Plugin::factory('Widget_Upload')->uploadHandle = array('UpyunFile_Plugin', 'uploadHandle');
    Plugin::factory('Widget_Upload')->modifyHandle = array('UpyunFile_Plugin', 'modifyHandle');
    Plugin::factory('Widget_Upload')->deleteHandle = array('UpyunFile_Plugin', 'deleteHandle');
    Plugin::factory('Widget_Upload')->attachmentHandle = array('UpyunFile_Plugin', 'attachmentHandle');
    Plugin::factory('Widget_Upload')->attachmentDataHandle = array('UpyunFile_Plugin', 'attachmentDataHandle');
    Plugin::factory('Widget_Abstract_Contents')->contentEx = array('UpyunFile_Plugin', 'replace');
    Plugin::factory('Widget_Archive')->beforeRender = array('UpyunFile_Plugin', 'Widget_Archive_beforeRender');
    return _t('插件已经激活，请正确设置插件');
  }

  /**
   * 禁用插件方法,如果禁用失败,直接抛出异常
   *
   * @static
   * @access public
   * @return string
   */
  public static function deactivate(): string
  {
    return _t('插件已被禁用');
  }

  /**
   * 获取插件配置面板
   *
   * @access public
   * @param Form $form 配置面板
   * @return void
   */
  public static function config(Form $form): void
  {
    $convert = new Radio('convert', array('1' => _t('开启'), '0' => _t('关闭')), '0', _t('图片链接修改'), _t('把文章中图片的链接修改为又拍云 CDN 链接，启用前请把放在 typecho 上传目录中的图片同步到又拍云中'));
    $form->addInput($convert);
    $upyundomain = new Text('upyundomain', NULL, 'https://', _t('绑定域名：'), _t('该绑定域名为绑定Upyun服务的域名，由Upyun提供，注意以 http(s):// 开头，最后不要加 /'));
    $form->addInput($upyundomain->addRule('required', _t('您必须填写绑定域名，它是由 Upyun 提供')));

    $upyunpathmode = new Radio(
      'mode',
      array('typecho' => _t('Typecho结构(' . self::getUploadDir() . '/年/月/文件名)'), 'simple' => _t('精简结构(/年/月/文件名)')),
      'typecho',
      _t('目录结构模式'),
      _t('默认为 Typecho 结构模式')
    );

    $form->addInput($upyunpathmode);

    $upyunhost = new Text('upyunhost', NULL, NULL, _t('服务名称：'));
    $upyunhost->input->setAttribute('class', 'mini');
    $form->addInput($upyunhost->addRule('required', _t('您必须填写服务名称，它是由Upyun提供')));

    $upyunuser = new Text('upyunuser', NULL, NULL, _t('操作员：'));
    $upyunuser->input->setAttribute('class', 'mini');
    $form->addInput($upyunuser->addRule('required', _t('您必须填写操作员，它是由 Upyun 提供')));

    $upyunpwd = new Password('upyunpwd', NULL, NULL, _t('密码：'));
    $form->addInput($upyunpwd->addRule('required', _t('您必须填写密码，它是由 Upyun 提供'))
      ->addRule(array('UpyunFile_Plugin', 'validate'), _t('验证不通过，请核对 Upyun 操作员和密码是否输入正确')));

    $convertPic = new Radio('convertPic', array('1' => _t('开启'), '0' => _t('关闭')), '0', _t('又拍云图片处理'), _t('启用本功能需在又拍云控制台中创建缩略图版本，又拍云文档：<a href="https://help.upyun.com/knowledge-base/image/#thumb">https://help.upyun.com/knowledge-base/image/#thumb</a><br>本功能不会处理带有后缀 <b>_nothumb</b> 的图片（比如：example_nothumb.png）'));
    $form->addInput($convertPic);

    $thumbId = new Text('thumbId', NULL, NULL, _t('图片处理 - 缩略图版本名称：'), _t('启用又拍云图片处理必须正确填写缩略图版本名称'));
    $thumbId->input->setAttribute('class', 'mini');
    $form->addInput($thumbId);

    $outputFormat = new Text('outputFormat', NULL, NULL, _t('图片处理 - 转码输出格式：'), _t('如：jpg、png、webp 等，不填即为源文件拓展名'));
    $outputFormat->input->setAttribute('class', 'mini');
    $form->addInput($outputFormat);

    $addToken = new Radio('addToken', array('1' => _t('开启'), '0' => _t('关闭')), '0', _t('又拍云 Token 防盗链'), _t('启用本功能需在又拍云控制台中启用 Token 防盗链'));
    $form->addInput($addToken);

    $secret = new Password('secret', NULL, NULL, _t('密钥'), _t('启用又拍云 Token 防盗链必须正确填写又拍云控制台中设置的密钥'));
    $form->addInput($secret);

    $etime = new Text('etime', NULL, NULL, _t('签名过期时间'), _t('单位为秒'));
    $etime->input->setAttribute('class', 'mini');
    $form->addInput($etime);

    // 配置备份功能
    require_once('Backups.php');
  }

  /**
   * 个人用户的配置面板
   *
   * @access public
   * @param Form $form
   * @return void
   */
  public static function personalConfig(Form $form)
  {
  }

  /**
   * 上传文件处理函数
   *
   * @access public
   * @param array $file 上传的文件
   * @return array|false
   * @throws Exception
   */
  public static function uploadHandle(array $file): array|false
  {
    if (empty($file['name'])) {
      return false;
    }

    //获取扩展名
    $ext = self::getSafeName($file['name']);

    if (!Upload::checkFileType($ext)) {
      return false;
    }

    $options = Widget::widget('Widget_Options');

    $date = new Date($options->gmtTime);

    //构建路径 /year/month/
    $path = '/' . $date->year . '/' . $date->month;
    $settings = $options->plugin('UpyunFile');
    $thumbId = '';
    if ($settings->mode === 'typecho') {
      $path = self::getUploadDir() . $path;
    }

    $nothumb = str_contains($file['name'], '_nothumb');
    //获取文件名及文件路径
    if (!$nothumb && !empty($settings->convertPic) && !empty($settings->outputFormat)) {
      foreach (self::IMG_EXT as $item) {
        if (strcasecmp($ext, $item) == 0) {
          $ext = $settings->outputFormat;
          $thumbId = $settings->thumbId;
          break;
        }
      }
    }
    $fileName = sprintf('%u', crc32(uniqid())) . ".$ext";
    $path = $path . '/' . $fileName;

    $uploadfile = self::getUploadFile($file);

    if (!isset($uploadfile)) {
      return false;
    }

    //上传文件
    $upyun = self::upyunInit();
    $fh = fopen($uploadfile, 'rb');
    if ($nothumb || empty($thumbId)) {
      $upyun->write($path, $fh);
    } else {
      $upyun->write($path, $fh, array('x-gmkerl-thumb' => $settings->thumbId));
    }
    //检查文件句柄是否是有效的资源
    if (is_resource($fh)) {
      fclose($fh);
    }

    if (!isset($file['size'])) {
      $fileInfo = $upyun->info($path);
      $file['size'] = $fileInfo['x-upyun-file-size'];
    }

    //返回相对存储路径
    return array(
      'name' => $nothumb || empty($settings->outputFormat) ? $file['name'] : substr($file['name'], 0, strrpos($file['name'], '.') + 1) . $ext,
      'path' => $path,
      'size' => $file['size'],
      'type' => $ext,
      'mime' => self::mimeContentType($path)
    );
  }

  /**
   * 修改文件处理函数
   *
   * @access public
   * @param array $content 老文件
   * @param array $file 新上传的文件
   * @return array|false
   * @throws Exception
   */
  public static function modifyHandle(array $content, array $file): array|false
  {
    if (empty($file['name'])) {
      return false;
    }

    //获取扩展名
    $ext = self::getSafeName($file['name']);

    if ($content['attachment']->type !== $ext) {
      return false;
    }

    //获取文件路径
    $path = $content['attachment']->path;

    $uploadfile = self::getUploadFile($file);

    if (!isset($uploadfile)) {
      return false;
    }
    //修改文件
    $settings = Widget::widget('Widget_Options')->plugin('UpyunFile');
    $upyun = self::upyunInit();
    $thumbId = '';
    $nothumb = str_contains($file['name'], '_nothumb');
    if (!$nothumb && !empty($settings->convertPic) && !empty($settings->outputFormat)) {
      foreach (self::IMG_EXT as $item) {
        if (strcasecmp($ext, $item) == 0) {
          $thumbId = $settings->thumbId;
          break;
        }
      }
    }
    $fh = fopen($uploadfile, 'rb');
    if ($nothumb && empty($thumbId)) {
      $upyun->write($path, $fh);
    } else {
      $upyun->write($path, $fh, array('x-gmkerl-thumb' => $settings->thumbId));
    }
    //检查文件句柄是否是有效的资源
    if (is_resource($fh)) {
      fclose($fh);
    }

    if (!isset($file['size'])) {
      $fileInfo = $upyun->info($path);
      $file['size'] = $fileInfo['x-upyun-file-size'];
    }

    //返回相对存储路径
    return array(
      'name' => $content['attachment']->name,
      'path' => $content['attachment']->path,
      'size' => $file['size'],
      'type' => $content['attachment']->type,
      'mime' => $content['attachment']->mime
    );
  }

  /**
   * 删除文件
   *
   * @access public
   * @param array $content 文件相关信息
   * @return string
   */
  public static function deleteHandle(array $content): string
  {
    $upyun = self::upyunInit();
    $path = $content['attachment']->path;

    try {
      $result = $upyun->delete($path);
      if (!$result) {
        error_log('UpyunFile: 删除云存储文件失败 - ' . $path);
      }
    } catch (\Exception $e) {
      // 记录错误但不阻止删除本地记录
      error_log('UpyunFile: 删除云存储文件异常 - ' . $path . ' - ' . $e->getMessage());
    }

    // 始终返回路径，允许 Typecho 删除本地记录
    return $path;
  }

  /**
   * 获取实际文件绝对访问路径
   *
   * @access public
   * @param Config $attachment 文件相关信息
   * @return string
   */
  public static function attachmentHandle(Config $attachment): string
  {
    $settings = Widget::widget('Widget_Options')->plugin('UpyunFile');
    $domain = $settings->upyundomain;
    $url = Common::url($attachment->path, $domain);
    if ($settings->addToken != 1) {
      return $url;
    }
    $etime = self::getEtime($settings->etime);
    $sign = substr(md5($settings->secret . '&' . $etime . '&' . parse_url($url, PHP_URL_PATH)), 12, 8) . $etime;
    return self::addParameter($url, '_upt', $sign);
  }

  /**
   * 获取实际文件数据
   *
   * @access public
   * @param array $content
   * @return array|string
   */
  public static function attachmentDataHandle(array $content): array|string
  {
    $upyun = self::upyunInit();
    return $upyun->info($content['attachment']->path);
  }

  /**
   * 验证Upyun签名
   *
   * @access public
   *
   * @return boolean
   */
  public static function validate(): bool
  {
    $host = Request::getInstance()->get("upyunhost");
    $user = Request::getInstance()->get("upyunuser");
    $pwd = Request::getInstance()->get("upyunpwd");

    try {
      require_once 'Upyun/vendor/autoload.php';
      $serviceConfig = new UpyunConfig($host, $user, $pwd);
      $upyun = new Upyun($serviceConfig);
      $hostUsage = (int)$upyun->usage();
    } catch (Exception) {
      $hostUsage = -1;
    }

    return $hostUsage >= 0;
  }

  /**
   * Upyun初始化
   *
   * @access public
   */
  public static function upyunInit()
  {
    $options = Widget::widget('Widget_Options')->plugin('UpyunFile');
    require_once 'Upyun/vendor/autoload.php';
    $serviceConfig = new UpyunConfig($options->upyunhost, $options->upyunuser, $options->upyunpwd);
    return new Upyun($serviceConfig);
  }

  /**
   * 获取上传文件
   *
   * @param array $file 上传的文件
   * @access private
   * @return string
   */
  private static function getUploadFile(array $file): string
  {
    return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
  }

  /**
   * 获取安全的文件名
   *
   * @param string $name
   * @static
   * @access private
   * @return string
   */
  private static function getSafeName(string &$name): string
  {
    $name = str_replace(array('\\', '"', '<', '>'), '/', $name);
    $name = !str_contains($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
    $info = pathinfo($name);
    $name = substr($info['basename'], 1);

    return isset($info['extension']) ? strtolower($info['extension']) : '';
  }

  /**
   *获取文件上传目录
   * @access private
   * @return string
   */
  private static function getUploadDir(): string
  {
    if (defined('__TYPECHO_UPLOAD_DIR__')) {
      return __TYPECHO_UPLOAD_DIR__;
    }
    return self::UPLOAD_DIR;
  }

  /**
   *获取文件Mime类型，处理掉异常
   * @access private
   * @param $fileName
   * @return string
   */
  private static function mimeContentType($fileName): string
  {
    //TODO:避免该方法
    //避免Typecho mime-content-type引发的异常
    /*异常详情：
    *Warning</b>:  mime_content_type(/path/filename.jpg) [<a href='function.mime-content-type'>function.mime-content-type</a>]:
    *failed to open stream: No such file or directory in <b>/webroot/var/Typecho/Common.php</b> on line <b>1058</b>
    */
    @$mime = Common::mimeContentType($fileName);

    if (!$mime) {
      return self::getMime($fileName);
    }
    return $mime;
  }

  /**
   *获取文件Mime类型
   * @access private
   * @param $fileName
   * @return string
   */
  private static function getMime($fileName): string
  {
    $mimeTypes = array(
      'ez' => 'application/andrew-inset',
      'csm' => 'application/cu-seeme',
      'cu' => 'application/cu-seeme',
      'tsp' => 'application/dsptype',
      'spl' => 'application/x-futuresplash',
      'hta' => 'application/hta',
      'cpt' => 'image/x-corelphotopaint',
      'hqx' => 'application/mac-binhex40',
      'nb' => 'application/mathematica',
      'mdb' => 'application/msaccess',
      'doc' => 'application/msword',
      'dot' => 'application/msword',
      'bin' => 'application/octet-stream',
      'oda' => 'application/oda',
      'ogg' => 'application/ogg',
      'prf' => 'application/pics-rules',
      'key' => 'application/pgp-keys',
      'pdf' => 'application/pdf',
      'pgp' => 'application/pgp-signature',
      'ps' => 'application/postscript',
      'ai' => 'application/postscript',
      'eps' => 'application/postscript',
      'rss' => 'application/rss+xml',
      'rtf' => 'text/rtf',
      'smi' => 'application/smil',
      'smil' => 'application/smil',
      'wp5' => 'application/wordperfect5.1',
      'xht' => 'application/xhtml+xml',
      'xhtml' => 'application/xhtml+xml',
      'zip' => 'application/zip',
      'cdy' => 'application/vnd.cinderella',
      'mif' => 'application/x-mif',
      'xls' => 'application/vnd.ms-excel',
      'xlb' => 'application/vnd.ms-excel',
      'cat' => 'application/vnd.ms-pki.seccat',
      'stl' => 'application/vnd.ms-pki.stl',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pps' => 'application/vnd.ms-powerpoint',
      'pot' => 'application/vnd.ms-powerpoint',
      'sdc' => 'application/vnd.stardivision.calc',
      'sda' => 'application/vnd.stardivision.draw',
      'sdd' => 'application/vnd.stardivision.impress',
      'sdp' => 'application/vnd.stardivision.impress',
      'smf' => 'application/vnd.stardivision.math',
      'sdw' => 'application/vnd.stardivision.writer',
      'vor' => 'application/vnd.stardivision.writer',
      'sgl' => 'application/vnd.stardivision.writer-global',
      'sxc' => 'application/vnd.sun.xml.calc',
      'stc' => 'application/vnd.sun.xml.calc.template',
      'sxd' => 'application/vnd.sun.xml.draw',
      'std' => 'application/vnd.sun.xml.draw.template',
      'sxi' => 'application/vnd.sun.xml.impress',
      'sti' => 'application/vnd.sun.xml.impress.template',
      'sxm' => 'application/vnd.sun.xml.math',
      'sxw' => 'application/vnd.sun.xml.writer',
      'sxg' => 'application/vnd.sun.xml.writer.global',
      'stw' => 'application/vnd.sun.xml.writer.template',
      'sis' => 'application/vnd.symbian.install',
      'wbxml' => 'application/vnd.wap.wbxml',
      'wmlc' => 'application/vnd.wap.wmlc',
      'wmlsc' => 'application/vnd.wap.wmlscriptc',
      'wk' => 'application/x-123',
      'dmg' => 'application/x-apple-diskimage',
      'bcpio' => 'application/x-bcpio',
      'torrent' => 'application/x-bittorrent',
      'cdf' => 'application/x-cdf',
      'vcd' => 'application/x-cdlink',
      'pgn' => 'application/x-chess-pgn',
      'cpio' => 'application/x-cpio',
      'csh' => 'text/x-csh',
      'deb' => 'application/x-debian-package',
      'dcr' => 'application/x-director',
      'dir' => 'application/x-director',
      'dxr' => 'application/x-director',
      'wad' => 'application/x-doom',
      'dms' => 'application/x-dms',
      'dvi' => 'application/x-dvi',
      'pfa' => 'application/x-font',
      'pfb' => 'application/x-font',
      'gsf' => 'application/x-font',
      'pcf' => 'application/x-font',
      'pcf.Z' => 'application/x-font',
      'gnumeric' => 'application/x-gnumeric',
      'sgf' => 'application/x-go-sgf',
      'gcf' => 'application/x-graphing-calculator',
      'gtar' => 'application/x-gtar',
      'tgz' => 'application/x-gtar',
      'taz' => 'application/x-gtar',
      'gz' => 'application/x-gtar',
      'hdf' => 'application/x-hdf',
      'phtml' => 'application/x-httpd-php',
      'pht' => 'application/x-httpd-php',
      'php' => 'application/x-httpd-php',
      'phps' => 'application/x-httpd-php-source',
      'php3' => 'application/x-httpd-php3',
      'php3p' => 'application/x-httpd-php3-preprocessed',
      'php4' => 'application/x-httpd-php4',
      'ica' => 'application/x-ica',
      'ins' => 'application/x-internet-signup',
      'isp' => 'application/x-internet-signup',
      'iii' => 'application/x-iphone',
      'jar' => 'application/x-java-archive',
      'jnlp' => 'application/x-java-jnlp-file',
      'ser' => 'application/x-java-serialized-object',
      'class' => 'application/x-java-vm',
      'js' => 'application/x-javascript',
      'chrt' => 'application/x-kchart',
      'kil' => 'application/x-killustrator',
      'kpr' => 'application/x-kpresenter',
      'kpt' => 'application/x-kpresenter',
      'skp' => 'application/x-koan',
      'skd' => 'application/x-koan',
      'skt' => 'application/x-koan',
      'skm' => 'application/x-koan',
      'ksp' => 'application/x-kspread',
      'kwd' => 'application/x-kword',
      'kwt' => 'application/x-kword',
      'latex' => 'application/x-latex',
      'lha' => 'application/x-lha',
      'lzh' => 'application/x-lzh',
      'lzx' => 'application/x-lzx',
      'frm' => 'application/x-maker',
      'maker' => 'application/x-maker',
      'frame' => 'application/x-maker',
      'fm' => 'application/x-maker',
      'fb' => 'application/x-maker',
      'book' => 'application/x-maker',
      'fbdoc' => 'application/x-maker',
      'wmz' => 'application/x-ms-wmz',
      'wmd' => 'application/x-ms-wmd',
      'com' => 'application/x-msdos-program',
      'exe' => 'application/x-msdos-program',
      'bat' => 'application/x-msdos-program',
      'dll' => 'application/x-msdos-program',
      'msi' => 'application/x-msi',
      'nc' => 'application/x-netcdf',
      'pac' => 'application/x-ns-proxy-autoconfig',
      'nwc' => 'application/x-nwc',
      'o' => 'application/x-object',
      'oza' => 'application/x-oz-application',
      'pl' => 'application/x-perl',
      'pm' => 'application/x-perl',
      'p7r' => 'application/x-pkcs7-certreqresp',
      'crl' => 'application/x-pkcs7-crl',
      'qtl' => 'application/x-quicktimeplayer',
      'rpm' => 'audio/x-pn-realaudio-plugin',
      'shar' => 'application/x-shar',
      'swf' => 'application/x-shockwave-flash',
      'swfl' => 'application/x-shockwave-flash',
      'sh' => 'text/x-sh',
      'sit' => 'application/x-stuffit',
      'sv4cpio' => 'application/x-sv4cpio',
      'sv4crc' => 'application/x-sv4crc',
      'tar' => 'application/x-tar',
      'tcl' => 'text/x-tcl',
      'tex' => 'text/x-tex',
      'gf' => 'application/x-tex-gf',
      'pk' => 'application/x-tex-pk',
      'texinfo' => 'application/x-texinfo',
      'texi' => 'application/x-texinfo',
      '~' => 'application/x-trash',
      '%' => 'application/x-trash',
      'bak' => 'application/x-trash',
      'old' => 'application/x-trash',
      'sik' => 'application/x-trash',
      't' => 'application/x-troff',
      'tr' => 'application/x-troff',
      'roff' => 'application/x-troff',
      'man' => 'application/x-troff-man',
      'me' => 'application/x-troff-me',
      'ms' => 'application/x-troff-ms',
      'ustar' => 'application/x-ustar',
      'src' => 'application/x-wais-source',
      'wz' => 'application/x-wingz',
      'crt' => 'application/x-x509-ca-cert',
      'fig' => 'application/x-xfig',
      'au' => 'audio/basic',
      'snd' => 'audio/basic',
      'mid' => 'audio/midi',
      'midi' => 'audio/midi',
      'kar' => 'audio/midi',
      'mpga' => 'audio/mpeg',
      'mpega' => 'audio/mpeg',
      'mp2' => 'audio/mpeg',
      'mp3' => 'audio/mpeg',
      'm3u' => 'audio/x-mpegurl',
      'sid' => 'audio/prs.sid',
      'aif' => 'audio/x-aiff',
      'aiff' => 'audio/x-aiff',
      'aifc' => 'audio/x-aiff',
      'gsm' => 'audio/x-gsm',
      'wma' => 'audio/x-ms-wma',
      'wax' => 'audio/x-ms-wax',
      'ra' => 'audio/x-realaudio',
      'rm' => 'audio/x-pn-realaudio',
      'ram' => 'audio/x-pn-realaudio',
      'pls' => 'audio/x-scpls',
      'sd2' => 'audio/x-sd2',
      'wav' => 'audio/x-wav',
      'pdb' => 'chemical/x-pdb',
      'xyz' => 'chemical/x-xyz',
      'bmp' => 'image/x-ms-bmp',
      'gif' => 'image/gif',
      'ief' => 'image/ief',
      'jpeg' => 'image/jpeg',
      'jpg' => 'image/jpeg',
      'jpe' => 'image/jpeg',
      'pcx' => 'image/pcx',
      'png' => 'image/png',
      'svg' => 'image/svg+xml',
      'svgz' => 'image/svg+xml',
      'tiff' => 'image/tiff',
      'tif' => 'image/tiff',
      'wbmp' => 'image/vnd.wap.wbmp',
      'ras' => 'image/x-cmu-raster',
      'cdr' => 'image/x-coreldraw',
      'pat' => 'image/x-coreldrawpattern',
      'cdt' => 'image/x-coreldrawtemplate',
      'djvu' => 'image/x-djvu',
      'djv' => 'image/x-djvu',
      'ico' => 'image/x-icon',
      'art' => 'image/x-jg',
      'jng' => 'image/x-jng',
      'psd' => 'image/x-photoshop',
      'pnm' => 'image/x-portable-anymap',
      'pbm' => 'image/x-portable-bitmap',
      'pgm' => 'image/x-portable-graymap',
      'ppm' => 'image/x-portable-pixmap',
      'rgb' => 'image/x-rgb',
      'xbm' => 'image/x-xbitmap',
      'xpm' => 'image/x-xpixmap',
      'xwd' => 'image/x-xwindowdump',
      'igs' => 'model/iges',
      'iges' => 'model/iges',
      'msh' => 'model/mesh',
      'mesh' => 'model/mesh',
      'silo' => 'model/mesh',
      'wrl' => 'x-world/x-vrml',
      'vrml' => 'x-world/x-vrml',
      'csv' => 'text/comma-separated-values',
      'css' => 'text/css',
      '323' => 'text/h323',
      'htm' => 'text/html',
      'html' => 'text/html',
      'uls' => 'text/iuls',
      'mml' => 'text/mathml',
      'asc' => 'text/plain',
      'txt' => 'text/plain',
      'text' => 'text/plain',
      'diff' => 'text/plain',
      'rtx' => 'text/richtext',
      'sct' => 'text/scriptlet',
      'wsc' => 'text/scriptlet',
      'tm' => 'text/texmacs',
      'ts' => 'text/texmacs',
      'tsv' => 'text/tab-separated-values',
      'jad' => 'text/vnd.sun.j2me.app-descriptor',
      'wml' => 'text/vnd.wap.wml',
      'wmls' => 'text/vnd.wap.wmlscript',
      'xml' => 'text/xml',
      'xsl' => 'text/xml',
      'h++' => 'text/x-c++hdr',
      'hpp' => 'text/x-c++hdr',
      'hxx' => 'text/x-c++hdr',
      'hh' => 'text/x-c++hdr',
      'c++' => 'text/x-c++src',
      'cpp' => 'text/x-c++src',
      'cxx' => 'text/x-c++src',
      'cc' => 'text/x-c++src',
      'h' => 'text/x-chdr',
      'c' => 'text/x-csrc',
      'java' => 'text/x-java',
      'moc' => 'text/x-moc',
      'p' => 'text/x-pascal',
      'pas' => 'text/x-pascal',
      '***' => 'text/x-pcs-***',
      'shtml' => 'text/x-server-parsed-html',
      'etx' => 'text/x-setext',
      'tk' => 'text/x-tcl',
      'ltx' => 'text/x-tex',
      'sty' => 'text/x-tex',
      'cls' => 'text/x-tex',
      'vcs' => 'text/x-vcalendar',
      'vcf' => 'text/x-vcard',
      'dl' => 'video/dl',
      'fli' => 'video/fli',
      'gl' => 'video/gl',
      'mpeg' => 'video/mpeg',
      'mpg' => 'video/mpeg',
      'mpe' => 'video/mpeg',
      'qt' => 'video/quicktime',
      'mov' => 'video/quicktime',
      'mxu' => 'video/vnd.mpegurl',
      'dif' => 'video/x-dv',
      'dv' => 'video/x-dv',
      'lsf' => 'video/x-la-asf',
      'lsx' => 'video/x-la-asf',
      'mng' => 'video/x-mng',
      'asf' => 'video/x-ms-asf',
      'asx' => 'video/x-ms-asf',
      'wm' => 'video/x-ms-wm',
      'wmv' => 'video/x-ms-wmv',
      'wmx' => 'video/x-ms-wmx',
      'wvx' => 'video/x-ms-wvx',
      'avi' => 'video/x-msvideo',
      'movie' => 'video/x-sgi-movie',
      'ice' => 'x-conference/x-cooltalk',
      'vrm' => 'x-world/x-vrml',
      'rar' => 'application/x-rar-compressed',
      'cab' => 'application/vnd.ms-cab-compressed'
    );

    $part = explode('.', $fileName);
    $size = count($part);

    if ($size > 1) {
      $ext = $part[$size - 1];
      if (isset($mimeTypes[$ext])) {
        return $mimeTypes[$ext];
      }
    }

    return 'application/octet-stream';
  }

  /**
   * 图片链接软修改
   *
   * @access public
   * @param $text
   * @param $widget
   * @param $lastResult
   * @return array|string|string[] $content
   */
  public static function replace($text, $widget, $lastResult): array|string
  {
    $text = empty($lastResult) ? $text : $lastResult;
    $options = Widget::widget('Widget_Options');
    $settings = $options->plugin('UpyunFile');
    if ($settings->convert == 1) {
      if (($widget instanceof Archive) || ($widget instanceof Comments)) {
        preg_match_all('/<img[^>]*src=[\'"]?([^>\'"\s]*)[\'"]?[^>]*>/i', $text, $matches);
        if ($matches) {
          foreach ($matches[1] as $val) {
            if (str_contains($val, rtrim($options->siteUrl, '/'))) {
              if ($settings->mode === 'typecho') {
                $text = str_replace(rtrim($options->siteUrl, '/') . '/usr/uploads', $settings->upyundomain . self::getUploadDir(), $text);
              } else {
                $text = str_replace(rtrim($options->siteUrl, '/') . '/usr/uploads', $settings->upyundomain, $text);
              }
            }
          }
        }
      }
    }
    return $text;
  }

  public static function Widget_Archive_beforeRender(): void
  {
    ob_start('UpyunFile_Plugin::beforeRender');
  }

  public static function addParameter($url, $key, $val): string
  {
    $query = parse_url($url, PHP_URL_QUERY);
    if (!empty($query)) {
      if (!str_contains($query, $key)) {
        $url .= "&$key=$val";
      } else {
        $url = substr($url, 0, -1 * strlen($query));
        $url .= preg_replace('/(.+?)=([^&?]*)/', "$key=$val", $query);
      }
    } else {
      $url .= "?$key=$val";
    }
    return $url;
  }

  public static function getEtime($timeout)
  {
    static $isFirst = true;
    if (isset($_COOKIE["upyun_token_etime"]) && $_COOKIE["upyun_token_etime"] - time() > 120) {
      return $_COOKIE["upyun_token_etime"];
    }
    $etime = time() + $timeout;
    if ($isFirst) {
      setcookie("upyun_token_etime", $etime);
      $isFirst = false;
    }
    return $etime;
  }

  public static function beforeRender($text): array|string|null
  {
    $settings = Widget::widget('Widget_Options')->plugin('UpyunFile');
    if ($settings->addToken == 1) {
      return preg_replace_callback(
        '/https?:\/\/[-A-Za-z0-9+&@#\/\%?=~_|!:,.;]+[-A-Za-z0-9+&@#\/\%=~_|]/i',
        function ($matches) use ($settings) {
          $etime = self::getEtime($settings->etime);
          $url = $matches[0];
          if (str_contains($url, $settings->upyundomain)) {
            $sign = substr(md5($settings->secret . '&' . $etime . '&' . parse_url($url, PHP_URL_PATH)), 12, 8) . $etime;
            $url = self::addParameter($url, '_upt', $sign);
          }
          return $url;
        },
        $text
      );
    }
    return $text;
  }
}
